# AUDITORÍA TÉCNICA — Diagnóstico SOLID y Control de Cambios (v5)

**Proyecto:** Prometheus (Sistema de administración de arriendos)
**Stack auditado:** Laravel 12 + Filament 3 (PHP ^8.3)
**Alcance:** `app/**` — Models (entidades de dominio), Filament Resources y Filament Pages. La capa de Widgets, Providers, Factories/Seeders y Rutas fue auditada pero **queda fuera de alcance** del ejercicio (no aporta entidades de dominio ni lógica central).
**Fecha:** 2026-09-11

> **Nota metodológica (v5 — depuración por alcance del ejercicio):**
>
> Sobre la v4 se retiran los hallazgos que no tocan **entidades de dominio ni lógica importante** o cuya
> política es **fija en este dominio**. Los IDs se conservan para no romper la trazabilidad con el UML.
>
> - **CONSERVADOS** (revisados y aprobados): **CC-01, CC-02, CC-03, CC-04, CC-08, CC-09, CC-10**.
> - **DESCARTADOS en esta versión:**
>   - **CC-05** (reglas de morosidad/ingresos en widgets): toca widgets, clases periféricas — fuera del alcance del ejercicio.
>   - **CC-07** (política de tenencia duplicada): la tenencia ("cada usuario ve/sube sus datos") es una **invariante fija** del
>     dominio (arriendos informales individuales, un dueño por registro); como la política no va a variar, no aplica la
>     falla OCP en este contexto.
>   - **CC-11** (dinero como `float`/moneda hardcodeada): el consumo problemático vive en widgets/presentación; aunque
>     modela columnas en `Rental`/`Payment`, se descarta por decisión de alcance del ejercicio.
> - **Ya retirado en v4:** **CC-06** (HTML manual en `infolist`) — uso idiomático de `formatStateUsing`, sin falla SOLID de fondo.
> - **Siempre fuera** (no son fallas SOLID): `total_persons` string y `is_paid` fantasma (defectos de esquema/funcionales),
>   N+1 en `IncomeChart` (rendimiento), series mágicas `[7,2,10,...]` de KPIs (dato/UX).
> - **NO son fallos por sí mismos** (patrones del framework): eventos Eloquent, `afterStateUpdated`, hooks de `CreateRecord`,
>   `Hidden::make('user_id')`, una clase Resource por entidad.

**Resultado: 7 hallazgos** (3 Alta, 3 Media, 1 Baja) — todos sobre entidades de dominio, Resources y Pages.

---

# TAREA 1 — Diagnóstico de Principios SOLID y Antipatrones

---

---
**ID de Trazabilidad:** CC-01
**Hallazgo 1:** Responsabilidad de infraestructura dentro del modelo: persistencia de archivos en eventos Eloquent de `Rental`

**1. Principio / Antipatrón:** Single Responsibility Principle (SRP) — el agregado `Rental` (concepto de dominio) además administra el filesystem (disco, rutas, reemplazo y borrado de acuerdos). Antipatrón acompañante: **God Class** en formación.

