# Guía de sustentación: Builder y Singleton

Este documento es la preparación para explicar los dos patrones que me tocan. Está escrito en tres partes:

1. **Builder** — qué trata, qué resuelve, qué pasó en nuestro aplicativo y cómo se lee su diagrama de clases.
2. **Singleton** — lo mismo, en el mismo orden.
3. **Guion para leer en voz alta** — el texto para decir, con las referencias a los archivos para abrir en pantalla.

Todo lo que está afirmación acá está verificado contra el código y contra el historial de git. Donde algo no tiene respaldo real, está dicho así.

---

# Parte 1 — Builder

## 1.1 Qué trata y qué resuelve

**Qué trata.** Un acta de entrega y recepción del inmueble. Es el documento que el arrendador llena cuando entrega la casa: quién recibe, cuándo, qué había en cada espacio, en qué estado, fotos, compromisos y firmas.

**Qué resuelve.** El acta tiene más de quince secciones y casi todas son opcionales. Las obligatorias son pocas: el alquiler, el nombre del arrendador, el nombre del inquilino y la fecha. Todo lo demás puede quedar vacío porque en una entrega real muchas veces así es.

El problema de fondo **no es que sean muchos parámetros**. Es algo más sutil y es lo que el patrón viene a resolver:

> Con quince parámetros idénticos y opcionales **no se puede distinguir "el inquilino no tiene gas" de "se me olvidó anotarlo el gas".**

Son el mismo `null`. No hay forma de saberlo mirando los datos, porque los dos casos se ven exactamente iguales. Y esa diferencia importa: una es un dato del negocio y la otra es un error mío.

**La solución del Builder.** En vez de que el acta se construya de una sola llamada, se construye **paso a paso**, y hay un método por sección. La regla que lo gobierna es simple:

> Solo existe la sección que se pidió. Si no se llamó al método, esa sección no existe en el acta.

Con eso la ausencia deja de ser un `null` ambiguo y pasa a ser información. La columna sigue siendo `null`, pero ahora `null` significa "nadie pidió esta sección", que es una decisión, no un olvido.

## 1.2 En nuestro aplicativo: antes y después

### El "antes" — con una salvedad que hay que decir

La primera versión del acta y el Builder se crearon en el mismo commit (`de28e5e`, "feat(patrones): Singleton y Builder con correccion del estado de pagos"). O sea: **no existía una versión anterior del acta que el Builder haya reemplazado.**

Esto es importante decirlo, porque si lo digo al revés me preguntan el "¿y antes cómo funcionaba?" y no tengo respuesta. Lo honesto es este:

> El Builder no resolvió un bug. Resolvió **el costo de una decisión de diseño que se iba a tomar igual**: cómo se iba a construir un objeto con quince propiedades opcionales que a veces se llenan y a veces no.

El costo evitado se ve comparando las dos formas de armar lo mismo.

**Lo que habría sido sin Builder** — un constructor con quince parámetros, todos opcionales:

```php
// NO existe en el código. Es lo que se evitó.
$act = new DeliveryAct(
    rental:        $rental,
    type:          $data['type'],
    occurred_at:   $data['occurred_at'],
    water:         $data['water_reading']   ?? null,
    energy:        $data['energy_reading']  ?? null,
    gas:           $data['gas_reading']     ?? null,
    landlord_doc:  $data['landlord_document'] ?? null,
    tenant_doc:    $data['tenant_document']   ?? null,
    items:         $items      ?? null,
    commitments:   $commit     ?? null,
    observations:  $observ     ?? null,
    landlord_sig:  $sig1       ?? null,
    tenant_sig:    $sig2       ?? null,
    signed_at:     $signedAt   ?? null,
);
```

Se lee regular. El problema aparece en la fila de `gas`: el `?? null` no dice nada. ¿No hay gas en la casa? ¿Se olvidó? La llamada no lo sabe. Y si mañana el acta necesita una sección nueva —zonas comunes, por ejemplo— hay que tocar **todos** los lugares donde se arma el acta, y agregar un parámetro más.

**Lo que hay hoy** — `app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php:67`:

