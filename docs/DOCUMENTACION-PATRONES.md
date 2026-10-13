# Documentación de Patrones de Diseño en Prometheus

Este documento explica cómo se implementaron los 5 patrones de diseño principales en el proyecto Prometheus: Singleton, Builder, Abstract Factory, Factory Method y Adapter.

---

## 1. Singleton

### Propósito
Garantizar que una clase tenga una única instancia por solicitud HTTP y proporcionar un punto de acceso global a ella.

### Implementación en Prometheus

#### AppSettings
**Ubicación:** `app/Settings/AppSettings.php`

**Flujo:**
1. El constructor es privado (`private function __construct(...)`) para evitar instanciación directa
2. La clase es `final` para evitar herencia que podría romper el singleton
3. El método estático `fromDatabase()` crea la instancia única leyendo de la base de datos
4. Si no hay configuración, devuelve valores por defecto con `defaults()`
5. Laravel Service Provider (`app/Providers/AppServiceProvider.php`) registra el singleton:
   ```php
   $this->app->singleton(AppSettings::class, fn () => AppSettings::fromDatabase());
   ```

**Por qué es inmutable:**
- Todos los atributos son `readonly`
- Una vez construida, no cambia durante la petición
- Cuando se guardan nuevos ajustes, `SettingsService::forgetCachedInstance()` descarta la instancia del contenedor para que la siguiente petición la reconstruya

**Uso en el sistema:**
- Documentos (facturas, comprobantes, cartas) reciben la configuración de aquí
- Acta de entrega recibe el catálogo de espacios de aquí
- Evita duplicar datos (razón social, NIT, moneda) en múltiples pantallas

#### CurrentUserContext
**Ubicación:** `app/Infrastructure/AuthUserContext.php`

**Flujo:**
1. Implementa `CurrentUserContextInterface`
2. Cada llamada a `id()` consulta `Auth::id()` actual
3. No guarda estado del usuario entre peticiones
4. Registrado como singleton en `AppServiceProvider`:
   ```php
   $this->app->singleton(CurrentUserContextInterface::class, AuthUserContext::class);
   ```

**Por qué es singleton:**
- El contexto debe ser el mismo en toda la petición
- Consultar Auth en cada llamada asegura que siempre sea el usuario autenticado actual

---

## 2. Builder

### Propósito
Construir objetos complejos paso a paso, separando el proceso de construcción de la representación del objeto.

### Implementación en Prometheus

#### DeliveryActBuilder
**Ubicación:** `app/Services/Acts/DeliveryActBuilder.php`

**Flujo de construcción:**

```php
$builder = app(DeliveryActBuilder::class)
    ->forRental($rental, $landlordName, $tenantName)  // Datos obligatorios
    ->ofType('entrega')                                // Tipo de acta
    ->onDate($occurredAt, $scheduledAt)               // Fecha
    ->withParties([...])                              // Opcional: documentos
    ->withMeterReadings([...])                        // Opcional: medidores
    ->withInventory([...])                            // Opcional: inventario
    ->withCommitments(..., ...)                       // Opcional: compromisos
    ->withSignatures(..., ..., ...)                   // Opcional: firmas
    ->build();                                        // Crear el acta
```

**Características:**
1. **Métodos que devuelven `$this`** para permitir encadenamiento
2. **Secciones opcionales:** Si no se llama a `withMeterReadings()`, el acta se guarda sin esa sección
3. **Validación en build():** Verifica que los datos obligatorios existan antes de persistir
4. **Separación de responsabilidades:** El Builder decide qué guardar, el modelo solo persiste

**Métodos del Builder:**
- `forRental()` - Establece el alquiler y nombres de partes (obligatorio)
- `ofType()` - Tipo de acta (entrega, recepción, cambio de arrendatario)
- `onDate()` - Fecha de visita y hora programada
- `withParties()` - Documentos de identidad (opcional)
- `withMeterReadings()` - Lecturas de agua, energía, gas (opcional)
- `withInventory()` - Inventario por espacios (opcional)
- `withCommitments()` - Compromisos y observaciones (opcional)
- `withSignatures()` - Firmas y fecha de firma (opcional)
- `build()` - Valida y crea el `DeliveryAct`

**Por qué no un constructor con 20 parámetros:**
- Distinguir entre "no se llenó" y "se dejó vacío a propósito"
- Evitar pasar `null` o arrays vacíos manualmente
- La pantalla de Filament solo llama a los métodos de las secciones que se llenaron