**¿Es idiomático en Laravel/Filament?** Los eventos Eloquent sí son idiomáticos, pero **no** para que el modelo conozca la política de carpetas (`users/{id}/rentals/{id}/agreement`), el disco (`Storage::disk('public')`) ni la convención `temp/uploads` que nace en la capa Filament. Esa orquestación de archivos es responsabilidad de un servicio de infraestructura, no del modelo de dominio.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Models/Rental.php`
- Método/Función: `booted()` (eventos `created`, `updating`, `deleted`)

```php
protected static function booted()
{
    static::created(function (Rental $rental) {
        if ($rental->agreement_path && str_starts_with($rental->agreement_path, 'temp/uploads')) {
            $newPath = "users/{$rental->user_id}/rentals/{$rental->id}/agreement/" . basename($rental->agreement_path);
            Storage::disk('public')->move($rental->agreement_path, $newPath);
            $rental->updateQuietly(['agreement_path' => $newPath]);
        }
    });

    static::updating(function (Rental $rental) {
        $original = $rental->getOriginal('agreement_path');
        if ($original && $original !== $rental->agreement_path) {
            Storage::disk('public')->delete($original);
        }
    });

    static::deleted(function (Rental $rental) {
        Storage::disk('public')
            ->deleteDirectory("users/{$rental->user_id}/rentals/{$rental->id}");
    });
}
```

**3. Impacto Concreto:**
- Flexibilidad: Migrar el storage (S3, GCS) o cambiar la estructura de carpetas obliga a editar el modelo de dominio.
- Extensibilidad: Cada regla nueva del ciclo de vida del contrato (auditoría, versionado) se acumula como otro hook en el mismo agregado.
- Desacoplamiento: El dominio queda atado a una implementación concreta (`Storage::disk('public')` = DIP) y a un detalle de la capa de UI (`temp/uploads`).

**4. Severidad Asignada:** Alta
Justificación: Es el agregado raíz del dominio y ahora también su "gestor de almacenamiento"; impide escalar y oculta efectos colaterales en la persistencia.

**5. Preview de la Solución (Patrón: Separación de responsabilidades — servicio de infraestructura):**

```php
final class AgreementStorageService {
    public function persist(string $tempPath, int $userId, int $rentalId): string;
    public function remove(string $path): void;
    public function removeAllFor(int $userId, int $rentalId): void;
}
// Los eventos solo invocan el servicio; el modelo ya no conoce Storage ni rutas.
```

---

**ID de Trazabilidad:** CC-02
**Hallazgo 2:** Caso de uso secuestrado por la presentación: la página `CreateRental` genera todo el plan de pagos

**1. Principio / Antipatrón:** SRP + violación de capas. Antipatrón acompañante: **God Method** — un solo método crea el contrato, genera la agenda de cobros (regla de negocio) y dispara una notificación de UI.

**¿Es idiomático en Laravel/Filament?** Sobrescribir `handleRecordCreation`/`afterCreate` en una página Filament es idiomático para **orquestación**. Lo que no es idiomático es ejecutar ahí un proceso de dominio completo (generar N payments) que debería ser un Caso de Uso reutilizable e inyectable.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
- Método/Función: `handleRecordCreation()`

```php
protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
{
    $rental = parent::handleRecordCreation($data);

    $start = Carbon::parse($rental->start_date);
    for ($i = 1; $i <= $rental->total_months; $i++) {
        $date = $start->copy()->addMonths($i)->format('Y-m-d');
        $rental->payments()->create([
            'date'         => $date,
            'amount'       => $rental->monthly_amount,
            'is_rent_paid' => false,
            'is_water_paid' => false,
            'is_energy_paid' => false,
            'is_gas_paid'  => false,
            'user_id'      => Auth::id(),
        ]);
    }

    Notification::make()->success()->title('Payment plan generated')->body('...')->send();

    return $rental;
}
```

**3. Impacto Concreto:**
- Flexibilidad: La regla "el contrato nace con su plan de cobros" no puede dispararse desde un comando, API o importación sin duplicarse.
- Extensibilidad: Un nuevo esquema de cobro (semestral, prorrateo, descuento) obliga a editar la página.
- Desacoplamiento: La generación de datos de negocio queda acoplada a Filament, a `Auth::id()` y a las notificaciones; sin capa de aplicación, la lógica no es testeable en aislamiento.

**4. Severidad Asignada:** Alta
Justificación: El proceso más importante del dominio está enterrado en la capa de vista; impide automatización y prueba unitaria.

**5. Preview de la Solución (Patrón: Caso de Uso / Servicio de aplicación):**

```php
final class PaymentPlanService {
    public function generateFor(Rental $rental): Collection;
}
// CreateRentalPage: $this->paymentPlanService->generateFor($rental);
```

---

**ID de Trazabilidad:** CC-03
**Hallazgo 3:** Regla de negocio replicada: la fecha de fin del contrato se calcula en 4 puntos distintos

**1. Principio / Antipatrón:** Open/Closed Principle (OCP) — el código no está cerrado a cambios porque la misma política de fechas vive en 4 lugares. Antipatrón acompañante: **Shotgun Surgery**.

**¿Es idiomático en Laravel/Filament?** Las closures reactivas `afterStateUpdated` del formulario son idiomáticas; el problema no es la closure, sino que la **regla de negocio** (`end = start + months`) esté copiada en el formulario y en dos páginas en lugar de centralizarse en el modelo/dominio.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Filament/Resources/RentalResource.php`, `.../Pages/CreateRental.php`, `.../Pages/EditRental.php`
- Método/Función: `form()` (2 closures) + `mutateFormDataBeforeCreate()` + `mutateFormDataBeforeSave()`

