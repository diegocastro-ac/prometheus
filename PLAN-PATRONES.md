# Plan de implementación: Factory Method, Abstract Factory, Builder y Singleton

Este documento fija, antes de escribir una línea de código, **dónde y por qué** se implementarán los cuatro patrones de diseño exigidos en la entrega, en el proyecto Prometheus.

La regla que rigió todo el plan: ningún patrón se implementa para cumplir el requisito académico. Si un patrón no tiene un lugar honesto en el proyecto, se dice explícitamente y se propone una funcionalidad con valor real que lo justifique.

---

## 1. Decisiones ya tomadas

| Decisión | Estado |
| --- | --- |
| Los cuatro booleanos de la tabla `payments` se eliminan | Aprobado |
| La familia de formatos Excel se aplaza a una entrega posterior | Aprobado |
| Las familias de formatos son PDF y Texto | Aprobado |
| El comprobante de pago solo se puede descargar si la factura ya está pagada | Aprobado |
| La factura cambia de estado cuando se le registra un pago | Aprobado |
| Dos instancias únicas: `AppSettings` como singleton y `DeliveryActBuilder` como scoped | Aprobado |
| El acta lleva inventario agrupado por espacio, en dos tablas | Recomendación aceptada |

---

## 2. Funcionalidades nuevas que hacen necesarios los patrones

### 2.1 Ajustes de la aplicación (Singleton)

**Qué es.** Una pantalla donde el arrendador registra el nombre de su negocio, su NIT, su dirección, la moneda, el logo, los textos legales de la Ley 820, los días de vencimiento de una factura, la numeración y el catálogo de espacios del inmueble.

**Por qué Singleton.** Esa configuración se necesita cada vez que se construye cualquier documento. Si se pasara como parámetro, habría que reenviarla en todas partes. Una sola instancia compartida elimina el reenvío.

**Beneficio adicional.** En el código actual existen cuatro copias de un caché estático para el contexto de usuario, en `app/Filament/Resources/RentalResource.php`, `app/Filament/Resources/TenantResource.php`, `app/Filament/Resources/RentalResource/Pages/ManageRentalPayments.php` y `app/Filament/Resources/PropertyResource.php`. Son cuatro estados globales que son copia uno del otro, es decir, un Singleton mal hecho. Este paso los elimina y cambia el binding de `app/Providers/AppServiceProvider.php` de `bind` a `singleton`.

### 2.2 Acta de entrega y recepción del inmueble (Builder)

**Qué es.** Un documento que el arrendador genera al entregar el inmueble, con el inventario de lo que hay en cada espacio, el estado de cada elemento, las lecturas de los medidores, fotos, compromisos y las firmas de las dos partes y un testigo.

**Por qué Builder.** El acta tiene más de quince secciones y la mayoría son opcionales. En una entrega real pueden quedar casi todas vacías. Pasarlo todo como parámetros en cascada haría la llamada ilegible y cada sección nueva obligaría a modificar todos los puntos donde el documento se genera.

**Por qué el inventario agrupado por espacio.** El valor del acta como prueba está en que las discusiones ocurren por espacio: "el baño ya estaba dañado". Una lista plana de elementos pierde esa fuerza. Los espacios vienen del catálogo de `AppSettings`, no de una tabla propia, porque ese catálogo es fijo y el usuario no lo edita.

### 2.3 Factura de venta del arriendo y comprobante de pago (Abstract Factory)

**Qué es.** El arrendador emite una factura por concepto y período. La factura tiene estados. Cuando los pagos registrados cubren el monto, la factura pasa a estar pagada, y solo a partir de ahí el comprobante de pago se puede descargar.

**Por qué importa.** Hoy el inquilino que necesita un comprobante para su contabilidad no tiene cómo obtenerlo de la aplicación. Con este flujo, el comprobante queda habilitado exactamente cuando la factura está realmente cobrada, y nunca antes.

### 2.4 Los mismos documentos en PDF y en texto (Abstract Factory)

**Qué es.** Los cuatro documentos del flujo de cobro y de arrendamiento se pueden descargar como PDF o copiar como texto plano.

**Para qué sirve el texto.** Es el botón de copiar para enviar por WhatsApp de forma manual, sin servicios de pago y sin exponer datos del inquilino ante intermediarios.

