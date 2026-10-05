# Diagramas UML de los cuatro patrones

Este documento acompaña a `PLAN-PATRONES.md`. Ahí se explica **por qué** se implementa cada patrón; aquí se muestra **cómo queda la estructura de clases** de cada uno.

## Cómo ver los diagramas

Los diagramas están escritos en Mermaid, así que se renderizan solos en GitHub, en VS Code con la extensión Mermaid y en la vista previa de Markdown del navegador. Si prefieres verlos como imagen, cualquier visor de Mermaid los convierte a SVG o PNG.

## Alcance de los diagramas

Cada diagrama incluye **solo las clases necesarias para que el patrón se entienda**. Se dejaron fuera, de forma deliberada:

- Las pantallas de Filament, salvo `AjustesPage`, que sí aparece porque es el cliente que usa el patrón.
- Los widgets del panel y las consultas duplicadas, porque no son parte de estos cuatro patrones.
- La capa de entrada HTTP, que no aporta nada a la lectura de los patrones.

## Resumen

| Patrón | Dónde se aplica | Diagrama |
| --- | --- | --- |
| Singleton | Configuración de la aplicación y el constructor del acta | Clases |
| Builder | Acta de entrega y recepción del inmueble | Clases y secuencia |
| Abstract Factory | Los cuatro documentos en PDF y en texto | Clases y secuencia |
| Factory Method | Cadencia de cobro de los alquileres | Clases y secuencia |

---

## 1. Singleton

**Qué resuelve.** Que exista una sola instancia de la configuración de la aplicación y una sola instancia del constructor del acta dentro de cada petición, en lugar de cuatro cachés estáticos que hoy son copias redundantes.

**Distinción importante.** `AppSettings` es un singleton real: es inmutable y vive una vez. `DeliveryActBuilder` se registra con alcance de petición, porque acumula secciones mientras construye y no debe arrastrar nada a la siguiente petición.

### Diagrama de clases

```mermaid
classDiagram
    direction TB

    class AppServiceProvider {
        <<contenedor>>
        +register()
        +boot()
    }

    class AppSettings {
        -string $businessName
        -string $taxId
        -string $address
        -string $currency
        -int $invoiceDueDays
        -string[] $spaceCatalog
        -constructor()
        +businessName() string
        +taxId() string
        +address() string
        +formatMoney(float $amount) string
        +spaceCatalog() string[]
    }

    class DeliveryActBuilder {
        -AppSettings $settings
        -DeliveryAct $act
        -constructor()
        +withParties() DeliveryActBuilder
        +withInventory() DeliveryActBuilder
        +withSignatures() DeliveryActBuilder
        +build() DeliveryAct
    }

    class AjustesPage {
        +save() void
        +businessName() string
    }

    class DeliveryAct {
        +render() void
    }

    AppServiceProvider ..> AppSettings : registra una vez
    AppServiceProvider ..> DeliveryActBuilder : registra por peticion
    AjustesPage ..> AppSettings : lee y escribe
    DeliveryActBuilder o-- AppSettings : aporta datos comunes
    DeliveryActBuilder ..> DeliveryAct : produce
```

**Cómo se lee.**

- Las flechas punteadas hacia `AppSettings` y `DeliveryActBuilder` salen del contenedor, no de otra clase de negocio. Ahí es donde se decide el tiempo de vida de cada instancia.
- `AppSettings` tiene constructor privado. Nadie puede crear una segunda instancia desde fuera; hay que pasar por el contenedor.
- `DeliveryActBuilder` depende de `AppSettings` y por eso hereda automáticamente el nombre del negocio, el NIT y el catálogo de espacios. Por eso no hay que pasarle esos datos como parámetros.

---

## 2. Builder

**Qué resuelve.** El acta tiene más de quince secciones y casi todas son opcionales. El Builder es el único que sabe cuáles existen, en qué orden van y cuáles se pueden omitir.

### Diagrama de clases