```php
// RentalResource::form() — evento de start_date
->afterStateUpdated(function (callable $get, callable $set) {
    $months = (int) $get('total_months');
    if ($get('start_date') && $months > 0) {
        $end = \Carbon\Carbon::parse($get('start_date'))->addMonths($months)->format('Y-m-d');
        $set('end_date', $end);
    }
}),

// RentalResource::form() — evento de total_months (misma fórmula)
->afterStateUpdated(function (callable $get, callable $set, $state) {
    $months = (int) $state;
    if ($get('start_date') && $months > 0) {
        $end = \Carbon\Carbon::parse($get('start_date'))->addMonths($months)->format('Y-m-d');
        $set('end_date', $end);
    }
}),
```

```php
// CreateRental::mutateFormDataBeforeCreate() y EditRental::mutateFormDataBeforeSave()
$data['end_date'] = Carbon::parse($data['start_date'])->addMonths((int) $data['total_months']);
```

**3. Impacto Concreto:**
- Flexibilidad: Cambiar la política (corrimiento a fin de mes, días de gracia) exige localizar y editar 4 ubicaciones.
- Extensibilidad: Todo nuevo flujo de contratos debe volver a implementar la fórmula.
- Desacoplamiento: La invariante de fechas queda dispersa entre la vista y las páginas; el dominio no la gobierna.

**4. Severidad Asignada:** Media
Justificación: No rompe el flujo hoy, pero viola OCP y convierte cualquier ajuste de política en una cacería manual.

**5. Preview de la Solución (Patrón: Value Object — `RentalPeriod`):**

```php
final class RentalPeriod {
    public function __construct(private Carbon $start, private int $months) {}
    public function endDate(): Carbon { return $this->start->copy()->addMonths($this->months); }
}
```

---

**ID de Trazabilidad:** CC-04
**Hallazgo 4:** Primitive Obsession: el estado de un pago se modela con 4 booleanos desconectados

**1. Principio / Antipatrón:** Primitive Obsession — un concepto de dominio (estado del pago) es representado con primitivos `bool` sin invariantes. Consecuencia SOLID: **OCP** — añadir una componente de cobro o cambiar la semántica de "al día" obliga a tocar esquema, formularios y cada vista que consume el estado.

**¿Es idiomático en Laravel/Filament?** **No** es un patrón de Laravel; Eloquent soporta `enum`/`cast` de forma nativa (`->casts(['status' => PaymentStatus::class])`). **Esto no trata sobre columnas `string`** (ese tipo de observación de esquema se descartó): el defecto es que el estado es un conjunto de flags crudos en lugar de un tipo de dominio.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Models/Payment.php`
- Método/Función: `$fillable` / `$casts` (sin cast de estado)

```php
protected $fillable = [
    'date',
    'amount',
    'is_rent_paid',
    'is_water_paid',
    'is_energy_paid',
    'is_gas_paid',
    'rental_id',
    'user_id',
];
```

```php
// Consecuencia en los consumidores: cada vista define su propio "pagado".
// Consumidor A — "vencido" si CUALQUIERA de los flags es false:
$q->where('is_rent_paid', false)
    ->orWhere('is_water_paid', false)
    ->orWhere('is_energy_paid', false)
    ->orWhere('is_gas_paid', false);

