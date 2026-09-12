# PROMETHEUS — Modelo robusto + los 23 patrones GoF como necesidades reales del negocio (v3)

## 0. Qué cambió respecto a la v2

En la v2 varios patrones estaban correctamente aplicados pero **cada uno vivía en su propia funcionalidad aislada**, y eso es justo lo que se siente "agregado porque sí". La observación clave para arreglarlo: **un negocio real no tiene 23 necesidades, tiene 5 o 6 — y cada una necesita varios patrones trabajando juntos**. Reorganicé todo alrededor de eso.

También noté dos cosas al revisar el ERD que me diste:
- `payments` ya tiene `is_rent_paid/is_water_paid/is_energy_paid/is_gas_paid`, pero **no existe ningún mecanismo que genere el cobro esperado cada mes** — hoy el arrendador tendría que acordarse solo de crear cada `payment`. Ese hueco es el que ata Command, State, Strategy, Decorator y Observer en un solo flujo real (el diagrama de arriba).
- Un arrendador informal en Colombia tiene una necesidad muy concreta que el modelo original no cubría: **declarar renta**. Eso le da un propósito real al patrón Iterator (que si no, es puramente técnico y no se siente como una funcionalidad).

---

## 1. Las 6 necesidades reales y qué patrones cubre cada una

| Necesidad del arrendador | Patrones que la resuelven |
|---|---|
| **A. "Tengo propiedades repetidas, no quiero volver a describirlas cada vez"** | Flyweight, Composite, Prototype, Memento |
| **B. "Necesito que el cobro mensual no dependa de que yo me acuerde"** | Command, State, Strategy, Decorator, Iterator |
| **C. "Avísame cuando algo necesita mi atención"** | Factory Method, Bridge, Observer |
| **D. "Necesito sacar esta información para mostrarla o declarar renta"** | Abstract Factory, Template Method, Proxy |
| **E. "Con muchas propiedades, necesito encontrar y confiar en lo que veo"** | Interpreter, Visitor, Chain of Responsibility |
| **F. Columna vertebral técnica (no la pide el arrendador, pero todo lo anterior la necesita)** | Singleton, Mediator, Facade, Adapter, Builder |

Cada bloque de abajo explica la necesidad primero, y **después** qué patrón resuelve qué parte — así el patrón nunca aparece antes que el problema.

---

## 2. El modelo de datos completo (DBML — pégalo directo en dbdiagram.io)

