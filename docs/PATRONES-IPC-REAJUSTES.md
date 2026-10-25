# Patrones de diseño del módulo de IPC y reajustes

Este documento explica los patrones de diseño que rigen el módulo de IPC del DANE
y el cálculo de reajustes del canon. Es la continuación de
[DOCUMENTACION-PATRONES.md](DOCUMENTACION-PATRONES.md), que cubre Singleton,
Builder, Abstract Factory, Factory Method y el Adapter de días hábiles.

La diferencia con los módulos anteriores es que aquí los patrones no son una
decisión estética: **cada uno existe porque sin él el módulo daría un número
incorrecto con toda seguridad**. Eso es lo que se explica en cada sección.

---

## Índice

1. [El problema que resuelve el módulo](#1-el-problema-que-resuelve-el-módulo)
2. [Adapter — el IPC del DANE](#2-adapter--el-ipc-del-dane)
3. [Repository — la copia local](#3-repository--la-copia-local)
4. [Template Method — la sincronización](#4-template-method--la-sincronización)
5. [Observer — la sincronización dispara el recálculo](#5-observer--la-sincronización-dispara-el-recálculo)
6. [Strategy — cómo se deriva el porcentaje](#6-strategy--cómo-se-deriva-el-porcentaje)
7. [Decorator — el pacto por debajo del IPC](#7-decorator--el-pacto-por-debajo-del-ipc)
8. [Chain of Responsibility — las reglas de la ley](#8-chain-of-responsibility--las-reglas-de-la-ley)
9. [Specification — las preguntas sí/no](#9-specification--las-preguntas-síno)
10. [Command — el reproceso de la cadena](#10-command--el-reproceso-de-la-cadena)
11. [El motor de cálculo](#11-el-motor-de-cálculo)
12. [Mapa completo](#12-mapa-completo)
13. [Por qué no sobreingeniería](#13-por-qué-no-sobreingeniería)
14. [La interfaz también es diseño](#14-la-interfaz-también-es-diseño)

---

## 1. El problema que resuelve el módulo

Antes de los patrones hay que entender el problema, porque algunos patrones que
suenan innecesarios se justifican solo por lo que pasaba sin ellos.

### 1.1 La fuente de datos es frágil y no documentada

El DANE **no publica una API REST del IPC**. Lo que alimenta su "Consulta
dinámica del IPC" es un motor de SQL embebido (Pentaho CDA) al que se le manda
la consulta por POST. No hay contrato, no hay versión y los nombres de las tablas
internas pueden cambiar sin aviso.

Al implementarlo se comprobaron tres cosas que cambian el diseño:

| Hallazgo verificado | Consecuencia de diseño |
|---|---|
| Devuelve **502 intermitente** (ocurrió 3 veces en una sola sesión) | Reintentos + nunca calcular contra el origen |
| Publica **coma decimal** (`193,28`) | Normalización en el borde, no en el dominio |
| `qryCiudad` devuelve el código **como número**: `8001` en vez de `08001` | Relleno a 5 dígitos o la consulta devuelve cero filas en silencio |
| El total nacional **no** es "ciudades menos una lista": es `PAI_ID = 50` | Elimina una lista de 25 códigos que había que mantener a mano |
| La guía original filtraba por niveles 14–18 de la canasta y devolvía **10,80 %** en diciembre de 2024, cuando el IPC oficial fue **5,20 %** | Filtro corregido + un comando que verifica las cifras |

### 1.2 El cálculo tenía estado obsoleto

El diseño anterior guardaba en cada reajuste el canon vigente del alquiler en
el momento de crearlo:

```php
// ANTES — el canon vigente es un valor que el propio servicio modifica
$previousRent = (float) $rental->monthly_amount;
```

Eso producía tres fallos silenciosos:

1. Editabas un reajuste anterior y el siguiente seguía calculándose sobre una copia vieja.
2. Borraste un reajuste y el canon del alquiler quedaba sin ninguna explicación.
3. **No había forma de recalcular**, porque no había ancla.

### 1.3 Las reglas legales estaban escondidas en condicionales

Las reglas de los artículos 18 y 20 vivían en un método con varios `if` dentro
de un servicio. Añadir una regla exigía tocar ese método, y la interfaz mostraba
una versión mientras el servicio exigía otra.

### 1.4 La lista de ciudades y de años estaba escrita en el código

El selector ofrecía valores fijos. Si el DANE publica una ciudad nueva, no
aparecía. Si un año no tiene dato de diciembre, se podía elegir y fallar al
calcular.

---

## 2. Adapter — el IPC del DANE

**Ubicación:** `app/Contracts/Ipc/IpcProvider.php` · `app/Infrastructure/Ipc/`

### 2.1 Qué problema resuelve

El dominio necesita "el IPC de esta geografía en estos años". El CDA del DANE
habla `paramparsSelect`, `doQuery`, comas decimales y códigos sin cero. Sin un
adaptador, cada uno de esos detalles se filtra hacia adentro y la regla del
artículo 20 queda llena de código de transporte.

### 2.2 El puerto

```php
namespace App\Contracts\Ipc;

interface IpcProvider
{
    /** @return list<IpcObservation> */
    public function fetchSeries(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): array;

    /** @return list<Geography> */
    public function fetchGeographies(): array;

    /** @return list<int> */
    public function fetchAvailableYears(): array;

    public function name(): string;
}
```

Devuelve **objetos ya normalizados**, no arrays. La conversión ocurre una sola vez,
en el borde, y a partir de ahí nadie vuelve a mirar texto crudo del DANE.

### 2.3 Las tres implementaciones

```
                    ┌──────────────────────────┐
                    │  IpcProvider (puerto)    │
                    └────────────┬─────────────┘
             ┌───────────────────┼───────────────────┐
             ↓                   ↓                   ↓
   ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
   │ DaneCdaIpcProvider│ │LocalSeriesIpc... │ │InMemoryIpcProvider│
   │  contra el DANE   │ │  lee ipc_series  │ │    para pruebas   │
   └──────────────────┘ └──────────────────┘ └──────────────────┘
      producción         PRODUCCIÓN           tests
      (sincronizar)      (calcular)
```

### 2.4 La decisión que más importa: cuál va por defecto

```php
// AppServiceProvider
$this->app->singleton(IpcProvider::class, LocalSeriesIpcProvider::class);
```

**El binding apunta a la copia local, no al DANE.** Es contraintuitivo y es
deliberado.

> Si la aplicación calculara contra el origen en tiempo real, una carta de
> reajuste que se envía por correo fallaría porque el DANE esté caído, y una
> corrección del DANE podría cambiar el texto de una carta ya emitida.

El flujo queda de una sola vía:

```
  ipc:sync ──► DaneCdaIpcProvider ──► ipc_series ──► todo lo demás
                  (adaptador)          (tabla)        (LocalSeriesIpcProvider)
```

### 2.5 Qué traduce el adaptador

| Entrada cruda del DANE | Sale del adaptador | Por qué importa |
|---|---|---|
| `"193,28"` | `193.28` | `(float) "193,28"` da `193`, fuera del rango de un índice |
| `"Sep"` | `9` | El mes llega abreviado |
| `8001` | `"08001"` | Sin el cero el filtro devuelve 0 filas **sin error** |
| `" "` | `null` | Guardar 0 afirmaría "el IPC no cambió", que es otra cosa |
| Columna por posición | Emparejada por `colName` | Añadir una columna al final no rompe el parseo |

### 2.6 Reintentos y circuit breaker

```php
for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    // 502 y timeout → reintentar con espera creciente
    // HTML en vez de JSON → NO reintentar: el esquema cambió
    // 200 sin metadata/resultset → NO reintentar: el esquema cambió
}
```

La distinción importa porque son fallos distintos:

| Fallo | Significado | Qué se hace |
|---|---|---|
| 502, timeout | Caída de red | Reintentar hasta 4 veces |
| HTML en vez de JSON | La ruta del `.cda` cambió | Fallar ya, con mensaje |
| JSON con otra forma | El esquema cambió | Fallar ya, con mensaje |

Confundirlos sería malo: con la lista equivocada, una caída transitoria del DANE
parecería un cambio de esquema, y quien lo arreglara se pondría a depurar el SQL
cuando el problema era la red.

Cuando se agotan los reintentos se lanza `DaneIpcUnavailable`, y el
sincronizador **conserva la última serie buena**: un fallo de red deja la app
desactualizada, nunca sin IPC.

### 2.7 Por qué `Infrastructure` y no `Adapters`

El proyecto ya tenía un Adapter (`BusinessCalendar`) con esta convención:
contrato en `app/Contracts`, implementación en `app/Infrastructure`. Mantenerla
era más valioso que inventar una segunda forma de nombrar lo mismo.

### 2.8 El Adapter que ya existía y se reutilizó

El Adapter de días hábiles no se escribió para este módulo: ya estaba en el
proyecto, atado al vencimiento de las facturas. La tentación natural habría sido
escribir un "calendario de reajustes" propio, y esa es exactamente la tentación
que el patrón castiga: dos fuentes de festivos que algún día divergen, y un
reajuste que se corre un día distinto al que dice la factura.

Lo que se hizo fue inyectar el mismo contrato.

```
app/Services/Adjustment/AdjustmentDateProposer.php
    └─ recibe App\Contracts\BusinessCalendar
```

`BusinessCalendar` ya tenía justo los dos métodos que hacían falta:
`isBusinessDay()` y `nextBusinessDay()`. No hubo que ampliar la interfaz, ni
duplicarla, ni moverla.

**Lo que el servicio NO hace, y por qué importa**

La pregunta era si la vigencia del reajuste debe correrse al siguiente día hábil.
La respuesta que se implementó es: *el plazo no se mueve, la fecha propuesta sí*.

- El artículo 20 cuenta "doce (12) meses de ejecución bajo un mismo precio". El
  plazo lo cumple una fecha: el aniversario del último cambio de canon. Correrlo
  al lunes siguiente sería alterar el cómputo legal.
- Pero el mismo artículo, en su párrafo 2, obliga a comunicar "el monto del
  incremento y la fecha en que se hará efectivo, a través del servicio postal
  autorizado". El correo autorizado no entrega los domingos ni en festivos. Una
  vigencia en domingo es una fecha que ya no se puede cumplir.

Por eso el servicio devuelve dos fechas y las mantiene separadas:

| método | qué es |
|---|---|
| `suggestedDate()` | el día hábil siguiente — lo que se propone en pantalla |
| `wasMoved()` | si difieren, para saber si hay algo que explicar |
| `explanation()` | el texto que dice qué se movió y por qué (vacío si no se movió) |

`nextBusinessDay()` ya resuelve el salto de fines de semana largos y festivos
seguidos, porque es el mismo código que evita que una factura venza en domingo.

**La ventaja de usar el mismo contrato en las pruebas**

`WeekendBusinessCalendar` está enlazado en el entorno de pruebas. Un calendario
propio habría exigido pruebas de cada festivo, o peor, fechas que envejecen. Al
reusar el contrato, la prueba del lunes siguiente es una línea.

```
tests/Unit/AdjustmentDateProposerTest.php
    ✓ un dia habil se propone tal cual
    ✓ un sabado se propone el lunes siguiente
    ✓ un domingo se propone el lunes siguiente
    ✓ la explicacion solo aparece cuando la fecha se movio
```

---

## 3. Repository — la copia local

**Ubicación:** `app/Services/Ipc/IpcSeriesRepository.php`

### 3.1 Qué problema resuelve

Si el cálculo lee del modelo directamente, un test necesita sembrar la tabla y
una pantalla necesita conocer el esquema. El Repository hace que ninguna de las
dos cosas sea necesaria.

### 3.2 El método que resuelve un bug real

```php
public function availableYears(?string $geographyCode = null): array
{
    return IpcSeries::query()
        ->withDecember()                                   // ← el art. 20 usa diciembre
        ->when($geographyCode !== null, ...)
        ->where('year', '<=', now()->year - 1)            // ← solo años cerrados
        ->distinct()
        ->orderByDesc('year')
        ->pluck('year')
        ->all();
}
```

Dos filtros que no son cosméticos:

- **Solo diciembre.** El artículo 20 toma la variación del año calendario, que
  es la de diciembre. Un año con once meses guardados no sirve para reajustar.
- **Solo años cerrados.** El dato de diciembre del año en curso solo existe si
  ya se publicó, y usarlo sería aplicar el IPC de un año que no termina.

Y las opciones del selector llevan el porcentaje al lado:

```php
'yearOptions()'  //  2025 => "2025 — 5,10 %"
```

Porque leer "2024" en un selector no dice si el que se aplicará es el de 2023. Es
la confusión más común de esta pantalla.

---

## 4. Template Method — la sincronización

**Ubicación:** `app/Services/Ipc/IpcSynchronizer.php`

### 4.1 Los cinco pasos fijos

```
syncGeography($codigo, $desde, $hasta)
   │
   ├─ pull      pedir la serie al adaptador
   ├─ validate  ← el paso que evita el peor fallo posible
   ├─ map       convertir a filas
   ├─ persist   upsert en transacción
   └─ report    devolver el resumen
```

Que el flujo sea fijo y solo cambie la geografía evita que una sincronización se
quede a medio camino: o se guardan todos los periodos de una geografía, o ninguno.

### 4.2 El paso que más importa

```php
private function validate(array $observations, string $code): array
{
    // Comparar contra las cifras OFICIALES conocidas
    $expected = config('dane_ipc_sql.expected', []);   // 2023=>9.28, 2024=>5.20, 2025=>5.10

    foreach ($expected as $year => $expectedVariation) {
        $actual = $byPeriod[$year]->calendarYearVariation();

        if (abs($actual - $expectedVariation) > 0.5) {
            return ['ok' => false, 'message' => '...Compruebe el filtro CIP_NIVEL_ESTRUCTURA...'];
        }
    }
}
```

> Si el DANE cambia el esquema y devuelve otra serie **sin avisar**, guardar eso
> como si fuera el IPC dejaría una base de datos con cifras falsas y cartas de
> reajuste citando falsedades.

Este paso es la diferencia entre un módulo que se degrada con dignidad y uno que
falla en silencio.

---

## 5. Observer — la sincronización dispara el recálculo

**Ubicación:** `app/Events/IpcSeriesSynchronized.php` · `app/Listeners/`

### 5.1 El acoplamiento que evita

Sin Observer, el comando de sincronización tendría que saber que existe una
tabla de tasas anuales y una cadena de reajustes:

```php
// SIN Observer — el comando conoce toda la aplicación
IpcRate::rebuild();
AdjustmentChainRecalculator::recalculateAll();
```

Con Observer, el comando anuncia un hecho y quien quiera reaccionar, reacciona:

```php
// SyncIpcDane
event(new IpcSeriesSynchronized(
    geographies: array_column($successful, 'geography'),
    storedPeriods: array_sum(array_column($successful, 'stored')),
    nationalOnly: ! $this->option('cities'),
));
```

```php
// AppServiceProvider
Event::listen(IpcSeriesSynchronized::class, RebuildAnnualRates::class);
Event::listen(IpcSeriesSynchronized::class, RebuildAdjustmentChains::class);
```

### 5.2 Por qué dos listeners y en ese orden

1. **`RebuildAnnualRates`** reconstruye `ipc_rates` desde la serie. Ese paso va
   primero porque es una tabla *derivada*: si el DANE corrige un dato, tiene que
   quedar corregido también.
2. **`RebuildAdjustmentChains`** reprocesa las cadenas. Si no se hiciera, un
   alquiler con reajustes seguiría mostrando topes calculados con la cifra vieja
   mientras el selector ofrece la nueva: las dos pantallas darían números
   distintos para el mismo año.

### 5.3 Por qué es seguro dejarlo siempre conectado

El recálculo es **idempotente**: correrlo dos veces no escribe nada la segunda.
Eso permite programarlo mensualmente sin miedo a que mueva datos que ya estaban
bien, y por eso se dejó también como tarea semanal de red de seguridad.

---

## 6. Strategy — cómo se deriva el porcentaje

**Ubicación:** `app/Services/Adjustment/Strategies/`

### 6.1 El problema

Hay más de una forma matemáticamente válida de deducir el porcentaje de un
reajuste. La elección no es de gusto: depende de que **lo que dice el artículo 20
sea o no calculable con ese método**.

### 6.2 Las dos implementaciones

| | `AnnualVariationStrategy` | `IndexRatioStrategy` |
|---|---|---|
| Método | Variación anual de diciembre | Razón entre dos índices |
| ¿Lo pide el art. 20? | **Sí** | No |
| `isStatutory()` | `true` | `false` |
| Uso | Alimenta el tope | Solo informativo |

### 6.3 El artículo 20, literalmente

> *"el incremento que haya tenido el índice de precios al consumidor **en el año
> calendario inmediatamente anterior** a aquel en que deba efectuarse el reajuste"*

De ahí salen tres decisiones, y las tres importan:

1. El año **anterior** al de vigencia. Un reajuste de octubre de 2026 usa el IPC de 2025.
2. La variación **anual** (diciembre contra diciembre), no la acumulada a la fecha del reajuste.
3. La serie **nacional**. El artículo no menciona ciudades.

```php
$ipcYear = (int) $effectiveFrom->format('Y') - 1;
$observation = $this->series->statutoryForAdjustmentYear($code, (int) $effectiveFrom->format('Y'));
```

### 6.4 Por qué la alternativa está pero no manda

`IndexRatioStrategy` calcula `(índice_corte / índice_base − 1) × 100`. Es exacta
y muchos contratos la redactan así.

En diciembre **coincide** con la variación anual, porque la razón entre dos
índices de diciembre reproduce esa variación. En cualquier otro momento **no
coincide**, porque el artículo toma un año calendario completo y ese método toma
una razón entre dos puntos cualesquiera.

Por eso existe y está marcada como informativa: es útil para un arrendador que
pactara expresamente ese método, pero su resultado **no puede vestirse de "tope
legal"** en la carta.

### 6.5 `FrozenSnapshotStrategy`

Una tercera implementación, pequeña, que devuelve un snapshot ya resuelto. Existe
para un caso concreto: cuando el año del IPC viene elegido explícitamente en el
formulario, la serie ya se consultó y el resultado no se deduce de una fecha sino
de una fila. Envolverlo en una clase anónima se leería peor que nombrarla.

### 6.6 El resolver

```php
class IpcBasisStrategyResolver
{
    private readonly array $strategies = [
        'annual_variation' => AnnualVariationStrategy::class,
        'index_ratio'      => IndexRatioStrategy::class,
    ];
}
```

El Strategy necesita un punto único donde se decida cuál usar. Sin él, cada
pantalla que quiera ofrecer la alternativa por índices construye la
implementación, y aparece un `switch` duplicado en tres sitios.

Un nombre desconocido **cae** en la estrategia legal en vez de romper: la
alternativa por índices es una comodidad, y que no exista no puede impedir
reajustar.

---

## 7. Decorator — el pacto por debajo del IPC

**Ubicación:** `app/Services/Adjustment/Decorators/PactadoCapDecorator.php`

### 7.1 El "hasta" del artículo 20

El artículo autoriza *"hasta"* el 100 % del IPC. Ese "hasta" admite que las
partes acuerden menos, y en la práctica es lo más común: un arrendador que no
quiere perder inquilinos aplica la mitad.

### 7.2 Por qué es un Decorator y no un `if` dentro de la estrategia

> Si el mínimo viviera dentro de la estrategia, al pedir el IPC del artículo 20
> para mostrarlo en la carta se devolvería **el pactado**, que es justo el dato
> que la carta tiene que mostrar **aparte**.

El decorador envuelve a la estrategia y solo cambia lo que se aplica, nunca lo
que la ley dice. Las dos cifras viajan separadas:

```php
$legalSnapshot   = $decorator->legalSnapshot($fecha);  // 5,10 %  ← el artículo 20
$appliedSnapshot = $decorator->resolve($fecha);        // 3,00 %  ← lo pactado
```

Y en la base de datos:

```php
'legal_pct'   => 5.10,   // el tope
'applied_pct' => 3.00,   // lo que se cobró
'legal_cap_rent' => 1_051_000,   // tope sobre el IPC, NO sobre el pacto
```

### 7.3 El detalle que cuesta un bug

El decorador **solo puede bajar, nunca subir**. Por eso el mínimo se toma con el
legal y no con `max()`:

```php
$applied = min($this->pactadoPercentage, $legal->percentage);
```

Subir lo decide el arrendador en la pantalla, donde se avisa de que excede el
IPC. Si el decorador pudiera subir, un valor mal puesto en el formulario dejaría
de bumping el tope sin que nadie lo notara.

---

## 8. Chain of Responsibility — las reglas de la ley

**Ubicación:** `app/Services/Adjustment/Validation/`

### 8.1 Por qué cadena y no un método con varios `if`

Las reglas de los artículos 18 y 20 son independientes entre sí: cada una mira
una cosa distinta del mismo reajuste, y todas tienen que cumplirse. La cadena
modela eso mejor que un método gigante, y sobre todo hace que **cada regla quede
en su propia clase con su artículo en la documentación**.

### 8.2 Las ocho reglas

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. HasIpcData                        Art. 20  │ BLOQUEANTE          │
│    ¿Existe el dato de diciembre?      ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 2. IsResidentialScope                Art. 1   │ aviso               │
│    ¿Es vivienda urbana?               ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 3. WithinTwelveMonthsSamePrice        Art. 20  │ aviso               │
│    ¿Cumplió 12 meses bajo un precio?   ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 4. StatutoryCalendarYear              Art. 20  │ aviso               │
│    ¿El IPC es del año anterior?       ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 5. CityIpcRequiresAcknowledgement     Art. 20  │ aviso               │
│    ¿Usó el IPC de una ciudad?         ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 6. CommercialValueWithinCatastral...  Art. 18  │ aviso               │
│    ¿Valor comercial ≤ 2 × avalúo?     ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 7. WithinOnePercentOfCommercialValue  Art. 18  │ aviso               │
│    ¿Canon ≤ 1 % del valor comercial?  ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 8. WithinCapOrAcknowledged            Art. 20  │ aviso               │
│    ¿Excede lo que da el IPC?          ────────│                     │
├─────────────────────────────────────────────────────────────────────┤
│ 9. NotificationHasValidMeans          Art. 20  │ aviso               │
│    ¿El medio es el que la ley nombra?  ────────│                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 8.3 El orden importa

Las reglas estructurales van primero. Si no hay IPC o el alquiler no cumple los
doce meses, avisar del uno por ciento del artículo 18 es ruido que tapa el
problema de fondo. Las reglas de importe van al final, cuando ya se sabe que hay
un reajuste en pie.

### 8.4 Solo una regla bloquea

`HasIpcData`. Y no por una razón jurídica sino práctica: **sin el dato no hay
carta**. Emitir una carta que cita un IPC inexistente deja al arrendador sin el
sustento que el mismo documento dice respaldar.

### 8.5 El cambio de postura que hubo que decidir

La versión anterior del sistema trataba el IPC como un **tope duro**: un
incremento por encima exigía acuerdo escrito y `applyToRental()` se negaba a
aplicarlo.

La decisión fue **permitirlo y avisar**. El motivo:

> El texto del artículo 20 admite las dos lecturas. "Hasta una proporción que no
> sea superior al 100 %" suena a límite, pero el criterio que algunos tribunales
> han aplicado es que el IPC es un *parámetro* de referencia. La jurisprudencia
> **no pudo verificarse** al construir este módulo (la búsqueda web no estaba
> disponible en el entorno de desarrollo). Bajo la lectura del límite, bloquear
> sería el error.

Lo que sí se mantiene es la consecuencia práctica: **el párrafo 3 del mismo
artículo dice que pagar no sana la falta de aviso**, así que la comunicación
importa más que nunca.

### 8.6 `AdjustmentContext`

Las reglas se leen mucho mejor con `$context->rental` que con
`$context['rental']`, y el value object hace explícito en un solo lugar qué datos
puede mirar cada regla. Existe además porque las reglas corren **antes** de que
exista el registro, mientras el usuario elige, y vuelven a correr al guardar.

Que dos lugares compartan la cadena es correcto: el formulario la anticipa y el
servicio la impone, porque una pantalla nunca es la garantía de nada.

---

## 9. Specification — las preguntas sí/no

**Ubicación:** `app/Services/Adjustment/Specifications/`

### 9.1 El problema

"¿Le toca reajustarse a este alquiler?" se pregunta desde el listado, desde el
panel y desde el propio formulario. Con una especificación, las tres pantallas
comparten definición y no pueden discrepar.

### 9.2 Compuesta con AND

```php
public function isDueForReajuste(Rental $rental): bool
{
    return $this->hasBaseAmount($rental)        // se puede calcular
        && $this->hasIpcAvailable($rental)      // hay dato para el año
        && $this->notYetAdjusted($rental);      // no hay un rejeuste pendiente
}
```

Que "faltan datos" sea `false` es deliberado: no es una excepción, es la
respuesta más útil que el sistema puede dar, porque señala **exactamente qué hay
que hacer** antes de poder reajustar.

### 9.3 Composición declarativa

```php
$spec = (new IsDueForReajuste($series))
    ->and(new HasCommercialValue())
    ->and(new Not(new IsPension()));
```

En vez de un método con cinco condiciones encadenadas, donde añadir una obliga a
leer todas para entender una.

### 9.4 Un detalle que no es evidente

`notYetAdjusted` devuelve `true` si **hay un reajuste pendiente de aplicar**, no
solo si no hay reajustes. Un reajuste registrado y sin aplicar es un paso
pendiente, no una tarea nueva, y ofrecer "reajustar" sobre él sería duplicar lo
que ya está a medias.

---

## 10. Command — el reproceso de la cadena

**Ubicación:** `app/Services/Adjustment/AdjustmentChainRecalculator.php`

### 10.1 La separación con el Calculator

`AdjustmentChainCalculator` dice **qué tiene que pasar**. `AdjustmentChainRecalculator`
lo **escribe**. La separación permite probar el algoritmo puro sin base de datos,
y concentra aquí las decisiones que dependen del almacenamiento — como que una
transacción abarque toda la cadena para que un fallo a mitad no deje ajustes a
medias.

### 10.2 Solo escribe lo que cambió

```php
if ($this->differs($adjustment, $updates)) {
    $adjustment->forceFill($updates)->saveQuietly();
    $changedIds[] = $adjustment->id;
}
```

Como `previous_rent` pasó a ser derivado, puede quedar desfasado si cambió el
canon acordado o el IPC. El `excess_over_cap` también se recalcula, porque
depende del canon de entrada.

---

## 11. El motor de cálculo

Este no es un patrón, es el corazón del módulo, pero se documenta aquí porque de
él dependen las decisiones de los anteriores.

### 11.1 La regla del artículo 20 que decide el encadenado

> *"Cada doce (12) meses de ejecución del contrato **bajo un mismo precio**..."*

La palabra que importa es **"bajo un mismo precio"**: cada vez que el canon
cambia, el reloj se reinicia.

**Corolario:** el canon de entrada de cada ajuste es el canon que **rigió**, no el
canon original del contrato. El precio vigente durante esos doce meses es el del
reajuste anterior.

### 11.2 La distinción que suele malentenderse

Hay dos reglas distintas y tienen respuestas legales **opuestas**:

| Regla | Respuesta |
|---|---|
| **Entre años**: ¿el IPC del año 2 se aplica sobre el canon original o sobre el reajustado? | **Sobre el reajustado.** Encadenar desde el original equivaldría a renunciar al 5,20 % de ese año. |
| **Al editar**: si corrijo el IPC de un ajuste, ¿aplico el nuevo sobre el resultado viejo? | **No.** Se reprocesa desde el ancla. |

La segunda es la que el módulo resuelve, y para eso existe el ancla.

### 11.3 El algoritmo

```
anchor_rent = rentals.anchor_monthly_amount

replay(ajustes ordenados por effective_from, sequence):
    R = anchor_rent
    for a in ajustes:
        a.previous_rent  = R                                    ← derivado, nunca leído
        a.legal_cap_rent = round(R × (1 + a.legal_pct  / 100))
        R = a.new_rent                                          ← el canon ACORDADO
    rentals.monthly_amount = R      ← solo si todos los nodos están APLICADOS
```

### 11.4 Tres propiedades que este diseño garantiza

**1. Determinista.** Correrlo dos veces sobre los mismos insumos da el mismo
resultado. Por eso es seguro como post-proceso de la sincronización: no puede
mover lo que ya se comunicò.

**2. Editar reprograma.** Si cambia el IPC del primer año, el canon de entrada
del segundo también cambia, porque se deriva de `new_rent` y no de una copia
guardada. **Esto no se podía hacer antes.**

**3. Respeta el canon acordado.** El replay deriva `previous_rent` pero respeta
`new_rent`. Corregir el IPC recalcula lo que la ley determina, no lo que las
partes pactaron.

### 11.5 Un ejemplo con las cifras reales del DANE

Base **900.000**, con los tres IPC oficiales verificados:

| Ajuste | Vigencia | IPC aplicado | `previous_rent` | `legal_cap_rent` | Acumulado |
|---|---|---|---|---|---|
| a₁ | oct 2024 | 2023 = **9,28 %** | 900.000 | 983.520 | +9,28 % |
| a₂ | oct 2025 | 2024 = **5,20 %** | 983.520 | 1.034.663 | +14,96 % |
| a₃ | oct 2026 | 2025 = **5,10 %** | 1.034.663 | 1.087.431 | **+20,83 %** |

La columna "acumulado" es la que ve el inquilino comparando lo que pagó al
empezar con lo que paga hoy. **No es el 5,10 % del último año:** es la composición
de todos.

### 11.6 Por qué el ancla es una columna y no se deriva

```php
// El ancla es el canon que rigió ANTES del primer reajuste, no el canon actual.
```

Si se tomara `monthly_amount` como ancla, un alquiler con tres reajustes se
calcularía desde el tercero, y al reprocesar las cifras de los dos primeros
cambiarían. **Una carta de reajuste ya emitida quedaría contradicha por el
sistema que la emitió.**

Por eso la migración de datos toma el `previous_rent` del reajuste más antiguo:
es la cifra que efectivamente se le aplicó al inquilino.

---

## 12. Mapa completo

```
┌─ Filament ────────────────────────────────────────────────────────┐
│  RentAdjustmentResource   ·   IpcSeriesResource   ·  CreateRent..  │
└────────────────────────────┬──────────────────────────────────────┘
                             │
┌─ Aplicación ───────────────▼──────────────────────────────────────┐
│                                                                     │
│  RentAdjustmentService                                           │
│      │                                                              │
│      ├─► AdjustmentChainCalculator      ¿qué debe pasar?            │
│      ├─► AdjustmentChainRecalculator    lo escribe (Command)       │
│      ├─► ReajusteValidationChain        las 9 reglas (CoR)         │
│      │      └─ 9 × ReajusteRule → AdjustmentContext → RuleResult   │
│      ├─► IpcBasisStrategyResolver       (Strategy)                  │
│      │      └─ PactadoCapDecorator       (Decorator)                │
│      └─► AdjustmentChainCalculator::geographyFor()                  │
│                                                                     │
│  AdjustmentDateProposer  ──► BusinessCalendar   (Adapter reusado)  │
│  IsDueForReajuste                          (Specification)          │
│  IpcSeriesRepository                       (Repository)             │
└────────────────────────────┬───────────────────────────────────────┘
                             │
┌─ Puertos ──────────────────▼───────────────────────────────────────┐
│  IpcProvider  (interfaz)   ·   DaneCdaIpcProvider                   │
└────────────┬──────────────────────────────────┬────────────────────┘
             │                                  │
   ┌─────────▼──────────┐              ┌────────▼─────────┐
   │ SqlScalar          │              │ SqlScalar         │
   │ CdaQueryFactory    │              │ IpcSeriesRepository│
   │ (garantía de SQL)  │              │ LocalSeriesIpc..  │
   └─────────┬──────────┘              └────────┬─────────┘
             │                                  │
      ┌──────▼───────┐                  ┌───────▼────────┐
      │ CDA DANE     │                  │ ipc_series     │
      │ (HTTP 502)   │                  │ (copia local)  │
      └──────────────┘                  └────────────────┘
```

### Los comandos

| Comando | Qué hace |
|---|---|
| `ipc:sync` | Trae la serie del DANE. `--city=`, `--cities`, `--from=` |
| `ipc:sync:geographies` | Catálogo de ciudades con IPC (+ DIVIPOLA para departamentos) |
| `ipc:doctor` | **Verifica que la copia local sea el IPC oficial** |
| `rent-adjustments:recalculate` | Reproceso manual. `--dry-run`, `--rental=` |

`ipc:doctor` es el que detecta que el esquema cambió:

```
+------+----------+------------+--------+------+
| Ano  | Esperado | En la base | Estado |
+------+----------+------------+--------+------+
| 2023 | 9,28 %   | 9,28 %     | OK     |
| 2024 | 5,20 %   | 5,20 %     | OK     |
| 2025 | 5,10 %   | 5,10 %     | OK     |
+------+----------+------------+--------+------+
```

### La programación

```php
Schedule::command('ipc:sync')->cron('0 8 8-15 * *')->withoutOverlapping();
Schedule::command('ipc:sync:geographies')->monthlyOn(20, '08:30')->withoutOverlapping();
Schedule::command('rent-adjustments:recalculate')->weeklyOn(1, '07:00')->withoutOverlapping();
```

Días 8 a 15 porque la fecha de publicación varía y programar un solo día
fallaría la mitad de las veces. Repetir no cuesta nada: es un `upsert`, el
comando aborta si la serie no cuadra, y el recálculo es idempotente.

---

## 13. Por qué no sobreingeniería

Cinco decisiones que evitan el exceso:

| Se evitó | Por qué |
|---|---|
| Un servicio IPC "de todo en uno" | El Adapter, el Repository y el Synchronizer tienen razones de cambio distintas |
| La regla del artículo 18 dentro del artículo 20 | Son independientes y el artículo 18 solo aplica si hay valor comercial declarado |
| Guardar en base el estado del reajuste | Las fechas ya lo dicen; una columna sería una segunda fuente de verdad que puede contradecirlas |
| Calculos por índice como opción por defecto | Es correcto pero no es lo que dice la ley. Existe, marcada como informativa |
| Un pipeline de "steps" configurable | Los cinco pasos de la sincronización nunca van a cambiar; una clase con cinco métodos es más legible que una configuración |

Y cuatro que sí valieron la pena:

| Se añadió | Por qué |
|---|---|
| El ancla en `rentals` | Sin ella no hay recálculo, y el recálculo era el requisito |
| `SqlScalar` | Convierte "no concatenar entrada de usuario" de una recomendación en una garantía del sistema de tipos |
| `ipc:doctor` | El CDA no tiene versión; sin esto un cambio de esquema sería indetectable |
| Las 9 reglas separadas | Cada una mapea a un artículo numerado; añadir una es agregar una clase |
| `AdjustmentDateProposer` | Reusa el Adapter de días hábiles de las facturas en vez de crear un segundo calendario de festivos que algún día divergiría |

---

## 14. La interfaz también es diseño

Un formulario que deja tomar decisiones incoherentes entre sí no es un problema
de usability: es un defecto del dominio que se hizo visible. Los tres arreglos
que se hicieron afectan reglas, no solo apariencia.

### 14.1 El año del IPC dejó de ser una decisión

**El defecto.** `effective_from` traía `today()->addYear()` y `ipc_year` traía el
último IPC publicado. Con el calendario real de hoy eso producía la pareja
`vigencia 2027 / IPC 2025`, que es una combinación **inválida** según el artículo
20 (que toma el año calendario anterior). El formulario nacía marking al usuario
como Apartándose de la ley antes de que hubiera tocado nada, y el panel legal se
encendía con una advertencia que el usuario no podía resolver porque no sabía de
dónde salía.

**El arreglo.** La vigencia manda. Al elegir alquiler o al mover la fecha, el año
se deriva:

```php
$set('ipc_year', self::statutoryYearFor($date));
```

Si el año que tocaría aún no está cerrado (`availableYears()` solo ofrece
`year <= now()->year - 1`), se ofrece el último cerrado y el panel de
comprobaciones avisa del desvío. Es la única conducta defendible: inventar un IPC
de un año abierto sería fabricar un dato oficial.

**Las pruebas que lo sostienen:**

```
✓ cambiar_la_vigencia_recalcula_el_ano_del_ipc
✓ una_vigencia_del_ano_que_aun_no_se_cierra_cae_en_el_ultimo_ipc_disponible
```

### 14.2 Las secciones siguen el orden de decisión, no el del modelo

```
1. Qué alquiler y desde cuándo      ← las dos decisiones humanas
2. Con qué cifra                    ← lo que se deriva
3. Lo que decidan las partes        ← plegada, casi nadie lo usa
4. Lo que dice la ley sobre esto    ← plegada, oculta si no hay nada que decir
```

Tres cosas que se corrigieron de paso:

- El párrafo legal largo que abría el formulario salió de ahí. Su lugar es la
  sección 4, que dice lo mismo **solo cuando concierne a lo que se está
  haciendo**. Un texto que se lee 500 veces y nunca aplica es ruido.
- La sección 3 va plegada y se despliega sola cuando hay una desviación. Mostrar
  "Confirmo que me aparto del artículo 20" siempre era invitar a marcar algo que
  no estaba pasando.
- La sección 4 se oculta cuando `legalPreview()` devuelve cadena vacía. Antes
  mostraba el marcador "Comprobaciones: Alquiler" antes de que hubiera un alquiler
  elegido: informaba de que no había nada que comprobar.

### 14.3 El orden del filtro de ciudades

```
Todos              (valor vacío = sin filtro)
NACIONAL           (base que fija el artículo 20)
BARRANQUILLA
BOGOTÁ
…
```

Las opciones siguen saliendo de `geographies`, no de una lista escrita en el
código. Lo único que se decide aquí es el **orden**, y por dos razones que no son
estéticas: el nacional va primero porque es la respuesta correcta en la mayoría
de los casos, y las ciudades van en alfabético porque ahí sí se busca por nombre.

En el selector del formulario (cuando la base es *ciudad*) el nacional **no**
aparece: ofrecerlo dentro de una lista que ya dice "ciudad" sería un valor
contradictorio.

```
Geography::filterOptions()     →  ['' => 'Todos'] + ipcOptions()
Geography::ipcCities()         →  solo ciudades con IPC, sin el nacional
Geography::locationOptions()   →  catálogo COMPLETO, marcando qué tiene IPC
```

Los dos últimos no son lo mismo, y la diferencia es el requisito:

| Método | Dónde se usa | Qué ofrece |
|---|---|---|
| `ipcCities()` | base del IPC en el reajuste | solo ciudades con serie |
| `locationOptions()` | ciudad de la propiedad | todo el catálogo, con marca |

Si el selector de propiedad usara `ipcCities()`, el arrendador no podría registrar
una propiedad que existe: el DANE publica el IPC para 23 ciudades, pero el DIVIPOLA
tiene del orden de mil municipios. Filtrar ahí no evita el problema, lo esconde
hasta el momento de calcular un reajuste. Marcado, se decide con la información a
la vista.

### 14.4 La ciudad vive en la propiedad, no en el alquiler

Es una decisión de modelo, no de formulario. La ubicación es del inmueble: un
edificio puede tener varios contratos y todos son la misma ciudad. Guardarla en
el alquiler obligaría a repetirla en cada contrato y abriría la posibilidad de que
dos contratos del mismo edificio se contradigan.

```
properties.geography_code  →  app/Models/Property::geography()
                             app/Models/Property::geographyWithIpc()
```

`geographyWithIpc()` existe para poder distinguir dos ausencias que se confunden:
"esta propiedad no tiene ciudad" (el dato falta) de "esta ciudad no tiene serie de
IPC" (el dato existe pero no sirve para el cálculo). El reajuste propone la ciudad
de la propiedad, pero solo si `has_ipc === true`: proponer una ciudad sin serie
dejaría al usuario sin salida desde ese formulario.

**La ciudad predeterminada de Ajustes es `NULLABLE`, y no es un detalle**

Había dos salidas para "no hay ciudad configurada": asumir el total nacional, o no
proponer nada. La segunda es la correcta, porque el total nacional no es una
ciudad. Rellenarlo automáticamente inventaría la ubicación del inmueble, y esa
suposición terminaría alimentando el tope de IPC de todos sus reajustes. No hay
backfill desde la dirección en texto libre por el mismo motivo: adivinar la ciudad
leyendo una dirección es fabricar un dato.

### 14.5 El IPC que no cambiaba al elegir ciudad

`IpcSeriesRepository::yearOptions()` **ya aceptaba** un código de geografía como
primer argumento. El formulario lo llamaba sin argumento:

```php
->options(fn (): array => app(IpcSeriesRepository::class)->yearOptions())   // ← nunca nacional
```

El método servía para todo menos para lo que se le pedía. Elegir «Ciudad del
inmueble» no cambiaba el porcentaje que se veía, porque el número que se pintaba
no venía de la ciudad. La corrección fue pasar el argumento, y volver la opción
reactiva:

```php
->options(fn (Get $get): array => app(IpcSeriesRepository::class)
    ->yearOptions(self::geographyCodeFor($get)))
```

Un detalle que parece menor y no lo es: el **año por defecto** sigue
resolviéndose sobre el total nacional, incluso con base ciudad. No es una
inconsistencia. Es el último año cerrado que existe, y es la única cifra
defendible cuando la ciudad todavía no tiene ese año. Si en su lugar se tomara el
año de la ciudad, una ciudad con la serie incompleta devolvería un año distinto
(o ninguno) y el formulario nacería con una combinación que el artículo 20 no pide.

### 14.6 La preview: por qué el campo parecía arbitrario

El campo «Canon reajustado» pide un **resultado** y no mostraba de dónde sale. La
pregunta que realmente se le hace al verlo es *"¿de dónde sale este número?"*, y
su respuesta no cabe en una línea de ayuda.

```
Canon vigente      → el monto sobre el que se aplica el IPC
IPC aplicado       → el porcentaje, con su año y su geografía
Incremento         → la diferencia entre ambos
Tope art. 20       → canon vigente + incremento
Canon que se registrará
Exceso o diferencia
```

El rótulo **«Canon vigente»** y no «canon anterior» es deliberado: `currentRentFor()`
devuelve el `new_rent` del último reajuste aplicado, no el canon original del
contrato. Confundir las dos cosas es exactamente el error que hizo necesario todo
el trabajo del ancla y del reproceso, y el formulario no debe alimentar esa
confusión.

La diferencia con el tope solo aparece cuando hay algo que decir: con el tope
exacto no se muestra un «0», que es ruido.

### 14.7 Un segundo bug que la preview destapó

Al dibujarla apareció un fallo que llevaba tiempo latente:

```php
public function previewIpc(..., ?int $ipcYear = null): IpcSnapshot {
    return $this->resolveStrategy(..., ipcYear: null, ...);   // ← siempre null
}
```

El preview pasaba `ipcYear` en `null`, así que caía siempre en el año estatutorio
y **ignoraba el año que el usuario había seleccionado**. En la combinación legal
los dos coinciden por casualidad y el bug es invisible; se manifiesta justo
cuando no coinciden, que es el caso del apartamiento del artículo 20: ahí la
preview se quedaba vacía, y es precisamente donde más hacía falta — en la
combinación que exige explicación.

```
✓ la_preview_refleja_el_ano_elegido_y_no_el_que_fija_la_ley
```

### 14.8 Los emoji y el registro formal

No es una cuestión de gusto. Estos mensajes van a un documento que el
arrendatario puede leer, y hay tres razones concretas:

1. **Un emoji es un adorno** en un texto legal, y se ve como tal.
2. **En una impresión en blanco y negro se convierte en un cuadrado negro**,
   justo al lado de la palabra que explica el recuadro.
3. **No todas las fuentes tienen el glifo.** En un comprobante pegado en un correo
   o impreso por una impresora corporativa, el reemplazo es un rombo vacío.

Por eso el nivel es una **palabra** con color, no un símbolo:

```
Impeditivo   →  --danger-700 sobre --danger-50
Atención     →  --warning-800 sobre --warning-50
Referencia   →  --gray-700 sobre --gray-50
```

Los colores salen de las variables del panel (`--danger-600`, `--primary-600`, …)
y no de una paleta propia: si estos avisos tuvieran su propio color, cambiar el
tema dejaría a los avisos como los únicos que no cambian, que es exactamente como
se nota que algo quedó atrás.

### 14.9 Por qué los mensajes de las reglas están en `lang/`

Estaban escritos dentro de las clases, y eso tenía tres consecuencias que no se
arreglan moviendo el texto, solo cambiándolo:

| Problema | Causa |
|---|---|
| Sin versión en inglés | el texto estaba atado al idioma de quien lee el código |
| Sin tildes (`articulo`, `esta`, `podria`) | escritas a mano, el registro formal no se cuidó |
| `Ejecute "php artisan ipc:sync"` dentro del mensaje | detalle técnico filtrado a quien ve la pantalla |

El tercero es el más grave. Un comando de consola en un mensaje que puede leer un
arrendatario es simplemente incorrecto. Ese detalle se movió a `ipc:doctor`, que
es donde pertenece: la consola es la herramienta de quien carga el dato, y ahí el
comando sí es la instrucción correcta.

```
lang/es/adjustment_rules.php   →  9 reglas × sus variantes
lang/en/adjustment_rules.php   →  las mismas, en el otro idioma
```

---

## Referencias de código

| Concepto | Archivo |
|---|---|
| Puerto del IPC | `app/Contracts/Ipc/IpcProvider.php` |
| Adaptador del DANE | `app/Infrastructure/Ipc/DaneCdaIpcProvider.php` |
| Proveedor de producción | `app/Infrastructure/Ipc/LocalSeriesIpcProvider.php` |
| Garantía de SQL | `app/Services/Ipc/SqlScalar.php` |
| Plantillas del CDA | `config/dane_ipc_sql.php` |
| Sincronización | `app/Services/Ipc/IpcSynchronizer.php` |
| Copia local | `app/Services/Ipc/IpcSeriesRepository.php` |
| Motor de cálculo | `app/Services/Adjustment/AdjustmentChainCalculator.php` |
| Reproceso | `app/Services/Adjustment/AdjustmentChainRecalculator.php` |
| Estrategias | `app/Services/Adjustment/Strategies/` |
| Decorador del pacto | `app/Services/Adjustment/Decorators/PactadoCapDecorator.php` |
| Reglas de la ley | `app/Services/Adjustment/Validation/` |
| Servicio de application | `app/Services/RentAdjustmentService.php` |
| Propuesta de fecha de vigencia | `app/Services/Adjustment/AdjustmentDateProposer.php` |
| Ciudad de la propiedad | `app/Models/Property.php` |
| Catálogo de ciudades | `app/Models/Geography.php` |
| Mensajes de las reglas (es/en) | `lang/es/adjustment_rules.php`, `lang/en/adjustment_rules.php` |
| Adapter de días hábiles (reusado) | `app/Contracts/BusinessCalendar.php` → `app/Infrastructure/Holidays/` |
| Tests del adaptador | `tests/Feature/DaneCdaIpcProviderTest.php` |
| Tests de la cadena | `tests/Feature/AdjustmentChainTest.php` |