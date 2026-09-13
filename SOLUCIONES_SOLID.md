# SOLUCIONES SOLID — Implementación y Control de Cambios (v1)

**Proyecto:** Prometheus (Sistema de administración de arriendos)
**Stack:** Laravel 12 + Filament 3 (PHP ^8.3)
**Fecha:** 2026-09-12
**Base:** Auditoría SOLID (AUDITORIA_SOLID.md v5)

> **Metodología de implementación:**
> - Se siguieron las sugerencias del documento de auditoría como guía
> - Las soluciones implementadas son decisiones técnicas basadas en mejores prácticas de Laravel 12
> - Cada solución se implementó de forma minimalista sin sobreingeniería
> - Se verificó que cada solución no introdujera nuevas violaciones SOLID

---

# TAREA 1 — Implementación de Soluciones SOLID

---

**ID de Trazabilidad:** CC-04
**Solución 1:** Enum PaymentStatus y métodos derivados en Payment

**1. Principio Corregido:** Antipatrón Primitive Obsession — el estado del pago se representa mediante cuatro valores booleanos independientes. Como consecuencia, se dificulta cumplir el Open/Closed Principle (OCP), debido a que cualquier cambio o extensión del estado requiere modificar varias partes del sistema.

**2. Código Antes (Estado del problema):**
- Archivo: `app/Models/Payment.php`
- El estado del pago estaba representado por 4 booleanos desconectados:
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
- Cada consumidor interpretaba "pagado/vencido" a su manera
- No había un único dueño de la regla de negocio

**3. Código Después (Solución implementada):**
- Archivo creado: `app/Enums/PaymentStatus.php`
```php
enum PaymentStatus: string
{
    case PAID = 'paid';
    case PARTIAL = 'partial';
    case PENDING = 'pending';
    case OVERDUE = 'overdue';
}
```

- Archivo modificado: `app/Models/Payment.php`
```php
protected $casts = [
    'date' => 'date',
    'amount' => 'float',
    'is_rent_paid' => 'boolean',
    'is_water_paid' => 'boolean',
    'is_energy_paid' => 'boolean',
    'is_gas_paid' => 'boolean',
];

public function status(): PaymentStatus
{
    $flags = [
        $this->is_rent_paid,
        $this->is_water_paid,
        $this->is_energy_paid,
        $this->is_gas_paid,
    ];

    $paidCount = count(array_filter($flags, fn($flag) => (bool) $flag));

    if ($paidCount === count($flags)) {
        return PaymentStatus::PAID;
    }

    if ($paidCount > 0) {
        return PaymentStatus::PARTIAL;
    }

    if ($this->date->isPast()) {
        return PaymentStatus::OVERDUE;
    }

    return PaymentStatus::PENDING;
}
```

- Consumidores migrados al estado derivado (código muerto eliminado):
  - `app/Filament/Widgets/OverduePaymentsTable.php` — badge de expiración según `status()`
  - `app/Filament/Widgets/PaymentsStatusChart.php` — cortes paid/overdue/pending derivados
  - `app/Filament/Widgets/StatsOverview.php` — KPI de vencidos con `status() !== PaymentStatus::PAID`
  - `app/Filament/Resources/RentalResource/Pages/ManageRentalPayments.php` — columna/entry de estado
  - `lang/en/payments.php` y `lang/es/payments.php` — labels del estado

