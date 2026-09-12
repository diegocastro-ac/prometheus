# Prometheus | Implementación de Patrones de Diseño GoF

## Technical Design Document

- Autor: Sistema de Arrendamiento Prometheus
- Última actualización: 2026-08-21
- Status: Listo para implementación - 23 patrones GoF organizados por categoría

## 1. Contexto y Objetivos

### 1.1 Contexto

Prometheus es un sistema de gestión de arrendamientos inmobiliarios desarrollado con Laravel 12.0 + Filament 3.3. El sistema actualmente implementa un CRUD básico para gestionar propiedades, inquilinos, alquileres y pagos. El objetivo es evolucionar el sistema incorporando los 23 patrones de diseño GoF de forma coherente, resolviendo necesidades reales del negocio de arrendamiento informal en Colombia.

El sistema ya tiene:
- Multi-tenancy por `user_id` (arrendadores)
- Modelos básicos: User, Tenant, Property, Rental, Payment
- Panel de administración Filament con widgets
- Dashboard con métricas básicas

### 1.2 Objetivos

1. Implementar los 23 patrones GoF organizados por categoría (creacionales, estructurales, comportamentales)
2. Cada patrón debe resolver una necesidad real del negocio de arrendamiento
3. Implementación modular: cada patrón es independiente y puede implementarse por separado
4. Evitar overengineering: implementaciones simples y naturales en el dominio
5. Mantener compatibilidad con Laravel/Filament existente
6. Aprovechar capacidades nativas del framework cuando sea apropiado

### 1.3 Enfoque de Implementación

- **Modular:** Cada patrón se implementa como un módulo independiente
- **Progresivo:** Se pueden implementar en cualquier orden dentro de su categoría
- **No destructivo:** Las implementaciones son aditivas al código existente
- **Educativo:** Cada patrón demuestra su propósito con un caso de uso real

## 2. Decisiones de Implementación (Confirmadas)

| Decisión | Justificación |
|----------|---------------|
| **Organización por categoría GoF** | Sigue el orden académico estándar: creacionales → estructurales → comportamentales |
| **Implementaciones simplificadas** | Evitar overengineering usando implementaciones naturales del dominio |
| **Independencia de framework específico** | Las implementaciones deben ser comprensibles sin depender de features exclusivas de Laravel |
| **Casos de uso del negocio** | Cada patrón se justifica por una necesidad real del arrendador colombiano |
| **Modularidad total** | Cada patrón puede implementarse, probarse y entenderse independientemente |
| **DBML como fuente de verdad** | El esquema de base de datos soporta todas las implementaciones de patrones |

## 3. Arquitectura de Patrones

### 3.1 Organización por Categoría

**Creacionales (5 patrones):**
1. Singleton - Contexto de arrendador
2. Factory Method - Contenido de notificaciones
3. Builder - Generación de contratos PDF
4. Prototype - Duplicación de modelos de propiedad
5. Abstract Factory - Familias de reportes financieros


**Estructurales (7 patrones):**

6. Adapter - Geocodificación de direcciones
7. Bridge - Configuración de preferencias de alertas
8. Composite - Desarrollo agrupa propiedades
9. Decorator - Composición dinámica de montos
10. Facade - Facade de creación de alquiler
11. Flyweight - Modelos de propiedad como estado compartido
12. Proxy - Cache de métricas del dashboard


**Comportamiento (11 patrones):**

13. Chain of Responsibility - Validaciones encadenadas
14. Command - Generación automática de cobros
15. Interpreter - Lenguaje natural del arrendador
16. Iterator - Reporte anual con generators
17. Mediator - Coordinación entre entidades
18. Memento - Snapshots de entidades
19. Observer - Detección de eventos
20. State - Máquina de estados de alquileres
21. Strategy - Cálculo de mora intercambiable
22. Template Method - Esqueleto de métricas
23. Visitor - Generador de reporte fiscal


### 3.2 Dependencias Entre Patrones

**Sin dependencias fuertes:** La mayoría de patrones son independientes

**Dependencias suaves:**
- State depende de Command (Command genera estados)
- Observer depende de State (Observer detecta cambios de estado)
- Iterator depende de Command (Command genera datos que Iterator recorre)
- Template Method usa Proxy (Proxy cachea resultados de Template Method)

**Recomendación de orden:** Dentro de cada categoría, el orden no es crítico excepto las dependencias suaves mencionadas.

## 4. Funcionalidades Completas del Sistema

### 4.1 Gestión de Propiedades Repetidas (Necesidad A)

**Funcionalidad:** Sistema para gestionar propiedades repetidas sin duplicar trabajo manual, permitiendo compartir características entre unidades similares mientras se mantiene control individual.

**Sub-funcionalidades:**
- **Modelos de propiedad compartidos (Flyweight):** Crear modelos base con características comunes (habitaciones, baños, área) que múltiples propiedades pueden referenciar, evitando duplicación de datos
- **Agrupación de propiedades en desarrollos (Composite):** Agrupar propiedades en desarrollos (conjuntos, edificios) permitiendo cálculos agregados de ingresos y ocupación
- **Duplicación de modelos existentes (Prototype):** Clonar modelos de propiedad con variaciones para crear rápidamente nuevas variantes sin llenar formularios desde cero
- **Versionado de cambios en propiedades (Memento):** Guardar snapshots de versiones anteriores de modelos y propiedades para permitir revertir cambios y auditoría

**Patrones que la implementan:** Flyweight, Composite, Prototype, Memento

---

### 4.2 Sistema de Cobro Mensual Automático (Necesidad B)

**Funcionalidad:** Sistema completamente automatizado que genera cobros mensuales, calcula recargoss por mora, gestiona estados de pagos y genera reportes fiscales, eliminando la necesidad de que el arrendador recuerde generar cobros manualmente.

**Sub-funcionalidades:**
- **Generación automática de cobros mensuales (Command):** Job programado que genera automáticamente los payment_schedules para todos los alquileres activos cada mes sin intervención manual
- **Gestión de estados de alquileres y schedules (State):** Máquina de estados que controla transiciones válidas (pendiente → atrasado → pagado, activo → atrasado → finalizado) con reglas de negocio específicas
- **Cálculo de recargo por mora configurable (Strategy):** Sistema intercambiable de cálculo de mora donde cada arrendador puede elegir su estrategia (porcentaje fijo, interés diario, monto fijo, ninguno)
- **Composición dinámica de montos de pago (Decorator):** Cálculo del monto final de pago añadiendo capas sucesivas (base → servicios → mora) para desglose transparente
- **Reporte anual para declaración de renta (Iterator):** Generación de reporte fiscal anual procesando grandes volúmenes de datos eficientemente sin agotar memoria

**Patrones que la implementan:** Command, State, Strategy, Decorator, Iterator

---

### 4.3 Sistema de Notificaciones Inteligente (Necesidad C)

**Funcionalidad:** Sistema de alertas que avisa automáticamente al arrendador cuando algo requiere su atención, con contenido personalizado según el tipo de evento y canales configurables según preferencias.

**Sub-funcionalidades:**
- **Detección automática de eventos (Observer):** Sistema que detecta eventos importantes (pagos atrasados, contratos por vencer, pagos completados) y dispara notificaciones automáticamente
- **Generación de contenido personalizado (Factory Method):** Factory que crea contenido específico de notificación según el tipo de evento, con datos relevantes del contexto
- **Configuración de preferencias de canales (Bridge):** Sistema que permite al arrendador configurar qué canal usar para cada tipo de alerta (ej: pagos atrasados por WhatsApp, contratos por email)

**Patrones que la implementan:** Observer, Factory Method, Bridge

---

### 4.4 Sistema de Reportes y Métricas Financieras (Necesidad D)

**Funcionalidad:** Sistema completo de generación de reportes financieros y métricas del dashboard que permite visualizar, exportar y analizar datos del negocio en diferentes formatos con cálculos optimizados.

**Sub-funcionalidades:**
- **Generación de familias de reportes financieros (Abstract Factory):** Sistema que crea componentes coherentes (datos, gráficas, tablas) para diferentes tipos de reportes (ingresos, rentabilidad, ocupación)
- **Esqueleto unificado de métricas (Template Method):** Template method que define el esqueleto común de cálculo de métricas (obtener → filtrar → procesar → formatear) reutilizado por todas las métricas del dashboard
- **Cache de cálculos pesados (Proxy):** Sistema de cache que almacena resultados de cálculos costosos del dashboard para mejorar performance y reducir carga en base de datos

**Patrones que la implementan:** Abstract Factory, Template Method, Proxy

---

### 4.5 Sistema de Búsqueda y Confianza de Datos (Necesidad E)

**Funcionalidad:** Sistema que permite al arrendador encontrar propiedades eficientemente con lenguaje natural y mantener confianza en los datos mediante búsqueda avanzada, validaciones robustas y auditoría completa.

**Sub-funcionalidades:**
- **Búsqueda en lenguaje natural (Interpreter):** Sistema que interpreta lenguaje natural del arrendador ("propiedades en Bogotá con precio menor a 2000000") y lo traduce a consultas dinámicas
- **Validaciones encadenadas al crear alquiler (Chain of Responsibility):** Cadena de validaciones que verifica disponibilidad de propiedad, inquilino, fechas y montos antes de crear un alquiler
- **Auditoría de cambios (Visitor):** Sistema que recorre entidades para extraer información de auditoría y generar reportes fiscales sin modificar las entidades originales

**Patrones que la implementan:** Interpreter, Chain of Responsibility, Visitor

---

### 4.6 Infraestructura Técnica del Sistema (Necesidad F)

**Funcionalidad:** Columna vertebral técnica que soporta todas las funcionalidades anteriores proporcionando servicios fundamentales de coordinación, simplificación, integración y generación de documentos.

**Sub-funcionalidades:**
- **Contexto global de arrendador (Singleton):** Contexto que proporciona el user_id del arrendador autenticado de forma consistente a través de toda la aplicación
- **Coordinación entre entidades (Mediator):** Mediador que centraliza la coordinación compleja entre entidades cuando ocurren eventos importantes, evitando acoplamiento directo
- **Simplificación de creación de alquileres (Facade):** Facade que simplifica la creación de alquileres coordinando múltiples servicios y validaciones en una sola llamada
- **Geocodificación de direcciones (Adapter):** Sistema que adapta la API externa de geocodificación (Nominatim) a la interfaz interna del sistema para obtener coordenadas de direcciones
- **Generación de contratos PDF (Builder):** Builder que construye contratos PDF paso a paso permitiendo agregar cláusulas opcionales (mascotas, garante, depósito) según el caso específico

**Patrones que la implementan:** Singleton, Mediator, Facade, Adapter, Builder

---

## 5. Pasos de Implementación

## CATEGORÍA 1: PATRONES CREACIONALES

### PASO 1: Singleton - Contexto de Arrendador

#### 1.1 Propósito del Patrón

Resolver el problema de acceder repetidamente al `user_id` del arrendador autenticado a través de múltiples capas del sistema (controladores, modelos, servicios, widgets). Singleton garantiza una única instancia por request.

#### 1.2 Necesidad del Negocio

El arrendador necesita que todas las operaciones se realicen en el contexto de su cuenta. Actualmente, `Auth::id()` se repite en múltiples lugares, violando DRY y dificultando testing.

#### 1.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear un contexto global que proporcione el `user_id` del arrendador autenticado de forma consistente a través de toda la aplicación.

**Partes de la funcionalidad:**
1. Crear clase `ArrendadorContext` que guarde el `user_id` 
2. Implementar patrón Singleton para garantizar una única instancia por request
3. Crear middleware que inicialice el contexto al inicio del request
4. Reemplazar todos los usos de `Auth::id()` por llamadas al contexto
5. Implementar métodos de testing para mockear el contexto en tests

**Alcance de la implementación:**
- Reemplazo en Resources de Filament (PropertyResource, RentalResource, TenantResource)
- Reemplazo en Widgets del dashboard (StatsOverview, IncomeChart, etc.)
- Reemplazo en scopes de Eloquent en modelos
- Reemplazo en cualquier lugar donde se use `Auth::id()` actualmente

#### 1.3 Implementación en Código Actual

**Archivo a crear:** `app/Services/ArrendadorContext.php`

**Estructura de la clase:**
```php
class ArrendadorContext {
    private static ?ArrendadorContext $instance = null;
    private int $userId;
    
    private function __construct(int $userId) {
        $this->userId = $userId;
    }
    
    public static function getInstance(): ArrendadorContext {
        if (self::$instance === null) {
            self::$instance = new self(Auth::id());
        }
        return self::$instance;
    }
    
    public function getUserId(): int {
        return $this->userId;
    }
}
```

#### 1.4 Decisiones de Diseño

**¿Por qué singleton y no service container?**
- Singleton es más explícito sobre la intención de "única instancia por request"
- Service container de Laravel es más poderoso pero oscurece el patrón educativo
- Para fines educativos, singleton demuestra mejor el concepto

**¿Dónde inicializar?**
- En un middleware de Laravel que se ejecuta temprano en el request
- El middleware extrae `Auth::id()` y llama a `ArrendadorContext::initialize()`

**¿Thread safety?**
- PHP no tiene threading en request HTTP tradicional
- Para contexto de CLI/queue, considerar request-scoped container

#### 1.5 Integración con Código Existente

**Lugares a modificar:**
- Reemplazar `Auth::id()` por `ArrendadorContext::getInstance()->getUserId()` en:
  - Resources de Filament (PropertyResource, RentalResource, etc.)
  - Widgets del dashboard
  - Scopes de Eloquent en modelos

**Middleware a crear:** `app/Http/Middleware/SetArrendadorContext.php`

#### 1.6 Consideraciones de Testing

**Mocking en tests:**
```php
ArrendadorContext::setInstanceForTesting(new ArrendadorContext(123));
```

**Reset entre tests:**
- Llamar a `ArrendadorContext::reset()` en `setUp()` de tests

#### 1.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/ArrendadorContext.php`
- `app/Http/Middleware/SetArrendadorContext.php`

**Modificar:**
- `app/Http/Kernel.php` - registrar middleware
- `app/Filament/Resources/PropertyResource.php` - usar contexto
- `app/Filament/Resources/RentalResource.php` - usar contexto
- `app/Filament/Resources/TenantResource.php` - usar contexto
- `app/Filament/Widgets/StatsOverview.php` - usar contexto
- `app/Filament/Widgets/IncomeChart.php` - usar contexto

---

### PASO 2: Factory Method - Contenido de Notificaciones

#### 2.1 Propósito del Patrón

Permitir la creación de diferentes tipos de notificaciones con contenido específico sin que el código cliente necesite conocer los detalles de construcción de cada tipo.

#### 2.2 Necesidad del Negocio

El sistema necesita enviar diferentes tipos de alertas (pago atrasado, contrato por vencer, bienvenida). Cada tipo requiere un mensaje diferente con datos específicos, pero el proceso de creación debe ser uniforme.

#### 2.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear una factory que genere objetos de contenido de notificaciones según el tipo de evento, encapsulando la lógica de construcción de cada tipo.

**Partes de la funcionalidad:**
1. Crear interfaz `NotificationContent` que defina estructura común de contenido
2. Implementar clases concretas para cada tipo: `PagoAtrasadoContent`, `ContratoVencerContent`, `BienvenidaContent`
3. Crear clase `NotificationFactory` con método `create($type, $context)` que retorne el contenido apropiado
4. Cada clase de contenido implementa lógica específica de mensaje y datos
5. Integrar factory en observers/events del sistema para usarla cuando ocurren eventos

**Alcance de la implementación:**
- Uso en PaymentObserver para notificaciones de pagos atrasados
- Uso en RentalObserver para notificaciones de contratos por vencer
- Uso en TenantObserver para notificaciones de bienvenida
- Reemplazo de cualquier lógica manual de construcción de mensajes de notificación

#### 2.3 Implementación en Código Actual

**Archivo a crear:** `app/Services/Notifications/NotificationFactory.php`

