# Plan de Mejoras - Prometheus (ESTADO ACTUALIZADO)

Este documento describe mejoras que **no modifican el flujo de negocio** pero elevan la calidad, profesionalismo y experiencia de usuario del sistema.

**Principios de Diseño:**
- ✅ Estilos acordes a la app (Colores ámbar del logo)
- ✅ Gama de colores consistente y profesional
- ✅ Diseño clean, sin excesos
- ✅ NO romper los patrones existentes (mejorar si es posible)
- ✅ Documentos que se sientan como tales (factura = factura, comprobante = comprobante)
- ✅ Nombre de empresa prominentes
- ✅ Presentación profesional y moderna tipo factura de servicios públicos
- ✅ Fuentes más grandes y legibles
- ✅ Información detallada del cliente, contrato, propiedad, facturación
- ✅ Secciones de estado con color completo según estado

---

## IMPLEMENTACIÓN COMPLETADA

### 1. PDFs Profesionales - ✅ COMPLETADO

### Estado Actual ✅ IMPLEMENTADO
- Usa FlexPDF con plantillas Blade específicas por tipo de documento
- Plantillas profesionales con diseño tipo factura de servicios públicos
- Fuente Times New Roman (serif) para cuerpo, Arial (sans-serif) para títulos
- Colores corporativos: Ámbar (#f59e0b) basado en logo de la app
- Secciones de estado con color completo (emitido/pagado/vencido/anulado)
- Información detallada del cliente, contrato, propiedad, facturación
- Logo removido (no carga correctamente en FlexPDF con asset())
- Números de página no implementados
- Distingue visualmente entre tipos de documento

### 1.1 Sistema de Colores Corporativos

**Paleta de colores actual (basado en logo ámbar):**
- Primary: `#f59e0b` (amber-500) - Headers, bordes, tablas
- Primary Dark: `#b45309` (amber-700) - Títulos
- Success: `#10b981` (emerald-500) - Estados positivos
- Warning: `#f59e0b` (amber-500) - Alertas
- Danger: `#dc2626` (red-600) - Estados negativos/vencidos
- Gray: `#6b7280` (gray-500) - Textos secundarios
- Background: `#f9fafb` (gray-50) - Fondos claros

**Nota:** Se decidió usar el color ámbar del logo en lugar de los colores de Filament por solicitud del usuario.

### 1.2 Plantillas Específicas por Tipo de Documento ✅ IMPLEMENTADO

Cada documento tiene su propia plantilla Blade con diseño profesional:

#### 1.2.1 Factura de Arrendamiento (RentInvoice)
**Archivo:** `resources/views/documents/invoice.blade.php` (nuevo)

**Diseño:**
- Encabezado grande con logo a la izquierda, datos del negocio a la derecha
- Sección "FACTURAR A" con datos del inquilino destacados
- Tabla de ítems con líneas por cada concepto
- Sección de totales con gradiente sutil
- Pie de página con condiciones de pago
- Color de estado: EMITIDA (blue), PAGADA (green), VENCIDA (red), ANULADA (gray)

**Estructura visual:**
```
┌─────────────────────────────────────────────────────────────┐
│ [LOGO]                    FACTURA DE VENTA #0001-2026      │
│                           Fecha: 01/01/2026                │
├─────────────────────────────────────────────────────────────┤
│ PROMETHEUS ARRENDAMIENTOS S.A.S.                            │
│ NIT: 900.123.456-7                                          │
│ Calle 123 #45-67, Bogotá D.C.                                │
│ Tel: (601) 123-4567 | Email: info@prometheus.co             │
├─────────────────────────────────────────────────────────────┤
│ FACTURAR A                                                  │
│ ──────────────────────────────────────────────────────────── │
│ Cliente: Juan Pérez                                         │
│ Inmueble: Apartamento 301, Torre A                          │
│ Dirección: Calle 456 #78-90, Bogotá D.C.                     │
│ Tel: (310) 123-4567                                         │
├─────────────────────────────────────────────────────────────┤
│ DETALLE DE LA FACTURA                                       │
│ ┌──────────────┬──────────────┬──────────────┬──────────────┐│
│ │ Concepto     │ Periodo      │ Cantidad     │ Valor        ││
│ ├──────────────┼──────────────┼──────────────┼──────────────┤│
│ │ Arrendamiento│ 2026-01      │ 1 mes        │ $1.000.000   ││
│ └──────────────┴──────────────┴──────────────┴──────────────┘│
│                                                              │
│ Subtotal:                                    $1.000.000    │
│ Total:                                       $1.000.000    │
├─────────────────────────────────────────────────────────────┤
│ INFORMACIÓN DE PAGO                                         │
│ Método de pago preferido: Transferencia bancaria             │
│ Banco: Nequi/Bancolombia                                     │
│ Cuenta: 123-456789-0                                        │
│ Vence: 05/01/2026                                           │
├─────────────────────────────────────────────────────────────┤
│ Estado: [●] EMITIDA                                          │
│                                                              │
│ Condiciones de pago: Pago dentro de los 5 días hábiles      │
│ después de la fecha de emisión. Pasado este plazo, se       │
│ aplicará interés de mora del 1% mensual.                    │
├─────────────────────────────────────────────────────────────┤
│ Página 1 de 1  │  Generado por Prometheus  │  01/01/2026    │
└─────────────────────────────────────────────────────────────┘
```

#### 1.2.2 Comprobante de Pago (PaymentReceipt)
**Archivo:** `resources/views/documents/receipt.blade.php` (nuevo)

**Diseño:**
- Encabezado compacto con marca de "COMPROBANTE"
- Bandera verde indicando "PAGADO"
- Tabla de pagos con timeline visual
- Total en grande con borde verde
- Aviso legal sobre que no sustituye factura electrónica

**Estructura visual:**
```
┌─────────────────────────────────────────────────────────────┐
│                    COMPROBANTE DE PAGO                      │
│                    #REC-0001-2026                            │
├─────────────────────────────────────────────────────────────┤
│ ✓  FACTURA PAGADA COMPLETAMENTE                            │
│                                                              │
│ Referencia: Factura #0001-2026                              │
│ Fecha de pago: 05/01/2026                                   │
├─────────────────────────────────────────────────────────────┤
│ DATOS DE LA FACTURA                                        │
│ Concepto: Arrendamiento                                      │
│ Periodo: 2026-01                                             │
│ Total facturado:                      $1.000.000             │
│ Total abonado:                        $1.000.000             │
│ Saldo pendiente:                      $       0             │
├─────────────────────────────────────────────────────────────┤
│ DETALLE DE ABONOS                                            │
│ ┌──────────────┬──────────────┬──────────────┬──────────────┐│
│ │ Fecha        │ Abono        │ Método       │ Referencia   ││
│ ├──────────────┼──────────────┼──────────────┼──────────────┤│
│ │ 01/01/2026   │ $500.000     │ Efectivo     │ #123         ││
│ │ 03/01/2026   │ $500.000     │ Transferencia│ #456         ││
│ └──────────────┴──────────────┴──────────────┴──────────────┘│
├─────────────────────────────────────────────────────────────┤
│                       TOTAL PAGADO: $1.000.000                │
├─────────────────────────────────────────────────────────────┤
│ AVISO LEGAL                                                 │
│ Este comprobante acredita el pago de la factura #0001-2026 │
│ y no sustituye la factura electrónica de venta autorizada   │
│ por la DIAN. Para efectos tributarios, use la factura       │
│ electrónica.                                                │
├─────────────────────────────────────────────────────────────┤
│ Página 1 de 1  │  Generado por Prometheus  │  05/01/2026    │
└─────────────────────────────────────────────────────────────┘
```

#### 1.2.3 Carta de Reajuste (AdjustmentLetter)
**Archivo:** `resources/views/documents/adjustment-letter.blade.php` (nuevo)

**Diseño:**
- Formato de carta formal
- Encabezado con "CARTA DE REAJUSTE"
- Datos del IPC con fuente oficial
- Cálculo del incremento detallado
- Aviso del tope legal (art 20 Ley 820)
- Espacio para firmas

#### 1.2.4 Estado de Cuenta (MonthlyStatement)
**Archivo:** `resources/views/documents/statement.blade.php` (nuevo)

**Diseño:**
- Tabla de todas las facturas del período
- Columnas de estado con badges de color
- Resumen de totales por estado
- Balance general

### 1.3 CSS Profesional para PDF

**Archivo:** `resources/css/pdf.css` (nuevo)

```css
/* Variables CSS basadas en colores de Filament */
:root {
    --color-primary: #3b82f6;
    --color-secondary: #6366f1;
    --color-success: #10b981;
    --color-warning: #f59e0b;
    --color-danger: #ef4444;
    --color-gray: #6b7280;
    --color-gray-light: #f3f4f6;
    --color-border: #e5e7eb;
}

/* Tipografía */
@font-face {
    font-family: 'Inter';
    src: url('/fonts/inter.woff2') format('woff2');
}

body {
    font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.5;
    color: #1f2937;
    background: white;
}

/* Contenedor principal */
.document-container {
    max-width: 210mm;
    margin: 0 auto;
    padding: 20mm;
}

/* Encabezado */
.document-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--color-primary);
}

.document-header-left {
    flex: 1;
}

.document-header-right {
    text-align: right;
}

.document-logo {
    max-height: 50px;
    max-width: 180px;
}

.document-title {
    font-size: 18pt;
    font-weight: 700;
    color: var(--color-primary);
    margin: 8px 0 4px;
}

.document-subtitle {
    font-size: 9pt;
    color: var(--color-gray);
}

/* Secciones */
.document-section {
    margin-bottom: 20px;
}

.document-section-title {
    font-size: 11pt;
    font-weight: 600;
    color: var(--color-gray);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--color-border);
}

/* Info box para "FACTURAR A" */
.document-info-box {
    background: var(--color-gray-light);
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 16px;
}

.document-info-box .info-label {
    font-size: 8pt;
    color: var(--color-gray);
    text-transform: uppercase;
}

.document-info-box .info-value {
    font-size: 10pt;
    font-weight: 500;
    color: #1f2937;
    margin-top: 2px;
}

/* Tablas */
.document-table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
}

.document-table th {
    background: var(--color-primary);
    color: white;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 9pt;
    text-transform: uppercase;
}

.document-table td {
    border: 1px solid var(--color-border);
    padding: 10px 12px;
    font-size: 10pt;
}

.document-table tr:nth-child(even) {
    background: var(--color-gray-light);
}

.document-table .text-right {
    text-align: right;
}

.document-table .text-center {
    text-align: center;
}

/* Badges de estado */
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 8pt;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.emitida {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge.pagada {
    background: #d1fae5;
    color: #065f46;
}

.status-badge.vencida {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.anulada {
    background: #f3f4f6;
    color: #4b5563;
}

/* Totales */
.document-totals {
    margin-top: 16px;
}

.document-total-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    font-size: 10pt;
}

.document-total-row.final {
    border-top: 2px solid var(--color-primary);
    padding-top: 12px;
    margin-top: 8px;
}

.document-total-label {
    color: var(--color-gray);
}

.document-total-value {
    font-weight: 600;
}

.document-total-value.final {
    font-size: 14pt;
    font-weight: 700;
    color: var(--color-primary);
}

/* Bandera de estado */
.status-banner {
    background: var(--color-success);
    color: white;
    padding: 12px 16px;
    text-align: center;
    font-weight: 600;
    margin-bottom: 16px;
    border-radius: 4px;
}

.status-banner.warning {
    background: var(--color-warning);
}

.status-banner.danger {
    background: var(--color-danger);
}

/* Pie de página */
.document-footer {
    border-top: 1px solid var(--color-border);
    padding-top: 12px;
    margin-top: 32px;
    font-size: 8pt;
    color: var(--color-gray);
    display: flex;
    justify-content: space-between;
}

.document-footer-left {
    flex: 1;
}

.document-footer-right {
    text-align: right;
}

/* Marca de agua */
.document-watermark {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-45deg);
    font-size: 72pt;
    color: rgba(0, 0, 0, 0.05);
    font-weight: 700;
    pointer-events: none;
    z-index: 0;
}

/* Avisos legales */
.legal-notice {
    background: #fffbeb;
    border-left: 4px solid var(--color-warning);
    padding: 12px 16px;
    margin: 16px 0;
    font-size: 9pt;
    color: #92400e;
}

/* Timeline para pagos */
.payment-timeline {
    margin: 12px 0;
}

.payment-timeline-item {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
}

.payment-timeline-item:last-child {
    border-bottom: none;
}

.payment-timeline-date {
    width: 100px;
    font-weight: 500;
}

.payment-timeline-amount {
    flex: 1;
    text-align: right;
    font-weight: 600;
}
```

### 1.4 Actualizar PdfRenderer con Selección de Plantilla

**Archivo:** `app/Documents/Rendering/PdfRenderer.php`

**Mejora:** Instead of a single generic template, select based on document type.

```php
public function render(DocumentBody $body): string
{
    $settings = app(AppSettings::class);
    $document = $body->document; // Add document reference to body

    // Seleccionar plantilla según tipo de documento
    $view = match($document::class) {
        RentInvoice::class => 'documents.invoice',
        PaymentReceipt::class => 'documents.receipt',
        AdjustmentLetter::class => 'documents.adjustment-letter',
        MonthlyStatement::class => 'documents.statement',
        default => 'documents.document',
    };

    return Pdf::loadView($view, [
        'body' => $body,
        'document' => $document,
        'settings' => $settings,
    ])
    ->setPaper('a4')
    ->setOrientation('portrait')
    ->setMargins([15, 15, 15, 15]) // Márgenes profesionales
    ->setOptions([
        'isRemoteEnabled' => false,
        'isHtml5ParserEnabled' => true,
        'defaultFont' => 'Inter',
        'dpi' => 150,
        'fontDir' => public_path('fonts'),
    ])
    ->output();
}
```

### 1.5 Extender DocumentBody con Referencia al Documento

**Archivo:** `app/Documents/Rendering/DocumentBody.php`

**Mejora:** Add document reference for template selection.

```php
class DocumentBody
{
    private ?object $document = null;

    public function withDocument(object $document): self
    {
        $this->document = $document;
        return $this;
    }

    public function document(): ?object
    {
        return $this->document;
    }
}
```

### 1.6 Actualizar Productos para Asignar Documento

**Archivos:** `RentInvoice.php`, `PaymentReceipt.php`, etc.

```php
// En buildBody()
$body = self::buildBody($invoice)
    ->withDocument($this); // Agregar referencia
```

---

## 2. Plantilla de Texto Mejorada - Prioridad Media

### Estado Actual
- Texto plano con alineación básica
- Sin separación visual clara
- No distingue tipos de documento

### Mejoras Propuestas

**Archivo:** `app/Documents/Rendering/PlainTextRenderer.php`

**Cambios:**
- Bordes ASCII más elaborados (╔ ═ ╗ ║ ╚ ╝)
- Secciones numeradas con estilo
- Formato de moneda alineado a derecha
- Diferenciación por tipo de documento
- Header con logo ASCII si está configurado

**Ejemplo de salida para Comprobante:**
```
╔════════════════════════════════════════════════════════════════╗
║                    COMPROBANTE DE PAGO                          ║
║                    #REC-0001-2026                              ║
╚════════════════════════════════════════════════════════════════╝

┌────────────────────────────────────────────────────────────────┐
│ ✓ FACTURA PAGADA COMPLETAMENTE                                   │
└────────────────────────────────────────────────────────────────┘

1. DATOS DE LA FACTURA
────────────────────────────────────────────────────────────────
  Factura      : 0001-2026
  Concepto     : Arrendamiento
  Periodo      : 2026-01
  Alquiler     : Apartamento 301
  Emisión      : 01/01/2026
  Pago         : 05/01/2026
  Total fact.  : $ 1.000.000
  Total abonado: $ 1.000.000
  Saldo        : $       0

2. DETALLE DE ABONOS
────────────────────────────────────────────────────────────────
  ┌────────────┬────────────┬──────────────┬──────────────┐
  │ Fecha      │ Abono      │ Método       │ Referencia   │
  ├────────────┼────────────┼──────────────┼──────────────┤
  │ 01/01/2026 │ $ 500.000  │ Efectivo     │ #123         │
  │ 03/01/2026 │ $ 500.000  │ Transferencia│ #456         │
  └────────────┴────────────┴──────────────┴──────────────┘

3. AVISO LEGAL
────────────────────────────────────────────────────────────────
  Este comprobante acredita el pago de la factura 0001-2026
  y no sustituye la factura electrónica de venta autorizada
  por la DIAN.

╔════════════════════════════════════════════════════════════════╗
║  Generado por Prometheus el 05/01/2026 a las 14:30              ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 3. Mejoras a los Patrones de Diseño

### 3.1 Singleton - AppSettings

**Mejora:** Agregar método para obtener colores CSS inline (útil para PDFs dinámicos)

```php
// En AppSettings.php
public function cssVariables(): string
{
    return sprintf(
        '--color-primary: %s; --color-secondary: %s;',
        $this->primaryColor,
        $this->secondaryColor
    );
}
```

### 3.2 Builder - DeliveryActBuilder

**Mejora:** Agregar validación de estado del inquilino antes de crear acta

```php
public function forRental(Rental $rental, string $landlordName, string $tenantName): self
{
    // Validar que el alquiler esté activo
    if (!$rental->isActive()) {
        throw new RuntimeException(
            'No se puede crear acta para un alquiler inactivo.'
        );
    }

    // ... resto del código
}
```

### 3.3 Abstract Factory - DocumentFactory

**Mejora:** Agregar método para previsualizar documento sin renderizar completo

```php
// En DocumentFactory interface
public function preview(AbstractDocument $document): string;
```

### 3.4 Factory Method - PaymentPlanGenerator

**Mejora:** Agregar validación de que el alquiler tenga fecha de inicio futura

```php
// En PaymentPlanGenerator base
protected function validateRental(): void
{
    if ($this->rental->start_date->isPast()) {
        throw new RuntimeException(
            'No se puede generar plan para un alquiler que ya comenzó.'
        );
    }
}
```

---

## 4. Validaciones Mejoradas - Prioridad Media

### 4.1 Validation Rules Centralizadas

**Archivo:** `app/Rules/DocumentRules.php` (nuevo)

```php
namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

class PositiveAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value) || $value <= 0) {
            $fail('El :attribute debe ser un valor positivo mayor que cero.');
        }
    }
}

class ValidHexColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $fail('El :attribute debe ser un color hexadecimal válido (ej: #3b82f6).');
        }
    }
}
```

### 4.2 Validar Invoice antes de crear

**Archivo:** `app/Services/PaymentPlans/PaymentPlanGenerator.php`

**Validaciones:**
- Periodo no solapado con facturas existentes
- Monto dentro de rango razonable (min: 10.000, max: 100.000.000)
- Fecha de vencimiento posterior a emisión

### 4.3 Validar Payment antes de registrar

**Archivo:** `app/Services/PaymentRecordingService.php`

**Validaciones adicionales:**
- Monto no excede saldo pendiente de factura
- Referencia única por factura (si método es transferencia)

---

## 5. Notificaciones Mejoradas - Prioridad Media

### Estado Actual
- Notificaciones básicas de Filament
- Sin contexto en errores

### Mejoras Propuestas

**Archivo:** `app/Services/NotificationService.php` (nuevo)

```php
class NotificationService
{
    public function invoiceCreated(Invoice $invoice): void
    {
        Notification::make()
            ->success()
            ->title(__('invoice.notifications.created'))
            ->body(sprintf(
                'Factura %s creada para el periodo %s por %s',
                $invoice->number,
                $invoice->period,
                Money::exact($invoice->amount)
            ))
            ->duration(5000)
            ->send();
    }