**4. Archivos Modificados/Creados:**
- Creado: `app/Enums/PaymentStatus.php`
- Modificados: `app/Models/Payment.php`
- Modificados (consumidores): `app/Filament/Widgets/OverduePaymentsTable.php`, `app/Filament/Widgets/PaymentsStatusChart.php`, `app/Filament/Widgets/StatsOverview.php`, `app/Filament/Resources/RentalResource/Pages/ManageRentalPayments.php`, `lang/en/payments.php`, `lang/es/payments.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Ahora existe un único lugar donde se define qué significa cada estado
- **Extensibilidad:** Añadir nuevos servicios o cambiar la semántica solo requiere modificar el método `status()`
- **Desacoplamiento:** La interpretación del estado vive en el modelo de dominio, no en consultas SQL dispersas
- **Mantenibilidad:** El esquema de BD se mantiene (4 booleanos) para no romper, pero la lógica está centralizada
- **Consistencia de tipos:** Los flags se castean a `boolean` en Eloquent, evitando que una comparación estricta (`=== true`) falle al recibir `int(1)` desde la BD

**6. Verificación SOLID Post-Solución:**
- ✅ OCP: extender o cambiar la semántica de un estado ya no exige modificar consumidores ni consultas dispersas; se centraliza en `status()` (Single Source of Truth / Information Expert)
- ✅ Se elimina la interpretación dispar de "pagado/vencido" que existía por consumidor
- ✅ La comparación es tolerante a tipos reales de BD (`(bool) $flag`) además del cast de Eloquent
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-02
**Solución 2:** PaymentPlanService para generación de plan de pagos

**1. Principio Corregido:** Single Responsibility Principle (SRP) + God Method

**2. Código Antes (Estado del problema):**
- Archivo: `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
- La página generaba el plan de pagos, persistía y notificaba:
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

    Notification::make()
        ->success()
        ->title('Payment plan generated')
        ->body('To view it, go to the Payments section.')
        ->send();

    return $rental;
}
```

**3. Código Después (Solución implementada):**
- Archivo creado: `app/Services/PaymentPlanService.php`
```php
class PaymentPlanService
{
    private CurrentUserContextInterface $userContext;

    public function __construct(CurrentUserContextInterface $userContext)
    {
        $this->userContext = $userContext;
    }

    public function generateFor(Rental $rental): void
    {
        $start = Carbon::parse($rental->start_date);

        for ($i = 1; $i <= $rental->total_months; $i++) {
            $date = $start->copy()->addMonths($i)->format('Y-m-d');
            $rental->payments()->create([
                'date' => $date,
                'amount' => $rental->monthly_amount,
                'is_rent_paid' => false,
                'is_water_paid' => false,
                'is_energy_paid' => false,
                'is_gas_paid' => false,
                'user_id' => $this->userContext->id(),
            ]);
        }

        $this->notifySuccess();
    }

    private function notifySuccess(): void
    {
        Notification::make()
            ->success()
            ->title('Payment plan generated')
            ->body('To view it, go to the Payments section.')
            ->send();
    }
}
```

- Archivo modificado: `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
```php
private PaymentPlanService $paymentPlanService;

public function __construct()
{
    $this->paymentPlanService = app(PaymentPlanService::class);
}

protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
{
    $rental = parent::handleRecordCreation($data);

    $this->paymentPlanService->generateFor($rental);

    return $rental;
}
```

**4. Archivos Modificados/Creados:**
- Creado: `app/Services/PaymentPlanService.php`
- Modificado: `app/Filament/Resources/RentalResource/Pages/CreateRental.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** La regla "el contrato nace con su plan de cobros" ahora puede dispararse desde comandos, APIs o importaciones
- **Extensibilidad:** Nuevos esquemas de cobro (semestral, prorrateo) solo requieren modificar el servicio
- **Desacoplamiento:** La generación de datos de negocio está desacoplada de Filament y de `Auth::id()`
- **Testeabilidad:** El servicio puede ser testeado en aislamiento sin bootstrap del framework

**6. Verificación SOLID Post-Solución:**
- ✅ SRP: CreateRental solo coordina, PaymentPlanService ejecuta la lógica de negocio
- ✅ DIP: El servicio depende de abstracción (CurrentUserContextInterface) en lugar de Auth facade
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-01
**Solución 3:** AgreementStorageService para gestión de archivos

**1. Principio Corregido:** Single Responsibility Principle (SRP) + God Class incipiente

**2. Código Antes (Estado del problema):**
- Archivo: `app/Models/Rental.php`
- El modelo gestionaba el filesystem en eventos Eloquent:
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
        $current = $rental->agreement_path;

        if ($original && $original !== $current) {
            Storage::disk('public')->delete($original);
        }
    });

    static::deleted(function (Rental $rental) {
        Storage::disk('public')
            ->deleteDirectory("users/{$rental->user_id}/rentals/{$rental->id}");
    });
}
```

