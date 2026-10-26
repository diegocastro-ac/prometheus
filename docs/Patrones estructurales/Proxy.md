# Proxy

## 1. Nombre y propósito

**Proxy** es un patrón estructural que coloca un objeto con la **misma interfaz** que otro delante de él para **controlar el acceso**. El cliente depende del Subject y no sabe si está hablando con el objeto real o con el Proxy.

En Prometheus se aplica a la **fuente de festivos**. `CachedHolidaySource` implementa `HolidaySource`, cachea los festivos por país y año y absorbe los fallos de Nager.Date antes de que lleguen al calendario de días hábiles.

La variante usada es un **Proxy de caché con degradación controlada**: no crea el objeto real de forma perezosa ni comprueba permisos; decide si responde desde caché, si delega en el objeto real o si devuelve una lista vacía cuando la API falla.

## 2. Problema que resuelve en Prometheus

Los generadores de facturas, `RentalPeriod`, `AdjustmentDateProposer` y las pantallas de reajustes necesitan saber si una fecha es hábil. Para eso consultan `BusinessCalendar`, cuyo adaptador de producción obtiene los festivos de Nager.Date.

La consulta original tenía tres responsabilidades mezcladas en `NagerDateBusinessCalendar`:

- traducir HTTP/JSON a una lista de fechas;
- cachear la respuesta por país y año;
- decidir qué hacer cuando la API falla.

Esa mezcla viola responsabilidad única y hace que un cambio de política de caché obligue a modificar el adaptador. Además, el dominio no debe conocer la URL, el país, la forma del JSON ni la disponibilidad de un servicio externo.

La solución necesita:

- una interfaz común para pedir festivos;
- un objeto real que solo hable con Nager.Date;
- un intermediario que cachee y degrade sin cambiar el contrato del cliente;
- una composición explícita en el contenedor;
- la posibilidad de sustituir el intermediario por un doble en pruebas.

## 3. Solución y roles del patrón

Se creó `HolidaySource` como **Subject**. `NagerDateHolidaySource` es el **RealSubject**: implementa esa interfaz, conoce la URL, el país y el JSON, y lanza una excepción si la API no responde correctamente. `CachedHolidaySource` es el **Proxy**: implementa el mismo Subject, consulta la caché, delega en el real en caso de fallo de caché y captura los errores del real. `NagerDateBusinessCalendar` es el **Client**: calcula días hábiles usando únicamente `HolidaySource`.

| Rol GoF | Clase | Responsabilidad |
| --- | --- | --- |
| **Subject** | `HolidaySource` | Declara `holidaysFor(int $year): array`; es el contrato compartido entre Proxy y RealSubject. |
| **RealSubject** | `NagerDateHolidaySource` | Traduce HTTP/JSON de Nager.Date a fechas ISO; no cachea ni degrada. |
| **Proxy** | `CachedHolidaySource` | Controla el acceso: responde desde caché, delega en el real o devuelve `[]` si el real falla. |
| **Client** | `NagerDateBusinessCalendar` | Usa `HolidaySource` para calcular días hábiles; no conoce la API ni la caché. |

La composición en producción es:

```text
NagerDateBusinessCalendar
  -> CachedHolidaySource        (Proxy)
       -> NagerDateHolidaySource (RealSubject / Adapter)
```

`NagerDateHolidaySource` cumple **dos roles**: es el RealSubject del Proxy y, a la vez, el Adapter que traduce Nager.Date a `HolidaySource`. Los patrones se componen; el Proxy se coloca delante del Adapter.

El flujo del Proxy es:

1. Construye la clave `holidays:{country}:{year}`.
2. Si `Cache::get()` devuelve un arreglo, lo retorna sin llamar al real.
3. Si no hay caché, llama a `$real->holidaysFor($year)`.
4. Guarda el resultado un día con `Cache::put(..., now()->addDay())`.
5. Si el real lanza `Throwable`, registra un `Log::warning` y devuelve `[]` **sin cachear el fallo**.

`NagerDateBusinessCalendar` sigue aplicando su propia política de respaldo: si el Proxy devuelve una lista vacía, los días de semana se consideran hábiles y solo se excluyen sábados y domingos. Si `nextBusinessDay()` supera 366 iteraciones, usa `WeekendBusinessCalendar`. El Proxy no reemplaza ese respaldo; solo evita que un fallo de red llegue como excepción al cálculo.

`AppServiceProvider` arma la cadena en producción y mantiene `WeekendBusinessCalendar` para `testing`. La caché y el `Log` se usan mediante las fachadas de Laravel; las pruebas los controlan con `Cache::flush()` y `Http::fake()`.

## 4. Diagrama de clases