```php
$builder = app(DeliveryActBuilder::class)
    ->forRental($rental, $data['landlord_name'], $data['tenant_name'])
    ->ofType($data['type'])
    ->onDate(Carbon::parse($data['occurred_at']), ...)
    ->withParties([...]);
```

Y después, cada sección opcional se pide **solo si hay algo que pedir**. El fragmento clave está en `CreateDeliveryAct.php:86-88`:

```php
if ($readings !== []) {
    $builder = $builder->withMeterReadings($readings);
}
```

Ese `if` es el corazón del patrón en acción. Si el arrendador no escribió lecturas, **el método nunca se invoca**. No hay ningún `null` que interpretar: simplemente la sección no se pidió.

### El "después" — tres consecuencias concretas en el código

**1. La ausencia se vuelve información.** `app/Models/DeliveryAct.php:80`:

```php
public function hasMeterReadings(): bool
{
    return $this->water_reading !== null
        || $this->energy_reading !== null
        || $this->gas_reading !== null;
}
```

El método cuenta si hay alguna lectura, no pregunta si el de gas es `null`. Porque si preguntara "¿está el gas en `null`?", la respuesta sería siempre que sí, y no diría nada.

**2. Agregar una sección nueva es agregar un método.** Si mañana el acta necesita "zonas comunes", se agrega `withCommonAreas()` al Builder. La pantalla lo llama si el usuario lo llenó, y nada más se toca. `PLAN-PATRONES.md` dice exactamente esto: *"Agregar una sección nueva es agregar un método al Builder. Ningún otro punto de la aplicación cambia."*

**3. La validación vive en el Builder, no en la pantalla.** `app/Services/Acts/DeliveryActBuilder.php:163`:

```php
foreach (['rental_id', 'landlord_name', 'tenant_name', 'occurred_at'] as $required) {
    $value = $this->sections[$required] ?? null;
    if ($value === null || (is_string($value) && trim($value) === '')) {
        throw new RuntimeException("El acta necesita el dato obligatorio [{$required}].");
    }
}
```

Falla de forma explícita en vez de guardar un acta a medio construir. Y esa excepción la atrapa la pantalla en `CreateDeliveryAct.php:40-50`, que avisa con una notificación roja en vez de mostrar un error 500.

## 1.3 Cómo explicaría el diagrama de clases

El diagrama completo está en `UML-PLANTUML-PATRONES.md`, sección 2. Acá va cómo lo leo.

### Primero: el truco que hace legible todo el diagrama

UML tiene cinco flechas y si no las distinguís el diagrama no dice nada. Estas son las que importan:

| Flecha | Qué significa | Cómo lo explico |
| --- | --- | --- |
| `A ..> B` | **dependencia**, punteada | "A **crea** a B" |
| `A --> B` | asociación, continua | "A **usa o guarda** a B" |
| `A *-- B` | **composición**, con asterisco | "B es parte de A: si A se borra, B se borra" |
| `A <\|-- B` | herencia | "B es un tipo de A" |
| `A <\|.. B` | realiza | "B implementa el contrato de A" |

**La frase que lo landea:** *"Todas las flechas punteadas del diagrama son 'yo lo creo'. Las continuas son 'yo lo uso o lo guardo'."*

Con eso el resto se lee solo.

### Después: clase por clase

**`DeliveryActBuilder`** — es la clase central y la única que tiene muchos métodos. Lo que hay que notar:

- Todos los métodos `with...` devuelven `DeliveryActBuilder` (el `return $this`). Eso es lo que permite el encadenamiento de la línea 67, y lo que hace que el orden de armado sea explícito a simple vista.
- Los atributos privados son `-array $sections`, `-list $items` y `-bool $inventoryRequested`. Ahí está acumulado el estado mientras construye. Por eso no puede ser una instancia única de por vida (ver Parte 2.3).
- El método `build()` está aparte de los `with...` a propósito: los `with...` solo acumulan, `build()` valida y produce. Esa separación es lo que hace el patrón.