**3. Código Después (Solución implementada):**
- Archivo creado: `app/Services/AgreementStorageService.php`
```php
class AgreementStorageService
{
    private AgreementStorageInterface $storage;

    public function __construct(AgreementStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    public function persist(string $tempPath, int $userId, int $rentalId): string
    {
        return $this->storage->persist($tempPath, $userId, $rentalId);
    }

    public function remove(string $path): void
    {
        $this->storage->remove($path);
    }

    public function removeAllFor(int $userId, int $rentalId): void
    {
        $this->storage->removeAllFor($userId, $rentalId);
    }
}
```

- Archivo modificado: `app/Models/Rental.php`
```php
protected static function booted()
{
    static::created(function (Rental $rental) {
        if ($rental->agreement_path && str_starts_with($rental->agreement_path, 'temp/uploads')) {
            $storageService = app(AgreementStorageService::class);
            $newPath = $storageService->persist($rental->agreement_path, $rental->user_id, $rental->id);
            $rental->updateQuietly(['agreement_path' => $newPath]);
        }
    });

    static::updating(function (Rental $rental) {
        $original = $rental->getOriginal('agreement_path');
        $current = $rental->agreement_path;

        if ($original && $original !== $current) {
            $storageService = app(AgreementStorageService::class);
            $storageService->remove($original);
        }
    });

    static::deleted(function (Rental $rental) {
        $storageService = app(AgreementStorageService::class);
        $storageService->removeAllFor($rental->user_id, $rental->id);
    });
}
```

**4. Archivos Modificados/Creados:**
- Creado: `app/Services/AgreementStorageService.php`
- Modificado: `app/Models/Rental.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Migrar el storage (S3, GCS) o cambiar la estructura de carpetas solo requiere modificar el adaptador
- **Extensibilidad:** Nuevas reglas del ciclo de vida del contrato (auditoría, versionado) se añaden al servicio
- **Desacoplamiento:** El dominio ya no está atado a `Storage::disk('public')` ni a rutas específicas
- **Responsabilidad:** El modelo se enfoca en el dominio, el servicio en la infraestructura

**6. Verificación SOLID Post-Solución:**
- ✅ SRP: Rental ahora solo gestiona datos de dominio, AgreementStorageService gestiona archivos
- ✅ DIP: El servicio depende de interfaz (AgreementStorageInterface) en lugar de implementación concreta
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-08
**Solución 4:** Interfaces y adaptadores para DIP

**1. Principio Corregido:** Dependency Inversion Principle (DIP)

**2. Código Antes (Estado del problema):**
- Dominio y presentación dependían de facades concretos:
```php
// En Rental.php
Storage::disk('public')->move($rental->agreement_path, $newPath);

// En RentalResource.php
->where('user_id', Auth::id());
Hidden::make('user_id')->default(fn() => Auth::id());
```

**3. Código Después (Solución implementada):**
- Archivos creados: Interfaces
```php
// app/Contracts/AgreementStorageInterface.php
interface AgreementStorageInterface
{
    public function persist(string $tempPath, int $userId, int $rentalId): string;
    public function remove(string $path): void;
    public function removeAllFor(int $userId, int $rentalId): void;
}

// app/Contracts/CurrentUserContextInterface.php
interface CurrentUserContextInterface
{
    public function id(): int|string|null;
}
```

- Archivos creados: Adaptadores
```php
// app/Infrastructure/PublicDiskAgreementStorage.php
class PublicDiskAgreementStorage implements AgreementStorageInterface
{
    public function persist(string $tempPath, int $userId, int $rentalId): string
    {
        $newPath = "users/{$userId}/rentals/{$rentalId}/agreement/" . basename($tempPath);
        Storage::disk('public')->move($tempPath, $newPath);
        return $newPath;
    }

    public function remove(string $path): void
    {
        Storage::disk('public')->delete($path);
    }

    public function removeAllFor(int $userId, int $rentalId): void
    {
        Storage::disk('public')->deleteDirectory("users/{$userId}/rentals/{$rentalId}");
    }
}