```dbml
Table users {
  user_id int [pk, increment]
  name varchar
  email varchar [unique]
  email_verified_at datetime
  password varchar
  document_type varchar
  default_late_fee_strategy varchar // fixed_percentage | daily_interest | fixed_amount | none
  default_late_fee_value decimal
  created_at datetime
  updated_at datetime

  Note: 'Arrendador. Toda tabla del sistema cuelga de user_id (multi-tenancy).'
}

Table tenants {
  tenant_id int [pk, increment]
  document varchar
  name varchar
  phone_number varchar
  email varchar
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'Inquilino. No confundir con "tenant" de multi-tenancy: aquí es la persona que arrienda.'
}

Table developments {
  development_id int [pk, increment]
  name varchar
  type varchar // conjunto_cerrado | conjunto_abierto | edificio | urbanizacion
  address varchar
  city varchar
  lat decimal
  lng decimal
  has_common_areas boolean
  admin_fee decimal
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'Opcional. Agrupa varias properties (Composite).'
}

Table property_models {
  property_model_id int [pk, increment]
  name varchar
  description text
  bedrooms int
  bathrooms int
  area_m2 decimal
  parking_spots int
  suggested_monthly_amount decimal
  development_id int [ref: > developments.development_id]
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'Opcional. Estado intrínseco compartido entre unidades iguales (Flyweight). development_id nullable.'
}

Table property_model_images {
  image_id int [pk, increment]
  property_model_id int [ref: > property_models.property_model_id]
  path varchar
  display_order int
  is_cover boolean
}

Table properties {
  property_id int [pk, increment]
  unit_identifier varchar
  name varchar
  description text
  address varchar
  city varchar
  lat decimal
  lng decimal
  status varchar // disponible | arrendada | mantenimiento | inactiva
  property_model_id int [ref: > property_models.property_model_id]
  development_id int [ref: > developments.development_id]
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'property_model_id y development_id son nullable: una propiedad puede ser 100% autónoma, como hoy.'
}

Table property_images {
  image_id int [pk, increment]
  property_id int [ref: > properties.property_id]
  path varchar
  display_order int
}

Table rentals {
  rental_id int [pk, increment]
  name varchar
  description text
  start_date date
  end_date date
  total_months int
  total_persons int
  monthly_amount decimal
  due_day int // día del mes en que vence el pago
  agreement_path varchar
  status varchar // activo | atrasado | finalizado | cancelado
  tenant_id int [ref: > tenants.tenant_id]
  property_id int [ref: > properties.property_id]
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'status reemplaza is_active con una máquina de estados real (State).'
}

Table payment_schedules {
  schedule_id int [pk, increment]
  rental_id int [ref: > rentals.rental_id]
  period_month date // primer día del mes que cubre
  due_date date
  expected_amount decimal
  late_fee_amount decimal
  status varchar // pendiente | pagado | atrasado
  payment_id int [ref: > payments.payment_id]
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'El "cobro esperado" del mes. Lo genera un Command automático; payment_id se llena cuando se paga.'
}

Table payments {
  payment_id int [pk, increment]
  date date
  base_amount decimal
  late_fee_amount decimal
  amount decimal // total: base + servicios + mora
  is_rent_paid boolean
  is_water_paid boolean
  is_energy_paid boolean
  is_gas_paid boolean
  rental_id int [ref: > rentals.rental_id]
  user_id int [ref: > users.user_id]
  created_at datetime
  updated_at datetime

  Note: 'amount se compone por capas (Decorator): base_amount + servicios marcados + late_fee_amount.'
}

Table notifications {
  notification_id int [pk, increment]
  type varchar // pago_atrasado | contrato_por_vencer | bienvenida_inquilino
  channel varchar // email | whatsapp | in_app
  status varchar // enviado | fallido | pendiente
  message text
  rental_id int [ref: > rentals.rental_id]
  tenant_id int [ref: > tenants.tenant_id]
  user_id int [ref: > users.user_id]
  sent_at datetime
  created_at datetime

  Note: 'rental_id y tenant_id nullable según el tipo de notificación. El canal de envío se determina por alert_preferences (Bridge pattern).'
}

Table alert_preferences {
  preference_id int [pk, increment]
  user_id int [ref: > users.user_id]
  alert_type varchar // pago_atrasado | contrato_por_vencer | bienvenida_inquilino
  channel varchar // email | whatsapp | sms | in_app
  created_at datetime
  updated_at datetime

  Note: 'Preferencias del arrendador para Bridge: qué canal para cada tipo de alerta.'
}

Table saved_filters {
  filter_id int [pk, increment]
  name varchar
  entity_type varchar // properties | rentals | payments
  definition text // lenguaje natural del arrendador
  user_id int [ref: > users.user_id]
  created_at datetime

  Note: 'Vista guardada. El usuario escribe en lenguaje natural del negocio; definition es el texto que interpreta el PropiedadQueryInterpreter (Interpreter pattern).'
}

Table entity_snapshots {
  snapshot_id int [pk, increment]
  entity_type varchar // property | property_model
  entity_id int
  snapshot json
  user_id int [ref: > users.user_id]
  created_at datetime

  Note: 'Historial de versiones (Memento) para properties y property_models.'
}

Table audit_logs {
  audit_id int [pk, increment]
  entity_type varchar
  entity_id int
  action varchar // create | update | delete
  changes json
  user_id int [ref: > users.user_id]
  created_at datetime

  Note: 'Registra cambios generados por DeclaracionRentaVisitor al recorrer entidades para reporte fiscal (Visitor pattern).'
}
```

**Diseño deliberado:** las tablas nuevas de infraestructura (`payment_schedules`, `notifications`, `alert_preferences`, `saved_filters`, `entity_snapshots`, `audit_logs`) son **aditivas** — nada de lo que ya tenías en `users`, `tenants`, `properties`, `rentals`, `payments` se rompe. `rentals.is_active` se reemplaza por `status` porque ya no alcanza (ver sección B), y `properties` gana columnas nullable, así que un registro creado hoy sigue siendo válido mañana.

---

## 3. Detalle por necesidad

### A. "Tengo propiedades repetidas, no quiero volver a describirlas cada vez"

Esta es la necesidad que tú mismo señalaste. Un arrendador con un edificio de 20 apartamentos casi idénticos hoy llena el mismo formulario 20 veces.