**Estructura de la factory:**
```php
interface NotificationContent {
    public function getMessage(): string;
    public function getData(): array;
}

class PagoAtrasadoContent implements NotificationContent {
    public function getMessage(): string {
        return "El pago del alquiler está atrasado";
    }
    public function getData(): array {
        return ['monto' => $this->monto, 'dias_atraso' => $this->dias];
    }
}

class NotificationFactory {
    public function create(string $type, array $context): NotificationContent {
        return match($type) {
            'pago_atrasado' => new PagoAtrasadoContent($context),
            'contrato_vencer' => new ContratoVencerContent($context),
            'bienvenida' => new BienvenidaContent($context),
        };
    }
}
```

#### 2.4 Decisiones de Diseño

**¿Por qué Factory Method y no simple switch?**
- Factory Method encapsula la lógica de creación
- Permite agregar nuevos tipos sin modificar el código cliente
- Facilita testing al poder mockear la factory

**¿Dónde ubicar la factory?**
- En `app/Services/Notifications/` para mantener organización por dominio
- Como servicio inyectable via Laravel Service Container

**¿Relación con Laravel Notifications?**
- Factory Method crea el *contenido* de la notificación
- Laravel Notifications maneja el *envío* a través de canales
- Son complementarios, no redundantes

#### 2.5 Integración con Código Existente

**Uso en Observer/Events:**
```php
class PaymentObserver {
    public function updated(Payment $payment) {
        if ($payment->isOverdue()) {
            $content = app(NotificationFactory::class)
                ->create('pago_atrasado', ['payment' => $payment]);
            // Enviar notificación con el contenido
        }
    }
}
```

#### 2.6 Consideraciones de Testing

**Test de factory:**
```php
$factory = new NotificationFactory();
$content = $factory->create('pago_atrasado', $context);
$this->assertInstanceOf(PagoAtrasadoContent::class, $content);
```

#### 2.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Notifications/NotificationFactory.php`
- `app/Services/Notifications/NotificationContent.php` (interface)
- `app/Services/Notifications/PagoAtrasadoContent.php`
- `app/Services/Notifications/ContratoVencerContent.php`
- `app/Services/Notifications/BienvenidaContent.php`

**Modificar:**
- `app/Providers/AppServiceProvider.php` - registrar factory en service container
- Eventos/Observers existentes - usar factory para crear contenido

---

### PASO 3: Builder - Generación de Contratos PDF

#### 3.1 Propósito del Patrón

Construir objetos complejos (contratos PDF) paso a paso, permitiendo diferentes representaciones del mismo proceso de construcción.

#### 3.2 Necesidad del Negocio

Los contratos de arrendamiento tienen partes opcionales (cláusula de mascotas, garante, depósito) que se combinan de diferentes formas según el caso específico. Builder permite construir el contrato final configurando solo las partes necesarias.

#### 3.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear un builder que construya contratos PDF paso a paso, permitiendo agregar cláusulas opcionales de forma fluida antes de generar el documento final.

**Partes de la funcionalidad:**
1. Crear clase `ContractBuilder` con métodos para agregar cláusulas opcionales
2. Implementar métodos fluidos (fluent interface) como `withPets()`, `withGuarantor()`, `withDeposit()`
3. Crear método `build()` que combine todas las cláusulas y genere el PDF final
4. Integrar generación PDF usando librería (dompdf o similar)
5. Guardar PDF generado en storage con ruta estructurada por usuario/rental
6. Actualizar modelo Rental con la ruta del contrato generado

**Alcance de la implementación:**
- Integración en formulario de creación/edición de Rental en Filament
- Reemplazo de carga manual de contratos por generación automática
- Almacenamiento de contratos en `storage/app/public/users/{user_id}/rentals/{rental_id}/contract.pdf`
- Opción de regenerar contrato con diferentes cláusulas sin perder el anterior

#### 3.3 Implementación en Código Actual

**Archivo a crear:** `app/Services/Contracts/ContractBuilder.php`

**Estructura del builder:**
```php
class ContractBuilder {
    private array $clauses = [];
    private ?string $petsClause = null;
    private ?string $guarantorClause = null;
    private ?string $depositClause = null;
    
    public function withPets(string $terms): self {
        $this->petsClause = $terms;
        return $this;
    }
    
    public function withGuarantor(string $guarantorData): self {
        $this->guarantorClause = $guarantorData;
        return $this;
    }
    
    public function withDeposit(float $amount): self {
        $this->depositClause = "Depósito: $amount";
        return $this;
    }
    
    public function build(): string {
        // Combinar todas las cláusulas en el documento final
        return $this->generatePdf();
    }
}
```

#### 3.4 Decisiones de Diseño

**¿Por qué Builder y no parámetros del constructor?**
- Constructor con muchos parámetros es difícil de leer y mantener
- Builder permite configuración opcional clara y legible
- Builder puede validación paso a paso

**¿Qué librería PDF usar?**
- `barryvdh/laravel-dompdf` - simple, integración Laravel
- Alternativa: `snappy/pdf` (wkhtmltopdf) - más potente pero más complejo
- Para este caso educativo, dompdf es suficiente

**¿Dónde guardar el PDF generado?**
- En `storage/app/public/users/{user_id}/rentals/{rental_id}/contract.pdf`
- Usar Laravel Storage para abstracción del filesystem

#### 3.5 Integración con Código Existente

**Uso al crear Rental:**
```php
$builder = new ContractBuilder();
$builder->addBasicClause($rental->terms);

if ($request->has_pets) {
    $builder->withPets($request->pets_terms);
}

if ($request->has_guarantor) {
    $builder->withGuarantor($request->guarantor_data);
}

$contractPath = $builder->build();
$rental->agreement_path = $contractPath;
```

#### 3.6 Consideraciones de Testing

**Test de builder:**
```php
$builder = new ContractBuilder();
$result = $builder->withPets('no mascotas')->build();
$this->assertStringContainsString('no mascotas', $result);
```

#### 3.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Contracts/ContractBuilder.php`
- `app/Services/Contracts/PdfGenerator.php` (helper para generación PDF)

**Modificar:**
- `app/Filament/Resources/RentalResource.php` - integrar builder en formulario
- `app/Models/Rental.php` - actualizar lógica de contrato
- `composer.json` - agregar dependencia PDF (si no existe)

---

### PASO 4: Prototype - Duplicación de Modelos de Propiedad

#### 4.1 Propósito del Patrón

Crear nuevos objetos clonando existentes en lugar de crearlos desde cero, optimizando cuando la inicialización es costosa o cuando se necesitan variantes similares.

#### 4.2 Necesidad del Negocio

Los arrendadores frecuentemente tienen variantes similares de modelos de propiedad (ej: "Modelo B estándar" vs "Modelo B con balcón"). Clonar un modelo existente es más eficiente que llenar todos los campos desde cero.

#### 4.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar capacidad de clonar modelos de propiedad existentes con variaciones, permitiendo crear nuevas variantes rápidamente sin duplicar trabajo manual.

**Partes de la funcionalidad:**
1. Agregar método `cloneWithVariations()` al modelo PropertyModel
2. Usar método `replicate()` de Laravel para clonar atributos del modelo
3. Permitir especificar variaciones (nombre, descripción, características específicas)
4. Clonar imágenes asociadas al modelo automáticamente
5. Generar nombre único para el clon (ej: "Modelo B (copia)")
6. Validar que el clon se cree correctamente con relaciones intactas

**Alcance de la implementación:**
- Acción de "Duplicar" en PropertyModelResource de Filament
- Formulario para especificar variaciones al clonar
- Redirección automática al modelo clonado después de creación
- Mantenimiento de relación con Development si el original tenía una

#### 4.3 Implementación en Código Actual

**Método a agregar en modelo:** `app/Models/PropertyModel.php`

**Estructura del método clone:**
```php
class PropertyModel extends Model {
    public function cloneWithVariations(array $variations): self {
        $clone = $this->replicate();
        $clone->name = $variations['name'] ?? $clone->name . ' (copia)';
        $clone->description = $variations['description'] ?? $clone->description;
        // Aplicar variaciones específicas
        $clone->save();
        
        // Clonar imágenes asociadas
        foreach ($this->images as $image) {
            $image->replicate()->update(['property_model_id' => $clone->id]);
        }
        
        return $clone;
    }
}
```

#### 4.4 Decisiones de Diseño

**¿Por qué usar replicate() de Laravel?**
- Laravel ya implementa clonación a nivel de Eloquent
- `replicate()` copia todos los atributos excepto PK y timestamps
- Es más eficiente que reconstruir el objeto manualmente

**¿Qué datos clonar vs qué dejar para variación?**
- Clonar: estructura base (habitaciones, baños, área)
- Variación: nombre, descripción, características específicas
- Imágenes: clonar por defecto, permitir excluir

**¿Relación con Memento?**
- Prototype es para crear variantes similares
- Memento es para versionar cambios
- Complementarios: puedes clonar (Prototype) y luego versionar (Memento)

#### 4.5 Integración con Código Existente

**Acción en UI de Filament:**
```php
// En PropertyModelResource
Actions\Action::make('clone')
    ->form([
        TextInput::make('name'),
        Textarea::make('variations'),
    ])
    ->action(function (PropertyModel $record, array $data) {
        $clone = $record->cloneWithVariations($data);
        return redirect(PropertyModelResource::getUrl('edit', ['record' => $clone]));
    });
```

#### 4.6 Consideraciones de Testing

**Test de clonación:**
```php
$original = PropertyModel::factory()->create();
$clone = $original->cloneWithVariations(['name' => 'Variante']);
$this->assertNotEquals($original->id, $clone->id);
$this->assertEquals('Variante', $clone->name);
```

#### 4.7 Archivos a Crear/Modificar

**Modificar:**
- `app/Models/PropertyModel.php` - agregar método `cloneWithVariations()`
- `app/Filament/Resources/PropertyModelResource.php` - agregar acción de clonar (si existe el resource)

---

### PASO 5: Abstract Factory - Familias de Reportes Financieros

#### 5.1 Propósito del Patrón

Proveer una interfaz para crear familias de objetos relacionados (reportes financieros con componentes coherentes) sin especificar sus clases concretas.

#### 5.2 Necesidad del Negocio

El arrendador necesita diferentes tipos de reportes financieros (ingresos, rentabilidad, ocupación). Cada tipo requiere componentes coherentes (datos, gráfica, tabla) que deben funcionar juntos. Abstract Factory garantiza consistencia.

#### 5.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear factories que generen familias de componentes coherentes para diferentes tipos de reportes financieros, garantizando que datos, gráficas y tablas sean compatibles entre sí.

**Partes de la funcionalidad:**
1. Crear interfaz `ReportFactory` con métodos para crear datos, gráfica y tabla
2. Implementar factories concretas: `ReportIngresosFactory`, `ReportRentabilidadFactory`
3. Cada factory crea componentes específicos coherentes (ej: IngresosData + IngresosChart + IngresosTable)
4. Crear interfaces para componentes: `ReportData`, `ReportChart`, `ReportTable`
5. Implementar lógica de cálculo específica en cada componente de datos
6. Generar visualizaciones coherentes con los datos en cada componente de gráfica/tabla

**Alcance de la implementación:**
- Generación de reportes en dashboard de Filament
- Exportación de reportes en diferentes formatos (PDF, Excel)
- Creación de nuevos tipos de reportes agregando nuevas factories
- Integración con widgets existentes del dashboard

#### 5.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Reports/`

**Estructura de Abstract Factory:**
```php
interface ReportFactory {
    public function createData(): ReportData;
    public function createChart(): ReportChart;
    public function createTable(): ReportTable;
}

class ReportIngresosFactory implements ReportFactory {
    public function createData(): ReportData {
        return new IngresosData();
    }
    public function createChart(): ReportChart {
        return new IngresosChart();
    }
    public function createTable(): ReportTable {
        return new IngresosTable();
    }
}

class ReportRentabilidadFactory implements ReportFactory {
    // Implementación similar para rentabilidad
}
```

#### 5.4 Decisiones de Diseño

**¿Por qué Abstract Factory y no Factory Method simple?**
- Abstract Factory crea *familias* de objetos relacionados
- Factory Method crea *un tipo* de objeto
- Aquí necesitamos coherencia entre datos+gráfica+tabla

**¿Qué componentes conforman la familia?**
- Datos: lógica de cálculo específica del tipo de reporte
- Gráfica: visualización coherente con los datos
- Tabla: estructura tabular coherente con los datos

**¿Integración con widgets de Filament?**
- Los widgets de Filament consumen los componentes generados
- Abstract Factory se encarga de la generación, Filament de la presentación

#### 5.5 Integración con Código Existente

**Uso en controlador o servicio:**
```php
class ReportGenerator {
    public function generate(string $reportType): array {
        $factory = match($reportType) {
            'ingresos' => new ReportIngresosFactory(),
            'rentabilidad' => new ReportRentabilidadFactory(),
        };
        
        $data = $factory->createData();
        $chart = $factory->createChart();
        $table = $factory->createTable();
        
        return compact('data', 'chart', 'table');
    }
}
```

#### 5.6 Consideraciones de Testing

**Test de factory:**
```php
$factory = new ReportIngresosFactory();
$data = $factory->createData();
$chart = $factory->createChart();
$table = $factory->createTable();
$this->assertInstanceOf(IngresosData::class, $data);
$this->assertInstanceOf(IngresosChart::class, $chart);
```

#### 5.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Reports/ReportFactory.php` (interface)
- `app/Services/Reports/ReportIngresosFactory.php`
- `app/Services/Reports/ReportRentabilidadFactory.php`
- `app/Services/Reports/ReportData.php` (interface)
- `app/Services/Reports/ReportChart.php` (interface)
- `app/Services/Reports/ReportTable.php` (interface)
- Implementaciones concretas de cada componente

**Modificar:**
- `app/Filament/Widgets/` - integrar con widgets existentes
- `app/Http/Controllers/` - agregar endpoint para reportes (si aplica)

---

## CATEGORÍA 2: PATRONES ESTRUCTURALES

### PASO 6: Adapter - Geocodificación de Direcciones

#### 6.1 Propósito del Patrón

Permitir que clases con interfaces incompatibles trabajen juntas. En este caso, adaptar la API de geocodificación externa a la interfaz interna del sistema.

#### 6.2 Necesidad del Negocio

El sistema necesita convertir direcciones de texto a coordenadas (lat/lng) para mostrar mapas y filtrar propiedades por ubicación. La API gratuita de Nominatim (OpenStreetMap) tiene una interfaz diferente a la que el sistema necesita internamente.

#### 6.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear un adapter que adapte la API de geocodificación de Nominatim a la interfaz interna del sistema, permitiendo convertir direcciones a coordenadas de forma transparente.

**Partes de la funcionalidad:**
1. Crear interfaz `GeocodingService` que defina método `geocode($address)` retornando array con lat/lng/city
2. Implementar `NominatimAdapter` que llame a la API de Nominatim y adapte la respuesta
3. Extraer datos relevantes de la respuesta de Nominatim (lat, lon, ciudad)
4. Implementar lógica de extracción de ciudad desde la respuesta de la API
5. Crear observer en Property/Development que use el adapter al guardar/actualizar direcciones
6. Implementar cache para evitar requests duplicados a la API externa

**Alcance de la implementación:**
- Geocodificación automática al crear/actualizar Properties
- Geocodificación automática al crear/actualizar Developments
- Actualización de campos lat, lng, city en los modelos correspondientes
- Integración con mapas en Filament para mostrar ubicaciones
- Filtros por ciudad en búsqueda de propiedades

#### 6.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Geocoding/`

**Estructura del Adapter:**
```php
interface GeocodingService {
    public function geocode(string $address): array;
}

class NominatimAdapter implements GeocodingService {
    private string $baseUrl = 'https://nominatim.openstreetmap.org/search';
    
    public function geocode(string $address): array {
        $response = Http::get($this->baseUrl, [
            'q' => $address,
            'format' => 'json',
        ]);
        
        $data = $response->json()[0] ?? null;
        
        return [
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lon'] ?? null,
            'city' => $this->extractCity($data),
        ];
    }
    
    private function extractCity(array $data): string {
        // Lógica para extraer ciudad de la respuesta de Nominatim
    }
}
```

#### 6.4 Decisiones de Diseño