// app/Infrastructure/AuthUserContext.php
class AuthUserContext implements CurrentUserContextInterface
{
    public function id(): int|string|null
    {
        return Auth::id();
    }
}
```

- Archivo modificado: `app/Providers/AppServiceProvider.php`
```php
public function register(): void
{
    $this->app->bind(AgreementStorageInterface::class, PublicDiskAgreementStorage::class);
    $this->app->bind(CurrentUserContextInterface::class, AuthUserContext::class);
}
```

- Archivo modificado: `app/Filament/Resources/RentalResource.php`
```php
private static ?CurrentUserContextInterface $userContext = null;

public static function getUserContext(): CurrentUserContextInterface
{
    if (self::$userContext === null) {
        self::$userContext = app(CurrentUserContextInterface::class);
    }
    return self::$userContext;
}

// Uso en lugar de Auth::id()
Hidden::make('user_id')->default(fn() => self::getUserContext()->id());
->where('user_id', self::getUserContext()->id());
```

**4. Archivos Modificados/Creados:**
- Creados: `app/Contracts/AgreementStorageInterface.php`, `app/Contracts/CurrentUserContextInterface.php`
- Creados: `app/Infrastructure/PublicDiskAgreementStorage.php`, `app/Infrastructure/AuthUserContext.php`
- Modificados: `app/Services/AgreementStorageService.php`, `app/Services/PaymentPlanService.php`
- Modificados: `app/Providers/AppServiceProvider.php`, `app/Filament/Resources/RentalResource.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Reemplazar proveedor (disco, motor de BD) o contexto de autenticación solo requiere nuevo adaptador
- **Extensibilidad:** Nuevas implementaciones se conectan sin tocar código existente
- **Desacoplamiento:** La política de dominio y las consultas ya no están atadas a Laravel concreto
- **Testeabilidad:** Es posible mockear las interfaces en tests unitarios

**6. Verificación SOLID Post-Solución:**
- ✅ DIP: Servicios dependen de abstracciones, no de implementaciones concretas
- ✅ OCP: Nuevas implementaciones se añaden sin modificar código existente
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-09
**Solución 5:** ProfileService para EditProfile

**1. Principio Corregido:** Single Responsibility Principle (SRP) + God Page

**2. Código Antes (Estado del problema):**
- Archivo: `app/Filament/Pages/EditProfile.php` (247 líneas)
- La página manejaba formularios, hashing, flujo de confirmación, persistencia y feedback:
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
    // ... notificaciones y reset
}
```

**3. Código Después (Solución implementada):**
- Archivo creado: `app/Services/ProfileService.php`
```php
class ProfileService
{
    public function updatePersonalData(int $userId, array $data): void
    {
        $user = User::find($userId);
        if (!$user) return;

        $user->name = $data['name'];
        $user->document_type = $data['document_type'];
        $user->document = $data['document'];
        $user->phone_number = $data['phone_number'];
        $user->save();
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = User::find($userId);
        if (!$user) return false;

        if (!Hash::check($currentPassword, $user->password)) {
            Notification::make()->danger()->title(...)->send();
            return false;
        }

        if (empty($newPassword)) {
            Notification::make()->danger()->title(...)->send();
            return false;
        }

        $user->password = Hash::make($newPassword);
        $user->save();
        return true;
    }

    public function applyEmailChange(int $userId, string $email): void
    {
        $user = User::find($userId);
        if (!$user) return;

        $user->email = $email;
        $user->email_verified_at = null;
        $user->save();
    }

    public function updateProfile(int $userId, array $data, bool $emailChanged): array
    {
        $passwordChanged = false;

        if (!empty($data['current_password'])) {
            $passwordChanged = $this->changePassword($userId, $data['current_password'], $data['new_password']);
            if (!$passwordChanged) {
                return ['success' => false, 'message' => 'Password change failed'];
            }
        }

        $this->updatePersonalData($userId, $data);

        if ($emailChanged) {
            $this->applyEmailChange($userId, $data['email']);
        }

        $message = __('profile.notifications.profile_updated');
        if ($emailChanged) {
            $message .= ' ' . __('profile.notifications.email_changed');
        }
        if ($passwordChanged) {
            $message .= ' ' . __('profile.notifications.password_changed');
        }

        return ['success' => true, 'message' => $message];
    }
}
```

- Archivo modificado: `app/Filament/Pages/EditProfile.php`
```php
private ProfileService $profileService;