**Validaciones del Builder:**
- Verifica que cada elemento del inventario tenga espacio y nombre
- Valida que los espacios estén en el catálogo (o los crea automáticamente)
- Verifica datos obligatorios antes de persistir
- Lanza `RuntimeException` con mensajes claros para el usuario

---

## 3. Abstract Factory

### Propósito
Proveer una interfaz para crear familias de objetos relacionados sin especificar sus clases concretas.

### Implementación en Prometheus

#### DocumentFactory
**Ubicación:** `app/Documents/Factories/DocumentFactory.php`

**Familias de productos:**
1. **Familia PDF:** `PdfDocumentFactory`
2. **Familia Texto Plano:** `TextDocumentFactory`

**Productos de cada familia:**
- `paymentReceipt()` - Comprobante de pago
- `adjustmentLetter()` - Carta de reajuste
- `monthlyStatement()` - Estado de cuenta
- `rentInvoice()` - Factura de arrendamiento

**Flujo:**

```php
// Cliente nunca sabe qué familia está usando
$factory = app(DocumentFactory::class);

// Pedir productos - la fábrica decide cómo crearlos
$receipt = $factory->paymentReceipt($invoice);
$letter = $factory->adjustmentLetter($adjustment);
$statement = $factory->monthlyStatement($period, $invoices);
$invoice = $factory->rentInvoice($invoice);
```

**Características:**
1. **Interfaz única:** `DocumentFactory` declara los 4 métodos de creación
2. **Familias intercambiables:** Las dos implementaciones tienen los mismos métodos
3. **Imposible mezclar familias:** No se puede pedir "PDF del comprobante" y "texto del estado de cuenta"
4. **Inyección de renderizador:** Cada fábrica inyecta su propio renderizador

#### PdfDocumentFactory
**Ubicación:** `app/Documents/Factories/PdfDocumentFactory.php`

```php
class PdfDocumentFactory implements DocumentFactory
{
    public function __construct(
        private readonly PdfRenderer $renderer,
    ) {}

    public function paymentReceipt(Invoice $invoice): PaymentReceipt
    {
        return PaymentReceipt::forInvoice($invoice, $this->renderer);
    }
    // ... otros métodos iguales con PdfRenderer
}
```

#### TextDocumentFactory
**Ubicación:** `app/Documents/Factories/TextDocumentFactory.php`

```php
class TextDocumentFactory implements DocumentFactory
{
    public function __construct(
        private readonly PlainTextRenderer $renderer,
    ) {}

    public function paymentReceipt(Invoice $invoice): PaymentReceipt
    {
        return PaymentReceipt::forInvoice($invoice, $this->renderer);
    }
    // ... otros métodos iguales con PlainTextRenderer
}
```

**Configuración en AppServiceProvider:**
```php
// Abstract Factory: la familia de documentos por defecto es texto plano
$this->app->bind(DocumentFactory::class, TextDocumentFactory::class);
```

**Por qué Abstract Factory y no un parámetro "formato":**
- Un parámetro permitiría mezclar productos incompatibles
- Con Abstract Factory, toda la operación usa la misma familia
- Las fábricas encapsulan la lógica de inyección de renderizadores
- Facilita agregar nuevas familias (ej: HTML, Markdown) sin cambiar el cliente

---

## 4. Factory Method

### Propósito
Definir una interfaz para crear un objeto, pero dejar que las subclases decidan qué clase instanciar. Permite delegar la instanciación a subclases.

### Implementación en Prometheus

#### Documentos
**Ubicación:** `app/Documents/PaymentReceipt.php`, `RentInvoice.php`, `AdjustmentLetter.php`, `MonthlyStatement.php`

**Patrón en cada documento:**

```php
class PaymentReceipt extends AbstractDocument
{
    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
        private readonly Invoice $invoice,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    // Factory Method
    public static function forInvoice(Invoice $invoice, DocumentRenderer $renderer): self
    {
        // Validación de dominio
        if ($invoice->status !== InvoiceStatus::PAGADA) {
            throw new DomainException('...');
        }

        return new self(
            body: self::buildBody($invoice),
            renderer: $renderer,
            slug: 'comprobante-'.$invoice->number,
            invoice: $invoice,
        );
    }
}
```

**Factory Methods en el sistema:**