**¿Por qué Nominatim y no Google Maps?**
- Nominatim es gratuito y no requiere API key
- Google Maps requiere cuenta y tiene límites de cuota
- Para proyecto educativo, Nominatim es más accesible

**¿Rate limiting?**
- Nominatim tiene límite de 1 req/seg
- Implementar cache para evitar requests duplicados
- Considerar queue para geocodificación en masa

**¿Fallback strategy?**
- Si Nominatim falla, intentar servicio alternativo
- Guardar última ubicación conocida
- Permitir entrada manual de coordenadas

#### 6.5 Integración con Código Existente

**Uso al crear/actualizar Property:**
```php
class PropertyObserver {
    public function saving(Property $property) {
        if ($property->isDirty('address') && empty($property->lat)) {
            $geocoder = app(GeocodingService::class);
            $coords = $geocoder->geocode($property->address);
            $property->lat = $coords['lat'];
            $property->lng = $coords['lng'];
            $property->city = $coords['city'];
        }
    }
}
```

#### 6.6 Consideraciones de Testing

**Mock del adapter:**
```php
$mockAdapter = new class implements GeocodingService {
    public function geocode(string $address): array {
        return ['lat' => 4.7110, 'lng' => -74.0721, 'city' => 'Bogotá'];
    };
};
```

#### 6.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Geocoding/GeocodingService.php` (interface)
- `app/Services/Geocoding/NominatimAdapter.php`
- `app/Services/Geocoding/CacheDecorator.php` (opcional, para cache)

**Modificar:**
- `app/Models/Property.php` - agregar observer
- `app/Models/Development.php` - agregar observer
- `app/Providers/AppServiceProvider.php` - registrar adapter en service container

---

### PASO 7: Bridge - Configuración de Preferencias de Alertas

#### 7.1 Propósito del Patrón

Desacoplar una abstracción (tipo de alerta) de su implementación (canal de comunicación), permitiendo que ambas varíen independientemente.

#### 7.2 Necesidad del Negocio

Los arrendadores tienen preferencias reales: "quiero pagos atrasados por WhatsApp, contratos por vencer por email". Bridge permite configurar qué canal para cada tipo de alerta sin duplicar código.

#### 7.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de preferencias de alertas que permita a los arrendadores configurar qué canal usar para cada tipo de alerta, desacoplando el tipo de alerta del canal de envío.

**Partes de la funcionalidad:**
1. Crear interfaz `AlertType` para abstracción de tipos de alerta (pago atrasado, contrato vencer, etc.)
2. Crear interfaz `Channel` para implementación de canales (email, WhatsApp, SMS, in-app)
3. Implementar clases concretas de alertas: `PagoAtrasadoAlert`, `ContratoVencerAlert`, `BienvenidaAlert`
4. Implementar clases concretas de canales: `EmailChannel`, `WhatsAppChannel`, `SMSChannel`
5. Crear clase `AlertConfigurator` que gestione preferencias y envíe alertas usando canal apropiado
6. Crear servicio `AlertPreferenceService` que cargue preferencias desde tabla `alert_preferences`
7. Integrar configurador en observers/events para enviar alertas según preferencias del usuario

**Alcance de la implementación:**
- UI de configuración de preferencias en perfil de usuario
- Carga automática de preferencias al iniciar sesión
- Envío de alertas según preferencias configuradas
- Agregar nuevos canales sin modificar alertas existentes
- Agregar nuevos tipos de alerta sin modificar canales existentes

#### 7.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Alerts/`

**Estructura del Bridge:**
```php
interface AlertType {
    public function getMessage(): string;
    public function getPriority(): string;
}

class PagoAtrasadoAlert implements AlertType {
    public function getMessage(): string {
        return "El pago está atrasado";
    }
    public function getPriority(): string {
        return "alta";
    }
}

interface Channel {
    public function send(string $message, string $priority): bool;
}

class EmailChannel implements Channel {
    public function send(string $message, string $priority): bool {
        // Lógica de envío de email
    }
}

class WhatsAppChannel implements Channel {
    public function send(string $message, string $priority): bool {
        // Lógica de envío de WhatsApp
    }
}

class AlertConfigurator {
    private array $preferences = [];
    
    public function setPreference(string $alertType, Channel $channel): void {
        $this->preferences[$alertType] = $channel;
    }
    
    public function sendAlert(AlertType $alert): bool {
        $type = get_class($alert);
        $channel = $this->preferences[$type] ?? new EmailChannel();
        return $channel->send($alert->getMessage(), $alert->getPriority());
    }
}
```

#### 7.4 Decisiones de Diseño

**¿Por qué Bridge y no simple switch en código?**
- Bridge permite agregar nuevos canales sin modificar alertas
- Bridge permite agregar nuevas alertas sin modificar canales
- Configuración es datos, no código lógico

**¿Almacenamiento de preferencias?**
- En tabla `alert_preferences` (ya definida en DBML)
- Estructura: user_id, alert_type, channel
- Cargar preferencias al iniciar sesión

**¿Relación con Laravel Notifications?**
- Bridge es para *configuración de preferencias*
- Laravel Notifications es para *envío real*
- Complementarios: Bridge decide qué canal, Laravel Notifications lo ejecuta

#### 7.5 Integración con Código Existente

**Carga de preferencias:**
```php
class AlertPreferenceService {
    public function loadForUser(int $userId): AlertConfigurator {
        $configurator = new AlertConfigurator();
        $preferences = AlertPreference::where('user_id', $userId)->get();
        
        foreach ($preferences as $pref) {
            $channel = $this->instantiateChannel($pref->channel);
            $configurator->setPreference($pref->alert_type, $channel);
        }
        
        return $configurator;
    }
}
```

#### 7.6 Consideraciones de Testing

**Test de configuración:**
```php
$configurator = new AlertConfigurator();
$configurator->setPreference(PagoAtrasadoAlert::class, new WhatsAppChannel());
$result = $configurator->sendAlert(new PagoAtrasadoAlert());
$this->assertTrue($result);
```

#### 7.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Alerts/AlertType.php` (interface)
- `app/Services/Alerts/PagoAtrasadoAlert.php`
- `app/Services/Alerts/ContratoVencerAlert.php`
- `app/Services/Alerts/Channel.php` (interface)
- `app/Services/Alerts/EmailChannel.php`
- `app/Services/Alerts/WhatsAppChannel.php`
- `app/Services/Alerts/SMSChannel.php`
- `app/Services/Alerts/AlertConfigurator.php`
- `app/Services/Alerts/AlertPreferenceService.php`

**Modificar:**
- `app/Models/AlertPreference.php` - crear modelo (tabla ya en DBML)
- `database/migrations/` - crear migración para alert_preferences
- Eventos/Observers - usar configurador para envío

---

### PASO 8: Composite - Desarrollo Agrupa Propiedades

#### 8.1 Propósito del Patrón

Componer objetos en estructuras de árbol y tratar individuales y composiciones uniformemente. En este caso, tratar un `Development` y una `Property` de la misma forma para cálculos de ingresos.

#### 8.2 Necesidad del Negocio

Los arrendadores con conjuntos o edificios necesitan ver ingresos agregados. `Composite` permite calcular ingresos de una propiedad individual o de un desarrollo completo con la misma interfaz.

#### 8.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar interfaz uniforme para calcular métricas financieras tanto en propiedades individuales como en desarrollos que agrupan múltiples propiedades.

**Partes de la funcionalidad:**
1. Crear interfaz `IngresoCalculable` con métodos comunes (calcularIngresoMensual, calcularOcupación)
2. Implementar interfaz en modelo Property con cálculos para una sola propiedad
3. Implementar interfaz en modelo Development con cálculos agregados de sus propiedades
4. En Development, iterar sobre propiedades relacionadas y sumar sus cálculos individuales
5. Implementar cálculos de ocupación considerando total de unidades vs unidades ocupadas
6. Usar interfaz uniforme en widgets y reportes para tratar individual y composición igual

**Alcance de la implementación:**
- Cálculo de ingresos en widgets de dashboard (funciona para Property o Development)
- Cálculo de ocupación en métricas del sistema
- Reportes financieros que pueden nivelar por propiedad o por desarrollo
- Gestión de developments en Filament con visualización de métricas agregadas

#### 8.3 Implementación en Código Actual

**Archivos a crear/modificar:** `app/Models/`, `app/Interfaces/`

**Estructura del Composite:**
```php
interface IngresoCalculable {
    public function calcularIngresoMensual(): float;
    public function calcularOcupacion(): float;
}

class Property extends Model implements IngresoCalculable {
    public function calcularIngresoMensual(): float {
        return $this->rentals()->where('status', 'activo')->sum('monthly_amount');
    }
    
    public function calcularOcupacion(): float {
        return $this->rentals()->where('status', 'activo')->count() / max(1, $this->total_units);
    }
}

class Development extends Model implements IngresoCalculable {
    public function calcularIngresoMensual(): float {
        return $this->properties->sum(function ($property) {
            return $property->calcularIngresoMensual();
        });
    }
    
    public function calcularOcupacion(): float {
        $totalIngresos = $this->properties->sum(function ($p) {
            return $p->rentals()->where('status', 'activo')->count();
        });
        $totalUnidades = $this->properties->sum('total_units');
        return $totalIngresos / max(1, $totalUnidades);
    }
}
```

#### 8.4 Decisiones de Diseño

**¿Por qué Composite y no simple suma en controlador?**
- Composite encapsula la lógica de cálculo en los modelos
- Permite tratar individual y composición uniformemente
- Facilita agregar nuevos niveles de composición (ej: Development → Buildings → Properties)

**¿Qué operaciones uniformes?**
- Cálculo de ingresos mensuales
- Cálculo de ocupación
- Cálculo de rentabilidad
- Estadísticas generales

**¿Relación con modelos Eloquent?**
- Eloquent ya tiene relaciones (hasMany, belongsTo)
- Composite agrega la *interfaz uniforme* sobre las relaciones existentes
- No duplica lógica de Eloquent, la extiende

#### 8.5 Integración con Código Existente

**Uso en widgets/dashboard:**
```php
// Funciona igual para Property o Development
function mostrarIngresos(IngresoCalculable $entidad) {
    return $entidad->calcularIngresoMensual();
}

$ingresoPropiedad = mostrarIngresos($property);
$ingresoDesarrollo = mostrarIngresos($development);
```

#### 8.6 Consideraciones de Testing

**Test de composición:**
```php
$property1 = Property::factory()->create();
$property2 = Property::factory()->create();
$development = Development::factory()
    ->has($property1)
    ->has($property2)
    ->create();

$this->assertEquals(
    $property1->calcularIngresoMensual() + $property2->calcularIngresoMensual(),
    $development->calcularIngresoMensual()
);
```

#### 8.7 Archivos a Crear/Modificar

**Crear:**
- `app/Interfaces/IngresoCalculable.php`
- `app/Models/Development.php` (si no existe, tabla ya en DBML)

**Modificar:**
- `app/Models/Property.php` - implementar interfaz
- `app/Models/Development.php` - implementar interfaz
- `app/Filament/Resources/DevelopmentResource.php` - crear resource para gestionar developments
- `app/Filament/Widgets/` - usar interfaz uniforme en widgets

---

### PASO 9: Decorator - Composición Dinámica de Montos

#### 9.1 Propósito del Patrón

Añadir responsabilidades adicionales a un objeto dinámicamente. En este caso, componer el monto total de un pago añadiendo capas (base, servicios, mora).

#### 9.2 Necesidad del Negocio

El monto final de un pago se compone de múltiples capas: monto base del arriendo + servicios (agua/luz/gas) + recargo por mora. Decorator permite esta composición dinámica de forma limpia.

#### 9.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de cálculo de montos de pago usando decoradores que añaden capas sucesivamente (base → servicios → mora) para calcular el monto final.

**Partes de la funcionalidad:**
1. Crear interfaz `CalculadorMonto` con método `calcular(Payment $payment)`
2. Implementar `CalculadorBase` que retorne el `base_amount` del pago
3. Implementar `DecoratorServicios` que envuelva calculador y añada montos de servicios marcados
4. Implementar `DecoratorMora` que envuelva calculador y añada `late_fee_amount`
5. Componer decoradores en orden: base → servicios → mora
6. Usar decorador al crear/actualizar Payment para calcular `amount` final
7. Preservar desglose en base de datos (base_amount, late_fee_amount, flags de servicios)

**Alcance de la implementación:**
- Cálculo automático del monto total al guardar pagos en Filament
- Visualización del desglose en la UI del pago
- Posibilidad de agregar nuevos tipos de cargos como decoradores adicionales
- Auditoría de cómo se compuso el monto final de cada pago

#### 9.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Payments/`

**Estructura del Decorator:**
```php
interface CalculadorMonto {
    public function calcular(Payment $payment): float;
}

class CalculadorBase implements CalculadorMonto {
    public function calcular(Payment $payment): float {
        return $payment->base_amount;
    }
}

class DecoratorServicios implements CalculadorMonto {
    private CalculadorMonto $wrapped;
    
    public function __construct(CalculadorMonto $wrapped) {
        $this->wrapped = $wrapped;
    }
    
    public function calcular(Payment $payment): float {
        $monto = $this->wrapped->calcular($payment);
        
        if ($payment->is_water_paid) $monto += $payment->water_amount ?? 0;
        if ($payment->is_energy_paid) $monto += $payment->energy_amount ?? 0;
        if ($payment->is_gas_paid) $monto += $payment->gas_amount ?? 0;
        
        return $monto;
    }
}

class DecoratorMora implements CalculadorMonto {
    private CalculadorMonto $wrapped;
    
    public function __construct(CalculadorMonto $wrapped) {
        $this->wrapped = $wrapped;
    }
    
    public function calcular(Payment $payment): float {
        return $this->wrapped->calcular($payment) + $payment->late_fee_amount;
    }
}
```

#### 9.4 Decisiones de Diseño

**¿Por qué Decorator y no cálculo directo en modelo?**
- Decorator permite composición dinámica de capas
- Facilita agregar nuevos tipos de cargos sin modificar cálculo existente
- Separa la lógica de composición de la persistencia

**¿Preservación en base de datos?**
- La base de datos guarda el desglose (base_amount, late_fee_amount, flags)
- Decorator opera sobre el cálculo, no sobre la persistencia
- Ambos son complementarios

**¿Orden de decoradores?**
- Base → Servicios → Mora (lógico: primero base, luego extras, luego recargo)
- El orden importa para el cálculo correcto

#### 9.5 Integración con Código Existente

**Uso al crear/actualizar Payment:**
```php
$calculador = new DecoratorMora(
    new DecoratorServicios(
        new CalculadorBase()
    )
);

$payment->amount = $calculador->calcular($payment);
$payment->save();
```

#### 9.6 Consideraciones de Testing

**Test de composición:**
```php
$payment = Payment::factory()->make([
    'base_amount' => 1000,
    'is_water_paid' => true,
    'water_amount' => 50,
    'late_fee_amount' => 100,
]);

$calculador = new DecoratorMora(new DecoratorServicios(new CalculadorBase()));
$total = $calculador->calcular($payment);
$this->assertEquals(1150, $total); // 1000 + 50 + 100
```

#### 9.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Payments/CalculadorMonto.php` (interface)
- `app/Services/Payments/CalculadorBase.php`
- `app/Services/Payments/DecoratorServicios.php`
- `app/Services/Payments/DecoratorMora.php`

**Modificar:**
- `app/Models/Payment.php` - agregar campos water_amount, energy_amount, gas_amount si no existen
- `app/Filament/Resources/PaymentResource.php` - usar calculador al guardar
- `database/migrations/` - actualizar tabla payments si faltan campos

---

### PASO 10: Facade - Facade de Creación de Alquiler

#### 10.1 Propósito del Patrón

Proporcionar una interfaz simplificada a un subsistema complejo. En este caso, simplificar la creación de un alquiler que involucra múltiples entidades y validaciones.

#### 10.2 Necesidad del Negocio

Crear un alquiler (`Rental`) requiere coordinar múltiples operaciones: validar propiedad disponible, validar inquilino disponible, validar fechas, crear el rental, opcionalmente generar primer schedule de pago. Facade simplifica esto para el código cliente.

