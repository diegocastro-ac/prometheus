# Diagramas UML GOF - Patrones Decorator y Proxy

Este documento contiene los diagramas UML estilo GoF de los patrones Decorator y Proxy implementados en la aplicación, basados en el código real.

---

## Patrón Decorator - Pactado del IPC

El patrón Decorator se aplica para limitar el IPC al porcentaje pactado por las partes, manteniendo separadas la cifra legal y la aplicada.

### Clases Participantes

| Rol GoF | Clase | Ruta |
| --- | --- | --- |
| **Component** | `IpcBasisStrategy` | `app/Services/Adjustment/Strategies/IpcBasisStrategy.php` |
| **ConcreteComponent** | `AnnualVariationStrategy` | `app/Services/Adjustment/Strategies/AnnualVariationStrategy.php` |
| **Decorator** | `PactadoCapDecorator` | `app/Services/Adjustment/Decorators/PactadoCapDecorator.php` |
| **Client** | `RentAdjustmentService` | `app/Services/RentAdjustmentService.php` |

### Diagrama UML

```plantuml
@startuml decorator_pactado
top to bottom direction

interface IpcBasisStrategy <<Component>> {
  +name() string
  +resolve(DateTimeInterface $effectiveFrom, ?string $geographyCode) IpcSnapshot
  +isStatutory() bool
  +description() string
}

class AnnualVariationStrategy <<ConcreteComponent>> {
  -readonly IpcSeriesRepository $series
  +name() string
  +resolve(DateTimeInterface $effectiveFrom, ?string $geographyCode) IpcSnapshot
  +isStatutory() bool
  +description() string
}

class PactadoCapDecorator <<Decorator>> {
  -readonly IpcBasisStrategy $inner
  -readonly ?float $pactadoPercentage
  -readonly ?string $geographyCode
  +name() string
  +resolve(DateTimeInterface $effectiveFrom, ?string $geographyCode) IpcSnapshot
  +isStatutory() bool
  +description() string
  +inner() IpcBasisStrategy
  +hasPactadoCap() bool
  +legalSnapshot(DateTimeInterface $effectiveFrom, ?string $geographyCode) IpcSnapshot
}

class RentAdjustmentService <<Client>> {
  -resolveStrategy(...) PactadoCapDecorator
  +record(...) RentAdjustment
}

IpcBasisStrategy <|.. AnnualVariationStrategy
IpcBasisStrategy <|.. PactadoCapDecorator

PactadoCapDecorator --> IpcBasisStrategy : decorates
RentAdjustmentService ..> PactadoCapDecorator : uses

note right of PactadoCapDecorator
  legalSnapshot(): IPC sin pacto
  resolve(): min(pactado, legal)
  Sin pacto: devuelve el legal
  Pacto mayor: no sube el IPC
end note

note bottom of RentAdjustmentService
  Usa legalSnapshot() para el tope
  y resolve() para la cifra aplicada
end note
@enduml
```

---

## Patrón Proxy - Festivos (Cache con Degradación)

El patrón Proxy se aplica para controlar el acceso a la fuente de festivos de Nager.Date, implementando caché y degradación controlada ante fallos.

### Clases Participantes

| Rol GoF | Clase | Ruta |
| --- | --- | --- |
| **Subject** | `HolidaySource` | `app/Contracts/HolidaySource.php` |
| **Proxy** | `CachedHolidaySource` | `app/Infrastructure/Holidays/CachedHolidaySource.php` |
| **RealSubject** | `NagerDateHolidaySource` | `app/Infrastructure/Holidays/NagerDateHolidaySource.php` |
| **Client** | `NagerDateBusinessCalendar` | `app/Infrastructure/Holidays/NagerDateBusinessCalendar.php` |

### Diagrama UML