---

## 3. Los cuatro patrones y dónde se aplican

### 3.1 Singleton

**Familia y variantes.** No aplica: Singleton no tiene familia de productos.

**Dónde.** `AppSettings` y `DeliveryActBuilder`.

**Clases y su rol.**

| Clase | Rol |
| --- | --- |
| `AppSettings` | Guarda y entrega la configuración. Inmutable. Se crea una sola vez en toda la aplicación. |
| `DeliveryActBuilder` | Arma el acta. Se comporta como instancia única dentro de cada petición. |
| `AjustesPage` | La pantalla de Filament. Solo lee y escribe en `AppSettings`. |
| `AppServiceProvider` | Registra las instancias y garantiza que todos obtengan la misma. |

**Consideración importante.** Un Builder acumula estado mientras construye, así que registrarlo como singleton permanente puede filtrar información de una petición a la siguiente si el proyecto llegara a usar Octane. Por eso `DeliveryActBuilder` se registra con `scoped`, que en Laravel significa una instancia por petición y reinicio automático. `AppSettings` sí puede ser singleton real porque nunca cambia.

### 3.2 Builder

**Familia y variantes.** No aplica: Builder no tiene familia de productos.

**Dónde.** La construcción del acta de entrega.

**Clases y su rol.**

| Clase | Rol |
| --- | --- |
| `DeliveryActBuilder` | Arma el acta paso a paso. Es el único que sabe qué secciones existen, en qué orden van y cuáles son opcionales. |
| `AppSettings` | Le entrega los datos comunes: nombre del negocio, NIT, dirección, moneda, textos legales, catálogo de espacios. |
| `DeliveryAct` | El acta ya armada. Es el resultado final y no se modifica. |
| `ActItem` | Cada elemento inventariado, con su espacio, su estado y su foto. |

**Flujo del usuario.** El arrendador entra al alquiler, pulsa Crear acta, elige el tipo, registra las lecturas de los medidores que le apliquen, va sumando el inventario por espacio marcando el estado de cada elemento, sube fotos, escribe compromisos y observaciones, y descarga el acta para firmarla. El acta queda archivada y ya no se modifica.

**Qué gana.** Agregar una sección nueva, por ejemplo zonas comunes, es agregar un método al Builder. Ningún otro punto de la aplicación cambia.

### 3.3 Abstract Factory

**La familia.** La forma de entregar un documento.

**Las variantes.** PDF y Texto. Cada una es una familia completa que produce los cuatro documentos.

**Las dos familias y sus productos.**

| | Familia PDF | Familia Texto |
| --- | --- | --- |
| Comprobante de pago | PDF | texto plano |
| Carta de reajuste del IPC | PDF | texto plano |
| Estado de cuenta mensual | PDF | texto plano |
| Factura de venta del arriendo | PDF | texto plano |

**Por qué es Abstract Factory y no un simple switch.** Cada familia produce un juego completo de productos con tres piezas que van juntas: la plantilla, el generador y la acción de entrega. Una factura construida por la familia PDF nunca puede entregarse con el botón de copiar, porque ese botón pertenece a la familia de texto. El patrón existe justamente para impedir esa mezcla, y en este caso la mezcla sería un error real y visible para el usuario.

**Clases y su rol.**

| Clase | Rol |
| --- | --- |
| `DocumentFactory` | La interfaz común. Declara que se puede crear un comprobante, una carta, un estado de cuenta y una factura, sin decir en qué formato. |
| `PdfDocumentFactory` | Produce la familia PDF: los cuatro documentos con su plantilla y su botón de descarga. |
| `TextDocumentFactory` | Produce la familia de texto: los cuatro documentos como texto plano y su botón de copiar. |
| `Document` | El documento ya armado, listo para mostrarse. No sabe de qué fábrica salió. |
| `PaymentReceiptDocument` | El comprobante de pago. Solo se crea sobre una factura pagada. |
| `AdjustmentLetterDocument` | La carta de reajuste del IPC. |
| `MonthlyStatementDocument` | El estado de cuenta mensual. |
| `RentInvoiceDocument` | La factura de venta del arriendo. |

**Qué gana.** Agregar un quinto documento es crear la clase del producto y el método en las dos fábricas: queda disponible en ambos formatos sin código repetido. Y es imposible que un documento en PDF aparezca con el botón de copiar.