1. **PaymentReceipt::forInvoice()**
   - Valida que la factura esté pagada
   - Construye el `DocumentBody` con datos de pagos
   - Incluye imagen del comprobante (data URI)
   - Crea la instancia con el renderizador inyectado

2. **RentInvoice::forInvoice()**
   - No valida estado (factura puede estar sin pagar)
   - Construye el `DocumentBody` con datos de la factura
   - Incluye tabla de pagos parciales si existen
   - Crea la instancia con el renderizador inyectado

3. **AdjustmentLetter::forAdjustment()**
   - Valida que el reajuste tenga IPC y fechas
   - Construye el `DocumentBody` con datos del reajuste
   - Incluye advertencia legal si excede tope
   - Crea la instancia con el renderizador inyectado

4. **MonthlyStatement::forPeriod()**
   - Recibe lista de facturas del periodo
   - Calcula totales (cobrado, pendiente)
   - Construye el `DocumentBody` con resumen
   - Crea la instancia con el renderizador inyectado

**Características:**
1. **Constructor privado:** Solo se puede crear a través del Factory Method
2. **Validación de dominio:** El Factory Method valida antes de instanciar
3. **Construcción del cuerpo:** `buildBody()` encapsula la lógica de armar el contenido
4. **Inyección de renderizador:** El Factory Method recibe el renderizador de la Abstract Factory

**Por qué Factory Method:**
- Centraliza la lógica de validación
- Asegura que los objetos se creen en un estado válido
- Permite cambios en la construcción sin afectar el cliente
- Los documentos pueden tener reglas de creación diferentes (ej: PaymentReceipt requiere factura pagada)

---

## 5. Adapter

### Propósito
Convertir la interfaz de una clase en otra que el cliente espera. Permite que clases con interfaces incompatibles trabajen juntas, envolviendo a una de ellas con un adaptador.

### Implementación en Prometheus

#### Problema que resuelve

Al generar el plan de cobros de un alquiler, cada factura calculaba su vencimiento así:

```php
'due_at' => $period->copy()->addDays($dueDays)->toDateString(),
```

Es decir, fecha de emisión + días de plazo. El problema: si el vencimiento cae en sábado, domingo o festivo colombiano, la factura vence un día en que no se puede pagar. El dominio necesitaba saber si un día es **hábil**, pero:

- Los festivos son datos que viven fuera del sistema (calendario oficial).
- La única fuente confiable es una API externa (Nager.Date), cuya interfaz es un `GET` HTTP con JSON, no un método de dominio.
- No se puede (ni se debe) modificar la API externa, ni acoplar las tres cadencias de cobro a un cliente HTTP.

#### Los cuatro roles del patrón

| Rol GoF | Clase en Prometheus | Responsabilidad |
| --- | --- | --- |
| **Target** | `BusinessCalendar` (interfaz) | Lo que el dominio espera: `isBusinessDay()` y `nextBusinessDay()`. |
| **Adapter** | `NagerDateBusinessCalendar` | Envuelve la API externa y traduce su JSON a `BusinessCalendar`. |
| **Adaptee** | API Nager.Date | Servicio externo con interfaz incompatible (HTTP + JSON). No se toca. |
| **Client** | `PaymentPlanGenerator` y sus subclases | Consumen `BusinessCalendar` sin saber que detrás hay una API. |

**Ubicaciones:**
- `app/Contracts/BusinessCalendar.php` (Target)
- `app/Infrastructure/Holidays/NagerDateBusinessCalendar.php` (Adapter)
- `app/Infrastructure/Holidays/WeekendBusinessCalendar.php` (implementación de respaldo)

#### Target: BusinessCalendar

```php
interface BusinessCalendar
{
    public function isBusinessDay(CarbonInterface $date): bool;

    public function nextBusinessDay(CarbonInterface $date): CarbonInterface;
}
```

#### Adapter: NagerDateBusinessCalendar

Es el **único** punto del sistema que sabe que los festivos llegan como un arreglo JSON, con el código de país en la URL. Traduce ese formato a la interfaz del dominio:

```php
public function isBusinessDay(CarbonInterface $date): bool
{
    if ($date->isWeekend()) {
        return false;
    }

    return ! in_array($date->toDateString(), $this->holidaysFor((int) $date->year), true);
}

public function nextBusinessDay(CarbonInterface $date): CarbonInterface
{
    $candidate = Carbon::instance($date);

    while (! $this->isBusinessDay($candidate)) {
        $candidate = $candidate->copy()->addDay();
    }

    return $candidate;
}

private function fetchHolidays(int $year): ?array
{
    $response = Http::timeout(5)->get("{$this->baseUrl}/PublicHolidays/{$year}/{$this->country}");

    return collect($response->json())->pluck('date')->all();
}
```