// Consumidor B — "pagado" SOLO si is_rent_paid es true:
->where('is_rent_paid', true)
```

**3. Impacto Concreto:**
- Flexibilidad: Cambiar la semántica de "pagado" propaga por toda la app; no hay un único dueño de la regla.
- Extensibilidad: Añadir un servicio (internet, administración) exige sembrar otro `is_*_paid` en esquema, formularios y vistas (viola OCP).
- Desacoplamiento: La interpretación del estado vive en consultas SQL de la presentación, no en el modelo de dominio.

**4. Severidad Asignada:** Alta
Justificación: El núcleo del negocio (¿cobró?, ¿está al día?) carece de modelo de estado; cada extensión multiplica el costo y las inconsistencias entre vistas.

**5. Preview de la Solución (Patrón: Enum / Value Object de dominio):**

```php
enum PaymentStatus { case PAID; case PARTIAL; case PENDING; case OVERDUE; }

class Payment {
    public function status(): PaymentStatus { /* derivación única de los 4 flags */ }
    public function isPaid(): bool { return $this->status() === PaymentStatus::PAID; }
}
```

---

**ID de Trazabilidad:** CC-08
**Hallazgo 8:** DIP: el dominio y la presentación dependen de implementaciones concretas (facades `Storage`, `Auth` y Eloquent) sin contratos propios

**1. Principio / Antipatrón:** Dependency Inversion Principle (DIP) — las políticas (modelo de dominio, Resources/Pages) dependen de detalles (facade `Storage`, facade global `Auth`, consultas Eloquent directas) en lugar de abstracciones definidas por la propia aplicación (repositorios/ports).

**¿Es idiomático en Laravel/Filament?** Usar facades y Eloquent es lo **idiomático** de Laravel. La observación de diseño es el **sentido de la dependencia**: el dominio no debería acoplarse a cabos concretos cuando su ciclo de vida depende de ellos (CC-01 mostró el caso `Storage`). DIP se aplica donde hay variación posible: proveedor de disco y contexto de autenticación.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Models/Rental.php`, `app/Filament/Resources/RentalResource.php`
- Método/Función: `booted()`, `getEloquentQuery()`

```php
// Dominio dependiendo de infraestructura concreta:
Storage::disk('public')->move($rental->agreement_path, $newPath);
```

```php
// Presentación dependiendo de la fachada global (Resources y Pages):
->where('user_id', Auth::id());
```

**3. Impacto Concreto:**
- Flexibilidad: Reemplazar un proveedor (disco, motor de BD) o cambiar el contexto de autenticación exige reescribir dominio/presentación.
- Extensibilidad: No hay puertos donde conectar nuevas implementaciones sin tocar el código existente.
- Desacoplamiento: La política de dominio y las consultas quedan atadas a Laravel; probar unidad exige bootstrap del framework.

**4. Severidad Asignada:** Media
Justificación: Estructuralmente el código es "Laravel-puro" (correcto para una app pequeña), pero DIP no está atendido en los puntos que ya sabemos que varían: storage y autenticación.

**5. Preview de la Solución (Patrón: Ports & Adapters):**

```php
interface AgreementStorage { public function store(string $from, string $to): void; ... }  // CC-01
interface CurrentUserContext { public function id(): int|string|null; }

// Adaptadores: LocalDiskAgreementStorage, AuthUserContext
```

---

**ID de Trazabilidad:** CC-09
**Hallazgo 9:** God Page: `EditProfile` concentra perfil, credenciales, verificación de email, notificaciones y persistencia en 247 líneas