```plantuml
@startuml proxy_festivos_clases
top to bottom direction

interface BusinessCalendar <<Target>> {
  +isBusinessDay(CarbonInterface $date) bool
  +nextBusinessDay(CarbonInterface $date) CarbonInterface
}

class NagerDateBusinessCalendar <<Client>> {
  -readonly HolidaySource $source
  +isBusinessDay(CarbonInterface $date) bool
  +nextBusinessDay(CarbonInterface $date) CarbonInterface
}

interface HolidaySource <<Subject>> {
  +holidaysFor(int $year) array
}

class CachedHolidaySource <<Proxy>> {
  -readonly HolidaySource $real
  -readonly string $country
  +holidaysFor(int $year) array
}

class NagerDateHolidaySource <<RealSubject / Adapter>> {
  -readonly string $baseUrl
  -readonly string $country
  +holidaysFor(int $year) array
}

class WeekendBusinessCalendar <<Respaldo - no GoF>> {
  +isBusinessDay(CarbonInterface $date) bool
  +nextBusinessDay(CarbonInterface $date) CarbonInterface
}

class AppServiceProvider <<Composición - no GoF>> {
  +register() void
}

class NagerDateAPI <<Adaptee - no GoF del Proxy>> {
  +GET /PublicHolidays/:year/:country
}

class Cache <<Infraestructura - no GoF>> {
  +get(string $key) mixed
  +put(string $key, mixed $value, DateTimeInterface $ttl) bool
}

class Log <<Infraestructura - no GoF>> {
  +warning(string $message, array $context) void
}

BusinessCalendar <|.. NagerDateBusinessCalendar
BusinessCalendar <|.. WeekendBusinessCalendar
NagerDateBusinessCalendar --> HolidaySource : source

HolidaySource <|.. CachedHolidaySource
HolidaySource <|.. NagerDateHolidaySource
CachedHolidaySource --> HolidaySource : real
CachedHolidaySource ..> Cache : consulta y guarda
CachedHolidaySource ..> Log : registra fallo
NagerDateHolidaySource ..> NagerDateAPI : HTTP + JSON

AppServiceProvider ..> NagerDateBusinessCalendar : crea Client
AppServiceProvider ..> CachedHolidaySource : crea Proxy
AppServiceProvider ..> NagerDateHolidaySource : crea RealSubject
AppServiceProvider ..> WeekendBusinessCalendar : binding testing

note right of HolidaySource
  Subject: el Client solo
  conoce este contrato.
end note

note bottom of CachedHolidaySource
  Cache hit: responde el Proxy.
  Cache miss: delega en el real
  y guarda un dia.
  Fallo: log + [] sin cachear.
end note

note bottom of NagerDateHolidaySource
  RealSubject y Adapter:
  traduce Nager.Date a fechas.
  No cachea ni degrada.
end note
@enduml
```

## 5. Clases participantes y valoración

| Clase | Ruta | Rol | Participación |
| --- | --- | --- | --- |
| `HolidaySource` | `app/Contracts/HolidaySource.php` | Subject | Contrato de un solo método; permite intercambiar Proxy y RealSubject. |
| `CachedHolidaySource` | `app/Infrastructure/Holidays/CachedHolidaySource.php` | Proxy | Cachea por país/año, delega en el real y degrada a `[]` si falla. |
| `NagerDateHolidaySource` | `app/Infrastructure/Holidays/NagerDateHolidaySource.php` | RealSubject / Adapter | Consulta Nager.Date, valida la respuesta y extrae las fechas; lanza `RuntimeException` si falla. |
| `NagerDateBusinessCalendar` | `app/Infrastructure/Holidays/NagerDateBusinessCalendar.php` | Client | Implementa `BusinessCalendar`; consulta festivos, salta fines de semana y aplica el tope de 366 días. |
| `BusinessCalendar` | `app/Contracts/BusinessCalendar.php` | Target contextual | Contrato del dominio; no forma parte del Proxy. |
| `WeekendBusinessCalendar` | `app/Infrastructure/Holidays/WeekendBusinessCalendar.php` | Respaldo / doble | Implementación de fin de semana usada por `testing` y como último respaldo. |
| `AppServiceProvider` | `app/Providers/AppServiceProvider.php` | Composición | Une Client, Proxy y RealSubject en producción. |
| `Cache` / `Log` | Fachadas de Laravel | Infraestructura | Almacenamiento temporal y registro de fallos del Proxy. |
| API Nager.Date | Servicio externo | Adaptee | Origen remoto de los festivos. |

### 5.1. Ventajas

- **Transparencia:** el Client sigue dependiendo de `HolidaySource`; no cambia cuando se agrega o se quita el Proxy.
- **Menos llamadas remotas:** un año ya cacheado se responde sin tocar Nager.Date.
- **Aislamiento del fallo:** el RealSubject puede lanzar; el Proxy decide degradar y el calendario no recibe la excepción.
- **Caché no envenenada:** un fallo no se guarda, por lo que el siguiente intento vuelve a consultar la API.
- **Responsabilidad única:** la traducción HTTP/JSON queda en `NagerDateHolidaySource`; la política de acceso, en `CachedHolidaySource`.
- **Sustitución en pruebas:** el Subject permite probar Proxy y real por separado; en `testing`, el calendario se sustituye por `WeekendBusinessCalendar`.
- **Clave explícita:** `holidays:{country}:{year}` evita mezclar países o años distintos.
- **Composición con Adapter:** el mismo objeto real puede ser RealSubject y Adapter sin duplicar la traducción.

### 5.2. Desventajas

- **Datos con hasta un día de antigüedad:** el TTL de un día puede retrasar la incorporación de un festivo corregido o publicado tarde.
- **Degradación ambigua:** el Client recibe `[]` tanto si no hay festivos como si la API falló; la diferencia solo queda en el log.
- **Cálculo potencialmente incorrecto:** al degradar, los días de semana se tratan como hábiles aunque sean festivos; el respaldo solo cubre fines de semana.
- **Sin reintentos ni backoff:** cada fallo de caché puede esperar el timeout de la petición; el Proxy no reintenta.
- **Invalidación limitada:** no hay tags ni versión de clave; para limpiar la caché en pruebas se usa `Cache::flush()`.
- **País duplicado en la composición:** el país se pasa al Proxy y al RealSubject; una construcción inconsistente produciría claves y consultas distintas.
- **Dependencias por fachada:** `Cache` y `Log` no se inyectan; se controlan con las utilidades de Laravel, pero no con dobles explícitos del constructor.
- **Dos responsabilidades en el real:** `NagerDateHolidaySource` es RealSubject y Adapter a la vez; es correcto por composición, pero exige explicar ambos roles.
- **Tope de 366 días:** si el Proxy devuelve una lista vacía durante un período largo, `nextBusinessDay()` puede agotar el tope y usar el respaldo de fin de semana.