```mermaid
classDiagram
    direction TB

    class AjustesPage {
        -DeliveryActBuilder $builder
        +render() void
        +descargar() void
    }

    class DeliveryActBuilder {
        -AppSettings $settings
        -DeliveryAct $act
        +withLandlord() DeliveryActBuilder
        +withTenant() DeliveryActBuilder
        +withMeterReadings() DeliveryActBuilder
        +withInventory(ActItem[] items) DeliveryActBuilder
        +withPhotos(ActPhoto[] photos) DeliveryActBuilder
        +withCommitments() DeliveryActBuilder
        +withObservations() DeliveryActBuilder
        +withSignatures(Signer[] signers) DeliveryActBuilder
        +build() DeliveryAct
    }

    class DeliveryAct {
        -Rental $rental
        -ActType $type
        -Carbon $occurredAt
        -MeterReading[] $readings
        -ActItem[] $items
        -ActPhoto[] $photos
        -string $commitments
        -string $observations
        -Signer[] $signers
        +content() ActContent
    }

    class ActContent {
        <<contrato de salida>>
        +html() string
        +file() ActFile
    }

    class ActItem {
        +string $space
        +ActItemState $state
        +string $note
        +string $photoPath
    }

    class ActItemState {
        <<enumeracion>>
        NUEVO
        BUENO
        REPARABLE
        POR_REEMPLAZAR
        DESTRUIDO
    }

    class AppSettings {
        +businessName() string
        +taxId() string
        +spaceCatalog() string[]
    }

    AjustesPage o-- DeliveryActBuilder : usa
    DeliveryActBuilder o-- AppSettings : aporta datos comunes
    DeliveryActBuilder ..> DeliveryAct : construye paso a paso
    DeliveryAct *-- ActItem : contiene
    DeliveryAct ..> ActContent : produce
    ActItem --> ActItemState
```

**Cómo se lee.**

- Todos los métodos `with...` devuelven el propio Builder. Eso permite encadenarlos y es lo que hace que el orden de armado sea explícito en la pantalla.
- Las secciones obligatorias son las que no tienen método opcional: el Builder las coloca siempre en `build()`.
- `ActContent` separa el acta del formato en que se muestra. Gracias a ese contrato, el acta se puede imprimir en PDF hoy y mandar como texto mañana sin tocar el Builder.

### Diagrama de secuencia

```mermaid
sequenceDiagram
    actor Arrendador
    participant Page as AjustesPage
    participant Builder as DeliveryActBuilder
    participant Settings as AppSettings
    participant Act as DeliveryAct

    Arrendador->>Page: Crear acta
    Page->>Builder: withLandlord()
    Builder->>Settings: businessName(), taxId()
    Settings-->>Builder: datos del negocio
    Builder-->>Page: mismo Builder
    Arrendador->>Page: agregar refrigerador en cocina
    Page->>Builder: withInventory(items)
    Builder->>Builder: crear el acta vacia
    Arrendador->>Page: agregar lecturas de agua y energia
    Page->>Builder: withMeterReadings()
    Builder->>Builder: agregar seccion de medidores
    Arrendador->>Page: omitir la seccion de terraza
    Note over Page,Builder: no se invoca withTerrace, la seccion no existe
    Arrendador->>Page: descargar
    Page->>Builder: build()
    Builder->>Act: ensamblar las secciones pedidas
    Act-->>Builder: acta completa
    Builder-->>Page: DeliveryAct
    Page-->>Arrendador: PDF
```

**Lo que demuestra la secuencia.** El Builder no tiene un método para todo el documento. Arma lo que se le pide. Si una sección no se pide, no aparece en el acta. Esa es exactamente la diferencia frente a pasar veinte parámetros.

---

## 3. Abstract Factory

**Qué resuelve.** Los cuatro documentos existen en dos formatos: PDF y texto. Cada formato es una familia completa que trae su plantilla, su generador y su acción de entrega. Las familias no se pueden mezclar entre sí.

### Diagrama de clases