    public function paymentRecorded(Payment $payment, bool $justSettled): void
    {
        if ($justSettled) {
            Notification::make()
                ->success()
                ->title(__('invoice.notifications.settled'))
                ->body(sprintf(
                    'La factura %s ha sido pagada completamente',
                    $payment->invoice->number
                ))
                ->icon('heroicon-o-check-circle')
                ->send();
        } else {
            Notification::make()
                ->info()
                ->title(__('invoice.notifications.payment_recorded'))
                ->body(sprintf(
                    'Abono de %s registrado en factura %s. Saldo pendiente: %s',
                    Money::exact($payment->amount),
                    $payment->invoice->number,
                    Money::exact($payment->invoice->balance())
                ))
                ->send();
        }
    }

    public function error(string $title, string $message): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($message)
            ->duration(8000)
            ->send();
    }
}
```

---

## 6. Performance y Optimización - Prioridad Baja

### 6.1 Eager Loading en Documentos

**Archivo:** `app/Documents/RentInvoice.php`

```php
private static function buildBody(Invoice $invoice): DocumentBody
{
    // Cargar relaciones para evitar N+1
    $invoice->load(['rental', 'user', 'payments' => fn($q) => $q->orderBy('date')]);

    // ... resto del código
}
```

### 6.2 Caché de AppSettings

**Archivo:** `app/Providers/AppServiceProvider.php`

```php
public function boot(): void
{
    if (!app()->runningInConsole()) {
        // Caché de AppSettings por 1 hora
        // Se invalida al guardar en AjustesPage
    }
}
```

---

## 7. Seguridad - Prioridad Baja

### 7.1 Rate Limiting en Documentos

**Archivo:** `routes/web.php`

```php
Route::middleware(['auth', 'throttle:documents'])
    ->group(function () {
        Route::get('/documents/{id}', [DocumentController::class, 'show']);
    });