- **Flyweight — `property_models` como estado compartido.** `PropertyModel` guarda lo que es igual entre unidades (`bedrooms`, `area_m2`, fotos). `Property` solo guarda lo que es propio de esa unidad (`unit_identifier`, `status`, `address`). `Property` no copia los datos del modelo, los **referencia** por `property_model_id` — si corriges el área del modelo, las 20 unidades se actualizan solas.
- **Composite — `developments` agrega `properties`.** `ResumenIngreso::calcularIngresoMensual()` funciona igual si se lo pides a una sola `Property` o a un `Development` completo (que internamente suma cada una de sus propiedades). Responde directamente "¿cuánto me está dejando el Conjunto Villa del Río?".
- **Prototype — duplicar un `property_model`.** Cuando el arrendador tiene "Modelo B" y quiere "Modelo B con balcón", clona el modelo existente en vez de llenarlo de cero.
- **Memento — versionar ediciones de `property` y `property_model`.** Justo porque ahora un modelo es compartido, un error al editarlo afecta a varias unidades a la vez — por eso el historial de versiones (`entity_snapshots`) importa más aquí que en un CRUD plano.

**Por qué ya no se siente forzado:** los cuatro patrones resuelven la misma pregunta ("¿cómo evito repetir trabajo sin perder el control de cada unidad?"), no cuatro problemas inventados por separado.

---

### B. "Necesito que el cobro mensual no dependa de que yo me acuerde"

Este es el hueco más grande que encontré en el modelo original: no existía nada que generara el cobro del mes. Es la necesidad más "dolorosa" de un arrendador informal (por eso el documento menciona que hoy manejan todo en Excel) y es la que amarra más patrones de forma orgánica — el diagrama que armé arriba es exactamente este flujo.

- **Command — generación automática del cobro mensual.** Un job programado (cron de Laravel) recorre los `rentals` con `status = activo` y, para cada uno, ejecuta un `GenerarCobroCommand` que crea la fila en `payment_schedules` con el `expected_amount` correspondiente. Al ser un objeto Command (no una función suelta), se puede loguear, reintentar si falla, o revertir si se generó por error — no es solo "ejecutar una acción", es tratar esa acción como un objeto con ciclo de vida propio.
- **State — el `schedule` y el `rental` tienen estados reales.** `payment_schedules.status` pasa de `pendiente` a `atrasado` si se cruza el `due_date` sin pago, y a `pagado` cuando se linkea un `payment`. Si varios `schedules` de un `rental` quedan atrasados, el `rental.status` mismo transiciona a `atrasado`. Las reglas de qué transición es válida (no se puede pasar de `atrasado` a `finalizado` sin saldar) viven en las clases de estado, no dispersas en `if`s.
- **Strategy — cálculo del recargo por mora.** Cuando un `schedule` pasa a `atrasado`, se calcula `late_fee_amount` según la estrategia que el arrendador eligió en su perfil (`users.default_late_fee_strategy`): porcentaje fijo, interés diario, o monto fijo. Cada arrendador informal en Colombia maneja esto distinto — algunos ni cobran mora — por eso tiene que ser intercambiable y no una fórmula fija en el código.
- **Decorator — composición dinámica del monto de pago.** El cálculo del monto total se arma por capas usando wrappers: `CalculadorBase` (monto base del arriendo) es decorado por `DecoratorServicios` (agrega agua/luz/gas si están marcados) y luego por `DecoratorMora` (agrega recargo por atraso). Cada decorador recibe el cálculo anterior y añade su capa. En la base de datos se guarda el desglose (`base_amount`, `late_fee_amount`, flags de servicios), pero la lógica de cálculo sigue el patrón Decorator con composición dinámica de objetos.
- **Iterator — reporte anual para declaración de renta.** Un arrendador informal en Colombia necesita, una vez al año, todos sus `payments` agrupados por `rental`/`tenant` para declarar renta. Recorrer años de historial cargando todo en memoria no escala; un `PaymentIterator` sobre un cursor (`chunk()` de Eloquent) alimenta tanto el export a CSV/Excel como cualquier vista paginada, sin que ninguno de los dos sepa cómo se recorre la data.

**Por qué ya no se siente forzado:** los cinco patrones son pasos consecutivos de **un solo proceso de negocio real** (genera → vence → calcula recargo → se paga → se reporta), no cinco funcionalidades independientes que casualmente usan patrones distintos.

---

### C. "Avísame cuando algo necesita mi atención"