```mermaid
classDiagram
    direction TB

    class DocumentFactory {
        <<interfaz>>
        +createReceipt(Payment $payment) PaymentReceiptDocument
        +createAdjustmentLetter(RentAdjustment $adj) AdjustmentLetterDocument
        +createStatement(AccountStatement $st) MonthlyStatementDocument
        +createInvoice(Invoice $invoice) RentInvoiceDocument
    }

    class PdfDocumentFactory {
        +createReceipt(Payment $payment) PaymentReceiptDocument
        +createAdjustmentLetter(RentAdjustment $adj) AdjustmentLetterDocument
        +createStatement(AccountStatement $st) MonthlyStatementDocument
        +createInvoice(Invoice $invoice) RentInvoiceDocument
    }

    class TextDocumentFactory {
        +createReceipt(Payment $payment) PaymentReceiptDocument
        +createAdjustmentLetter(RentAdjustment $adj) AdjustmentLetterDocument
        +createStatement(AccountStatement $st) MonthlyStatementDocument
        +createInvoice(Invoice $invoice) RentInvoiceDocument
    }

    class Document {
        <<abstracta>>
        #string $title
        #string $body
        +render() string
    }

    class PaymentReceiptDocument {
        +Invoice $invoice
        +render() string
    }

    class AdjustmentLetterDocument {
        +RentAdjustment $adjustment
        +IpcRate $ipcRate
        +render() string
    }

    class MonthlyStatementDocument {
        +AccountStatement $statement
        +render() string
    }

    class RentInvoiceDocument {
        +Invoice $invoice
        +render() string
    }

    class Invoice {
        +string $number
        +string $concept
        +float $amount
        +InvoiceStatus $status
    }

    class InvoiceStatus {
        <<enumeracion>>
        EMITIDA
        PAGADA
        VENCIDA
        ANULADA
    }

    DocumentFactory <|.. PdfDocumentFactory : implementa
    DocumentFactory <|.. TextDocumentFactory : implementa
    PdfDocumentFactory ..> PaymentReceiptDocument : produce
    PdfDocumentFactory ..> AdjustmentLetterDocument : produce
    PdfDocumentFactory ..> MonthlyStatementDocument : produce
    PdfDocumentFactory ..> RentInvoiceDocument : produce
    TextDocumentFactory ..> PaymentReceiptDocument : produce
    TextDocumentFactory ..> AdjustmentLetterDocument : produce
    TextDocumentFactory ..> MonthlyStatementDocument : produce
    TextDocumentFactory ..> RentInvoiceDocument : produce
    Document <|-- PaymentReceiptDocument
    Document <|-- AdjustmentLetterDocument
    Document <|-- MonthlyStatementDocument
    Document <|-- RentInvoiceDocument
    PaymentReceiptDocument --> Invoice : exige pagada
    RentInvoiceDocument --> Invoice
    Invoice --> InvoiceStatus
```

**Cómo se lee.**

- La interfaz `DocumentFactory` declara los cuatro métodos y no dice nada sobre el formato. Quien la consume no sabe si va a recibir un PDF o un texto.
- Las dos fábricas concretas implementan los mismos cuatro métodos. Cada una devuelve el mismo producto, pero con su plantilla y su forma de entrega.
- Ningún producto sabe de qué fábrica salió. Solo sabe renderizarse. Esa es la garantía de que un documento en PDF nunca aparecerá con el botón de copiar.
- `PaymentReceiptDocument` apunta a `Invoice` y solo se crea cuando la factura está pagada. La regla de negocio queda visible en el diagrama, no escondida en la pantalla.

### Diagrama de secuencia

```mermaid
sequenceDiagram
    actor Arrendador
    participant View as Vista de documentos
    participant Factory as DocumentFactory
    participant Receipt as PaymentReceiptDocument

    Arrendador->>View: elige el formato Texto
    View->>Factory: createReceipt(payment)
    Note over Factory: la factura esta pagada, se puede crear
    Factory-->>View: PaymentReceiptDocument con plantilla de texto
    View->>Receipt: render()
    Receipt-->>View: texto plano
    View-->>Arrendador: muestra boton copiar
    Arrendador->>View: elige el formato PDF
    View->>Factory: createReceipt(payment)
    Factory-->>View: PaymentReceiptDocument con plantilla PDF
    View->>Receipt: render()
    Receipt-->>View: archivo
    View-->>Arrendador: descarga
```

**Lo que demuestra la secuencia.** El cliente pide el mismo documento dos veces y en los dos casos pide solo un formato. Nunca pide "texto con descarga", porque esa combinación no existe en ninguna familia.

---

## 4. Factory Method

**Qué resuelve.** Hoy la cadencia mensual está escrita dentro del servicio y no hay forma de cobrarla cada quince días ni con anticipo. El Factory Method deja que cada subclase conozca solo su propio ritmo.

### Diagrama de clases