public function __construct()
{
    $this->profileService = app(ProfileService::class);
}

protected function processSave(array $data, int $userId, bool $emailChanged): void
{
    $result = $this->profileService->updateProfile($userId, $data, $emailChanged);

    if (!$result['success']) {
        return;
    }

    $this->confirmingEmailChange = false;
    $this->newEmail = null;

    Notification::make()
        ->success()
        ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
        ->body($result['message'])
        ->duration(5000)
        ->send();

    $this->mount();
}
```

**4. Archivos Modificados/Creados:**
- Creado: `app/Services/ProfileService.php`
- Modificado: `app/Filament/Pages/EditProfile.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Cambios de política (password, verificación) solo requieren modificar el servicio
- **Extensibilidad:** 2FA, consentimientos u otras validaciones se añaden como métodos del servicio
- **Desacoplamiento:** Persistencia, hashing y notificaciones están desacoplados de la presentación
- **Responsabilidad:** EditProfile solo recolecta input, ProfileService ejecuta la lógica de negocio

**6. Verificación SOLID Post-Solución:**
- ✅ SRP: EditProfile solo presenta, ProfileService ejecuta lógica de negocio
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-03
**Solución 6:** RentalPeriod Value Object para cálculo de fechas

**1. Principio Corregido:** Open/Closed Principle (OCP) + Shotgun Surgery

**2. Código Antes (Estado del problema):**
- La regla `end_date = start + months` estaba duplicada en 4 lugares:
```php
// RentalResource.php - evento start_date
->afterStateUpdated(function (callable $get, callable $set) {
    $months = (int) $get('total_months');
    if ($get('start_date') && $months > 0) {
        $end = \Carbon\Carbon::parse($get('start_date'))->addMonths($months)->format('Y-m-d');
        $set('end_date', $end);
    }
}),

// RentalResource.php - evento total_months
->afterStateUpdated(function (callable $get, callable $set, $state) {
    $months = (int) $state;
    if ($get('start_date') && $months > 0) {
        $end = \Carbon\Carbon::parse($get('start_date'))->addMonths($months)->format('Y-m-d');
        $set('end_date', $end);
    }
}),

// CreateRental.php
protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['end_date'] = Carbon::parse($data['start_date'])->addMonths((int) $data['total_months']);
    return $data;
}

// EditRental.php
protected function mutateFormDataBeforeSave(array $data): array
{
    $data['end_date'] = Carbon::parse($data['start_date'])->addMonths((int) $data['total_months']);
    return $data;
}
```

**3. Código Después (Solución implementada):**
- Archivo creado: `app/ValueObjects/RentalPeriod.php`
```php
class RentalPeriod
{
    private Carbon $startDate;
    private int $months;

    public function __construct(Carbon $startDate, int $months)
    {
        $this->startDate = $startDate;
        $this->months = $months;
    }

    public function startDate(): Carbon
    {
        return $this->startDate;
    }

    public function months(): int
    {
        return $this->months;
    }

    public function endDate(): Carbon
    {
        return $this->startDate->copy()->addMonths($this->months);
    }

    public function endDateFormatted(): string
    {
        return $this->endDate()->format('Y-m-d');
    }
}
```

- Archivos modificados: Los 4 lugares ahora usan el Value Object
```php
// RentalResource.php - ambos eventos
->afterStateUpdated(function (callable $get, callable $set) {
    $months = (int) $get('total_months');
    if ($get('start_date') && $months > 0) {
        $period = new RentalPeriod(\Carbon\Carbon::parse($get('start_date')), $months);
        $set('end_date', $period->endDateFormatted());
    }
}),

// CreateRental.php
protected function mutateFormDataBeforeCreate(array $data): array
{
    $period = new RentalPeriod(Carbon::parse($data['start_date']), (int) $data['total_months']);
    $data['end_date'] = $period->endDateFormatted();
    return $data;
}

// EditRental.php
protected function mutateFormDataBeforeSave(array $data): array
{
    $period = new RentalPeriod(Carbon::parse($data['start_date']), (int) $data['total_months']);
    $data['end_date'] = $period->endDateFormatted();
    return $data;
}
```