**`AppSettings`** — la flecha `DeliveryActBuilder --> AppSettings : aporta datos comunes` es continua. El Builder no la crea, la recibe ya hecha por el contenedor. Y no le pasa los datos del negocio por parámetro: los pide cuando los necesita. Esa es la unión con el Singleton, y la menciono como tal: *"el Builder se apoya en el Singleton para no recibir el nombre del negocio y el NIT por parámetro"*.

**`Rental`** — `DeliveryActBuilder --> Rental` también continua. Y esto merece explicación porque es fino: el Builder **no guarda** el alquiler. Solo lo lee en `forRental()` para deducir dos cosas:

```php
$this->sections['rental_id'] = $rental->id;
$this->sections['user_id'] = $rental->user_id;
```

Deducir el `user_id` del alquiler y no pedirlo es una decisión de seguridad: si la pantalla lo pidiera, alguien podría escribir el `user_id` de otro. Al deducirlo, es imposible. Esa es la razón por la que la flecha es de "uso" y no de "guarda".

**`DeliveryAct`** — la flecha `DeliveryActBuilder ..> DeliveryAct : build() produce` es **punteada**, y es la más importante del diagrama. Punteada significa "el Builder la crea". El Builder es quien decide el nombre de la clase, entonces la flecha va del Builder hacia el producto.

Y `DeliveryAct *-- ActItem` lleva **asterisco**: composición. Cada `ActItem` existe porque pertenece a un acta; si el acta se borra, los ítems también. Esa es la definición de composición.

En cambio `ActItem --> ActItemState` es continua, sin asterisco: el estado `BUENO` o `DESTRUIDO` existe antes y después del acta, no es propiedad de ella. Un ítem **usa** un estado; no lo **posee**.

Esa diferencia entre `*--` y `-->` es la que hay que apuntar, porque es donde más se equivoca la gente.

---

# Parte 2 — Singleton

## 2.1 Qué trata y qué resuelve

**Qué trata.** La configuración del negocio: nombre, NIT, dirección, moneda, días de vencimiento de facturas, textos legales de la Ley 820, colores y catálogo de espacios del inmueble.

**Qué resuelve.** Que esos datos se lean **una sola vez** y que todos los que los necesiten reciban el mismo objeto, sin pasarlos de mano en mano por veinte funciones.

## 2.2 En nuestro aplicativo: antes y después

### El "antes" — acá sí hay código real, se puede mostrar

Este caso es distinto al del Builder. El "antes" existe y está en el historial de git. Lo puedes abrir con `git show de28e5e^` y ahí está.

**Problema 1: el contexto de usuario estaba duplicado cuatro veces.**

Antes, cada recurso de Filament tenía su propia copia de exactamente el mismo bloque. En `app/Filament/Resources/RentalResource.php` había:

```php
// ANTES — no existe en el código actual. Se ve con: git show de28e5e^
// private static ?CurrentUserContextInterface $userContext = null;
//
// public static function getUserContext(): CurrentUserContextInterface
// {
//     if (self::$userContext === null) {
//         self::$userContext = app(CurrentUserContextInterface::class);
//     }
//     return self::$userContext;
// }
```

Y el bloque idéntico, línea por línea, también en:

- `app/Filament/Resources/TenantResource.php`
- `app/Filament/Resources/PropertyResource.php`
- `app/Filament/Resources/RentalResource/Pages/ManageRentalPayments.php`

Cuatro clases. Cuatro variables estáticas. Cuatro lugares que hay que recordar si se agrega un método a la interfaz `CurrentUserContextInterface`, porque si se olvida uno, ese recurso se queda con la versión vieja.

**Y esto es lo importante: eso ya *parecía* un Singleton, y no lo era.** Cada copia es una variable estática distinta. Son cuatro instancias diferentes del mismo concepto. `PLAN-PATRONES.md` lo dice así: *"Son cuatro estados globales que son copia uno del otro, es decir, un Singleton mal hecho."*

Un Singleton mal hecho es peor que no usar Singleton, porque **engaña**: el código parece correcto y el problema no se ve.

**Problema 2: el binding era `bind`, no `singleton`.**

`git show de28e5e^:app/Providers/AppServiceProvider.php` muestra el `register()` completo. Tenía esto:

```php
$this->app->bind(AgreementStorageInterface::class, PublicDiskAgreementStorage::class);
$this->app->bind(CurrentUserContextInterface::class, AuthUserContext::class);
```

`bind` significa "cada vez que alguien pida esto, creame uno nuevo". Con `AuthUserContext` el daño era leve, porque la clase no guarda estado: pregunta el id a `Auth` en cada llamada y siempre tiene el valor correcto. Pero `AppSettings` sí guarda estado, y con `bind` habría creado una instancia nueva por llamada, leyendo la base de datos cada vez.

### El "después" — dos cambios

**1. El contenedor decide el tiempo de vida.** `app/Providers/AppServiceProvider.php:30-34`:

```php
$this->app->singleton(CurrentUserContextInterface::class, AuthUserContext::class);
$this->app->singleton(AppSettings::class, fn () => AppSettings::fromDatabase());
```

`singleton` significa "resolveme esto **una vez** y guardá la respuesta. Todo el que pregunte recibe la misma". Se borraron los cuatro `private static` a mano: ya no hacen falta, porque el contenedor hace ese trabajo mejor.

**2. `AppSettings` se hizo inmutable y con constructor privado.** `app/Settings/AppSettings.php:19-38`:

```php
final class AppSettings
{
    private function __construct(
        private readonly string $businessName,
        private readonly string $taxId,
        private readonly string $address,
        private readonly string $currency,
        // ...
    ) {}
```

Dos decisiones acá:

- `private function __construct()`: nadie puede hacer `new AppSettings(...)` desde fuera. Hay que pasar por el contenedor, que es el único que garantiza una sola instancia.
- `readonly` en todas las propiedades: la instancia **no se puede mutar**. Nadie la cambia por detrás.

Y si la clase no se puede mutar, ¿cómo se actualizan los ajustes? Porque el NIT sí cambia. Y ahí viene la pieza que el diagrama anterior no mostraba, y que es la que hace que esto sea un Singleton de verdad y no un dato congelado.

### La invalidación: por qué `AppSettings` sigue siendo un Singleton si el dato cambia

`app/Services/SettingsService.php:49` llama a `forgetCachedInstance()`, que hace esto (`SettingsService.php:81-84`):

```php
public function forgetCachedInstance(): void
{
    app()->forgetInstance(AppSettings::class);
}
```

Guarda el dato nuevo en la base de datos y **descarta el objeto viejo del contenedor**. La siguiente petición pide `AppSettings`, el contenedor ya no la tiene, la vuelve a crear desde la base de datos, y ahí sí llega el valor nuevo.

**La frase que hay que decir en la defensa, porque es donde se traba la gente:**

> El Singleton no es "el dato se guarda una vez para siempre". Es "el contenedor resuelve la clase una vez y le entrega ese mismo objeto a todos los que la pidan". Si el valor cambia, **se descarta el objeto, no se muta**.

Eso es lo que permite que `AppSettings` sea inmutable y aun así el NIT se actualice. Las dos cosas no se contradicen porque están en planos distintos: el dato se cambia en la base de datos, y el objeto se rehace.

Hay una prueba que lo demuestra, en `tests/Feature/AppSettingsSingletonTest.php`:

- `el_contenedor_entrega_la_misma_instancia_de_app_settings()` — dos pedidos dan el mismo objeto.
- `la_configuracion_se_lee_una_sola_vez_desde_la_base_de_datos()` — cambia la fila en la base de datos y el valor en memoria **no cambia**.
- `guardar_ajustes_descarta_la_instancia_cacheada()` — después de guardar, el objeto es distinto y trae lo nuevo.

## 2.3 Cómo explicaría el diagrama de clases

El diagrama completo está en `UML-PLANTUML-PATRONES.md`, sección 1.

### El recorrido: de arriba hacia abajo

El diagrama está en `direction TB` a propósito, y cuenta dos historias.

**Historia 1: las dos flechas de arriba.** Las dos flechas punteadas que salen de `AppServiceProvider` son lo primero que hay que señalar. `AppServiceProvider` lleva `<<contenedor>>` y tiene solo dos métodos: `register()` y `boot()`.

Las dos relaciones:

- `AppServiceProvider ..> AppSettings : singleton, una vez`
- `AppServiceProvider ..> DeliveryActBuilder : scoped, por peticion`

Ambas punteadas: el contenedor **crea** las dos. Y las dos salen del contenedor, no de ninguna clase de negocio. Eso es el punto: **el tiempo de vida de un objeto lo decide el contenedor, no el código que lo usa.** El Builder no se registra a sí mismo. La pantalla no pide una instancia nueva. Solo el contenedor decide.

**Historia 2: por qué una es `singleton` y la otra `scoped`.**

Esta es la distinción central del diagrama, y está en los dos esterotipos:

- `AppSettings` lleva `<<singleton>>`. Una instancia para toda la aplicación. Puede hacerlo porque **nunca cambia**: todas sus propiedades son `readonly`.
- `DeliveryActBuilder` lleva `<<scoped>>`. Una instancia **por petición**.

¿Por qué no puede ser singleton? Porque acumula estado. Tiene `-array $sections`, `-list $items` y `-bool $inventoryRequested`. Si fuera una única instancia para toda la aplicación, las secciones del acta de Juan se filtrarían al acta de Ana: la siguiente petición encontraría el Builder con secciones ya llenas de otro documento. En el modo normal de PHP (una petición, se destruye todo) no se nota, pero en Octane, que se usa en producción para no reiniciar Laravel, se nota y es un bug de verdad.

Por eso en `AppServiceProvider.php:40-42` está `scoped` y no `singleton`, y el comentario del código lo explica.

**La frase para cerrar la parte técnica:**

> Los dos comparten la misma idea —"una sola instancia"— pero por razones distintas. `AppSettings` es único **porque no cambia**. `DeliveryActBuilder` es único **dentro de su petición, porque sí cambia**.

**Historia 3: la invalidación.** La flecha `SettingsService ..> AppSettings : forgetInstance() al guardar` es punteada y tiene su propio sentido: no es "la usa", es "**la destruye**". Y `AjustesPage --> SettingsService` es continua, porque la pantalla escribe la base de datos a través del servicio y no toca `AppSettings` para nada.

Esa es la historia completa: la pantalla nunca ve la configuración. Le pide datos al servicio, y el servicio decide cuándo existe una instancia nueva.

---

# Parte 3 — Guion para leer en voz alta

Los archivos de código que se citan están entre paréntesis con su ruta, para abrirlos en pantalla si hace falta.

---

### Apertura

> "Voy a explicar dos patrones. El primero es el Builder, que se usa para armar el acta de entrega y recepción del inmueble. El segundo es el Singleton, que se usa para la configuración del negocio.
>
> Los dos se parecen en que resuelven un problema de *dónde vive un objeto y quién lo crea*. Pero resuelven problemas opuestos, y esa es la parte interesante: uno se comparte porque no cambia, el otro se rehace porque sí cambia."

---

### Builder: el problema

> "El acta de entrega es el documento que el arrendador llena cuando entrega la casa. Dice quién recibe, cuándo fue la visita, qué había en cada espacio y en qué estado, y las firmas.
>
> Tiene más de quince secciones: las partes, la fecha, el tipo, las lecturas de agua, energía y gas, los documentos de identidad, el inventario con fotos, los compromisos, las observaciones y las firmas. Casi todas son opcionales. En una entrega real, muchas veces se dejan vacías.
>
> Y acá está el problema, que no es que sean muchos. El problema es que si las paso todas como parámetros, quince de ellos son `null` y no se distingue 'el inquilino no tiene gas en la casa' de 'se me olvidó anotarlo el gas'. Los dos casos se ven exactamente igual: `null`. Y esa diferencia importa, porque uno es un dato del negocio y el otro es un error mío."

---

### Builder: el patrón