#### 10.3 Funcionalidad a Implementar

**Funcionalidad específica:** Crear un facade que orquestre la creación completa de alquileres, coordinando validaciones, creación de entidades y generación de dependencias en una sola llamada simplificada.

**Partes de la funcionalidad:**
1. Crear clase `AlquilerFacade` que inyecte servicios necesarios (PropertyService, TenantService, RentalService, PaymentScheduleService)
2. Implementar método `crear(array $datos)` que coordine todo el proceso
3. Validar propiedad disponible usando PropertyService
4. Validar inquilino disponible usando TenantService
5. Validar fechas y montos con lógica específica
6. Crear Rental usando RentalService con datos validados
7. Generar primer PaymentSchedule usando PaymentScheduleService
8. Manejar transacciones y rollback si falla algún paso
9. Retornar Rental creado o lanzar excepción con detalles del error

**Alcance de la implementación:**
- Simplificación de formulario de creación de Rental en Filament
- Reemplazo de lógica dispersa en controladores por llamada única al facade
- Coordinación automática de todas las validaciones y creaciones necesarias
- Manejo unificado de errores y transacciones

#### 10.3 Implementación en Código Actual

**Archivo a crear:** `app/Services/Rentals/AlquilerFacade.php`

**Estructura del Facade:**
```php
class AlquilerFacade {
    private PropertyService $propertyService;
    private TenantService $tenantService;
    private RentalService $rentalService;
    private PaymentScheduleService $scheduleService;
    
    public function __construct(
        PropertyService $propertyService,
        TenantService $tenantService,
        RentalService $rentalService,
        PaymentScheduleService $scheduleService
    ) {
        $this->propertyService = $propertyService;
        $this->tenantService = $tenantService;
        $this->rentalService = $rentalService;
        $this->scheduleService = $scheduleService;
    }
    
    public function crear(array $datos): Rental {
        // 1. Validar propiedad disponible
        $this->propertyService->validarDisponible($datos['property_id']);
        
        // 2. Validar inquilino disponible
        $this->tenantService->validarDisponible($datos['tenant_id']);
        
        // 3. Validar fechas
        $this->validarFechas($datos['start_date'], $datos['end_date']);
        
        // 4. Crear rental
        $rental = $this->rentalService->crear($datos);
        
        // 5. Generar primer schedule de pago
        $this->scheduleService->generarPrimerSchedule($rental);
        
        return $rental;
    }
    
    private function validarFechas($start, $end): void {
        // Lógica de validación de fechas
    }
}
```

#### 10.4 Decisiones de Diseño

**¿Por qué Facade y no llamar servicios directamente?**
- Facade encapsula la secuencia y validaciones
- Código cliente no necesita conocer complejidad interna
- Facilita testing al poder mockear todo el facade

**¿Qué operaciones incluye el facade?**
- Validaciones de negocio (propiedad, inquilino, fechas)
- Creación de entidad principal
- Creación de entidades dependientes
- Transacciones y rollback si falla

**¿Relación con Chain of Responsibility?**
- Chain of Responsibility maneja validaciones encadenadas
- Facade orquesta el flujo completo de creación
- Pueden usarse juntos: Facade usa Chain para validaciones

#### 10.5 Integración con Código Existente

**Uso en controlador o formulario Filament:**
```php
// En RentalResource::form()
Forms\Components\Actions\Action::make('crear')
    ->action(function (array $data) {
        $facade = app(AlquilerFacade::class);
        $rental = $facade->crear($data);
        return redirect(RentalResource::getUrl('edit', ['record' => $rental]));
    });
```

#### 10.6 Consideraciones de Testing

**Test del facade:**
```php
$facade = new AlquilerFacade(
    $mockPropertyService,
    $mockTenantService,
    $mockRentalService,
    $mockScheduleService
);

$rental = $facade->crear($datosValidos);
$this->assertInstanceOf(Rental::class, $rental);
```

#### 10.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Rentals/AlquilerFacade.php`
- `app/Services/Rentals/PropertyService.php` (si no existe)
- `app/Services/Rentals/TenantService.php` (si no existe)
- `app/Services/Rentals/RentalService.php` (si no existe)
- `app/Services/Rentals/PaymentScheduleService.php` (si no existe)

**Modificar:**
- `app/Filament/Resources/RentalResource.php` - usar facade en lugar de lógica directa
- `app/Providers/AppServiceProvider.php` - registrar facade en service container

---

### PASO 11: Flyweight - Modelos de Propiedad como Estado Compartido

#### 11.1 Propósito del Patrón

Reducir el uso de memoria compartiendo estado intrínseco entre objetos similares. En este caso, compartir características de propiedades idénticas entre múltiples unidades.

#### 11.2 Necesidad del Negocio

Un arrendador con un edificio de 20 apartamentos casi idénticos actualmente duplica la misma información (habitaciones, baños, área) 20 veces. Flyweight permite compartir el estado común.

#### 11.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de modelos de propiedad compartidos donde múltiples unidades pueden referenciar el mismo modelo con características comunes, reduciendo duplicación de datos.

**Partes de la funcionalidad:**
1. Crear modelo `PropertyModel` que guarde estado intrínseco compartido (habitaciones, baños, área, etc.)
2. Crear modelo `Property` con estado extrínseco específico (ubicación, estado, identificador)
3. Agregar relación `property->belongsTo(PropertyModel)` para referenciar modelo compartido
4. Implementar accesores dinámicos en Property que deleguen al modelo cuando no tienen valor propio
5. Permitir que `property_model_id` sea nullable para propiedades 100% autónomas
6. Crear migraciones para tablas `property_models` y `property_model_images`
7. Implementar UI para gestionar modelos compartidos y asignarlos a propiedades

**Alcance de la implementación:**
- Creación de modelos de propiedad para edificios con unidades similares
- Asignación de modelos a propiedades individuales
- Actualización en cascada: cambiar modelo actualiza todas las propiedades que lo referencian
- Compatibilidad con propiedades autónomas que no usan modelos
- Gestión de imágenes a nivel de modelo (compartidas) vs propiedad (específicas)

#### 11.3 Implementación en Código Actual

**Archivos a crear/modificar:** `app/Models/`

**Estructura del Flyweight:**
```php
// Estado intrínseco compartido
class PropertyModel extends Model {
    protected $fillable = [
        'name', 'description', 'bedrooms', 'bathrooms', 
        'area_m2', 'parking_spots', 'suggested_monthly_amount'
    ];
    
    // No tiene user_id, es compartido entre propiedades del mismo arrendador
}

// Estado extrínseco específico de cada unidad
class Property extends Model {
    protected $fillable = [
        'unit_identifier', 'name', 'description', 'address',
        'city', 'lat', 'lng', 'status', 'property_model_id'
    ];
    
    public function model() {
        return $this->belongsTo(PropertyModel::class, 'property_model_id');
    }
    
    // Accesores dinámicos al estado compartido
    public function getBedroomsAttribute() {
        return $this->model?->bedrooms ?? $this->attributes['bedrooms'] ?? null;
    }
    
    public function getAreaM2Attribute() {
        return $this->model?->area_m2 ?? $this->attributes['area_m2'] ?? null;
    }
}
```

#### 11.4 Decisiones de Diseño

**¿Qué es estado intrínseco vs extrínseco?**
- Intrínseco (compartido): características físicas (habitaciones, baños, área)
- Extrínseco (específico): ubicación, estado actual, identificador de unidad

**¿Propiedades 100% autónomas vs modeladas?**
- `property_model_id` es nullable: una propiedad puede ser 100% autónoma
- Si no tiene modelo, tiene sus propios atributos
- Si tiene modelo, comparte estado intrínseco

**¿Relación con Prototype?**
- Flyweight comparte estado entre objetos existentes
- Prototype crea nuevos objetos clonando existentes
- Complementarios: puedes clonar un modelo (Prototype) para crear nuevas unidades (Flyweight)

#### 11.5 Integración con Código Existente

**Creación de propiedad con modelo:**
```php
// Crear modelo compartido
$modelo = PropertyModel::create([
    'name' => 'Apartamento Estándar',
    'bedrooms' => 2,
    'bathrooms' => 1,
    'area_m2' => 60,
]);

// Crear múltiples propiedades que comparten el modelo
foreach (['101', '102', '103'] as $unit) {
    Property::create([
        'unit_identifier' => $unit,
        'property_model_id' => $modelo->id,
        'address' => "Calle Principal #$unit",
        'status' => 'disponible',
    ]);
}
```

#### 11.6 Consideraciones de Testing

**Test de compartición:**
```php
$modelo = PropertyModel::factory()->create(['bedrooms' => 2]);
$prop1 = Property::factory()->create(['property_model_id' => $modelo->id]);
$prop2 = Property::factory()->create(['property_model_id' => $modelo->id]);

$modelo->update(['bedrooms' => 3]);

// Ambas propiedades reflejan el cambio en el modelo compartido
$this->assertEquals(3, $prop1->fresh()->bedrooms);
$this->assertEquals(3, $prop2->fresh()->bedrooms);
```

#### 11.7 Archivos a Crear/Modificar

**Crear:**
- `app/Models/PropertyModel.php` (tabla ya en DBML)
- `app/Models/PropertyModelImage.php` (tabla ya en DBML)

**Modificar:**
- `app/Models/Property.php` - agregar relación con PropertyModel
- `app/Models/Property.php` - agregar accesores dinámicos
- `database/migrations/` - crear migraciones para tablas nuevas
- `app/Filament/Resources/PropertyModelResource.php` - crear resource para gestionar modelos

---

### PASO 12: Proxy - Cache de Métricas del Dashboard

#### 12.1 Propósito del Patrón

Proporcionar un sustituto o placeholder para controlar el acceso a un objeto costoso. En este caso, cachear cálculos pesados del dashboard para evitar repetirlos.

#### 12.2 Necesidad del Negocio

Las métricas del dashboard (ingresos mensuales, pagos atrasados, ocupación) requieren consultas costosas a la base de datos. Sin cache, cada recarga de página recalcula todo. Proxy cachea los resultados temporalmente.

#### 12.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar un proxy que cachee los resultados de cálculos pesados del dashboard, mejorando performance y reduciendo carga en la base de datos.

**Partes de la funcionalidad:**
1. Crear interfaz `MetricasDashboard` con métodos para obtener diferentes métricas
2. Implementar `MetricasReales` que realice los cálculos costosos reales
3. Implementar `MetricasProxy` que envuelva a MetricasReales y use Laravel Cache
4. Configurar duración de cache por tipo de métrica (5 min para frecuentes, 1 hora para estáticas)
5. Usar claves de cache específicas por usuario y tipo de métrica
6. Implementar invalidación de cache cuando se modifican datos relevantes
7. Inyectar proxy en widgets del dashboard en lugar de cálculos directos

**Alcance de la implementación:**
- Widgets de dashboard (StatsOverview, IncomeChart, etc.) usan proxy automáticamente
- Invalidación automática de cache al crear/editar Payments o cambiar Rentals
- Configuración de tiempos de cache según frecuencia de cambios
- Mejora perceptible de performance en dashboard con muchos datos

#### 12.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Dashboard/`

**Estructura del Proxy:**
```php
interface MetricasDashboard {
    public function obtenerIngresosMensuales(): float;
    public function obtenerPagosAtrasados(): int;
    public function obtenerOcupacion(): float;
}

class MetricasReales implements MetricasDashboard {
    public function obtenerIngresosMensuales(): float {
        // Cálculo costoso real
        return Payment::whereMonth('date', now()->month)
            ->whereHas('rental', fn($q) => $q->where('user_id', Auth::id()))
            ->sum('amount');
    }
    
    public function obtenerPagosAtrasados(): int {
        // Cálculo costoso real
    }
    
    public function obtenerOcupacion(): float {
        // Cálculo costoso real
    }
}

class MetricasProxy implements MetricasDashboard {
    private MetricasDashboard $metricasReales;
    private int $cacheDuration = 300; // 5 minutos
    
    public function __construct(MetricasDashboard $metricasReales) {
        $this->metricasReales = $metricasReales;
    }
    
    public function obtenerIngresosMensuales(): float {
        return Cache::remember('dashboard:ingresos:' . Auth::id(), $this->cacheDuration, function() {
            return $this->metricasReales->obtenerIngresosMensuales();
        });
    }
    
    public function obtenerPagosAtrasados(): int {
        return Cache::remember('dashboard:atrasados:' . Auth::id(), $this->cacheDuration, function() {
            return $this->metricasReales->obtenerPagosAtrasados();
        });
    }
    
    public function obtenerOcupacion(): float {
        return Cache::remember('dashboard:ocupacion:' . Auth::id(), $this->cacheDuration, function() {
            return $this->metricasReales->obtenerOcupacion();
        });
    }
}
```

#### 12.4 Decisiones de Diseño

**¿Por qué Proxy y no cache directo en widgets?**
- Proxy encapsula la lógica de cache
- Widgets no necesitan saber si los datos vienen de cache o cálculo real
- Facilita cambiar estrategia de cache sin modificar widgets

**¿Duración del cache?**
- 5 minutos para métricas que cambian frecuentemente
- 1 hora para métricas que cambian poco
- Configurable por tipo de métrica

**¿Invalidación de cache?**
- Invalidar al crear/editar payments
- Invalidar al cambiar estado de rentals
- Invalidar manualmente vía comando artisan

#### 12.5 Integración con Código Existente

**Uso en widgets:**
```php
class StatsOverview extends BaseWidget {
    protected function getStats(): array {
        $metricas = app(MetricasDashboard::class); // Inyecta Proxy
        
        return [
            Stat::make('Ingresos', $metricas->obtenerIngresosMensuales()),
            Stat::make('Atrasados', $metricas->obtenerPagosAtrasados()),
            Stat::make('Ocupación', $metricas->obtenerOcupacion() . '%'),
        ];
    }
}
```

#### 12.6 Consideraciones de Testing

**Test de cache:**
```php
Cache::flush();
$proxy = new MetricasProxy(new MetricasReales());

// Primera llamada: calcula y cachea
$result1 = $proxy->obtenerIngresosMensuales();

// Segunda llamada: usa cache
$result2 = $proxy->obtenerIngresosMensuales();

$this->assertEquals($result1, $result2);
```

#### 12.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Dashboard/MetricasDashboard.php` (interface)
- `app/Services/Dashboard/MetricasReales.php`
- `app/Services/Dashboard/MetricasProxy.php`

**Modificar:**
- `app/Filament/Widgets/StatsOverview.php` - usar proxy
- `app/Filament/Widgets/IncomeChart.php` - usar proxy
- `app/Filament/Widgets/OverduePaymentsTable.php` - usar proxy
- `app/Providers/AppServiceProvider.php` - registrar proxy en service container
- `app/Models/Payment.php` - invalidar cache al guardar
- `app/Models/Rental.php` - invalidar cache al cambiar estado

---

## CATEGORÍA 3: PATRONES DE COMPORTAMIENTO

### PASO 13: Chain of Responsibility - Validaciones Encadenadas

#### 13.1 Propósito del Patrón

Permitir que más de un objeto maneje una solicitud sin conocer explícitamente el receptor. En este caso, encadenar validaciones al crear un alquiler.

#### 13.2 Necesidad del Negocio

Crear un alquiler requiere múltiples validaciones: propiedad disponible, inquilino disponible, fechas válidas, montos razonables. Chain of Responsibility permite agregar/remover validaciones sin modificar el código central.

#### 13.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar cadena de validaciones para creación de alquileres donde cada handler valida un aspecto específico y pasa al siguiente si la validación es exitosa.

**Partes de la funcionalidad:**
1. Crear interfaz `ValidationHandler` con métodos `setNext()` y `handle()`
2. Implementar clase abstracta `AbstractHandler` con lógica base de la cadena
3. Implementar handlers concretos: `PropiedadDisponibleHandler`, `InquilinoDisponibleHandler`, `FechasValidasHandler`, `MontosValidosHandler`
4. Cada handler valida su aspecto específico y lanza excepción si falla
5. Crear `ValidationChainFactory` que construya la cadena en el orden apropiado
6. Integrar cadena en AlquilerFacade para validaciones antes de crear rental
7. Permitir agregar/remover handlers dinámicamente según configuración