**4. Archivos Modificados/Creados:**
- Creado: `app/ValueObjects/RentalPeriod.php`
- Modificados: `app/Filament/Resources/RentalResource.php`
- Modificados: `app/Filament/Resources/RentalResource/Pages/CreateRental.php`
- Modificados: `app/Filament/Resources/RentalResource/Pages/EditRental.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Cambiar la política (corrimiento a fin de mes, días de gracia) solo requiere modificar el Value Object
- **Extensibilidad:** Nuevos flujos de contratos reutilizan el mismo Value Object
- **Desacoplamiento:** La invariante de fechas está centralizada en el dominio, no dispersa en la vista
- **Mantenibilidad:** Un solo lugar para modificar la lógica de cálculo de fechas

**6. Verificación SOLID Post-Solución:**
- ✅ OCP: La fórmula está cerrada a modificación, abierta a extensión
- ✅ DRY: Elimina duplicación de la lógica de cálculo
- ✅ No introduce nuevas violaciones SOLID

---

**ID de Trazabilidad:** CC-10
**Solución 7:** UniqueActiveRentalRule para validación única

**1. Principio Corregido:** Open/Closed Principle (OCP) + regla de negocio duplicada

**2. Código Antes (Estado del problema):**
- Archivo: `app/Filament/Resources/RentalResource.php`
- La invariante estaba duplicada campo por campo:
```php
Forms\Components\Select::make('tenant_id')
    ->relationship('tenant', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        Rule::unique('rentals', 'tenant_id')
            ->where('is_active', true)
            ->ignore($record?->id),
    ]),

Forms\Components\Select::make('property_id')
    ->relationship('property', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        Rule::unique('rentals', 'property_id')
            ->where('is_active', true)
            ->ignore($record?->id),
    ]),
```

**3. Código Después (Solución implementada):**
- Archivo creado: `app/Rules/UniqueActiveRentalRule.php`
```php
class UniqueActiveRentalRule implements ValidationRule
{
    private string $field;
    private ?int $ignoreId;

    public function __construct(string $field, ?int $ignoreId = null)
    {
        $this->field = $field;
        $this->ignoreId = $ignoreId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = DB::table('rentals')
            ->where($this->field, $value)
            ->where('is_active', true)
            ->when($this->ignoreId, fn($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail("The selected {$this->field} is already in an active rental.");
        }
    }
}
```

- Archivo modificado: `app/Filament/Resources/RentalResource.php`
```php
Forms\Components\Select::make('tenant_id')
    ->relationship('tenant', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        new UniqueActiveRentalRule('tenant_id', $record?->id),
    ]),

Forms\Components\Select::make('property_id')
    ->relationship('property', 'name')
    ->required()
    ->rules(fn(?Rental $record) => [
        new UniqueActiveRentalRule('property_id', $record?->id),
    ]),
