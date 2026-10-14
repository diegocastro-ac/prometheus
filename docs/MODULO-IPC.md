# Módulo de IPC y reajustes

Este documento describe qué hace el módulo de reajustes de la Ley 820 de 2003,
por qué está construido como está, y qué patrones de diseño hacen posible esa
estructura.

---

## Índice

**Parte I — La funcionalidad**

1. [Qué resuelve](#1-qué-resuelve)
2. [De dónde salen las cifras](#2-de-dónde-salen-las-cifras)
3. [El cálculo](#3-el-cálculo)
4. [Las reglas de la ley](#4-las-reglas-de-la-ley)
5. [La pantalla](#5-la-pantalla)
6. [Los datos que la ley pide](#6-los-datos-que-la-ley-pide)

**Parte II — Los patrones**

7. [Adapter](#7-adapter)
8. [Repository](#8-repository)
9. [Observer](#9-observer)
10. [Template Method](#10-template-method)
11. [Singleton](#11-singleton)
12. [Strategy](#12-strategy)
13. [Decorator](#13-decorator)
14. [Chain of Responsibility](#14-chain-of-responsibility)
15. [Specification](#15-specification)
16. [Command](#16-command)
17. [Abstract Factory](#17-abstract-factory)
18. [Cómo encajan](#18-cómo-encajan)

---

# Parte I — La funcionalidad

## 1. Qué resuelve

El módulo calcula reajustes de canon de alquiler bajo el artículo 20 de la Ley
820 de 2003, que es la norma colombiana de vivienda urbana.

La ley fija tres reglas que el módulo tiene que respetar:

- El incremento no puede superar el **100 % del IPC** del año calendario
  inmediatamente anterior al de vigencia.
- Solo puede aplicarse **cada doce meses bajo un mismo precio**. Cada vez que el
  canon cambia, el reloj se reinicia.
- El **monto y la fecha** deben comunicarse por el servicio postal autorizado o
  por el mecanismo pactado en el contrato.

El módulo cubre además los artículos 1 y 18, que son independientes y afectan a
un subconjunto de los contratos:

- **Artículo 1:** la ley se aplica a vivienda urbana. En un inmueble comercial el
  canon lo fija el mercado y el IPC pasa de ser un tope a ser una referencia.
- **Artículo 18:** limita el canon completo al **1 % del valor comercial** y exige
  que esa estimación no supere el doble del avalúo catastral.

## 2. De dónde salen las cifras

El IPC oficial se descarga del **CDA del DANE**, un servicio público sin
documentación de contrato estable. El módulo lo consume a través de un adaptador
dedicado que traduce sus peculiaridades —decimales con coma, nombres de mes en
texto, códigos numéricos que llegan sin ceros a la izquierda, errores 502
intermitentes— a las estructuras que el resto del sistema entiende.

Lo descargado se guarda en una tabla de **serie completa** (`ipc_series`), con
todos los periodos de todas las geographies. Esa copia local es la única que
consulta el resto de la aplicación: el cálculo nunca depende de que el DANE esté
respondiendo.

Sobre esa copia se derivan dos artefactos:

- **Tasas anuales** (`ipc_rates`), una por año y geografía, con la variación del
  año calendario completo. Es la cifra que el artículo 20 consume.
- **Catálogo de ciudades** (`geographies`), con la marca de cuáles tienen serie
  publicada.

## 3. El cálculo

La regla que decide el resultado es que **el IPC se aplica al canon vigente, no al
canon original del contrato**.

Un alquiler que empieza en 1.000.000, se reajusta a 1.051.000 y un año después se
reajusta otra vez, no aplica el IPC sobre 1.000.000: lo aplica sobre 1.051.000.
Sumar el porcentaje nuevo sobre el canon original produciría una cadena
subestimada, y el crecimiento acumulado se perdería.

Para que eso sea posible, cada alquiler guarda un **ancla**: el canon con el que
se hizo el primer cálculo y la fecha en que quedó establecido. A partir del ancla,
la cadena de reajustes se reconstruye entera:

```
ancla →Ajuste 1 → Ajuste 2 → … → Ajuste n
         ↑            ↑
         └─ sobre el resultado del anterior
```

El motor hace dos cosas distintas para cada ajuste, y la distinción es lo que
sostiene la exactitud del cálculo:

- **`previous_rent`** es el canon que estaba en vigor antes de ese reajuste. Es la
  base sobre la que se aplica el IPC.
- **`legal_cap_rent`** es ese canon multiplicado por el IPC. Es el tope que
  respalda la ley.

El canon que realmente se pacta puede ser menor que el tope —el artículo 20 dice
«hasta», no «exactamente»— y por eso son dos cifras y no una.

El reproceso es **idempotente**: si cambia el IPC de un año, se reconstruye la
cadena completa y solo se escriben las filas que efectivamente cambian. Eso
permite que la corrección de un dato del DANE se propague a todos los alquileres
sin efectos colaterales en los que ya estaban bien.

## 4. Las reglas de la ley

El módulo no decide qué es legal en un `if` suelto. Hay **nueve reglas
independientes**, cada una en su propia clase, que mapean a un artículo numerado:

| Regla | Artículo | Qué comprueba |
|---|---|---|
| `HasIpcData` | 20 | Existe el dato oficial del año que la ley exige |
| `IsResidentialScope` | 1 | El alquiler está dentro del ámbito de la ley |
| `WithinTwelveMonthsSamePrice` | 20 | Se cumplen los doce meses bajo un mismo precio |
| `StatutoryCalendarYear` | 20 | El IPC aplicado es el del año calendario anterior |
| `CityIpcRequiresAcknowledgement` | 20 | Usar el IPC de una ciudad es apartarse del texto |
| `CommercialValueWithinCatastralCeiling` | 18 | El valor comercial no pasa de dos avalúos |
| `WithinOnePercentOfCommercialValue` | 18 | El canon no pasa del 1 % del valor comercial |
| `WithinCapOrAcknowledged` | 20 | El incremento no excede el IPC |
| `NotificationHasValidMeans` | 20 | El medio de comunicación está reconocido |

Cada regla tiene **tres niveles de resultado**: bloqueo, advertencia o
información. Solo una bloquea, y lo hace por una razón práctica, no jurídica: sin
el dato oficial no hay carta que emitir.

Las demás avisan. Es deliberado: el artículo 20 permite pactar entre las partes
más que el IPC si lo hacen por escrito, y permitir registros que se apartan con
constancia de ello es más fiel a la ley que rechazarlos sin más.

## 5. La pantalla

El formulario de reajuste se organiza según el **orden de decisión** y no según el
modelo de datos. Son cuatro bloques:

1. **Qué alquiler y desde cuándo.** Son las dos únicas decisiones que el sistema
   no puede tomar. Al elegir el alquiler se propose el aniversario de su último
   reajuste y el año del IPC que la ley exige para esa fecha.
2. **Con qué cifra.** Base del IPC (nacional o ciudad), año, y canon resultante.
   Debajo, una **vista previa del cálculo** que muestra la cadena completa: canon
   vigente, IPC aplicado, incremento, tope del artículo 20, canon elegido y la
   diferencia con el tope.
3. **Lo que decidan las partes.** Porcentaje pactado y reconocimiento de la
   apartación. Va plegada porque es lo que casi nadie usa.
4. **Comprobaciones legales.** El resultado de correr las nueve reglas contra lo
   que se está escribiendo. Solo aparece si hay algo que decir.

La vista previa responde a la pregunta que realmente surge al ver el campo del
canon: *¿de dónde sale este número?*. Su primera fila se llama **canon vigente** y
no «canon anterior» a propósito, porque son cosas distintas y confundirlas es el
error que hace inservible el reproceso.

Sobre la vigencia hay una distinción que conviene explicitar. El artículo 20
cuenta los doce meses desde el último cambio de canon, y ese plazo lo cumple una
fecha: el aniversario. **El plazo legal no se mueve.** Lo que sí se mueve es la
fecha que se propone cuando ese aniversario cae en un día no hábil, para que el
aviso por correo autorizado pueda entregarse. Las dos fechas se mantienen
separadas, y el sistema explica cuál es cuál cuando difieren.

## 6. Los datos que la ley pide

El artículo 1 y el 18 piden datos que el artículo 20 no menciona: el tipo de
inmueble, su valor comercial y su avalúo catastral. Viven en el alquiler y están
en su formulario, y solo se exigen cuando son residenciales, porque en un local
comercial la ley no aplica.

La **ciudad del inmueble** vive en la propiedad, no en el alquiler. La ubicación
es del inmueble: un edificio puede tener varios contratos y todos son la misma
ciudad. Además hay una ciudad predeterminada en Ajustes que se propone al crear
una propiedad; si no se ha declarado ninguna, el campo queda vacío y se exige,
porque el total nacional del IPC no es una ciudad.

El **catálogo de espacios** del acta de entrega sigue la misma lógica: es
vocabulario del arrendador, no un ajuste de la aplicación, y se administra desde
la pantalla donde se usa.

---

# Parte II — Los patrones

Los patrones que siguen están **implementados en el módulo**. No se listan
patrones que no se usen, ni se explican en abstracto: cada uno aparece porque hay
una razón concreta que lo justifica.

## 7. Adapter

**Qué resuelve**

El CDA del DANE no es una interfaz estable. Cambia el formato de los decimales,
devuelve códigos numéricos donde se esperan cadenas, nombra los meses en texto y
falla de forma intermitente. Si todo eso se colara en el código de negocio, cada
pantalla y cada cálculo tendría que conocer las rarezas de un servicio externo.

**Cómo está resuelto**

Hay un contrato —`IpcProvider`— que es lo que el dominio espera saber: dame el
IPC de esta geografía para este año. Y hay una implementación que traduce la
respuesta del CDA a ese contrato.

La traducción es real y no cosmética:

- El decimal con coma se convierte al formato numérico que usa el resto.
- El código de ciudad numérico se rellena con ceros a la izquierda, porque `8001`
  y `08001` son la misma ciudad y la consulta SQL distingue.
- Los nombres de mes en texto se convierten a número.
- El 502 intermitente se reintenta con espera creciente.
- Una respuesta con forma distinta a la esperada se distingue de un error de red,
  porque una cosa es que el DANE falle y otra es que haya cambiado el esquema.

**Qué problema resuelve**

Todo lo que hay aguas abajo —la validación, el cálculo, las pantallas— trabaja
contra el contrato y nunca contra el CDA. Cuando el servicio cambie, el cambio
queda contenido en un archivo.

Hay un segundo adaptador en el módulo, y cumple la misma función en otro
dominio: la **propuesta de fecha de vigencia**. El sistema necesita saber si un
día es hábil y cuál es el siguiente, y eso lo responde un calendario. El módulo
no implementa un calendario propio: reutiliza el mismo contrato que ya usa el
vencimiento de las facturas, de modo que las dos funcionalidades comparten una
única fuente de festivos.

## 8. Repository

**Qué resuelve**

La serie del IPC es la fuente de verdad de un cálculo legal, y se consulta desde
muchos sitios: el selector de años, la vista previa, la carta, el motor de
reajustes, la pantalla de datos.

**Cómo está resuelto**

El acceso a esa tabla vive en un solo lugar. Fuera de él, nadie escribe SQL sobre
la serie.

Eso no es solo limpieza. Concentrarlo resolvió un problema concreto: el selector
de años ofrecía **años sin dato de diciembre**, y el usuario podía elegir uno y
descubrirlo al intentar calcular. Ahora el repositorio es quien decide qué años
son ofrecibles —los que tienen dato de diciembre y están cerrados— y el
formulario no puede ofrecer un año que no va a servir.

También centraliza el acceso al último período de cada geografía, que devuelve
la fecha de la última sincronización. Esa fecha es lo que permite detectar una
serie vieja.

**Qué problema resuelve**

Una regla que aplica a todos los lectores queda escrita una vez. Si el criterio
de qué años son válidos cambia, cambia en un sitio y todos los lectores lo
respetan.

## 9. Observer

**Qué resuelve**

Sincronizar el IPC tiene una consecuencia que no es evidente: **afecta a los
reajustes ya registrados**.

El IPC es la base legal del tope. Si el DANE publica una corrección para
diciembre de un año, todos los topes calculados con esa cifra dejan de ser
correctos. Y si solo se actualiza el selector de años, la aplicación termina
ofreciendo un porcentaje nuevo mientras los alquileres siguen mostrando topes
calculados con el anterior: dos pantallas dando números distintos para el mismo
año.

**Cómo está resuelto**

La sincronización emite un evento de dominio —la serie fue actualizada— y dos
reacciones independientes lo atienden:

- Una reconstruye las **tasas anuales** a partir de la serie nueva.
- Otra **reprocesa las cadenas de reajuste** de todos los alquileres afectados.

Nadie que sincronice tiene que saber que existen reajustes, y nadie que registre
un reajuste tiene que saber que existe una sincronización. El editor de la serie
no conoce el dominio de los alquileres, y el dominio de los alquileres no
conoce la descarga de datos.

**Qué problema resuelve**

Rompe la dependencia circular entre dos operaciones que no se conocen. El efecto
secundario de una sincronización queda registrado **junto a la operación que lo
origina**, y por eso no se olvida: es imposible añadir una sincronización sin que
las reacciones se tengan en cuenta.

La segunda reacción es idempotente a propósito. Eso permite dejar el enlace
siempre activo con seguridad: si la sincronización mensual no cambia nada, el
reproceso no escribe ninguna fila.

## 10. Template Method

**Qué resuelve**

La sincronización siempre hace lo mismo, en el mismo orden, para cualquier
geografía. Y los pasos no son negociables.

**Cómo está resuelto**

Hay una clase con la secuencia fija, y lo único que cambia es **qué** se
sincroniza. Se define la estructura; las implementaciones aportan el dato
concreto.

Los cinco pasos están deliberadamente en ese orden. El paso que garantiza que
una sincronización no se quede a medio camino es el que valida **antes** de
escribir: la serie que se va a guardar tiene que corresponder a la geografía
pedida. Una sincronización parcial es peor que ninguna, porque deja datos de un
lugar donde se esperaba otro.

**Qué problema resuelve**

Estructura y comportamiento quedan separados. La secuencia no puede alterarse por
accidente desde una subclase, y añadir una géographie nueva no obliga a
reimplementar el proceso.

## 11. Singleton

**Qué resuelve**

La configuración de la aplicación —razón social, NIT, moneda, textos legales— la
necesitan los documentos, el acta de entrega y las pantallas. Y debe ser **la
misma** para todos dentro de una misma petición.

**Cómo está resuelto**

Existe una sola instancia por petición, construida una vez desde la base de
datos. Los documentos y los servicios la reciben en lugar de recibir cada dato
por parámetro, que es lo que hacía que la información se duplicara en varias
pantallas y los documentos no

y no coincidieran entre sí.

**Qué problema resuelve**

Elimina la duplicación y garantiza coherencia dentro de una operación. Cuando se
guarda una configuración nueva, la instancia se descarta para que la siguiente
petición la vuelva a leer, de modo que un documento impreso en el mismo minuto
que el guardado no muestre el NIT anterior.

## 12. Strategy

**Qué resuelve**

El porcentaje de un reajuste se puede calcular de más de una manera, y la
correcta depende de la base elegida.

La variación anual es lo que fija la ley: el porcentaje acumulado del año
calendario. Pero un cálculo por razón de índices entre dos periodos es
legítimamente informative, y hay un tercer caso —el IPC congelado en el momento
en que se registró el ajuste— que sirve para que la carta historical no cambie
cuando el DANE corrige un dato.

**Cómo está resuelto**

Cada forma de cálculo es una implementación de una interfaz común. El servicio
que necesita un porcentaje pide una estrategia por nombre; el resolver devuelve
la implementación correspondiente.

Las tres strategias declaran **si son calculables** en un caso dado. Eso importa:
el cálculo por índices, por ejemplo, no se puede aplicar sobre una fecha sin
índice de ese mes, y la estrategia lo dice en lugar de fallar.

**Qué problema resuelve**

Cada variante del algoritmo queda en su propia clase, sin condicionales que
decidan entre caminos. Añadir una forma de cálculo es agregar una clase y su
nombre, sin tocar lo existente.

La que se elige por defecto es la que dice la ley. Las demás están disponibles y
marcadas como informativas, no ocultas: son correctas en su contexto, y quien las
usa lo sabe.

## 13. Decorator

**Qué resuelve**

El artículo 20 dice «hasta el 100 % del IPC». «Hasta» significa que las partes
pueden acordar menos. Un alquiler puede pactar la mitad del IPC, y esa decisión
tiene que **sumarse** al cálculo sin alterar la estrategia que produce el
porcentaje legal.

**Cómo está resuelto**

Hay una pieza que envuelve a la estrategia y recorta su resultado según el
porcentaje pactado. La estrategia no sabe que existe el pacto: sigue devolviendo
el tope legal. El decorador lo envuelve y aplica el mínimo acordado.

**Qué problema resuelve**

Permite combinar behaviours sin que ninguna de las dos piezas tenga que conocer a
la otra. El recorte por pacto y el cálculo por índice son ortogonales: funcionan
juntos, y ninguno de los dos necesita una rama especial para el otro.

Hay un detalle de implementación que conviene subrayar: el decorador tiene
que **separar** el porcentaje legal del aplicado. Si solo guardara el resultado
ya recortado, la carta no podría mostrar el tope que la ley fija, y esa es
precisamente la cifra que el arrendatario necesita ver para saber que no le
excedieron.

## 14. Chain of Responsibility

**Qué resuelve**

Hay nueve comprobaciones legales que hay que correr, y **el orden importa**.

El artículo 18 solo tiene sentido si hay valor comercial declarado: sin él no
hay contra qué comparar el canon, y avisar del uno por ciento sería ruido que
tapa el aviso que sí importa. La regla que verifica la existencia del dato tiene
que correr antes que la que lo usa.

Y el resultado importa igual: **solo una regla bloquea**. Las demás informan.

**Cómo está resuelto**

Cada comprobación es un objeto que sabe examinar un contexto de reajuste y decir
qué encontró. La cadena las recorre en orden y entrega cada resultado.

Añadir una comprobación es agregar una clase. No hay un método con nueve
condicionales donde el orden es invisible y una condición nueva obliga a leer
todo para saber dónde insertarla.

**Qué problema resuelve**

Cada comprobación vive aislada y probable por separado. El contexto que reciben
contiene **todo** lo que la regla necesita, incluido el artículo al que
corresponde, de modo que el aviso que ve el usuario dice en qué norma se apoya.

El resultado es tipado por severidad, y el resumen sabe distinguir un bloqueo de
un aviso. Eso permite que la interfaz se comporte de forma distinta: una cosa es
impedir el registro y otra es mostrar lo que conviene revisar.

## 15. Specification

**Qué resuelve**

Hay preguntas de sí o no que se combinan. ¿El alquiler está listo para reajustarse?
Eso es varias condiciones a la vez: que tenga reajustes previos, que se cumplan
los doce meses, que exista el IPC del año que corresponde.

**Cómo está resuelto**

Una especificación encapsula una condición. Las especificaciones se combinan:
hay una conjunción y una disyunción, y saber si un alquiler cumple una regla
compuesta se expresa componiendo, no encadenando condiciones en un `if`.

**Qué problema resuelve**

Una condición nombrada es legible y reutilizable. La composición permite decir
«esto **y** aquello» sin escribir una función que reciba tres booleanos.

El efecto secundario útil es que las condiciones se prueban por separado y en
combinación, que es como se detecta que dos reglas se contradicen.

## 16. Command

**Qué resuelve**

Reprocesar la cadena de un alquiler tiene que hacer exactamente dos cosas:
calcular qué **debería** ser cada ajuste, y escribir solo lo que cambió.

Separarlas importa. Si el cálculo fallara a mitad, no habría que haber escrito
nada: se ejecuta dentro de una transacción, y el cálculo completo ocurre antes
de la primera escritura.

Y el reproceso **sigue siendo una operación de la aplicación**, con su propia
regla de negocio —qué ajustar, cuándo hacerlo, si es idempotente—, no un
procedimiento suelto invocado desde una pantalla.

**Cómo está resuelto**

El comando recibe la intención —reprocesar este alquiler— y se encarga de la
ejecución. Comparar lo calculado con lo almacenado es parte del comando, no de
quien lo invoca.

**Qué problema resuelve**

Convierte una tarea de mantenimiento en algo que se puede probar, reutilizar y
ejecutar desde consola sin montar nada.

## 17. Abstract Factory

**Qué resuelve**

Los documentos del sistema —factura, recibo, carta de reajuste, estado de
cuenta— comparten una estructura: todos reciben un cuerpo ya armado y un
renderizador. Y todos existen en dos formatos, texto y PDF.

**Cómo está resuelto**

Hay una fábrica abstracta que declara qué documentos produce, y dos
implementaciones concretas: una devuelve documentos de texto plano, la otra de
PDF.

La elección no está codificada en quien pide el documento. Hay un **localizador**
que resuelve el nombre de la familia, y ese es el punto donde la pantalla decide.
El resto del sistema pide «una factura» y no sabe si le va a llegar en texto o en
PDF.

**Qué problema resuelve**

Cada familia produce sus productos sin conocer la otra. El formato se decide en
un solo sitio, y añadir un formato nuevo no obliga a modificar los documentos.

## 18. Cómo encajan

Los patrones no se eligieron porCompletar un catálogo. Cada uno responde a un
problema que el módulo tiene de verdad:

| Necesidad | Patrón |
|---|---|
| Hablar con un servicio externo inestable | **Adapter** |
| Un acceso a datos con reglas propias | **Repository** |
| Propagar el efecto de una operación sobre otras | **Observer** |
| Un proceso con pasos fijos y variables | **Template Method** |
| Configuración compartida y coherente | **Singleton** |
| Varias formas de calcular lo mismo | **Strategy** |
| Añadir una restricción sin alterar lo anterior | **Decorator** |
| Varias comprobaciones en un orden que importa | **Chain of Responsibility** |
| Condiciones combinables y nombradas | **Specification** |
| Operaciones de mantenimiento explícitas | **Command** |
| Dos familias de un mismo producto | **Abstract Factory** |

Leídos juntos forman una arquitectura con una dirección clara:

- **Los bordes usan adaptadores.** El CDA y el calendario entran traducidos.
- **El centro no conoce los bordes.** El cálculo no sabe de dónde vienen los
  datos, solo los recibe por contrato.
- **Las variantes se resuelven por Strategy y Decorator**, no con condicionales.
- **Las obligaciones de la ley se expresan como objetos**, no como controles de
  flujo.
- **Las consecuencias de una acción se suscriben con Observer**, no se invocan
  desde donde se dispara la acción.

---

## Referencias de código

| Concepto | Archivo |
|---|---|
| Contrato del IPC | `app/Contracts/Ipc/IpcProvider.php` |
| Adaptador del DANE | `app/Infrastructure/Ipc/DaneCdaIpcProvider.php` |
| Proveedor de producción | `app/Infrastructure/Ipc/LocalSeriesIpcProvider.php` |
| Copia local | `app/Services/Ipc/IpcSeriesRepository.php` |
| Sincronización | `app/Services/Ipc/IpcSynchronizer.php` |
| Consulta al CDA | `app/Services/Ipc/CdaQueryFactory.php` |
| Parámetros de consulta | `config/dane_ipc_sql.php` |
| Propuesta de fecha de vigencia | `app/Services/Adjustment/AdjustmentDateProposer.php` |
| Contrato de días hábiles | `app/Contracts/BusinessCalendar.php` |
| Motor de cálculo | `app/Services/Adjustment/AdjustmentChainCalculator.php` |
| Reproceso | `app/Services/Adjustment/AdjustmentChainRecalculator.php` |
| Estrategias | `app/Services/Adjustment/Strategies/` |
| Decorador del pacto | `app/Services/Adjustment/Decorators/PactadoCapDecorator.php` |
| Reglas de la ley | `app/Services/Adjustment/Validation/` |
| Servicio de aplicación | `app/Services/RentAdjustmentService.php` |
| Composición de reglas | `app/Services/Adjustment/Validation/ReajusteValidationChain.php` |
| Especificaciones | `app/Services/Adjustment/Specifications/` |
| Eventos y reacciones | `app/Events/`, `app/Listeners/` |
| Fábricas de documentos | `app/Documents/Factories/` |
| Configuración | `app/Settings/AppSettings.php` |
| Pantalla de reajustes | `app/Filament/Resources/RentAdjustmentResource.php` |
| Pantalla de datos del IPC | `app/Filament/Resources/IpcSeriesResource.php` |
| Selector de espacios | `app/Filament/Support/SpaceCatalogField.php` |
| Mensajes de las reglas | `lang/es/adjustment_rules.php`, `lang/en/adjustment_rules.php` |