**Alcance de la implementación:**
- Validaciones automáticas al crear alquiler en Filament
- Errores específicos por cada tipo de validación fallida
- Posibilidad de agregar nuevas validaciones sin modificar código central
- Reutilización de la misma cadena en diferentes contextos (API, UI, CLI)

#### 13.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Validation/`

**Estructura de la cadena:**
```php
interface ValidationHandler {
    public function setNext(ValidationHandler $handler): ValidationHandler;
    public function handle(array $data): bool;
}

abstract class AbstractHandler implements ValidationHandler {
    private ?ValidationHandler $next = null;
    
    public function setNext(ValidationHandler $handler): ValidationHandler {
        $this->next = $handler;
        return $handler;
    }
    
    public function handle(array $data): bool {
        if ($this->next) {
            return $this->next->handle($data);
        }
        return true;
    }
}

class PropiedadDisponibleHandler extends AbstractHandler {
    public function handle(array $data): bool {
        $property = Property::find($data['property_id']);
        
        if ($property->status !== 'disponible') {
            throw new ValidationException('La propiedad no está disponible');
        }
        
        if ($property->rentals()->where('status', 'activo')->exists()) {
            throw new ValidationException('La propiedad ya tiene un alquiler activo');
        }
        
        return parent::handle($data);
    }
}

class InquilinoDisponibleHandler extends AbstractHandler {
    public function handle(array $data): bool {
        $tenant = Tenant::find($data['tenant_id']);
        
        if ($tenant->rentals()->where('status', 'activo')->exists()) {
            throw new ValidationException('El inquilino ya tiene un alquiler activo');
        }
        
        return parent::handle($data);
    }
}

class FechasValidasHandler extends AbstractHandler {
    public function handle(array $data): bool {
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        
        if ($end->lte($start)) {
            throw new ValidationException('La fecha final debe ser posterior a la inicial');
        }
        
        if ($start->lt(now()->subMonth())) {
            throw new ValidationException('La fecha inicial no puede ser tan antigua');
        }
        
        return parent::handle($data);
    }
}
```

#### 13.4 Decisiones de Diseño

**¿Por qué Chain y no validaciones en FormRequest?**
- Chain permite agregar/remover validaciones dinámicamente
- Chain permite reutilizar validaciones en diferentes contextos
- Chain separa validación de la lógica del formulario

**¿Orden de la cadena?**
- Propiedad disponible → Inquilino disponible → Fechas → Montos
- El orden puede afectar performance (validaciones rápidas primero)
- Validaciones costosas al final

**¿Integración con Laravel Validation?**
- Chain puede complementar Laravel Validation
- Validaciones complejas de negocio en Chain
- Validaciones simples de formato en Laravel

#### 13.5 Integración con Código Existente

**Configuración de la cadena:**
```php
class ValidationChainFactory {
    public function crearCadenaAlquiler(): ValidationHandler {
        $propiedad = new PropiedadDisponibleHandler();
        $inquilino = new InquilinoDisponibleHandler();
        $fechas = new FechasValidasHandler();
        $montos = new MontosValidosHandler();
        
        $propiedad->setNext($inquilino)->setNext($fechas)->setNext($montos);
        
        return $propiedad;
    }
}
```

**Uso en facade/servicio:**
```php
$cadena = app(ValidationChainFactory::class)->crearCadenaAlquiler();
$cadena->handle($datos);
```

#### 13.6 Consideraciones de Testing

**Test de cada handler:**
```php
$handler = new PropiedadDisponibleHandler();
$data = ['property_id' => $property->id];

$this->expectException(ValidationException::class);
$handler->handle($data);
```

**Test de la cadena completa:**
```php
$cadena = $factory->crearCadenaAlquiler();
$this->assertTrue($cadena->handle($datosValidos));
```

#### 13.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Validation/ValidationHandler.php` (interface)
- `app/Services/Validation/AbstractHandler.php`
- `app/Services/Validation/PropiedadDisponibleHandler.php`
- `app/Services/Validation/InquilinoDisponibleHandler.php`
- `app/Services/Validation/FechasValidasHandler.php`
- `app/Services/Validation/MontosValidosHandler.php`
- `app/Services/Validation/ValidationChainFactory.php`

**Modificar:**
- `app/Services/Rentals/AlquilerFacade.php` - usar cadena en validación
- `app/Providers/AppServiceProvider.php` - registrar factory en service container

---

### PASO 14: Command - Generación Automática de Cobros

#### 14.1 Propósito del Patrón

Encapsular una solicitud como un objeto, permitiendo parametrizar clientes con diferentes solicitudes, colar solicitudes o registrar operaciones. En este caso, generar automáticamente los cobros mensuales.

#### 14.2 Necesidad del Negocio

El arrendador necesita que los cobros mensuales se generen automáticamente sin que tenga que acordarse. Un comando programado genera los `payment_schedules` para todos los alquileres activos.

#### 14.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar comando artisan que genere automáticamente los cobros mensuales (payment_schedules) para todos los alquileres activos, ejecutándose mediante cron programado.

**Partes de la funcionalidad:**
1. Crear comando artisan `GenerarCobroMensualCommand` con signature y descripción
2. Implementar lógica que recorra todos los rentals con status 'activo' del usuario actual
3. Para cada rental, verificar si ya existe schedule para el mes actual (evitar duplicados)
4. Calcular fecha de vencimiento según `due_day` del rental
5. Crear PaymentSchedule con monto esperado y estado 'pendiente'
6. Implementar manejo de errores: continuar con siguiente rental si falla uno
7. Programar comando en cron diario para ejecución automática
8. Implementar logging de resultados y errores para auditoría

**Alcance de la implementación:**
- Generación automática de schedules el día 1 de cada mes (o diaria con verificación)
- Prevención de duplicados verificando existencia de schedule del periodo
- Integración con State pattern para actualizar estados de schedules automáticamente
- Logging de operaciones para debugging y auditoría
- Ejecución manual posible via `php artisan rentals:generate-monthly-payments`

#### 14.3 Implementación en Código Actual

**Archivos a crear:** `app/Console/Commands/`

**Estructura del Command:**
```php
class GenerarCobroMensualCommand extends Command {
    protected $signature = 'rentals:generate-monthly-payments';
    protected $description = 'Genera automáticamente los cobros mensuales para alquileres activos';
    
    public function handle() {
        $rentals = Rental::where('status', 'activo')
            ->where('user_id', ArrendadorContext::getInstance()->getUserId())
            ->get();
        
        foreach ($rentals as $rental) {
            try {
                $this->generarScheduleParaRental($rental);
                $this->info("Schedule generado para rental {$rental->id}");
            } catch (\Exception $e) {
                $this->error("Error generando schedule para rental {$rental->id}: {$e->getMessage()}");
            }
        }
        
        return 0;
    }
    
    private function generarScheduleParaRental(Rental $rental): void {
        $periodo = now()->startOfMonth();
        
        // Verificar si ya existe schedule para este periodo
        $existente = PaymentSchedule::where('rental_id', $rental->id)
            ->where('period_month', $periodo)
            ->exists();
        
        if ($existente) {
            return; // Ya existe, saltar
        }
        
        PaymentSchedule::create([
            'rental_id' => $rental->id,
            'period_month' => $periodo,
            'due_date' => $this->calcularFechaVencimiento($rental),
            'expected_amount' => $rental->monthly_amount,
            'status' => 'pendiente',
            'user_id' => $rental->user_id,
        ]);
    }
    
    private function calcularFechaVencimiento(Rental $rental): Carbon {
        return now()->startOfMonth()->day($rental->due_day);
    }
}
```

#### 14.4 Decisiones de Diseño

**¿Por qué Command y no simple job en cola?**
- Command encapsula la lógica como objeto reutilizable
- Command puede ejecutarse manualmente (artisan) o programado (cron)
- Command permite logging, retry, rollback más estructurado

**¿Frecuencia de ejecución?**
- Diaria: verifica si debe generar schedules del mes actual
- Mensual: genera schedules el día 1 de cada mes
- Recomendación: diaria con verificación de duplicados

**¿Manejo de errores?**
- Continuar con siguiente rental si falla uno
- Logear errores detallados
- Considerar retry automático para errores transitorios

#### 14.5 Integración con Código Existente

**Programación en cron:**
```bash
# En app/Console/Kernel.php
protected function schedule(Schedule $schedule) {
    $schedule->command('rentals:generate-monthly-payments')
        ->daily()
        ->at('00:00')
        ->withoutOverlapping();
}
```

**Ejecución manual:**
```bash
php artisan rentals:generate-monthly-payments
```

#### 14.6 Consideraciones de Testing

**Test del command:**
```php
$this->artisan('rentals:generate-monthly-payments')
    ->assertExitCode(0);

$this->assertDatabaseHas('payment_schedules', [
    'rental_id' => $rental->id,
    'period_month' => now()->startOfMonth(),
]);
```

#### 14.7 Archivos a Crear/Modificar

**Crear:**
- `app/Console/Commands/GenerarCobroMensualCommand.php`
- `app/Models/PaymentSchedule.php` (tabla ya en DBML)

**Modificar:**
- `app/Console/Kernel.php` - programar command en schedule
- `database/migrations/` - crear migración para payment_schedules

---

### PASO 15: Interpreter - Lenguaje Natural del Arrendador

#### 15.1 Propósito del Patrón

Dado un lenguaje, definir una representación para su gramática junto con un intérprete que usa la representación para interpretar sentencias en el lenguaje. En este caso, interpretar lenguaje natural del arrendador para búsquedas.

#### 15.2 Necesidad del Negocio

Los arrendadores buscan propiedades en lenguaje natural: "propiedades en Bogotá con precio menor a 2000000". Interpreter traduce este lenguaje a consultas Eloquent dinámicas.

#### 15.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar intérprete que procese lenguaje natural del arrendador para búsquedas de propiedades, traduciendo palabras clave a condiciones de consulta Eloquent.

**Partes de la funcionalidad:**
1. Crear clase `PropiedadQueryInterpreter` con diccionario de palabras clave del dominio
2. Definir palabras clave: "en" (ciudad), "con precio menor a" (operador <), "con precio mayor a" (operador >), "disponibles/arrendadas" (estado)
3. Implementar método `interpretar($query)` que extraiga condiciones del lenguaje natural
4. Implementar método `aplicarCondiciones($query, $conditions)` que aplique condiciones a Builder Eloquent
5. Implementar lógica de parsing para extraer valores después de palabras clave
6. Integrar intérprete en SavedFilters para procesar búsquedas guardadas
7. Integrar intérprete en búsqueda UI de propiedades para búsqueda en tiempo real

**Alcance de la implementación:**
- Búsqueda de propiedades en lenguaje natural en la UI
- Guardado de búsquedas como filtros que usan el intérprete
- Soporte para agregar nuevas palabras clave sin modificar lógica principal
- Logging de queries interpretadas para debugging y mejora del sistema

#### 15.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Search/`

**Estructura del Interpreter:**
```php
class PropiedadQueryInterpreter {
    private array $keywords = [
        'en' => 'city',
        'con precio menor a' => '<',
        'con precio mayor a' => '>',
        'disponibles' => 'status = disponible',
        'arrendadas' => 'status = arrendada',
    ];
    
    public function interpretar(string $query): array {
        $conditions = [];
        
        foreach ($this->keywords as $keyword => $campo) {
            if (str_contains(strtolower($query), $keyword)) {
                $conditions[] = $this->parseCondition($query, $keyword, $campo);
            }
        }
        
        return $conditions;
    }
    
    private function parseCondition(string $query, string $keyword, string $campo): array {
        $parts = explode($keyword, strtolower($query));
        $valor = trim($parts[1] ?? '');
        
        if (in_array($campo, ['<', '>'])) {
            return ['field' => 'monthly_amount', 'operator' => $campo, 'value' => (float)$valor];
        }
        
        if ($campo === 'city') {
            return ['field' => 'city', 'operator' => '=', 'value' => $valor];
        }
        
        if (str_contains($campo, '=')) {
            [$field, $value] = explode(' = ', $campo);
            return ['field' => $field, 'operator' => '=', 'value' => $value];
        }
        
        return [];
    }
    
    public function aplicarCondiciones(Builder $query, array $conditions): Builder {
        foreach ($conditions as $condition) {
            $query->where($condition['field'], $condition['operator'], $condition['value']);
        }
        
        return $query;
    }
}
```

#### 15.4 Decisiones de Diseño

**¿Por qué Interpreter simplificado y no DSL completo?**
- DSL completo requiere parser complejo (lexer, parser, AST)
- Interpreter simplificado es suficiente para lenguaje natural básico
- Más educativo y menos overengineering

**¿Palabras clave soportadas?**
- Operadores de ubicación: "en [ciudad]"
- Operadores de precio: "con precio menor/mayor a [monto]"
- Operadores de estado: "disponibles", "arrendadas"
- Extensible con nuevas palabras clave

**¿Manejo de errores?**
- Si no se reconoce palabra clave, ignorar silenciosamente
- Si no se puede extraer valor, usar null
- Loggear queries interpretadas para debugging

#### 15.5 Integración con Código Existente

**Uso en SavedFilters:**
```php
class SavedFilter extends Model {
    public function aplicarQuery(): Builder {
        $interpreter = new PropiedadQueryInterpreter();
        $conditions = $interpreter->interpretar($this->definition);
        
        $query = Property::query();
        return $interpreter->aplicarCondiciones($query, $conditions);
    }
}
```

**Uso en búsqueda UI:**
```php
$query = $request->get('query');
$interpreter = new PropiedadQueryInterpreter();
$conditions = $interpreter->interpretar($query);

$properties = Property::where('user_id', Auth::id());
$properties = $interpreter->aplicarCondiciones($properties, $conditions);
```

#### 15.6 Consideraciones de Testing

**Test de interpretación:**
```php
$interpreter = new PropiedadQueryInterpreter();
$conditions = $interpreter->interpretar("propiedades en Bogotá con precio menor a 2000000");

$this->assertCount(2, $conditions);
$this->assertEquals('city', $conditions[0]['field']);
$this->assertEquals('<', $conditions[1]['operator']);
```

#### 15.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Search/PropiedadQueryInterpreter.php`

**Modificar:**
- `app/Models/SavedFilter.php` - usar interpreter para aplicar query
- `app/Filament/Resources/PropertyResource.php` - integrar búsqueda natural
- `app/Filament/Resources/SavedFilterResource.php` - crear resource para gestionar filtros guardados

---

### PASO 16: Iterator - Reporte Anual con Generators

#### 16.1 Propósito del Patrón

Proporcionar una forma de acceder secuencialmente a los elementos de un objeto agregado sin exponer su representación subyacente. En este caso, recorrer pagos anuales sin cargar todo en memoria.

#### 16.2 Necesidad del Negocio

El arrendador necesita un reporte anual de todos los pagos para declaración de renta. Cargar años de historial en memoria no escala. Iterator permite procesar los datos en chunks.

#### 16.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar iterator que recorra pagos anuales en chunks sin cargar todo en memoria, permitiendo procesar grandes volúmenes de datos para reportes fiscales.

**Partes de la funcionalidad:**
1. Crear clase `PaymentIterator` que implemente interfaz Iterator de PHP
2. Implementar lógica de carga de chunks usando offset/limit de Eloquent
3. Implementar métodos de Iterator: current(), next(), key(), valid(), rewind()
4. Configurar tamaño de chunk (100-500 registros) balanceando memoria y performance
5. Crear `ReporteAnualService` que use el iterator para procesar datos año por año
6. Implementar agregación de datos (ingresos totales, por rental, por período) usando el iterator
7. Generar reporte final con estructura optimizada para declaración de renta

**Alcance de la implementación:**
- Generación de reportes anuales para declaración de renta sin agotar memoria
- Exportación de datos a CSV/Excel usando iteración eficiente
- Procesamiento de grandes volúmenes de datos históricos
- Paginación automática en reportes grandes

#### 16.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Reports/`