```mermaid
classDiagram
    direction TB

    class PaymentPlanService {
        +generateFor(Rental $rental, BillingCadence $cadence) void
    }

    class BillingCadence {
        <<enumeracion>>
        MENSUAL
        QUINCENAL
        ANTICIPO
    }

    class PaymentPlanGenerator {
        <<abstracta>>
        #Rental $rental
        #Invoice[] createInvoices() Invoice[]
        +generate() void
    }

    class MonthlyPlanGenerator {
        #Invoice[] createInvoices() Invoice[]
    }

    class QuincenalPlanGenerator {
        #Invoice[] createInvoices() Invoice[]
    }

    class AdvancePlanGenerator {
        #Invoice[] createInvoices() Invoice[]
    }

    class Invoice {
        +string $number
        +string $concept
        +float $amount
        +string $period
        -Carbon $issuedAt
        -Carbon $dueDate
        +InvoiceStatus $status
        +refreshStatus() void
        +applyPayment(Payment $payment) void
    }

    class InvoiceStatus {
        <<enumeracion>>
        EMITIDA
        PAGADA
        VENCIDA
        ANULADA
    }

    PaymentPlanService ..> BillingCadence : recibe la eleccion
    PaymentPlanService ..> PaymentPlanGenerator : pide el generador
    PaymentPlanGenerator <|-- MonthlyPlanGenerator
    PaymentPlanGenerator <|-- QuincenalPlanGenerator
    PaymentPlanGenerator <|-- AdvancePlanGenerator
    PaymentPlanGenerator ..> Invoice : crea las facturas
    Invoice --> InvoiceStatus
    Invoice ..> Payment : recibe pagos
```

**Cómo se lee.**

- El paso 3 del plan elimina los cuatro interruptores de servicios pagados y hace que el estado se deduzca de la factura. Por eso los generadores crean `Invoice` y no `Payment`. La relación entre `Invoice` y `Payment` es lo que permite que el comprobante se habilite cuando la factura queda pagada.
- `PaymentPlanService` recibe la elección del usuario y pide el generador. No contiene ningún cálculo de fechas.
- El método `createInvoices()` es el punto de decisión: cada subclase decide cuántos meses cubre y qué día vence, sin que nadie más se entere.

### Diagrama de secuencia

```mermaid
sequenceDiagram
    actor Arrendador
    participant Page as Crear alquiler
    participant Service as PaymentPlanService
    participant Gen as PaymentPlanGenerator
    participant Inv as Invoice

    Arrendador->>Page: elige la cadencia QUINCENAL
    Page->>Service: generateFor(rental, QUINCENAL)
    Service->>Service: resolver el generador
    Service->>Gen: generate()
    Gen->>Inv: crear dos facturas al mes
    Note over Gen,Inv: dia 1 por canon, dia 15 por servicios
    Inv-->>Gen: invoices
    Gen-->>Service: plan generado
    Service-->>Page: notificacion de exito
    Page-->>Arrendador: plan creado
```

**Lo que demuestra la secuencia.** El servicio no sabe qué significa quincenal. Solo pide el generador que corresponde y ejecuta. Agregar la cadencia "semanal" sería agregar una clase, sin tocar el servicio ni las pantallas.

---

## Cómo se relacionan los cuatro patrones

No están aislados: el paso 1 los sostiene a todos.

```
AppSettings  (Singleton, una vez)
     |
     +---->  DeliveryActBuilder  (Singleton de peticion)
     |               |
     |               +---->  DeliveryAct
     |
     +---->  PdfDocumentFactory  (Abstract Factory)
     |               |
     +---->  TextDocumentFactory (Abstract Factory)
                     |
                     +---->  documentos que reciben Invoice

PaymentPlanService  ---->  PaymentPlanGenerator  (Factory Method)
                                  |
                                  +---->  genera Invoice
```

- Los dos **Singleton** están en la base y alimentan al **Builder** y a las dos fábricas. Por eso los documentos salen con el nombre del negocio, el NIT, la moneda y los textos legales correctos sin que nadie los pase como parámetro.
- El **Factory Method** produce las facturas. Las fábricas de documentos y el Builder se apoyan en esas facturas, no en pagos sueltos.

## Nota sobre el orden de lectura

Si solo quieres entender el flujo de negocio de punta a punta, el camino más corto es:

1. El arrendador crea un alquiler y elige la cadencia. **Factory Method** genera las facturas.
2. El arrendador emite la factura y registra pagos. La factura cambia de estado cuando queda pagada.
3. Con la factura pagada, la **Abstract Factory** produce el comprobante en PDF o en texto.
4. En cualquier momento, el **Builder** arma el acta de entrega usando la configuración del **Singleton**.