### 3.4 Factory Method

**Familia y variantes.** No aplica: Factory Method no tiene familia de productos.

**Dónde.** La generación del plan de cobros según la cadencia elegida.

**Clases y su rol.**

| Clase | Rol |
| --- | --- |
| `PaymentPlanGenerator` | La clase abstracta. Declara el paso de crear el plan, sin decir cada cuánto se cobra. |
| `MonthlyPlanGenerator` | Solo sabe de cadencia mensual. |
| `QuincenalPlanGenerator` | Solo sabe de cadencia quincenal. |
| `AdvancePlanGenerator` | Solo sabe de anticipo y saldos. |
| `PaymentPlanService` | Pide el generador que corresponde, lo ejecuta y avisa. No conoce ninguna cadencia. |

**Flujo del usuario.** Al crear un alquiler, el arrendador elige cada cuánto cobra. La aplicación elige el generador correspondiente, ese generador crea las facturas con las fechas correctas y la aplicación avisa que el plan quedó generado.

**Qué gana.** Hoy la cadencia mensual está escrita dentro de `app/Services/PaymentPlanService.php` y no hay forma de cambiarla. Quien use el servicio no necesita saber de cadencias, y agregar una nueva es agregar una clase.

---

## 4. Orden de ejecución

```
Paso 0. Corrección de dos defectos previos
Paso 1. Singletons y pantalla de Ajustes
Paso 2. Builder: acta de entrega
Paso 3. Facturas, estados y eliminación de los booleanos
Paso 4. Abstract Factory: los cuatro documentos en PDF y Texto
Paso 5. Factory Method: las tres cadencias de cobro
```

Los pasos 0 a 2 no dependen de las facturas. El paso 3 es el que las introduce y tiene que ir antes del 4. El paso 5 es independiente y puede entregarse aparte.

Cada paso es un commit independiente y reversible.

---

## 5. Detalle de cada paso

### Paso 0. Corrección de dos defectos previos

Ninguno de los patrones debe heredar estos dos defectos.

**Defecto 1: precedencia del estado de pago.** El método `status()` de `app/Models/Payment.php` devuelve parcial antes de evaluar la fecha de vencimiento, así que un pago parcial y ya vencido nunca aparece como vencido.

**Defecto 2: filtro divergente entre los widgets del panel.** `app/Filament/Widgets/IncomeChart.php` no filtra los alquileres activos, mientras que `app/Filament/Widgets/StatsOverview.php`, `app/Filament/Widgets/PaymentsStatusChart.php` y `app/Filament/Widgets/OverduePaymentsTable.php` sí lo hacen. El resultado es que el gráfico de ingresos incluye alquileres terminados y no cuadra con el indicador de morosidad.

### Paso 1. Singletons y pantalla de Ajustes

- Crear `AppSettings` con los datos de configuración y registrarlo con `singleton`.
- Registrar `DeliveryActBuilder` con `scoped`.
- Cambiar el binding de `CurrentUserContextInterface` de `bind` a `singleton` en `AppServiceProvider`.
- Eliminar los cuatro cachés estáticos duplicados y pasar el contexto de usuario por inyección.
- Crear la pantalla de Ajustes en Filament con los textos en español e inglés.

### Paso 2. Builder: acta de entrega

- Migraciones de `delivery_acts` y `act_items`.
- `ActItemState` como enumeración de los estados posibles de un elemento.
- `DeliveryActBuilder` con un método por sección, todas opcionales salvo las obligatorias.
- Pantalla de Filament para capturar el inventario agrupado por espacio.
- Descarga del acta en PDF, usando el motor que ya exista en el proyecto.

### Paso 3. Facturas, estados y eliminación de los booleanos

- Migración de `invoices` con número consecutivo por usuario y año, alquiler, concepto, período, monto, fecha de vencimiento y estado.
- `InvoiceStatus` como enumeración con los cuatro estados: emitida, pagada, vencida y anulada.
- La tabla `payments` gana la referencia a la factura y pierde los cuatro booleanos de servicios pagados.
- La factura calcula su propio estado a partir de la suma de los pagos asociados.
- El estado del pago se deriva de la factura: pagada si los pagos cubren el monto, parcial si hay pagos insuficientes, vencida si pasó el vencimiento sin cubrir el monto. Esto elimina el defecto del paso 0 en su raíz.
- `PaymentPlanService` pasa a generar facturas por período en vez de pagos sueltos.
- La pantalla de pagos de un alquiler reemplaza los cuatro interruptores por la selección de la factura a la que se aplica el pago.
- Se actualizan las consultas que filtraban por los booleanos eliminados en los tres widgets y en el gráfico.