> "El Builder resuelve eso construyendo el acta paso a paso, con un método por sección. Solo se invoca el método de las secciones que realmente se llenaron.
>
> En la pantalla está así: si el arrendador no escribió lecturas, hay un `if` que no llama al método. Entonces la sección nunca se pide. No hay ningún `null` que interpretar. La ausencia pasó de ser un dato ambiguo a ser una decisión.
>
> El código está en `app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php:86`.
>
> El Builder acumula lo que se le pide en un arreglo privado —`$sections`— y cuando se llama `build()` valida que estén los cuatro datos obligatorios y arma y guarda el acta. Si falta uno, lanza una excepción con un mensaje claro, y la pantalla la atrapa y la muestra como una notificación roja en vez de un error 500.
>
> Lo que el Builder no tiene es un método 'construir acta con todo'. Eso es justamente lo que lo distingue de solo pasar muchos parámetros."

---

### Builder: qué resuelve, en concreto

> "Tres cosas concretas.
>
> Primero, la ausencia se vuelve información. Está en `app/Models/DeliveryAct.php:80`: `hasMeterReadings()` cuenta si hay alguna lectura, en vez de preguntar si el gas es `null`.
>
> Segundo, agregar una sección nueva es agregar un método al Builder. Si mañana el acta necesita zonas comunes, se agrega `withCommonAreas()` y nada más se toca. Con quince parámetros habría que modificar todos los lugares donde se arma el acta.
>
> Tercero, la validación vive en el Builder y no en la pantalla, así que no se puede saltar desde otro punto del sistema."

---

### Builder: el diagrama

> "En el diagrama hay una sola cosa que hay que aprender para leerlo: las flechas punteadas significan 'yo creo esa clase', y las continuas significan 'yo la uso o la guardo'. Con eso todo lo demás se lee solo.
>
> La clase del centro es el Builder. Lo que se nota es que todos los métodos devuelven el propio Builder, y eso es lo que permite el encadenamiento: `forRental`, después `ofType`, después `onDate`. El orden se lee de arriba hacia abajo.
>
> La flecha punteada hacia `DeliveryAct` dice que el Builder la construye — es punteada porque el Builder decide el nombre de la clase.
>
> La flecha continua hacia `Rental` es diferente a propósito. El Builder no guarda el alquiler, solo lo lee para deducir el `user_id` del alquiler en vez de pedirlo en la pantalla. Si lo pidiera, alguien podría escribir el id de otro usuario; al deducirlo, es imposible.
>
> Y la flecha con asterisco entre `DeliveryAct` y `ActItem` es composición: los ítems existen porque pertenecen a esa acta. En cambio la flecha continua entre `ActItem` y `ActItemState` es solo uso: el estado es un valor que existe antes y después del acta."

---

### Singleton: el problema

> "El Singleton lo usa la configuración del negocio: nombre, NIT, dirección, moneda, días de vencimiento de facturas, textos legales y el catálogo de espacios.
>
> El problema que había era concreto y se puede ver en el historial de git. Antes de aplicar el patrón, cada recurso de Filament —alquileres, inquilinos, propiedades y el gestor de pagos— tenía su propia copia exacta de la misma variable estática que guardaba el contexto del usuario. Cuatro archivos con el mismo bloque de código, línea por línea.
>
> Y acá lo importante es que eso ya parecía un Singleton. Cada copia era una variable estática distinta, o sea cuatro instancias diferentes del mismo concepto. Es un Singleton mal hecho, y es peor que no usar Singleton, porque el código se ve correcto y el problema queda escondido.
>
> El segundo problema: en `app/Providers/AppServiceProvider.php` el registro era `bind`, no `singleton`. `bind` crea una instancia nueva cada vez que alguien la pide."

---

### Singleton: el patrón

> "El patrón cambió dos cosas.
>
> Primera: el contenedor pasó de `bind` a `singleton`, y con eso desaparecieron los cuatro bloques estáticos duplicados, porque ya no hacen falta. Ahora el contenedor resuelve la clase una vez y le entrega el mismo objeto a todos los que la pidan. Eso está en `app/Providers/AppServiceProvider.php:30`.
>
> Segunda: `AppSettings` quedó con constructor privado y todas sus propiedades `readonly`, en `app/Settings/AppSettings.php:19`. Con el constructor privado nadie puede hacer `new AppSettings(...)` desde afuera: hay que pasar por el contenedor. Y con `readonly` la instancia no se puede modificar.
>
> Pero acá viene lo que seguro se pregunta: si el objeto es inmutable y el NIT sí cambia, ¿cómo se actualiza?
>
> No se actualiza el objeto. Se actualiza la base de datos y **se descarta el objeto del contenedor**. Eso lo hace `SettingsService`, en `app/Services/SettingsService.php:81`, llamando a `forgetInstance()`. La siguiente petición pide la configuración, el contenedor ya no la tiene, la vuelve a leer de la base de datos, y ahí sí llega el valor nuevo.
>
> O sea: el Singleton no es 'el dato se guarda una vez para siempre'. Es 'el contenedor resuelve la clase una vez y entrega el mismo objeto a todos'. Si el valor cambia, se descarta el objeto, no se muta. Eso es lo que permite que sea inmutable y aun así actualizable."