**Estructura del Iterator:**
```php
class PaymentIterator implements \Iterator {
    private int $position = 0;
    private array $chunk;
    private int $chunkSize = 100;
    private int $totalProcessed = 0;
    private Builder $query;
    private ?\Closure $callback = null;
    
    public function __construct(Builder $query, int $chunkSize = 100) {
        $this->query = $query;
        $this->chunkSize = $chunkSize;
        $this->loadFirstChunk();
    }
    
    private function loadFirstChunk(): void {
        $this->chunk = $this->query
            ->offset($this->totalProcessed)
            ->limit($this->chunkSize)
            ->get()
            ->toArray();
    }
    
    public function current(): mixed {
        return $this->chunk[$this->position];
    }
    
    public function next(): void {
        $this->position++;
        
        if ($this->position >= count($this->chunk)) {
            $this->loadNextChunk();
            $this->position = 0;
        }
    }
    
    private function loadNextChunk(): void {
        $this->totalProcessed += count($this->chunk);
        $this->chunk = $this->query
            ->offset($this->totalProcessed)
            ->limit($this->chunkSize)
            ->get()
            ->toArray();
    }
    
    public function key(): mixed {
        return $this->totalProcessed + $this->position;
    }
    
    public function valid(): bool {
        return isset($this->chunk[$this->position]);
    }
    
    public function rewind(): void {
        $this->position = 0;
        $this->totalProcessed = 0;
        $this->loadFirstChunk();
    }
}
```

#### 16.4 Decisiones de Diseño

**¿Por qué Iterator manual y no chunk() de Eloquent?**
- Iterator manual demuestra el patrón educativamente
- chunk() de Eloquent es más práctico pero oculta el patrón
- Para fines educativos, Iterator manual es más claro

**¿Tamaño del chunk?**
- 100-500 registros por chunk
- Configurable según memoria disponible
- Balance entre memoria y performance

**¿Uso de generators de PHP?**
- Alternative más simple: `yield` en generators
- Iterator class es más explícito sobre el patrón
- Generators son más "PHP-way", Iterator es más "GoF-way"

#### 16.5 Integración con Código Existente

**Uso en reporte anual:**
```php
class ReporteAnualService {
    public function generar(int $userId, int $year): array {
        $query = Payment::where('user_id', $userId)
            ->whereYear('date', $year);
        
        $iterator = new PaymentIterator($query, 200);
        
        $reporte = [
            'total_ingresos' => 0,
            'total_pagos' => 0,
            'por_rental' => [],
        ];
        
        foreach ($iterator as $payment) {
            $reporte['total_ingresos'] += $payment['amount'];
            $reporte['total_pagos']++;
            
            $rentalId = $payment['rental_id'];
            if (!isset($reporte['por_rental'][$rentalId])) {
                $reporte['por_rental'][$rentalId] = 0;
            }
            $reporte['por_rental'][$rentalId] += $payment['amount'];
        }
        
        return $reporte;
    }
}
```

#### 16.6 Consideraciones de Testing

**Test del iterator:**
```php
Payment::factory()->count(250)->create();
$query = Payment::query();
$iterator = new PaymentIterator($query, 100);

$count = 0;
foreach ($iterator as $payment) {
    $count++;
}

$this->assertEquals(250, $count);
```

#### 16.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Reports/PaymentIterator.php`
- `app/Services/Reports/ReporteAnualService.php`

**Modificar:**
- `app/Filament/Resources/PaymentResource.php` - agregar acción de exportar reporte anual
- `app/Http/Controllers/` - agregar endpoint para reporte anual (si aplica)

---

### PASO 17: Mediator - Coordinación Entre Entidades

#### 17.1 Propósito del Patrón

Definir un objeto que encapsule cómo un conjunto de objetos interactúa. Promueve loose coupling al evitar que los objetos se refieran unos a otros explícitamente.

#### 17.2 Necesidad del Negocio

Cuando un pago cierra un schedule, alguien debe decidir si actualiza el rental status, libera al tenant, etc. Sin Mediator, los modelos se llaman directamente creando acoplamiento.

#### 17.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar mediador que coordine las reacciones complejas entre entidades cuando ocurren eventos importantes (pagos completados, schedules atrasados, etc.), centralizando la lógica de coordinación.

**Partes de la funcionalidad:**
1. Crear clase `GestionAlquilerMediator` que inyecte servicios necesarios (RentalService, TenantService, PaymentScheduleService, NotificationService)
2. Implementar método `pagoCompletado()` que coordine actualización de schedule, rental, tenant y notificaciones
3. Implementar método `scheduleAtrasado()` que coordine actualización de schedule, rental y alertas
4. Centralizar lógica de coordinación que de otra forma estaría dispersa en múltiples observers
5. Definir secuencias específicas de acciones para cada tipo de evento
6. Implementar manejo de errores y rollback si falla algún paso de la coordinación
7. Integrar mediador en observers que detectan eventos y delegan coordinación al mediador

**Alcance de la implementación:**
- Coordinación automática de actualizaciones de estado cuando se completan pagos
- Coordinación de notificaciones cuando hay cambios importantes
- Reducción de acoplamiento entre modelos (Property, Rental, Payment, Tenant)
- Centralización de lógica de negocio compleja en un solo lugar

#### 17.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Mediation/`

**Estructura del Mediator:**
```php
class GestionAlquilerMediator {
    private RentalService $rentalService;
    private TenantService $tenantService;
    private PaymentScheduleService $scheduleService;
    private NotificationService $notificationService;
    
    public function __construct(
        RentalService $rentalService,
        TenantService $tenantService,
        PaymentScheduleService $scheduleService,
        NotificationService $notificationService
    ) {
        $this->rentalService = $rentalService;
        $this->tenantService = $tenantService;
        $this->scheduleService = $scheduleService;
        $this->notificationService = $notificationService;
    }
    
    public function pagoCompletado(Payment $payment): void {
        // 1. Actualizar schedule asociado
        $schedule = $this->scheduleService->marcarComoPagado($payment);
        
        // 2. Verificar si todos los schedules del rental están pagados
        $rental = $payment->rental;
        $todosPagados = $this->scheduleService->estanTodosPagados($rental);
        
        if ($todosPagados) {
            // 3. Actualizar estado del rental
            $this->rentalService->marcarComoAlDia($rental);
            
            // 4. Verificar si se puede liberar al tenant
            if ($this->rentalService->estaFinalizado($rental)) {
                $this->tenantService->liberarTenant($rental->tenant);
            }
        }
        
        // 5. Enviar notificación de confirmación
        $this->notificationService->enviarConfirmacionPago($payment);
    }
    
    public function scheduleAtrasado(PaymentSchedule $schedule): void {
        // 1. Actualizar estado del schedule
        $this->scheduleService->marcarComoAtrasado($schedule);
        
        // 2. Verificar si múltiples schedules atrasados
        $rental = $schedule->rental;
        $multiplesAtrasados = $this->scheduleService->hayMultiplesAtrasados($rental);
        
        if ($multiplesAtrasados) {
            // 3. Actualizar estado del rental
            $this->rentalService->marcarComoAtrasado($rental);
        }
        
        // 4. Enviar notificación de mora
        $this->notificationService->enviarAlertaMora($schedule);
    }
}
```

#### 17.4 Decisiones de Diseño

**¿Por qué Mediator y no eventos/observers?**
- Mediator centraliza la lógica de coordinación compleja
- Events/Observers son para notificaciones simples
- Mediator encapsula secuencia de acciones específicas

**¿Qué coordinaciones maneja?**
- Pago completado → actualiza schedule → actualiza rental → libera tenant
- Schedule atrasado → actualiza schedule → actualiza rental → notifica
- Rental finalizado → actualiza rental → libera tenant → archiva

**¿Relación con Observer?**
- Observer detecta eventos (pago guardado, schedule vencido)
- Mediator coordina las reacciones a esos eventos
- Complementarios: Observer notifica, Mediator coordina

#### 17.5 Integración con Código Existente

**Uso en observers:**
```php
class PaymentObserver {
    public function updated(Payment $payment) {
        if ($payment->wasChanged('amount') && $payment->amount > 0) {
            $mediator = app(GestionAlquilerMediator::class);
            $mediator->pagoCompletado($payment);
        }
    }
}

class PaymentScheduleObserver {
    public function updated(PaymentSchedule $schedule) {
        if ($schedule->wasChanged('status') && $schedule->status === 'atrasado') {
            $mediator = app(GestionAlquilerMediator::class);
            $mediator->scheduleAtrasado($schedule);
        }
    }
}
```

#### 17.6 Consideraciones de Testing

**Test del mediator:**
```php
$mediator = new GestionAlquilerMediator(
    $mockRentalService,
    $mockTenantService,
    $mockScheduleService,
    $mockNotificationService
);

$payment = Payment::factory()->make();
$mediator->pagoCompletado($payment);

$mockScheduleService->shouldHaveReceived('marcarComoPagado');
$mockNotificationService->shouldHaveReceived('enviarConfirmacionPago');
```

#### 17.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Mediation/GestionAlquilerMediator.php`
- `app/Services/Rentals/RentalService.php` (si no existe)
- `app/Services/Tenants/TenantService.php` (si no existe)
- `app/Services/Payments/PaymentScheduleService.php` (si no existe)

**Modificar:**
- `app/Models/Payment.php` - agregar observer
- `app/Models/PaymentSchedule.php` - agregar observer
- `app/Providers/AppServiceProvider.php` - registrar mediator en service container

---

### PASO 18: Memento - Snapshots de Entidades

#### 18.1 Propósito del Patrón

Capturar y externalizar el estado interno de un objeto sin violar encapsulamiento, permitiendo restaurar el estado posteriormente. En este caso, versionar cambios en propiedades y modelos.

#### 18.2 Necesidad del Negocio

Dado que ahora un modelo de propiedad es compartido (Flyweight), un error al editarlo afecta a múltiples unidades. Memento permite revertir a versiones anteriores mediante snapshots.

#### 18.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de versionado de entidades mediante snapshots que permita guardar estados anteriores de PropertyModels y Properties, con capacidad de restauración.

**Partes de la funcionalidad:**
1. Crear modelo `EntitySnapshot` que guarde entity_type, entity_id, snapshot (JSON) y user_id
2. Crear clase `SnapshotManager` con métodos para crear, restaurar y listar snapshots
3. Implementar método `crearSnapshot()` que capture atributos actuales de una entidad
4. Implementar método `restaurarSnapshot()` que restaure entidad desde snapshot guardado
5. Implementar método `obtenerHistorial()` que liste snapshots de una entidad ordenados por fecha
6. Crear observers en PropertyModel y Property que generen snapshot antes de guardar cambios
7. Implementar acción de restauración en UI de Filament para volver a versiones anteriores
8. Implementar limpieza automática de snapshots antiguos según política de retención

**Alcance de la implementación:**
- Versionado automático de PropertyModels (crítico por Flyweight)
- Versionado automático de Properties (opcional)
- Restauración de versiones anteriores vía UI
- Historial de cambios visible para auditoría
- Protección contra errores en edición de modelos compartidos

#### 18.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Versioning/`

**Estructura del Memento:**
```php
class EntitySnapshot extends Model {
    protected $fillable = [
        'entity_type', 'entity_id', 'snapshot', 'user_id'
    ];
    
    protected $casts = [
        'snapshot' => 'array',
    ];
}

class SnapshotManager {
    public function crearSnapshot(Model $entity, int $userId): EntitySnapshot {
        return EntitySnapshot::create([
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'snapshot' => $entity->getAttributes(),
            'user_id' => $userId,
        ]);
    }
    
    public function restaurarSnapshot(EntitySnapshot $snapshot): Model {
        $entity = $snapshot->entity_type::find($snapshot->entity_id);
        
        if (!$entity) {
            throw new ModelNotFoundException("Entidad no encontrada");
        }
        
        $entity->fill($snapshot->snapshot);
        $entity->save();
        
        return $entity;
    }
    
    public function obtenerHistorial(Model $entity): Collection {
        return EntitySnapshot::where('entity_type', get_class($entity))
            ->where('entity_id', $entity->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
```

#### 18.4 Decisiones de Diseño

**¿Qué entidades versionar?**
- Property (crítico por Flyweight)
- PropertyModel (crítico por Flyweight)
- Opcional: Rental, Payment para auditoría

**¿Cuándo crear snapshots?**
- Antes de edición significativa
- En intervalos regulares (ej: cada 10 ediciones)
- Manualmente por acción del usuario

**¿Política de retención?**
- Mantener últimos N snapshots (ej: 10)
- Mantener snapshots por período (ej: 30 días)
- Limpieza automática via job programado

#### 18.5 Integración con Código Existente

**Uso en observers:**
```php
class PropertyModelObserver {
    public function updating(PropertyModel $model) {
        // Crear snapshot antes de guardar cambios
        $manager = app(SnapshotManager::class);
        $manager->crearSnapshot($model->getOriginal(), Auth::id());
    }
}
```

**Acción de restauración en UI:**
```php
// En PropertyModelResource
Actions\Action::make('restore_snapshot')
    ->form([
        Select::make('snapshot_id')
            ->options(fn($record) => $record->snapshots->pluck('created_at', 'id'))
    ])
    ->action(function (PropertyModel $record, array $data) {
        $snapshot = EntitySnapshot::find($data['snapshot_id']);
        $manager = app(SnapshotManager::class);
        $manager->restaurarSnapshot($snapshot);
    });
```

#### 18.6 Consideraciones de Testing

**Test de snapshot:**
```php
$model = PropertyModel::factory()->create();
$manager = new SnapshotManager();

$snapshot = $manager->crearSnapshot($model, 1);
$this->assertDatabaseHas('entity_snapshots', [
    'entity_id' => $model->id,
    'entity_type' => PropertyModel::class,
]);

$model->update(['bedrooms' => 5]);
$manager->restaurarSnapshot($snapshot);
$this->assertEquals(2, $model->fresh()->bedrooms); // Valor original
```

#### 18.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Versioning/SnapshotManager.php`
- `app/Models/EntitySnapshot.php` (tabla ya en DBML)

**Modificar:**
- `app/Models/PropertyModel.php` - agregar observer
- `app/Models/Property.php` - agregar observer (opcional)
- `app/Filament/Resources/PropertyModelResource.php` - agregar acción de restaurar snapshot
- `database/migrations/` - crear migración para entity_snapshots

---

### PASO 19: Observer - Detección de Eventos

#### 19.1 Propósito del Patrón

Definir una dependencia uno-a-muchos entre objetos de manera que cuando uno cambia de estado, todos sus dependientes son notificados y actualizados automáticamente.

#### 19.2 Necesidad del Negocio

El sistema necesita reaccionar a eventos: cuando un schedule pasa a atrasado, cuando un rental está por vencer, cuando se completa un pago. Observer permite notificaciones automáticas.

#### 19.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de detección de eventos usando Laravel Events/Listeners que permita reacciones automáticas cuando ocurren cambios importantes en el sistema.

**Partes de la funcionalidad:**
1. Crear eventos Laravel: `ScheduleAtrasado`, `RentalPorVencer`, `PagoCompletado`
2. Crear listeners que reaccionen a cada evento: `EnviarAlertaMoraListener`, `EnviarAlertaVencimientoListener`, `EnviarConfirmacionPagoListener`
3. Implementar lógica de detección en observers de modelos (PaymentScheduleObserver, RentalObserver, PaymentObserver)
4. Disparar eventos cuando se detectan condiciones específicas (cambio de status, fechas próximas, etc.)
5. Integrar listeners con Bridge pattern para usar preferencias de canales del usuario
6. Implementar dispatch asíncrono para events no críticos (notificaciones)
7. Registrar eventos y listeners en EventServiceProvider de Laravel

**Alcance de la implementación:**
- Detección automática de schedules atrasados y envío de alertas
- Detección de rentals por vencer y envío de recordatorios
- Confirmación automática cuando se completan pagos
- Sistema extensible: agregar nuevos events/listeners sin modificar código existente

#### 19.3 Implementación en Código Actual

**Archivos a crear/modificar:** `app/Observers/`, `app/Events/`, `app/Listeners/`