**Decisiones tomadas al implementar este paso.**

- *Opción A.* La factura es la fuente de verdad y el pago se administra desde ella. La pantalla de pagos por alquiler se elimina en vez de migrarse: `InvoiceResource` queda con la acción de registrar abono.
- *El período es un campo aparte.* Se guarda como texto `AAAA-MM` y no se deriva de `issued_at`, porque una factura de marzo puede emitirse en febrero. Es el mes que se cobra, no el mes del documento.
- *El consecutivo se reinicia cada año.* `invoice_sequences` es único por `(user_id, year)`, así que en enero vuelve a `0001`. El número no arrastra el del año anterior.
- *El concepto se guarda como clave.* En la tabla queda `rent`, `services`, `rent_services` o `adjustment`; la etiqueta traducida la pone la vista. Guardar el texto haría que un filtro por concepto no encontrara nada.
- *La pérdida de datos se acepta en desarrollo.*

**Advertencia sobre los datos existentes.** Al quitar los booleanos se descarta información que hoy no es recuperable. El monto del canon sí está en el campo de monto del pago, así que de cada pago existente se puede crear la factura del canon con el estado que correspondía. Pero los booleanos de agua, energía y gas nunca tuvieron monto asociado, así que esa información se pierde. Es aceptable en desarrollo. Si llegara a haber datos reales, la migración tendría que hacerse a mano antes de aplicar el cambio.

### Paso 4. Abstract Factory

- `DocumentFactory` con un método por tipo de documento.
- `PdfDocumentFactory` y `TextDocumentFactory`.
- Los cuatro productos concretos.
- El comprobante de pago solo se ofrece cuando la factura está pagada.
- Migración con el IPC por año, que la carta de reajuste necesita para citar la fuente oficial.
- La carta de reajuste incluye el tope legal y avisa cuando el incremento lo supera, porque en ese caso solo opera si hay acuerdo escrito.

### Paso 5. Factory Method

- `PaymentPlanGenerator` abstracto.
- `MonthlyPlanGenerator`, `QuincenalPlanGenerator` y `AdvancePlanGenerator`.
- Migración con el tipo de plan, con valor por defecto igual al plan actual para no romper los alquileres existentes.
- Pruebas del cálculo de fechas, especialmente en meses con diferente número de días.

---

## 6. Riesgos

1. **El paso 3 es el más grande.** Cambia el modelo de datos y toca modelos, servicios, widgets y una pantalla de Filament. Es el único capaz de romper funcionalidad existente. Por eso conviene hacerlo con pruebas escritas antes.
2. **Los montos siguen siendo número decimal.** No se resolvió en este plan y afecta la suma de los estados de cuenta. La recomendación sigue siendo pasar a centavos enteros, pero es un cambio aparte que puede aplazarse.
3. **No hay pruebas.** El repositorio no tiene cobertura real: la única prueba existente espera una respuesta exitosa en la raíz, pero esa ruta redirige al panel. Introducir cuatro patrones y una migración que elimina columnas sin pruebas es el riesgo mayor del plan. Recomiendo agregar pruebas del cálculo del estado de la factura y del generador de cadencia antes de empezar el paso 3.
4. **La factura no es una factura electrónica autorizada por la DIAN.** Es un respaldo en PDF. Para la factura electrónica existe software gratuito de la DIAN, pero exige firma digital y autorización, que es un proyecto aparte. El documento y la interfaz deben decirlo para que el arrendador no lo use como comprobante tributario.

---

## 7. Fuera de alcance

- Formatos Excel y CSV.
- Facturación electrónica autorizada por la DIAN.
- Envío automático por WhatsApp o cualquier pasarela de pago.
- Validación de identidad en plataformas externas.
- Geocodificación de direcciones.
- Cualquier estado de pago que el modelo actual no pueda expresar.