**1. Principio / Antipatrón:** SRP. Antipatrón acompañante: **God Class** (materializado como página) — un solo objeto maneja formularios, hashing, flujo de confirmación de email, persistencia y feedback.

**¿Es idiomático en Laravel/Filament?** Una página Filament con formularios y acciones es idiomática; lo que **no** es es que aquí conviven varios **casos de uso** (actualizar perfil, cambiar contraseña, gestionar verificación) en un único objeto de presentación.

**2. Evidencia en Código:**
- Clase/Archivo: `app/Filament/Pages/EditProfile.php`
- Método/Función: `save()`, `processSave()`, `confirmEmailChange()`, `sendVerificationEmail()`

```php
protected function processSave(array $data, $user, bool $emailChanged): void
{
    if (!empty($data['current_password'])) {
        if (!Hash::check($data['current_password'], $user->password)) {
            Notification::make()->danger()->title(...)->send();
            return;
        }
        if (empty($data['new_password'])) {
            Notification::make()->danger()->title(...)->send();
            return;
        }
        $user->password = Hash::make($data['new_password']);
    }

    $user->name = $data['name'];
    if ($emailChanged) {
        $user->email = $data['email'];
        $user->email_verified_at = null;
    }
    $user->document_type = $data['document_type'];
    $user->document = $data['document'];
    $user->phone_number = $data['phone_number'];
    $user->save();
    ...
}
```

**3. Impacto Concreto:**
- Flexibilidad: Cualquier cambio de política (password, verificación) obliga a tocar una clase que mezcla UI y negocio.
- Extensibilidad: 2FA, consentimientos u otras validaciones se acumulan como más métodos de la misma página.
- Desacoplamiento: Persistencia del usuario (BD), hashing y notificaciones quedan acoplados a un objeto de presentación no reutilizable.

**4. Severidad Asignada:** Media
Justificación: Es mantenible por tamaño, pero cada cambio de seguridad o de perfil exige navegar una clase con 5+ responsabilidades.

**5. Preview de la Solución (Patrón: Casos de Uso separados — `ProfileService`):**

```php
final class ProfileService {
    public function updatePersonalData(int $userId, PersonalDataDto $dto): void;
    public function changePassword(int $userId, string $current, string $new): void;
    public function applyEmailChange(int $userId, string $email): void;
}
// EditProfilePage queda como presentación: recolecta input y delega.
```

---

**ID de Trazabilidad:** CC-10
**Hallazgo 10:** Invariante de dominio duplicado en el formulario: "sin arriendo activo para el mismo tenant/propiedad" se valida campo por campo

**1. Principio / Antipatrón:** OCP — la restricción "un tenant/propiedad no puede estar en dos arriendos activos" está declarada dos veces (por `tenant_id` y por `property_id`) en el mismo formulario, encerrada en la capa de presentación.

**¿Es idiomático en Laravel/Filament?** La validación con `Rule::unique` en el formulario es idiomática; el defecto es **duplicar la invariante** por campo y encerrarla en la UI, dejando huecos si el `Rental` se crea desde otro flujo (CC-02).

**2. Evidencia en Código:**
- Clase/Archivo: `app/Filament/Resources/RentalResource.php`
- Método/Función: `form()`

```php
Forms\Components\Select::make('tenant_id')
    ->relationship('tenant', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        Rule::unique('rentals', 'tenant_id')->where('is_active', true)->ignore($record?->id),
    ]),
Forms\Components\Select::make('property_id')
    ->relationship('property', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        Rule::unique('rentals', 'property_id')->where('is_active', true)->ignore($record?->id),
    ]),
```

**3. Impacto Concreto:**
- Flexibilidad: Ajustar la regla (arriendos compartidos, tolerancias) obliga a editar ambas declaraciones.
- Extensibilidad: Cada flujo nuevo debe re-copiar el `Rule::unique(...)->where(...)`.
- Desacoplamiento: La integridad del negocio depende de reglas de la UI; insertar `Rental` por API/consola salta la invariante.