- **Observer — detección de eventos.** Cuando un `payment_schedule` pasa a `atrasado`, o un `rental.end_date` se acerca, se dispara un evento (`CobroAtrasado`, `AlquilerPorVencer`) sin que el código que detecta la condición sepa quién va a reaccionar.
- **Factory Method — contenido del aviso.** Cada tipo de evento necesita un mensaje distinto: `NotificacionFactory::crear('pago_atrasado')` arma el texto y los datos relevantes (cuánto debe, desde cuándo) sin que el resto del sistema conozca esa lógica de redacción.
- **Bridge — configuración de preferencias de alertas.** Los arrendadores tienen preferencias reales: "quiero pagos atrasados por WhatsApp, contratos por vencer por email". Bridge separa la abstracción (`TipoAlerta`: qué se notifica) de la implementación (`CanalComunicacion`: cómo se envía). El arrendador configura en `alert_preferences` qué canal para cada tipo de alerta. Cuando ocurre un evento, el sistema consulta la preferencia y usa el canal correspondiente. Agregar un canal nuevo (ej. SMS) no requiere modificar las alertas existentes, ni agregar alertas nuevas requiere modificar los canales.

**Por qué ya no se siente forzado:** es *una* funcionalidad ("Notificaciones") con tres responsabilidades separables que de otra forma terminarían mezcladas en una sola clase gigante — que es exactamente el problema que estos tres patrones existen para resolver.

---

### D. "Necesito sacar esta información para mostrarla o declarar renta"

- **Template Method — el esqueleto de cada métrica del dashboard.** Cada métrica (saldo cobrado vs. estimado, alquileres activos, pagos atrasados) sigue el mismo esqueleto: `obtenerDatos()` → `filtrarPorArrendador()` → `formatear()`. El esqueleto vive en una sola clase abstracta; cada métrica solo implementa sus tres pasos.
- **Abstract Factory — familias de reportes financieros.** El arrendador necesita diferentes tipos de reportes financieros: reporte de ingresos, reporte de rentabilidad, reporte de ocupación. Cada tipo de reporte requiere componentes coherentes (datos, gráfica, tabla) que funcionen juntos. `ReporteIngresosFactory` crea `DatosIngresos` + `GraficaIngresos` + `TablaIngresos`; `ReporteRentabilidadFactory` crea componentes coherentes para rentabilidad. Abstract Factory garantiza que los componentes de cada reporte sean compatibles entre sí.
- **Proxy — cache de los cálculos pesados.** El reporte anual (necesidad B) y las métricas del dashboard recorren potencialmente años de `payments`. Un `DashboardMetricasProxy` cachea el resultado unos minutos antes de dejar pasar la consulta real.

**Por qué ya no se siente forzado:** Template Method y Abstract Factory ya no son "el dashboard" y "exportar" como dos cosas separadas — son la misma pieza (cómo se calcula + en qué formato sale), y Proxy existe porque ambas cosas comparten el mismo cuello de botella de rendimiento.

---

### E. "Con muchas propiedades, necesito encontrar y confiar en lo que veo"

- **Interpreter — lenguaje de consulta del arrendador.** El arrendador escribe búsquedas en lenguaje natural simple: "propiedades en Bogotá con precio menor a 2000000". Un `PropiedadQueryInterpreter` interpreta ese lenguaje natural del negocio para construir la consulta Eloquent dinámicamente. El intérprete reconoce palabras clave del dominio ("en", "con precio menor a", "disponibles") y las traduce a condiciones de base de datos. Es un intérprete simplificado pero real: interpreta lenguaje estructurado para producir comportamiento, sin la complejidad de un DSL formal.
- **Chain of Responsibility — validaciones al crear un `rental`.** Las reglas de negocio que el documento original ya definía (una propiedad y un inquilino no pueden tener más de un alquiler activo) se organizan como una cadena: `PropiedadDisponibleHandler → InquilinoDisponibleHandler → FechasValidasHandler`. Con el nuevo `properties.status`, el primer eslabón ahora valida contra un campo real (`status = disponible`) en vez de inferirlo.
- **Visitor — generador de reporte de declaración de renta.** Un `DeclaracionRentaVisitor` recorre las entidades relevantes (`Property`, `Rental`, `Payment`) para extraer la información específica que el arrendador necesita para su declaración de renta anual. Cada entidad implementa un método `accept()` que recibe el visitor, y el visitor tiene métodos `visitProperty()`, `visitRental()`, `visitPayment()` que extraen los datos fiscales correspondientes. El patrón permite "visitar" diferentes entidades sin modificarlas, extrayendo solo lo necesario para el reporte específico.

**Por qué ya no se siente forzado:** las tres resuelven la misma tensión — más datos y más entidades significan más riesgo de perder confianza en lo que se ve — desde tres ángulos distintos (buscar, prevenir errores al crear, y rastrear lo que ya pasó).