---

### Singleton: el diagrama

> "El diagrama se lee de arriba hacia abajo y cuenta dos historias.
>
> La primera son las dos flechas punteadas que salen de la clase de arriba, que es el contenedor. Las dos salen de ahí y no de ninguna clase de negocio, y eso es el punto: el tiempo de vida de un objeto lo decide el contenedor, no el código que lo usa. El Builder no se registra a sí mismo, la pantalla no pide instancias nuevas. Solo el contenedor decide.
>
> La segunda historia es por qué una de esas flechas dice `singleton` y la otra dice `scoped`.
>
> `AppSettings` es singleton real porque no cambia: todas sus propiedades son de solo lectura.
>
> `DeliveryActBuilder` es `scoped`, o sea una instancia por petición, y **no** singleton. La razón es que acumula estado: tiene un arreglo de secciones y otro de ítems que se van llenando mientras construye. Si fuera una única instancia para toda la aplicación, las secciones del acta de un inquilino se filtrarían al acta del siguiente. En el modo normal de PHP eso no se nota porque cada petición se destruye entera, pero en Octane, que se usa en producción, sí, y sería un bug real.
>
> Entonces los dos comparten la misma idea, una sola instancia, pero por razones opuestas: la configuración es única **porque no cambia**, y el constructor es único **porque sí cambia**.
>
> En el diagrama hay una flecha más que vale la pena señalar: la que va de `SettingsService` hacia `AppSettings` con el texto 'forgetInstance al guardar'. Esa no es 'usa', es 'destruye'. Ahí está toda la actualización."

---

### El cierre: por qué los dos patrones juntos

> "Para cerrar, si tuviera que resumir la diferencia entre los dos en una frase: no es que el Singleton sea único y el Builder no. Los dos manejan una sola instancia, pero en vida útil distinta.
>
> La configuración se comparte **porque no cambia**. Es lo único en el sistema que es igual para todos los documentos siempre, entonces tiene sentido que haya una copia y que todos la lean.
>
> El Builder se rehace cada vez **porque sí cambia**: acumula secciones mientras arma el acta. Compartirlo sería filtrar datos de una petición a la siguiente.
>
> Y los dos resuelven el mismo problema de fondo, que es no pasar datos de mano en mano por veinte funciones. La configuración llega al Builder y a los documentos por inyección, no por parámetro. Eso es lo que hace que un documento salga con el NIT correcto sin que nadie lo haya pasado explícitamente."

---

# Preguntas que probablemente voy a tener que responder

### "¿Por qué el Builder es `scoped` y no `singleton`?"

Porque acumula estado mientras construye: `$sections` y `$items`. Una única instancia para toda la aplicación filtraría las secciones de un acta al siguiente. El comentario en `AppServiceProvider.php:36-39` explica exactamente esto.

### "¿Qué pasaría si `AppSettings` fuera `singleton` real y mutable?"

Se podrían escribir dos configuraciones distintas en la misma aplicación: el acta ya impresa con el NIT viejo y una factura nueva con el NIT nuevo, sin que nadie lo note. La inmutabilidad (`readonly`) y que el cambio se haga descartando la instancia son la misma decisión: **el objeto es consistente dentro de su vida, y cambiarlo significa empezar otra vida.**

###"¿El Builder es un Factory?"