**4. Severidad Asignada:** Baja
Justificación: Cumple en el flujo actual, pero duplica una regla de negocio pura en la capa de formulario y es frágil ante nuevas fuentes de creación.

**5. Preview de la Solución (Patrón: Regla de dominio reutilizable):**

```php
final class UniqueActiveRentalRule implements ValidationRule {
    public function __construct(private string $field) {} // 'tenant_id' | 'property_id'
    public function passes(string $attribute, mixed $value): bool { /* consulta centralizada */ }
}
// formulario y RentalService (CC-02) consumen la misma regla.
```

---

# TAREA 2 — Control de Cambios (Tabla Sintética)

| ID | Archivo / Clase Modificada | Fallo SOLID / Antipatrón | Descripción del Problema (1-3 oraciones) | Mejora Aplicada Propuesta (Patrón / Principio) | Severidad (A/M/B) |
|---|---|---|---|---|---|
| CC-01 | `app/Models/Rental.php` | SRP / God Class incipiente | El modelo mueve y borra archivos del contrato en hooks Eloquent, acoplado a `Storage::disk('public')` y a la convención `temp/uploads` de la UI. | `AgreementStorageService` (infraestructura); los eventos solo orquestan. | Alta |
| CC-02 | `CreateRental.php` | SRP / God Method | La página genera el plan mensual de pagos, persiste y notifica, enterrando el proceso de negocio en la presentación. | `PaymentPlanService` (Caso de Uso) inyectado; página delgada. | Alta |
| CC-03 | `RentalResource.php` + `CreateRental` + `EditRental` | OCP / Shotgun Surgery | `end_date = start + meses` está copiado en 2 closures y 2 `mutateFormData*`. | Value Object `RentalPeriod` con `endDate()` única fuente. | Media |
| CC-04 | `app/Models/Payment.php` | Primitive Obsession (estado) | El estado de pago son 4 booleanos crudos y cada consumidor interpreta "pagado/vencido" a su manera. | Enum `PaymentStatus` + `Payment::status()` derivado. | Alta |
| CC-08 | `app/Models/Rental.php`, Resources | DIP | Dominio/presentación dependen de facades y consultas concretas (`Storage`, `Auth`, Eloquent) sin contratos propios. | Ports & Adapters (`AgreementStorage`, `CurrentUserContext`). | Media |
| CC-09 | `app/Filament/Pages/EditProfile.php` | SRP / God Page | Página de 247 líneas con perfil, credenciales, verificación de email, notificaciones y persistencia. | `ProfileService` con casos de uso separados; página solo UI. | Media |
| CC-10 | `RentalResource.php` | OCP / regla de negocio en la UI | El invariante "único arriendo activo por tenant/propiedad" está duplicado campo por campo en el formulario. | `UniqueActiveRentalRule` (Rule centralizada) reutilizable. | Baja |

**Resumen de severidad:** 3 hallazgos **Altos** (CC-01, CC-02, CC-04) · 3 **Medios** (CC-03, CC-08, CC-09) · 1 **Bajo** (CC-10). Total: 7.

> **Prioridad sugerida de corrección:** 1) Núcleo del dominio de cobros — CC-04 y CC-02. 2) SRP/DIP de infraestructura — CC-01, CC-08 y CC-09. 3) OCP/duplicación — CC-03 y CC-10. La solución completa de cada preview se desarrolla en las entregas subsiguientes.

---

*Documento v5 generado para la asignatura **Patrones de Diseño** — Tarea 1 (Diagnóstico SOLID/Antipatrones) y Tarea 2 (Control de Cambios). Criterio aplicado: hallazgos SOLID clásicos sobre entidades de dominio, Resources y Pages; se retiran CC-05 y CC-11 (widgets, periféricos al ejercicio), CC-07 (política de tenencia fija en este dominio) y CC-06 (ya retirado en v4, uso idiomático de Filament).*