---

### F. Columna vertebral técnica

Estos cinco no los "pide" el arrendador directamente, pero sin ellos las 18 funcionalidades de arriba no se sostienen limpiamente. Siguen aplicándose 🔵 (sin mover nada de lo que el usuario ve):

- **Singleton — `ArrendadorContext`.** Resuelve `user_id` una sola vez por request; todos los scopes de Eloquent lo consultan en vez de repetir `auth()->id()`.
- **Mediator — coordinación entre `Tenant`/`Property`/`Rental`/`Payment`/`PaymentSchedule`.** Cuando un `payment` cierra un `schedule`, alguien tiene que decidir si eso libera al `tenant` o cambia el `rental.status` — un `GestionAlquilerMediator` centraliza esas reacciones para que los modelos no se llamen directamente entre sí.
- **Facade — `AlquilerFacade::crear()`.** Crear un `rental` toca `tenant`, `property` y opcionalmente el primer `payment_schedule` — una sola llamada simple para el formulario, coordinando servicios por dentro.
- **Adapter — geocodificación gratuita (Nominatim/OpenStreetMap).** Sigue siendo la integración recomendada: gratis para siempre, sin registro ni llave de API, alimenta los campos `lat/lng/city` de `properties` y `developments` (útil para D y E: filtrar por ciudad, mostrar mapa).
- **Builder — generación del contrato en PDF.** `ContratoBuilder` arma el documento con partes opcionales (cláusula de mascotas, garante, depósito) y guarda la ruta resultante en `rentals.agreement_path`, campo que tu ERD original ya preveía pero que hoy se llena a mano.

---

## 4. Tabla resumen final

| # | Patrón | Necesidad | Tipo | Funcionalidad |
|---|--------|-----------|------|----------------|
| 1 | Flyweight | A | 🟢 NUEVA | Modelos de propiedad (estado compartido) |
| 2 | Composite | A | 🟢 NUEVA | Desarrollo agrega ingresos de sus propiedades |
| 3 | Prototype | A | 🟢 NUEVA | Duplicar un modelo de propiedad |
| 4 | Memento | A | 🟢 NUEVA | Historial de versiones de propiedad/modelo |
| 5 | Command | B | 🟢 NUEVA | Generación automática del cobro mensual |
| 6 | State | B | 🟡 MODIFICA | Estado real de `rental`/`schedule` |
| 7 | Strategy | B | 🟢 NUEVA | Estrategia de cálculo de mora configurable |
| 8 | Decorator | B | 🟡 MODIFICA | Composición del monto de un pago |
| 9 | Iterator | B | 🟢 NUEVA | Reporte anual para declaración de renta |
| 10 | Observer | C | 🟢 NUEVA | Detección de eventos (mora, vencimiento) |
| 11 | Factory Method | C | 🟢 NUEVA | Contenido del aviso según el evento |
| 12 | Bridge | C | 🟢 NUEVA | Canal de envío desacoplado del tipo de aviso |
| 13 | Template Method | D | 🔵 APLICA | Esqueleto común de cada métrica del dashboard |
| 14 | Abstract Factory | D | 🟢 NUEVA | Exportar reportes en PDF/Excel/CSV |
| 15 | Proxy | D | 🔵 APLICA | Cache de cálculos pesados del dashboard/reporte anual |
| 16 | Interpreter | E | 🟢 NUEVA | Vistas y filtros guardados |
| 17 | Chain of Responsibility | E | 🔵 APLICA | Validaciones al crear un alquiler |
| 18 | Visitor | E | 🟢 NUEVA | Auditoría de cambios |
| 19 | Singleton | F | 🔵 APLICA | Contexto de arrendador |
| 20 | Mediator | F | 🔵 APLICA | Coordinación entre entidades |
| 21 | Facade | F | 🔵 APLICA | Registro de un alquiler completo |
| 22 | Adapter | F | 🟢 NUEVA | Geocodificación gratuita de direcciones |
| 23 | Builder | F | 🟢 NUEVA | Generador de contrato en PDF |

---

## 5. Para la clase: cómo presentarlo

Sugiero explicar cada bloque (A–F) como una historia completa antes de nombrar el patrón — primero el dolor del arrendador, después qué patrón resolvió cada parte. El bloque **B** (cobro mensual) es el más fuerte pedagógicamente porque son 5 patrones distintos cooperando en una sola línea de tiempo real (justo lo que muestra el diagrama de arriba); yo lo dejaría como el ejemplo central de la clase y el resto como refuerzo.