No, y es buena pregunta. Un Factory crea una clase conocida, de una vez. El Builder **va llamando métodos hasta llegar al objeto**, y el objeto no existe hasta el final. La diferencia: la factoría dice "creame *esta* clase", el Builder dice "anotá esto, después esto, y cuando termine me decís qué te resulta".

### "¿Qué pasa si dos peticiones piden `AppSettings` al mismo tiempo?"

Nada raro: `AppSettings` no guarda estado mutable, solo lee datos. Y es una lectura de una fila (`WHERE id = 1`), no tiene nada que ver con sesión de usuario ni con nada del usuario autenticado. Por eso se puede compartir entre peticiones: no depende de quién esté conectado.

### "Si es singleton, ¿nadie puede crear una segunda instancia?"

Efectivamente. `AppSettings` es `final`, y el constructor es `private`. Desde cualquier parte del código, `new AppSettings()` es un error de sintaxis. La única forma de obtenerla es `app(AppSettings::class)`, que devuelve la del contenedor. Eso está verificado en `AppSettingsSingletonTest.php`, en el test `el_contenedor_entrega_la_misma_instancia_de_app_settings`.

### "El catálogo de espacios, ¿dónde vive? El plan decía que en `AppSettings`."

⚠️ **Esta sí la puede hacer.** El plan original (`PLAN-PATRONES.md`, sección 2.2) dice que los espacios vienen del catálogo de `AppSettings`. Pero el código cambió después, en el commit `af45c15`: ahora `DeliveryActBuilder::assertSpaceIsCatalogued()` busca el espacio en la tabla `spaces` y **lo crea si no existe**, en vez de validar contra `AppSettings::spaceCatalog()`.

Eso tiene dos efectos:

1. El inventario pasó a ser la fuente del catálogo: si escribís "Bodega" en el inventario, "Bodega" queda como espacio válido.
2. **Hoy fallan 4 de las 10 pruebas de `DeliveryActBuilderTest`**, con `NOT NULL constraint failed: spaces.user_id` — porque el método usa `auth()->id()` y en el contexto de prueba no hay usuario autenticado.

Si me preguntan por el catálogo, respondo **describiendo el cambio**, no diciendo que todo funciona:

> "El plan original lo tenía en `AppSettings` porque el catálogo se tenía previsto fijo y configurable. Se cambió a la tabla `spaces` porque al inventariar un espacio nuevo tenía que agregarlo a mano en otra pantalla, y el inventario quedó siendo la fuente. Ese cambio es lo que deja hoy cuatro pruebas del Builder en rojo por el `user_id` de `spaces`: el método usa `auth()->id()` y en pruebas no hay sesión. Es un defecto conocido, no el comportamiento esperado."

### Resumen de archivos por si me los piden en vivo

| Qué | Dónde |
| --- | --- |
| El Builder | `app/Services/Acts/DeliveryActBuilder.php` |
| La pantalla que lo usa, con los `if` | `app/Filament/Resources/DeliveryActResource/Pages/CreateDeliveryAct.php` |
| El acta ya armada | `app/Models/DeliveryAct.php` |
| Cada elemento del inventario | `app/Models/ActItem.php` y `app/Enums/ActItemState.php` |
| La configuración | `app/Settings/AppSettings.php` |
| La pantalla de ajustes | `app/Filament/Pages/AjustesPage.php` |
| Quien guarda y descarta la configuración | `app/Services/SettingsService.php` |
| El contenedor: `singleton` y `scoped` | `app/Providers/AppServiceProvider.php:30-42` |
| Las pruebas del Singleton | `tests/Feature/AppSettingsSingletonTest.php` |
| Las pruebas del Builder | `tests/Feature/DeliveryActBuilderTest.php` |

---

## Nota final sobre el estado del código

Encontré **4 pruebas del Builder en rojo** y **1 de la pantalla de Ajustes** mientras preparaba esto. Ninguna es del Singleton, que tiene sus 7 pruebas verdes.

No las arreglé porque no era parte del encargo. Pero conviene que lo sepas antes de exponer, no después: si en la defensa se ejecuta la suite, se van a ver los cuatro fallos del Builder. Si te preguntan por la validación del catálogo de espacios, la respuesta ya está arriba.