```plantuml
@startuml proxy_festivos
top to bottom direction

interface HolidaySource <<Subject>> {
  +holidaysFor(int $year) array
}

class CachedHolidaySource <<Proxy>> {
  -readonly HolidaySource $real
  -readonly string $country
  +holidaysFor(int $year) array
}

class NagerDateHolidaySource <<RealSubject>> {
  -readonly string $baseUrl
  -readonly string $country
  +holidaysFor(int $year) array
}

class NagerDateBusinessCalendar <<Client>> {
  -readonly HolidaySource $source
  +isBusinessDay(CarbonInterface $date) bool
  +nextBusinessDay(CarbonInterface $date) CarbonInterface
}

HolidaySource <|.. CachedHolidaySource
HolidaySource <|.. NagerDateHolidaySource

CachedHolidaySource --> HolidaySource : delegates to
NagerDateBusinessCalendar --> HolidaySource : uses

note right of CachedHolidaySource
  Cache hit: responde inmediatamente
  Cache miss: delega al real
  Fallo: log + [] sin cachear
end note

note bottom of NagerDateHolidaySource
  Consulta Nager.Date API
  Traduce HTTP/JSON a fechas
  Lanza excepción si falla
end note
@enduml
```

---

## Patrón Proxy - Documentos (Protección y Auditoría)

El patrón Proxy se aplica para controlar el acceso a los documentos, registrando la auditoría del contenido y diferiendo la construcción del documento real.

### Clases Participantes

| Rol GoF | Clase | Ruta |
| --- | --- | --- |
| **Subject** | `Document` | `app/Documents/Contracts/Document.php` |
| **Proxy** | `DocumentProxy` | `app/Documents/DocumentProxy.php` |
| **RealSubject** | Documentos de fábricas (AbstractDocument) | `app/Documents/Documents/AbstractDocument.php` |
| **Descriptor** | `DocumentDescriptor` | `app/Documents/DocumentDescriptor.php` |
| **Logger** | `DocumentAccessLogger` | `app/Contracts/DocumentAccessLogger.php` |
| **Client** | `DocumentService` | `app/Documents/DocumentService.php` |

### Diagrama UML

```plantuml
@startuml proxy_documentos
top to bottom direction

interface Document <<Subject>> {
  +filename() string
  +mimeType() string
  +deliveryLabel() string
  +isDownload() bool
  +content() string
  +isEmpty() bool
  +preview() string
}

class DocumentProxy <<Proxy>> {
  -?Document $document
  -bool $audited
  -readonly DocumentDescriptor $descriptor
  -readonly Closure $builder
  -readonly DocumentAccessLogger $logger
  +filename() string
  +mimeType() string
  +deliveryLabel() string
  +isDownload() bool
  +content() string
  +isEmpty() bool
  +preview() string
  -audit() void
  -document() Document
}

class DocumentDescriptor <<Descriptor>> {
  +string $type
  +string $format
  +?int $userId
  +?string $reference
  +array $context
}

interface DocumentAccessLogger <<Logger>> {
  +log(DocumentDescriptor $descriptor) void
}

class DocumentService <<Client>> {
  +receiptFor(Invoice, ?string) Document
  +invoiceFor(Invoice, ?string) Document
  +adjustmentLetterFor(RentAdjustment, ?string) Document
  +statementFor(Rental, string, ?string) Document
}

Document <|.. DocumentProxy

DocumentProxy --> Document : delegates to
DocumentProxy --> DocumentDescriptor : describes
DocumentProxy --> DocumentAccessLogger : audits
DocumentService ..> DocumentProxy : creates

note right of DocumentProxy
  content(): audita y delega
  Metadata: no audita
  Primer método: construye y memoriza
  Fallo de auditoría: no entrega
end note

note bottom of DocumentDescriptor
  No contiene el documento ni su contenido
  Se crea antes de construir el real
end note
@enduml
```

---

## Notas

- Los diagramas incluyen únicamente las clases relevantes para mostrar el patrón según GoF
- Los métodos corresponden exactamente a los implementados en el código real
- Se respetan las firmas de métodos tal como aparecen en las clases PHP
- Las relaciones de composición y delegación reflejan la estructura del código