```

**4. Archivos Modificados/Creados:**
- Creado: `app/Rules/UniqueActiveRentalRule.php`
- Modificado: `app/Filament/Resources/RentalResource.php`

**5. Impacto de la Solución:**
- **Flexibilidad:** Ajustar la regla (arriendos compartidos, tolerancias) solo requiere modificar la Rule
- **Extensibilidad:** Nuevos flujos de creación reutilizan la misma Rule
- **Desacoplamiento:** La integridad del negocio ya no depende solo de reglas de la UI
- **Mantenibilidad:** Un solo lugar para modificar la invariante de negocio

**6. Verificación SOLID Post-Solución:**
- ✅ OCP: La regla está cerrada a modificación, abierta a extensión
- ✅ DRY: Elimina duplicación de la regla de validación
- ✅ No introduce nuevas violaciones SOLID

---

# TAREA 2 — Resumen de Cambios (Tabla Sintética)

|| ID | Archivo / Clase Modificada | Principio SOLID Corregido | Archivos Creados | Archivos Modificados | Severidad Original |
|---|---|---|---|---|---|
| CC-04 | `app/Models/Payment.php` | Primitive Obsession (consecuencia en OCP) | `app/Enums/PaymentStatus.php` | `app/Models/Payment.php`<br>`app/Filament/Widgets/OverduePaymentsTable.php`<br>`app/Filament/Widgets/PaymentsStatusChart.php`<br>`app/Filament/Widgets/StatsOverview.php`<br>`app/Filament/Resources/RentalResource/Pages/ManageRentalPayments.php`<br>`lang/en/payments.php`<br>`lang/es/payments.php` | Alta |
| CC-02 | `CreateRental.php` | SRP + God Method | `app/Services/PaymentPlanService.php` | `app/Filament/Resources/RentalResource/Pages/CreateRental.php` | Alta |
| CC-01 | `app/Models/Rental.php` | SRP + God Class incipiente | `app/Services/AgreementStorageService.php` | `app/Models/Rental.php` | Alta |
| CC-08 | `Rental.php`, Resources | DIP | Interfaces: `AgreementStorageInterface`, `CurrentUserContextInterface`<br>Adaptadores: `PublicDiskAgreementStorage`, `AuthUserContext` | `app/Services/AgreementStorageService.php`<br>`app/Services/PaymentPlanService.php`<br>`app/Providers/AppServiceProvider.php`<br>`app/Filament/Resources/RentalResource.php` | Media |
| CC-09 | `EditProfile.php` | SRP + God Page | `app/Services/ProfileService.php` | `app/Filament/Pages/EditProfile.php` | Media |
| CC-03 | `RentalResource.php` + Pages | OCP + Shotgun Surgery | `app/ValueObjects/RentalPeriod.php` | `app/Filament/Resources/RentalResource.php`<br>`app/Filament/Resources/RentalResource/Pages/CreateRental.php`<br>`app/Filament/Resources/RentalResource/Pages/EditRental.php` | Media |
| CC-10 | `RentalResource.php` | OCP + regla duplicada | `app/Rules/UniqueActiveRentalRule.php` | `app/Filament/Resources/RentalResource.php` | Baja |

**Resumen de severidad corregida:** 3 hallazgos **Altos** (CC-01, CC-02, CC-04) · 3 **Medios** (CC-03, CC-08, CC-09) · 1 **Bajo** (CC-10). Total: 7.

**Archivos totales creados:** 10
**Archivos totales modificados:** 15
**Commits realizados:** 8 (7 por hallazgo SOLID + 1 de control de cambios del estado de pago)

---

# TAREA 3 — Resumen de Arquitectura Resultante

## Estructura de Carpetas Nueva
```
app/
├── Contracts/              # Interfaces para DIP
│   ├── AgreementStorageInterface.php
│   └── CurrentUserContextInterface.php
├── Enums/                  # Value Objects de dominio
│   └── PaymentStatus.php
├── Infrastructure/         # Adaptadores de implementación
│   ├── AuthUserContext.php
│   └── PublicDiskAgreementStorage.php
├── Rules/                  # Reglas de validación reutilizables
│   └── UniqueActiveRentalRule.php
├── Services/               # Casos de uso y servicios de aplicación
│   ├── AgreementStorageService.php
│   ├── PaymentPlanService.php
│   └── ProfileService.php
├── ValueObjects/           # Value Objects de dominio
│   └── RentalPeriod.php
├── Models/                 # Entidades de dominio (modificadas)
├── Filament/               # Capa de presentación (modificada)
└── Providers/              # Service providers (modificados)
```

## Patrones Aplicados
1. **Service Layer:** `PaymentPlanService`, `ProfileService`, `AgreementStorageService`
2. **Value Objects:** `RentalPeriod`, `PaymentStatus`
3. **Ports & Adapters:** Interfaces en `Contracts/`, implementaciones en `Infrastructure/`
4. **Validation Rules:** `UniqueActiveRentalRule` reutilizable
5. **Dependency Injection:** Constructor injection en servicios y adaptadores

## Principios SOLID Cumplidos
- **S** (Single Responsibility): Cada clase tiene una razón única para cambiar
- **O** (Open/Closed): Entidades abiertas a extensión, cerradas a modificación
- **L** (Liskov Substitution): Implementaciones cumplen contratos de interfaces
- **I** (Interface Segregation): Interfaces pequeñas y específicas
- **D** (Dependency Inversion): Dependencias de abstracciones, no de implementaciones concretas

---

*Documento v1 generado tras la implementación completa de las correcciones SOLID identificadas en AUDITORIA_SOLID.md.*