**Estructura del Observer (usando Laravel Events):**
```php
// Eventos
class ScheduleAtrasado {
    public function __construct(
        public PaymentSchedule $schedule
    ) {}
}

class RentalPorVencer {
    public function __construct(
        public Rental $rental
    ) {}
}

// Listeners
class EnviarAlertaMoraListener {
    public function handle(ScheduleAtrasado $event) {
        $notificacion = app(NotificationFactory::class)
            ->create('pago_atrasado', ['schedule' => $event->schedule]);
        
        $configurator = app(AlertPreferenceService::class)
            ->loadForUser($event->schedule->user_id);
        
        $configurator->sendAlert($notificacion);
    }
}

class EnviarAlertaVencimientoListener {
    public function handle(RentalPorVencer $event) {
        $notificacion = app(NotificationFactory::class)
            ->create('contrato_vencer', ['rental' => $event->rental]);
        
        $configurator = app(AlertPreferenceService::class)
            ->loadForUser($event->rental->user_id);
        
        $configurator->sendAlert($notificacion);
    }
}
```

#### 19.4 Decisiones de Diseño

**¿Por qué Laravel Events y no observers clásicos?**
- Laravel Events es la implementación nativa de Observer
- Más idiomático en ecosistema Laravel
- Permite dispatch asíncrono via queues

**¿Qué eventos disparar?**
- Schedule pasa a atrasado
- Rental está por vencer (7 días)
- Rental finalizado
- Pago completado

**¿Síncrono vs asíncrono?**
- Events críticos: síncronos (ej: actualización de estado)
- Events no críticos: asíncronos (ej: notificaciones)
- Configurable por tipo de event

#### 19.5 Integración con Código Existente

**Dispatch de eventos:**
```php
class PaymentScheduleObserver {
    public function updated(PaymentSchedule $schedule) {
        if ($schedule->wasChanged('status') && $schedule->status === 'atrasado') {
            event(new ScheduleAtrasado($schedule));
        }
    }
}

class RentalObserver {
    public function updating(Rental $rental) {
        if ($rental->end_date->diffInDays(now()) <= 7 && $rental->status === 'activo') {
            event(new RentalPorVencer($rental));
        }
    }
}
```

**Registro de listeners:**
```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    ScheduleAtrasado::class => [
        EnviarAlertaMoraListener::class,
    ],
    RentalPorVencer::class => [
        EnviarAlertaVencimientoListener::class,
    ],
];
```

#### 19.6 Consideraciones de Testing

**Test de event dispatch:**
```php
Event::fake();
$schedule->update(['status' => 'atrasado']);
Event::assertDispatched(ScheduleAtrasado::class);
```

**Test de listener:**
```php
$listener = new EnviarAlertaMoraListener();
$event = new ScheduleAtrasado($schedule);
$listener->handle($event);
// Assert que se envió notificación
```

#### 19.7 Archivos a Crear/Modificar

**Crear:**
- `app/Events/ScheduleAtrasado.php`
- `app/Events/RentalPorVencer.php`
- `app/Listeners/EnviarAlertaMoraListener.php`
- `app/Listeners/EnviarAlertaVencimientoListener.php`

**Modificar:**
- `app/Providers/EventServiceProvider.php` - registrar events/listeners
- `app/Models/PaymentSchedule.php` - agregar observer
- `app/Models/Rental.php` - agregar observer

---

### PASO 20: State - Máquina de Estados de Alquileres

#### 20.1 Propósito del Patrón

Permitir que un objeto altere su comportamiento cuando su estado interno cambia. El objeto aparecerá como si cambiara de clase.

#### 20.2 Necesidad del Negocio

Los alquileres y schedules tienen estados reales con transiciones específicas: pendiente → atrasado → pagado, activo → atrasado → finalizado. State encapsula las reglas de transición.

#### 20.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar máquina de estados para Rentals y PaymentSchedules que encapsule las reglas de transición válidas y el comportamiento específico de cada estado.

**Partes de la funcionalidad:**
1. Crear interfaz `RentalState` con métodos que definan comportamiento de cada estado (puedeFinalizar, puedeCancelar, siguienteEstado)
2. Implementar clases concretas de estado: `RentalActivoState`, `RentalAtrasadoState`, `RentalFinalizadoState`, `RentalCanceladoState`
3. Cada estado implementa sus propias reglas de transiciones y comportamiento
4. Crear `RentalStateMachine` que gestione transiciones entre estados validando reglas
5. Implementar lógica de validación de transiciones (no puede ir de atrasado a finalizado sin saldar)
6. Integrar state machine en RentalService para cambios de estado controlados
7. Actualizar UI de Filament para mostrar solo transiciones válidas según estado actual

**Alcance de la implementación:**
- Control estricto de transiciones de estado en rentals y schedules
- Prevención de transiciones inválidas que romperían lógica de negocio
- Comportamiento específico por estado (ej: no se puede cancelar rental finalizado)
- Visualización en UI de estados actuales y transiciones permitidas

#### 20.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/States/`

**Estructura del State:**
```php
interface RentalState {
    public function puedeFinalizar(): bool;
    public function puedeCancelar(): bool;
    public function siguienteEstado(): ?string;
}

class RentalActivoState implements RentalState {
    public function puedeFinalizar(): bool {
        return false; // Requiere saldar pendientes primero
    }
    
    public function puedeCancelar(): bool {
        return true;
    }
    
    public function siguienteEstado(): ?string {
        return 'atrasado'; // Si hay pagos atrasados
    }
}

class RentalAtrasadoState implements RentalState {
    public function puedeFinalizar(): bool {
        return false; // Requiere saldar atrasos primero
    }
    
    public function puedeCancelar(): bool {
        return true;
    }
    
    public function siguienteEstado(): ?string {
        return 'activo'; // Si se ponen al día
    }
}

class RentalFinalizadoState implements RentalState {
    public function puedeFinalizar(): bool {
        return false; // Ya está finalizado
    }
    
    public function puedeCancelar(): bool {
        return false; // No se puede cancelar ya finalizado
    }
    
    public function siguienteEstado(): ?string {
        return null; // Estado terminal
    }
}

class RentalStateMachine {
    private array $states = [
        'activo' => RentalActivoState::class,
        'atrasado' => RentalAtrasadoState::class,
        'finalizado' => RentalFinalizadoState::class,
        'cancelado' => RentalCanceladoState::class,
    ];
    
    public function transition(Rental $rental, string $nuevoEstado): bool {
        $estadoActual = $this->getState($rental->status);
        $estadoObjetivo = $this->getState($nuevoEstado);
        
        if (!$this->transicionValida($estadoActual, $estadoObjetivo)) {
            throw new InvalidStateException("Transición inválida de {$rental->status} a {$nuevoEstado}");
        }
        
        $rental->status = $nuevoEstado;
        $rental->save();
        
        return true;
    }
    
    private function getState(string $estado): RentalState {
        return new $this->states[$estado]();
    }
    
    private function transicionValida(RentalState $actual, RentalState $objetivo): bool {
        // Lógica de validación de transiciones permitidas
        return true; // Simplificado para ejemplo
    }
}
```

#### 20.4 Decisiones de Diseño

**¿Por qué State y no simple enum con validaciones?**
- State encapsula comportamiento específico de cada estado
- State permite agregar nuevos estados sin modificar lógica existente
- State separa reglas de transición del modelo

**¿Qué transiciones permitir?**
- activo → atrasado (si hay pagos atrasados)
- atrasado → activo (si se ponen al día)
- activo → finalizado (si todos los pagos completados)
- activo → cancelado (si se cancela el contrato)

**¿Relación con Command?**
- Command genera schedules que cambian estados
- State maneja las transiciones válidas
- Complementarios: Command dispara cambios, State valida

#### 20.5 Integración con Código Existente

**Uso en servicio/observer:**
```php
class RentalService {
    public function actualizarEstado(Rental $rental, string $nuevoEstado): void {
        $stateMachine = app(RentalStateMachine::class);
        $stateMachine->transition($rental, $nuevoEstado);
    }
}
```

**Validaciones en UI:**
```php
// En RentalResource
Forms\Components\Select::make('status')
    ->options(fn($record) => $this->obtenerEstadosPermitidos($record))
    ->disabled(fn($record) => !$record->estadoActual()->puedeCambiar());
```

#### 20.6 Consideraciones de Testing

**Test de transiciones:**
```php
$machine = new RentalStateMachine();
$rental = Rental::factory()->create(['status' => 'activo']);

$this->assertTrue($machine->transition($rental, 'atrasado'));
$this->assertEquals('atrasado', $rental->fresh()->status);

$this->expectException(InvalidStateException::class);
$machine->transition($rental, 'finalizado'); // Transición inválida
```

#### 20.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/States/RentalState.php` (interface)
- `app/Services/States/RentalActivoState.php`
- `app/Services/States/RentalAtrasadoState.php`
- `app/Services/States/RentalFinalizadoState.php`
- `app/Services/States/RentalCanceladoState.php`
- `app/Services/States/RentalStateMachine.php`
- `app/Exceptions/InvalidStateException.php`

**Modificar:**
- `app/Models/Rental.php` - agregar relación con state machine
- `app/Services/Rentals/RentalService.php` - usar state machine
- `database/migrations/` - actualizar rentals.status si usa boolean actualmente

---

### PASO 21: Strategy - Cálculo de Mora Intercambiable

#### 21.1 Propósito del Patrón

Definir una familia de algoritmos, encapsular cada uno y hacerlos intercambiables. Strategy permite que el algoritmo varíe independientemente de los clientes que lo usan.

#### 21.2 Necesidad del Negocio

Los arrendadores colombianos manejan el recargo por mora de formas diferentes: porcentaje fijo, interés diario, monto fijo, o ninguno. Strategy permite configurar la estrategia preferida.

#### 21.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar sistema de cálculo de mora intercambiable donde cada arrendador pueda configurar su estrategia preferida, permitiendo agregar nuevas estrategias sin modificar código existente.

**Partes de la funcionalidad:**
1. Crear interfaz `LateFeeStrategy` con método `calcular($montoBase, $diasAtraso, $config)`
2. Implementar estrategias concretas: `PorcentajeFijoStrategy`, `InteresDiarioStrategy`, `MontoFijoStrategy`, `NingunaStrategy`
3. Cada estrategia implementa su propio algoritmo de cálculo de recargo
4. Crear `LateFeeCalculator` que seleccione estrategia según configuración del usuario
5. Agregar campos de configuración en User: `default_late_fee_strategy`, `default_late_fee_value`
6. Integrar calculator en observer de PaymentSchedule para calcular mora cuando pasa a atrasado
7. Implementar UI de configuración en perfil de usuario para seleccionar estrategia preferida

**Alcance de la implementación:**
- Cálculo automático de mora según preferencias de cada arrendador
- Configuración por usuario en su perfil
- Posibilidad de agregar nuevas estrategias de cálculo sin modificar lógica existente
- Cálculo transparente y auditable de cómo se calculó cada recargo

#### 21.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/LateFee/`

**Estructura del Strategy:**
```php
interface LateFeeStrategy {
    public function calcular(float $montoBase, int $diasAtraso, array $config): float;
}

class PorcentajeFijoStrategy implements LateFeeStrategy {
    public function calcular(float $montoBase, int $diasAtraso, array $config): float {
        $porcentaje = $config['value'] ?? 0.10; // 10% por defecto
        return $montoBase * $porcentaje;
    }
}

class InteresDiarioStrategy implements LateFeeStrategy {
    public function calcular(float $montoBase, int $diasAtraso, array $config): float {
        $tasaDiaria = $config['value'] ?? 0.001; // 0.1% diario por defecto
        return $montoBase * $tasaDiaria * $diasAtraso;
    }
}

class MontoFijoStrategy implements LateFeeStrategy {
    public function calcular(float $montoBase, int $diasAtraso, array $config): float {
        return $config['value'] ?? 50000; // $50,000 por defecto
    }
}

class NingunaStrategy implements LateFeeStrategy {
    public function calcular(float $montoBase, int $diasAtraso, array $config): float {
        return 0;
    }
}

class LateFeeCalculator {
    private array $strategies = [
        'fixed_percentage' => PorcentajeFijoStrategy::class,
        'daily_interest' => InteresDiarioStrategy::class,
        'fixed_amount' => MontoFijoStrategy::class,
        'none' => NingunaStrategy::class,
    ];
    
    public function calcular(User $user, PaymentSchedule $schedule): float {
        $strategyName = $user->default_late_fee_strategy;
        $strategyClass = $this->strategies[$strategyName] ?? NingunaStrategy::class;
        
        $strategy = new $strategyClass();
        
        $diasAtraso = $schedule->calcularDiasAtraso();
        $config = ['value' => $user->default_late_fee_value];
        
        return $strategy->calcular($schedule->expected_amount, $diasAtraso, $config);
    }
}
```

#### 21.4 Decisiones de Diseño

**¿Por qué Strategy y no simple switch?**
- Strategy encapsula cada algoritmo en su propia clase
- Strategy permite agregar nuevas estrategias sin modificar cálculo
- Strategy facilita testing de cada algoritmo independientemente

**¿Qué estrategias soportar?**
- Porcentaje fijo del monto base
- Interés compuesto diario
- Monto fijo independiente del monto
- Sin recargo (para arrendadores que no cobran mora)

**¿Configuración por usuario?**
- Cada arrendador configura su estrategia preferida
- Almacenado en `users.default_late_fee_strategy` y `default_late_fee_value`
- Sobreescribible por rental si necesario

#### 21.5 Integración con Código Existente

**Uso cuando schedule pasa a atrasado:**
```php
class PaymentScheduleObserver {
    public function updated(PaymentSchedule $schedule) {
        if ($schedule->wasChanged('status') && $schedule->status === 'atrasado') {
            $calculator = app(LateFeeCalculator::class);
            $user = $schedule->user;
            
            $lateFee = $calculator->calcular($user, $schedule);
            
            $schedule->late_fee_amount = $lateFee;
            $schedule->save();
        }
    }
}
```

**Configuración en perfil de usuario:**
```php
// En UserResource
Forms\Components\Select::make('default_late_fee_strategy')
    ->options([
        'fixed_percentage' => 'Porcentaje fijo',
        'daily_interest' => 'Interés diario',
        'fixed_amount' => 'Monto fijo',
        'none' => 'Sin recargo',
    ]);

Forms\Components\TextInput::make('default_late_fee_value')
    ->numeric()
    ->visible(fn($get) => $get('default_late_fee_strategy') !== 'none');
```

#### 21.6 Consideraciones de Testing

**Test de cada estrategia:**
```php
$strategy = new PorcentajeFijoStrategy();
$monto = $strategy->calcular(1000000, 5, ['value' => 0.10]);
$this->assertEquals(100000, $monto); // 10% de 1M
```

**Test del calculator:**
```php
$user = User::factory()->create([
    'default_late_fee_strategy' => 'fixed_percentage',
    'default_late_fee_value' => 0.10,
]);

$schedule = PaymentSchedule::factory()->make([
    'expected_amount' => 1000000,
]);

$calculator = new LateFeeCalculator();
$mora = $calculator->calcular($user, $schedule);
$this->assertEquals(100000, $mora);
```

#### 21.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/LateFee/LateFeeStrategy.php` (interface)
- `app/Services/LateFee/PorcentajeFijoStrategy.php`
- `app/Services/LateFee/InteresDiarioStrategy.php`
- `app/Services/LateFee/MontoFijoStrategy.php`
- `app/Services/LateFee/NingunaStrategy.php`
- `app/Services/LateFee/LateFeeCalculator.php`

**Modificar:**
- `app/Models/User.php` - agregar campos de configuración si no existen
- `app/Models/PaymentSchedule.php` - agregar observer para cálculo
- `app/Filament/Resources/UserResource.php` - agregar configuración en perfil
- `database/migrations/` - agregar campos a users si no existen

---

### PASO 22: Template Method - Esqueleto de Métricas

#### 22.1 Propósito del Patrón

Definir el esqueleto de un algoritmo en una operación, dejando algunos pasos para que las subclases los definan. Template Method permite redefinir ciertos pasos sin cambiar la estructura del algoritmo.

#### 22.2 Necesidad del Negocio

Las métricas del dashboard (ingresos, ocupación, pagos atrasados) siguen el mismo esqueleto: obtener datos → filtrar por arrendador → formatear resultado. Template Method define este esqueleto una vez.