**Características del adaptador:**
1. **Aísla la API externa:** la URL, el país y el formato del JSON solo existen aquí.
2. **Cachea por año:** `Cache::remember()` evita golpear la API en cada factura.
3. **Degrada con elegancia:** si la API falla o no responde, no cachea el error y delega en `WeekendBusinessCalendar`, para que generar facturas nunca dependa de la red.

#### Cliente: PaymentPlanGenerator

El cliente ya no calcula fechas a mano. La clase base expone un método compartido que delega en el Target:

```php
protected function dueDateFrom(Carbon $issued, int $dueDays): string
{
    return $this->calendar
        ->nextBusinessDay($issued->copy()->addDays($dueDays))
        ->toDateString();
}
```

Cada generador (mensual, quincenal, anticipo) lo usa sin saber de festivos:

```php
'due_at' => $this->dueDateFrom($period, $dueDays),
```

#### Registro en el contenedor

`AppServiceProvider` decide qué implementación del Target se inyecta:

```php
if ($this->app->environment('testing')) {
    $this->app->bind(BusinessCalendar::class, WeekendBusinessCalendar::class);
} else {
    $this->app->bind(BusinessCalendar::class, fn () => new NagerDateBusinessCalendar(
        (string) config('services.holidays.base_url'),
        (string) config('services.holidays.country'),
    ));
}
```

En pruebas se usa el calendario de fin de semana para no depender de la red; en producción, el adaptador de Nager.Date.

**Por qué Adapter y no poner el `Http::get()` dentro del generador:**
- El generador de cobros no debe saber que existe una API de festivos.
- Cambiar de fuente (otra API, tabla local) toca una sola clase.
- Se puede probar la generación de facturas sin red (basta con cambiar el binding).
- La API externa queda encapsulada detrás de una interfaz propia del dominio.

---

## Interacción entre Patrones

### Flujo completo de generación de un documento:

```
1. Usuario solicita documento en Filament
   ↓
2. Filament llama a DocumentAction
   ↓
3. DocumentAction obtiene Abstract Factory del contenedor
   (TextDocumentFactory por defecto, PdfDocumentFactory si el usuario eligió PDF)
   ↓
4. Abstract Factory llama al Factory Method del documento
   (ej: $factory->paymentReceipt($invoice))
   ↓
5. Factory Method valida dominio y construye el documento
   (PaymentReceipt::forInvoice($invoice, $renderer))
   ↓
6. Documento recibe el renderizador de la Abstract Factory
   ↓
7. Documento usa Singleton AppSettings para datos de la empresa
   ↓
8. Renderizador convierte DocumentBody en formato final
   ↓
9. Documento se entrega al usuario (descarga o copia al portapapeles)
```

### Dependencias entre patrones:

- **Abstract Factory** usa **Factory Method** para crear cada producto
- **Factory Method** recibe renderizador de la **Abstract Factory**
- **Factory Method** usa **Singleton** (AppSettings) para datos globales
- **Builder** usa **Singleton** (AppSettings) para catálogo de espacios
- **Singleton** (CurrentUserContext) se usa en todas las consultas para filtrar por usuario
- **Factory Method** (los generadores de cobro) usa **Adapter** (BusinessCalendar) para calcular el vencimiento en día hábil

---

## Diagramas de Clases

### Singleton - AppSettings

```
┌─────────────────────────────────┐
│      AppServiceProvider        │
└────────────┬────────────────────┘
             │
             │ singleton()
             ↓
┌─────────────────────────────────┐
│        AppSettings (final)      │
├─────────────────────────────────┤
│ - __construct() [private]       │
│ + fromDatabase(): self         │
│ + defaults(): self             │
│ + businessName(): string       │
│ + taxId(): string              │
│ ...                           │
└─────────────────────────────────┘
```

### Builder - DeliveryActBuilder

```
┌─────────────────────────────────┐
│      DeliveryActBuilder         │
├─────────────────────────────────┤
│ - sections: array               │
│ - items: array                 │
│ - inventoryRequested: bool      │
├─────────────────────────────────┤
│ + forRental(): self             │
│ + ofType(): self                │
│ + onDate(): self                │
│ + withParties(): self           │
│ + withMeterReadings(): self     │
│ + withInventory(): self          │
│ + withCommitments(): self       │
│ + withSignatures(): self        │
│ + build(): DeliveryAct          │
└─────────────────────────────────┘
```