```

### 7.2 Autorización por Recurso

**Archivos:** `Policies` para Invoice, Payment, DeliveryAct

```php
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id;
    }

    public function createPayment(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id
            && $invoice->canReceivePayments();
    }
}
```

---

## Orden de Implementación

### Fase 1: PDFs Profesionales (Impacto visual inmediato)
1. Migración de colores a AppSettings
2. Crear CSS pdf.css con paleta Filament
3. Crear plantillas específicas por documento
4. Actualizar PdfRenderer para seleccionar plantilla
5. Extender DocumentBody con referencia
6. Actualizar productos para asignar documento

### Fase 2: Plantilla de Texto
1. Mejorar PlainTextRenderer con bordes ASCII
2. Diferenciar por tipo de documento
3. Agregar header con logo ASCII

### Fase 3: Mejoras a Patrones
1. AppSettings: método cssVariables()
2. DeliveryActBuilder: validación de estado
3. DocumentFactory: método preview()
4. PaymentPlanGenerator: validación de fecha inicio

### Fase 4: Validaciones y Notificaciones
1. Crear DocumentRules
2. Validaciones en generadores
3. NotificationService

### Fase 5: Infraestructura
1. Eager loading
2. Caché de AppSettings
3. Rate limiting
4. Policies

---

## Notas de Implementación

- **Sin cambiar flujo:** Todas las mejoras son cosméticas o de infraestructura
- **Mantener patrones:** Solo mejoras sutiles, sin romper estructura
- **Mantener compatibilidad:** No romper datos existentes
- **Backward compatible:** Nuevos campos con defaults
- **Testing:** Cada mejora debe tener su test correspondiente
- **Diseño clean:** Sin excesos, minimalismo profesional
- **Colores consistentes:** Siempre usar paleta Filament