#### 22.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar esqueleto común para cálculo de métricas del dashboard donde cada tipo de métrica implementa solo sus pasos específicos, reutilizando lógica común de filtrado y formateo.

**Partes de la funcionalidad:**
1. Crear clase abstracta `DashboardMetric` que defina el template method `calcular()`
2. Definir esqueleto: obtenerDatos() → filtrarPorArrendador() → procesarDatos() → formatearResultado()
3. Implementar pasos comunes con lógica por defecto (filtrarPorArrendador, formatearResultado)
4. Definir pasos abstractos que subclases deben implementar (obtenerDatos, procesarDatos)
5. Implementar métricas concretas: `IngresosMensualesMetric`, `OcupacionMetric`, `PagosAtrasadosMetric`
6. Cada métrica concreta implementa solo su lógica específica de obtención y procesamiento
7. Integrar métricas en widgets de Filament usando el template method

**Alcance de la implementación:**
- Consistencia en cálculo de todas las métricas del dashboard
- Reutilización de lógica común (filtrado por usuario, formateo estándar)
- Facilidad para agregar nuevas métricas siguiendo el esqueleto
- Mantenimiento simplificado: cambios en esqueleto afectan todas las métricas

#### 22.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Metrics/`

**Estructura del Template Method:**
```php
abstract class DashboardMetric {
    protected int $userId;
    
    public function __construct(int $userId) {
        $this->userId = $userId;
    }
    
    // Template Method - define el esqueleto
    public function calcular(): array {
        $datos = $this->obtenerDatos();
        $datosFiltrados = $this->filtrarPorArrendador($datos);
        $resultado = $this->procesarDatos($datosFiltrados);
        $formateado = $this->formatearResultado($resultado);
        
        return $formateado;
    }
    
    // Pasos abstractos que las subclases deben implementar
    abstract protected function obtenerDatos(): Collection;
    abstract protected function procesarDatos(Collection $datos): mixed;
    
    // Pasos con implementación por defecto (pueden sobrescribirse)
    protected function filtrarPorArrendador(Collection $datos): Collection {
        return $datos->where('user_id', $this->userId);
    }
    
    protected function formatearResultado($resultado): array {
        return [
            'valor' => $resultado,
            'fecha' => now()->toDateString(),
        ];
    }
}

class IngresosMensualesMetric extends DashboardMetric {
    protected function obtenerDatos(): Collection {
        return Payment::whereMonth('date', now()->month)->get();
    }
    
    protected function procesarDatos(Collection $datos): float {
        return $datos->sum('amount');
    }
    
    protected function formatearResultado($resultado): array {
        return [
            'valor' => $resultado,
            'moneda' => 'COP',
            'fecha' => now()->format('F Y'),
        ];
    }
}

class OcupacionMetric extends DashboardMetric {
    protected function obtenerDatos(): Collection {
        return Property::with('rentals')->get();
    }
    
    protected function procesarDatos(Collection $datos): float {
        $totalUnidades = $datos->count();
        $unidadesOcupadas = $datos->filter(fn($p) => $p->rentals()->where('status', 'activo')->exists())->count();
        
        return $totalUnidades > 0 ? ($unidadesOcupadas / $totalUnidades) * 100 : 0;
    }
    
    protected function formatearResultado($resultado): array {
        return [
            'valor' => round($resultado, 2),
            'unidad' => '%',
            'fecha' => now()->toDateString(),
        ];
    }
}
```

#### 22.4 Decisiones de Diseño

**¿Por qué Template Method y no clases independientes?**
- Template Method garantiza consistencia en el proceso
- Evita duplicación de lógica de filtrado/formateo
- Facilita agregar nuevas métricas siguiendo el patrón

**¿Qué pasos son comunes vs variables?**
- Comunes: filtrar por arrendador, formateo básico
- Variables: obtención de datos, procesamiento específico
- Extensible: pasos comunes pueden sobrescribirse si es necesario

**¿Relación con Proxy?**
- Template Method define el cálculo
- Proxy cachea el resultado del cálculo
- Complementarios: Template Method calcula, Proxy cachea

#### 22.5 Integración con Código Existente

**Uso en widgets:**
```php
class StatsOverview extends BaseWidget {
    protected function getStats(): array {
        $userId = ArrendadorContext::getInstance()->getUserId();
        
        $ingresos = new IngresosMensualesMetric($userId);
        $ocupacion = new OcupacionMetric($userId);
        $atrasados = new PagosAtrasadosMetric($userId);
        
        return [
            Stat::make('Ingresos', $ingresos->calcular()['valor']),
            Stat::make('Ocupación', $ocupacion->calcular()['valor'] . '%'),
            Stat::make('Atrasados', $atrasados->calcular()['valor']),
        ];
    }
}
```

#### 22.6 Consideraciones de Testing

**Test de template method:**
```php
$metric = new IngresosMensualesMetric($userId);
$resultado = $metric->calcular();

$this->assertArrayHasKey('valor', $resultado);
$this->assertArrayHasKey('moneda', $resultado);
$this->assertEquals('COP', $resultado['moneda']);
```

**Test de subclase específica:**
```php
$metric = new IngresosMensualesMetric($userId);
$datos = $metric->obtenerDatos();
$this->assertInstanceOf(Collection::class, $datos);
```

#### 22.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Metrics/DashboardMetric.php` (abstract)
- `app/Services/Metrics/IngresosMensualesMetric.php`
- `app/Services/Metrics/OcupacionMetric.php`
- `app/Services/Metrics/PagosAtrasadosMetric.php`
- `app/Services/Metrics/RentabilidadMetric.php`

**Modificar:**
- `app/Filament/Widgets/StatsOverview.php` - usar métricas template method
- `app/Filament/Widgets/IncomeChart.php` - usar métricas template method
- `app/Filament/Widgets/OverduePaymentsTable.php` - usar métricas template method

---

### PASO 23: Visitor - Generador de Reporte Fiscal

#### 23.1 Propósito del Patrón

Representar una operación que se realiza sobre los elementos de una estructura de objetos. Visitor permite definir una nueva operación sin cambiar las clases de los elementos sobre los que opera.

#### 23.2 Necesidad del Negocio

El arrendador necesita un reporte anual para declaración de renta que extrae información específica de Properties, Rentals y Payments. Visitor permite recorrer estas entidades sin modificarlas.

#### 23.3 Funcionalidad a Implementar

**Funcionalidad específica:** Implementar visitor que recorra diferentes entidades (Property, Rental, Payment) para extraer información fiscal específica para declaración de renta, sin modificar las entidades originales.

**Partes de la funcionalidad:**
1. Crear interfaz `Auditable` con método `accept(DeclaracionRentaVisitor $visitor)`
2. Crear interfaz `DeclaracionRentaVisitor` con métodos visitProperty, visitRental, visitPayment
3. Implementar `DeclaracionRentaVisitorImpl` que extraiga datos fiscales específicos de cada entidad
4. Implementar interfaz Auditable en Property, Rental, Payment con método accept()
5. Cada entidad llama al método apropiado del visitor cuando se acepta la visita
6. Crear `ReporteFiscalService` que orquestre el recorrido de entidades y generación del reporte
7. Implementar lógica de agregación de datos fiscales (ingresos brutos, netos, valor comercial)
8. Generar reporte final con estructura optimizada para declaración de renta colombiana

**Alcance de la implementación:**
- Generación de reportes fiscales anuales para declaración de renta
- Extracción de datos específicos de cada tipo de entidad sin modificarlas
- Posibilidad de agregar nuevos tipos de reportes usando el mismo visitor
- Separación de lógica de reporte de la lógica de negocio de las entidades

#### 23.3 Implementación en Código Actual

**Archivos a crear:** `app/Services/Reports/`

**Estructura del Visitor:**
```php
interface Auditable {
    public function accept(DeclaracionRentaVisitor $visitor): void;
}

interface DeclaracionRentaVisitor {
    public function visitProperty(Property $property): void;
    public function visitRental(Rental $rental): void;
    public function visitPayment(Payment $payment): void;
    public function getReporte(): array;
}

class DeclaracionRentaVisitor implements DeclaracionRentaVisitor {
    private array $reporte = [
        'properties' => [],
        'rentals' => [],
        'pagos' => [],
        'totales' => [
            'ingresos_brutos' => 0,
            'ingresos_netos' => 0,
            'gastos' => 0,
        ]
    ];
    
    public function visitProperty(Property $property): void {
        $this->reporte['properties'][] = [
            'id' => $property->id,
            'direccion' => $property->address,
            'valor_comercial' => $property->valor_comercial ?? 0,
            'area_m2' => $property->area_m2 ?? 0,
        ];
    }
    
    public function visitRental(Rental $rental): void {
        $ingresoAnual = $rental->monthly_amount * 12;
        
        $this->reporte['rentals'][] = [
            'id' => $rental->id,
            'inquilino' => $rental->tenant->name,
            'periodo' => "{$rental->start_date} a {$rental->end_date}",
            'ingreso_anual' => $ingresoAnual,
        ];
        
        $this->reporte['totales']['ingresos_brutos'] += $ingresoAnual;
    }
    
    public function visitPayment(Payment $payment): void {
        $this->reporte['pagos'][] = [
            'fecha' => $payment->date->format('Y-m-d'),
            'monto' => $payment->amount,
            'servicios' => $this->obtenerServiciosPagados($payment),
        ];
        
        $this->reporte['totales']['ingresos_netos'] += $payment->amount;
    }
    
    public function getReporte(): array {
        return $this->reporte;
    }
    
    private function obtenerServiciosPagados(Payment $payment): array {
        $servicios = [];
        if ($payment->is_water_paid) $servicios[] = 'agua';
        if ($payment->is_energy_paid) $servicios[] = 'energía';
        if ($payment->is_gas_paid) $servicios[] = 'gas';
        return $servicios;
    }
}
```

#### 23.4 Decisiones de Diseño

**¿Por qué Visitor y no simple foreach?**
- Visitor encapsula la lógica de extracción de datos fiscales
- Visitor permite agregar nuevos tipos de reportes sin modificar entidades
- Visitor separa lógica de reporte de los modelos

**¿Qué entidades visitar?**
- Property: datos del activo inmobiliario
- Rental: datos del contrato de arrendamiento
- Payment: datos de ingresos reales

**¿Reporte qué información contiene?**
- Ingresos brutos por rental
- Ingresos netos por pagos recibidos
- Valor comercial de propiedades
- Gastos deducibles (si se implementan)

#### 23.5 Integración con Código Existente

**Implementar interfaz en modelos:**
```php
class Property extends Model implements Auditable {
    public function accept(DeclaracionRentaVisitor $visitor): void {
        $visitor->visitProperty($this);
    }
}

class Rental extends Model implements Auditable {
    public function accept(DeclaracionRentaVisitor $visitor): void {
        $visitor->visitRental($this);
    }
}

class Payment extends Model implements Auditable {
    public function accept(DeclaracionRentaVisitor $visitor): void {
        $visitor->visitPayment($this);
    }
}
```

**Uso en servicio de reporte:**
```php
class ReporteFiscalService {
    public function generar(int $userId, int $year): array {
        $visitor = new DeclaracionRentaVisitor();
        
        // Visitar todas las entidades del año
        Property::where('user_id', $userId)
            ->whereYear('created_at', $year)
            ->get()
            ->each(fn($p) => $p->accept($visitor));
        
        Rental::where('user_id', $userId)
            ->whereYear('start_date', $year)
            ->get()
            ->each(fn($r) => $r->accept($visitor));
        
        Payment::where('user_id', $userId)
            ->whereYear('date', $year)
            ->get()
            ->each(fn($p) => $p->accept($visitor));
        
        return $visitor->getReporte();
    }
}
```

#### 23.6 Consideraciones de Testing

**Test del visitor:**
```php
$visitor = new DeclaracionRentaVisitor();
$property = Property::factory()->make();
$rental = Rental::factory()->make();
$payment = Payment::factory()->make();

$property->accept($visitor);
$rental->accept($visitor);
$payment->accept($visitor);

$reporte = $visitor->getReporte();
$this->assertCount(1, $reporte['properties']);
$this->assertCount(1, $reporte['rentals']);
$this->assertCount(1, $reporte['pagos']);
```

#### 23.7 Archivos a Crear/Modificar

**Crear:**
- `app/Services/Reports/Auditable.php` (interface)
- `app/Services/Reports/DeclaracionRentaVisitor.php` (interface)
- `app/Services/Reports/DeclaracionRentaVisitorImpl.php`
- `app/Services/Reports/ReporteFiscalService.php`

**Modificar:**
- `app/Models/Property.php` - implementar Auditable
- `app/Models/Rental.php` - implementar Auditable
- `app/Models/Payment.php` - implementar Auditable
- `app/Filament/Resources/` - agregar acción de exportar reporte fiscal

---

## 5. Validación Final

### 5.1 Coherencia de Implementación

**23/23 patrones cubiertos:**
- ✅ Creacionales (5): Singleton, Factory Method, Builder, Prototype, Abstract Factory
- ✅ Estructurales (7): Adapter, Bridge, Composite, Decorator, Facade, Flyweight, Proxy
- ✅ Comportamiento (11): Chain of Responsibility, Command, Interpreter, Iterator, Mediator, Memento, Observer, State, Strategy, Template Method, Visitor

**Cada patrón:**
- Tiene propósito claro del negocio
- Tiene implementación modular independiente
- Tiene decisiones de diseño justificadas
- Tiene integración con código existente
- Tiene consideraciones de testing

### 5.2 Viabilidad Técnica

**100% viable:**
- DBML soporta todas las implementaciones
- Laravel/Filament es compatible con todos los patrones
- Implementaciones son simples y naturales
- No hay overengineering
- Cada patrón es educativamente correcto

### 5.3 Orden Recomendado de Implementación

**Fase 1 - Creacionales (cualquier orden):**
1. Singleton → Foundation para otros patrones
2. Factory Method → Foundation para Bridge/Observer
3. Builder → Independiente
4. Prototype → Independiente
5. Abstract Factory → Independiente

**Fase 2 - Estructurales (cualquier orden):**
6. Adapter → Independiente
7. Bridge → Depende de Factory Method
8. Composite → Independiente
9. Decorator → Independiente
10. Facade → Puede usar Chain of Responsibility
11. Flyweight → Independiente
12. Proxy → Puede usar Template Method

**Fase 3 - Comportamiento (respetar dependencias suaves):**
13. Chain of Responsibility → Foundation para Facade
14. Command → Foundation para State/Iterator
15. Interpreter → Independiente
16. Iterator → Depende de Command
17. Mediator → Puede usar Observer
18. Memento → Independiente
19. Observer → Foundation para Mediator
20. State → Depende de Command
21. Strategy → Independiente
22. Template Method → Foundation para Proxy
23. Visitor → Independiente

### 5.4 Consideraciones Generales

**Testing:**
- Cada patrón debe tener unit tests
- Tests deben mockear dependencias
- Tests deben verificar el patrón, no solo funcionalidad

**Documentación:**
- Cada patrón debe tener comentarios PHPDoc
- Decisiones de diseño documentadas en código
- Ejemplos de uso en archivos README de módulos

**Performance:**
- Monitorear impacto de patrones que afectan performance (Proxy, Iterator)
- Cache donde sea apropiado (Proxy ya lo hace)
- Optimizar queries en patrones que acceden DB (Iterator, Template Method)

**Mantenibilidad:**
- Seguir convenciones de Laravel
- Usar type hints donde sea posible
- Mantener separación de responsabilidades

---

## 6. Conclusión

Este TDD proporciona una guía completa para implementar los 23 patrones GoF en Prometheus de forma modular, educativa y técnicamente sólida. Cada patrón se presenta como un paso independiente con:

- Propósito claro del patrón
- Necesidad del negocio que resuelve
- Implementación detallada pero no código completo
- Decisiones de diseño justificadas
- Integración con código existente
- Consideraciones de testing
- Lista de archivos a crear/modificar

La implementación es 100% viable, coherente y pedagógicamente correcta, evitando overengineering y manteniendo el enfoque en necesidades reales del negocio de arrendamiento.