### Abstract Factory - DocumentFactory

```
           ┌──────────────────────┐
           │  DocumentFactory     │
           │   <<interface>>      │
           ├──────────────────────┤
           │ + paymentReceipt()   │
           │ + adjustmentLetter() │
           │ + monthlyStatement() │
           │ + rentInvoice()      │
           └──────────┬───────────┘
                      │
        ┌─────────────┴─────────────┐
        ↓                           ↓
┌───────────────────┐     ┌───────────────────┐
│ PdfDocumentFactory│     │TextDocumentFactory│
├───────────────────┤     ├───────────────────┤
│ - renderer:       │     │ - renderer:       │
│   PdfRenderer     │     │   PlainTextRenderer│
└───────────────────┘     └───────────────────┘
```

### Factory Method - Documentos

```
┌─────────────────────────────────┐
│     AbstractDocument           │
├─────────────────────────────────┤
│ - body: DocumentBody           │
│ - renderer: DocumentRenderer   │
│ - slug: string                 │
├─────────────────────────────────┤
│ + render(): string              │
│ + download(): Response         │
└─────────────────────────────────┘
         ▲         ▲         ▲
         │         │         │
┌────────┴────┐ ┌──┴────┐ ┌─┴──────────┐
│PaymentReceipt│ │RentInvoice│AdjustmentLetter│
├──────────────┤ ├──────────┤ ├───────────────┤
│ - invoice     │ │ - invoice│ │ - adjustment  │
├──────────────┤ ├──────────┤ ├───────────────┤
│ + forInvoice()│ │+forInvoice()│+forAdjustment()│
└──────────────┘ └──────────┘ └───────────────┘
```

### Adapter - BusinessCalendar

**Antes (sin Adapter):** cada generador calculaba `due_at = emisión + dueDays` a mano, sin conocer festivos.

```
┌──────────────────────────────┐
│   MonthlyPlanGenerator       │
├──────────────────────────────┤
│ 'due_at' => $period          │
│   ->addDays($dueDays)        │
│   (sin mirar festivos)       │
└──────────────────────────────┘
```

**Después (con Adapter):**

```
        ┌───────────────────────────────┐
        │      BusinessCalendar         │
        │        <<interface>>          │
        ├───────────────────────────────┤
        │ + isBusinessDay(date): bool   │
        │ + nextBusinessDay(date): date │
        └───────────────┬───────────────┘
                        │ implementa
        ┌───────────────┴────────────────┐
        ↓                                ↓
┌────────────────────────┐   ┌─────────────────────────┐
│ NagerDateBusinessCal.  │   │ WeekendBusinessCalendar │
│      <<Adapter>>       │   │      <<respaldo>>       │
├────────────────────────┤   ├─────────────────────────┤
│ - baseUrl: string      │   │ (solo fines de semana)  │
│ - country: string      │   └─────────────────────────┘
├────────────────────────┤
│ ...traduce JSON...     │
└───────────┬────────────┘
            │ usa al Adaptee
            ↓
┌────────────────────────┐
│   API Nager.Date       │
│      <<Adaptee>>       │
│ GET PublicHolidays/... │
└────────────────────────┘

PaymentPlanGenerator (Client)
   └── dueDateFrom() ..> BusinessCalendar (Target)
```

---

## Conclusión

Los 5 patrones trabajan juntos para lograr:

1. **SOLID - Single Responsibility:** Cada clase tiene una razón única para cambiar
2. **SOLID - Open/Closed:** Fácil agregar nuevas familias de documentos sin modificar código existente
3. **SOLID - Liskov Substitution:** Las fábricas son intercambiables
4. **SOLID - Dependency Inversion:** Los documentos dependen de abstracciones (DocumentRenderer, no de implementaciones concretas)
5. **KISS:** Patrones simples, sin sobreingeniería
6. **YAGNI:** Solo lo necesario para los requisitos actuales

La arquitectura facilita:
- Pruebas unitarias (mock de Abstract Factory, inyección de renderizadores)
- Mantenimiento (cada cambio está localizado)
- Extensión (nuevas familias de documentos, nuevos renderizadores)
- Consistencia (mismos métodos, mismos datos globales)
