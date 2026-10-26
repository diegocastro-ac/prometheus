# Guía: consumir el IPC del DANE desde Laravel

App de arrendamientos informales que calcula reajustes por IPC.

> **Estado:** verificado con peticiones reales (HTTP 200) contra el endpoint.
> El DANE **no publica una API REST oficial** del IPC. Lo que se usa es el servicio **CDA de Pentaho** que alimenta su "Consulta dinámica del IPC". Funciona y devuelve JSON, pero **no está documentado ni garantizado** y puede cambiar sin aviso. Por eso la guía recomienda **sincronizar a tu base de datos** y calcular siempre desde la copia local. Las secciones 6 a 9 cubren la regla legal del reajuste (Ley 820), las ciudades y códigos (Valledupar = `20001`) y los servicios relacionados.

---

## ⚠️ Errata importante (verificada contra el endpoint)

Esta guía se corrigió después de **ejecutar cada consulta contra el servicio real**
y comparar con las cifras del IPC oficial. Tres cosas estaban mal y una de ellas
producía un tope legal incorrecto:

| Afirmación original | Realidad verificada |
|---|---|
| Filtrar por `C.CIP_NIVEL_ESTRUCTURA IN ('14','15','16','17','18')` con `C.CIP_N18 IN ('437')` | **Eso no es el IPC.** Es una porción de la canasta. Diciembre 2024 da **10,80 %** cuando el IPC oficial de 2024 fue **5,20 %**. El filtro correcto es **solo `IN ('14')`**. |
| El total nacional se obtiene excluyendo 25 códigos de ciudad (`NOT IN (...)`) | El nacional es una fila propia con **`PAI_ID = 50`**. Filtrar por un código inventado devuelve **cero filas**. |
| El IPC por ciudad "no probado" | **Funciona.** `H.PAI_ID IN ('20001')` devuelve la serie de Valledupar (213 periodos, 2018→2026). |
| `qryCiudad` "no sé su respuesta" | Devuelve **23 ciudades**, y su `PAI_ID` viene **como número**: `8001` en vez de `08001`. Hay que rellenear a cinco dígitos. |

Además, el seeder que accompanyaba al código guardaba `2025 => 9,28 %`, que es
en realidad el IPC de **2023**. Las cifras correctas, que son las que devuelve el
CDA y coinciden con las publicadas por el DANE:

| Diciembre | Variación anual |
|---|---|
| 2023 | **9,28 %** |
| 2024 | **5,20 %** |
| 2025 | **5,10 %** |

La implementación que acompaña a esta guía incluye un comando que verifica esto:

```bash
php artisan ipc:doctor
```

> En el código del proyecto, todo esto ya está resuelto: el filtro corregido está
> en `config/dane_ipc_sql.php`, la normalización en `DaneCdaIpcProvider`, y las
> cifras de control en el propio validador de la sincronización. Ver
> [PATRONES-IPC-REAJUSTES.md](PATRONES-IPC-REAJUSTES.md).

---

## 1. Resumen de lo descubierto

| Dato | Valor |
|---|---|
| Endpoint | `https://apps.dane.gov.co/pentaho/plugin/cda/api/doQuery` |
| Método | **`POST`** |
| Cuerpo | `application/x-www-form-urlencoded` (la URL va sin query string) |
| Autenticación | Ninguna |
| Archivo CDA | `/public/IPC/IPCV7.cda` |
| Consulta que trae los valores | **`ktlDinamico`** |
| Respuesta | JSON con `metadata`, `resultset` y `queryInfo` |

### Cómo se llegó aquí

1. El tablero `.../:public:IPC:IPCV7.wcdf/generatedContent` devuelve HTML (Pentaho CDF), no datos.
2. En ese HTML aparecen consultas (`qryAnio`, `qryMes`, `qryCiudad`, `qryValor`, `ktlDinamico`...) y la ruta `/public/IPC/IPCV7.cda`.
3. Al inspeccionar la pestaña **Red** del navegador mientras se usa la consulta dinámica, se ve que los valores del IPC salen de **`ktlDinamico`**, enviando por POST fragmentos de SQL (`SELECT`, `FROM`, `WHERE`, `ORDER BY`) como parámetros.

---

## 2. La petición real (capturada del navegador)

```
POST https://apps.dane.gov.co/pentaho/plugin/cda/api/doQuery
Content-Type: application/x-www-form-urlencoded
```

| Campo del formulario | Valor |
|---|---|
| `path` | `/public/IPC/IPCV7.cda` |
| `dataAccessId` | `ktlDinamico` |
| `outputIndexId` | `1` |
| `pageSize` | `0` (0 = sin paginar, trae todo) |
| `pageStart` | `0` |
| `sortBy` | *(vacío)* |
| `paramparsSelect` | columnas a devolver (ver sección 3) |
| `paramparsFrom` | tablas/vistas (ver sección 3) |
| `paramparsWhere` | filtros (ver sección 3) |
| `paramparsOrderby` | ` T.TIE_ANO, T.TIE_MES, TT.TIE_MES` |
| `paramsearchBox` | *(vacío)* |

**Sobre los nombres:** CDA antepone `param` al nombre de cada parámetro de la consulta. Los parámetros se llaman `parsSelect`, `parsFrom`, `parsWhere`, `parsOrderby` y `searchBox`, por eso en el formulario aparecen como `paramparsSelect`, etc.

### Respuesta

```json
{
  "queryInfo": { "totalRows": "333" },
  "metadata": [
    { "colIndex": 0, "colType": "String", "colName": "Año" },
    { "colIndex": 1, "colType": "String", "colName": "Mes" }
  ],
  "resultset": [
    ["1999", "Ene", "Total Nivel de Ingresos", "Subclase", "..."]
  ]
}
```

- `metadata`: nombre de cada columna. **Mapea siempre por `colName`**, no por posición.
- `resultset`: filas como arreglos, en el mismo orden que `metadata`.
- `Mes` llega **abreviado en español** (`Ene`, `Feb`, ...), hay que convertirlo a número.

### Columnas devueltas

`Año`, `Mes`, `Nivel de ingresos`, `Niveles de Canasta`, `División`, `Grupo`, `Clase`, `Subclase`, `Ciudad`, `Número Índice`, `Variación Mensual`, `Variación Año Corrido`, `Variación Anual`, `Variación Trimestral IPC`, `Variacion Semestral IPC`, y las de contribución y participación (mensual, año corrido, anual, trimestral, semestral).

Para reajustar arriendos solo importan: **`Año`, `Mes`, `Número Índice`, `Variación Mensual`, `Variación Año Corrido`, `Variación Anual`**.

---

## 3. Los parámetros SQL (qué significan)

`ktlDinamico` arma una consulta SQL con los fragmentos que le envías.

### `paramparsSelect`

```sql
T.TIE_NOM_ANO "Año",TT.TIE_NOM_MES "Mes",CO.CPT_NOMBRE "Nivel de ingresos",C.CIP_TITULO_NIVEL "Niveles de Canasta",C1.CIP_N15_NOMBRE "División",C2.CIP_N16_NOMBRE "Grupo",C3.CIP_N17_NOMBRE "Clase",C4.CIP_N18_NOMBRE "Subclase",D.MUN_NOM "Ciudad", numero_indice "Número Índice" , IPC_VARIACION_MENSUAL "Variación Mensual" , VARIACION_ANO_CORRIDO "Variación Año Corrido" , VARIACION_ANUAL "Variación Anual" , IPC_VARIACION_TRIMESTRAL "Variación Trimestral IPC" , IPC_VARIACION_SEMESTRAL "Variacion Semestral IPC" , IPC_CONTRIBUCION_MENSUAL "Contribución Mensual IPC" , CONTRIBUCION_ANO_CORRIDO "Contribución Año corrido" , CONTRIBUCION_ANUAL "Contribución Anual" , IPC_CONTRIBUCION_TRIMESTRAL "Contribución Trimestral IPC" , IPC_CONTRIBUCION_SEMESTRAL "Contribución Semestral IPC" , IPC_PARTICIPACION_MENSUAL "Participación Mensual IPC" , PARTICIPACION_ANO_CORRIDO "Participación Año Corrido" , IPC_PARTICIPACION_TRIMESTRAL "Participación Trimestral IPC" , IPC_PARTICIPACION_SEMESTRAL "Participación Semestral IPC"
```

Los alias entre comillas (`"Año"`, `"Número Índice"`...) son los `colName` de la respuesta.

### `paramparsFrom`

```sql
VWIPC_PAIS_CIUDAD H,DIM_TIEMPO T,DIM_TIEMPO TT,DIM_IPC_CONCEPTO CO,VWDIM_IPC_CANASTA C,VWDIM_IPC_CANASTA C1,VWDIM_IPC_CANASTA C2,VWDIM_IPC_CANASTA C3,VWDIM_IPC_CANASTA C4,VWDIM_IPC_CIUDAD D
```

### `paramparsWhere` (resumen de lo que filtra)

| Condición | Qué hace |
|---|---|
| `T.TIE_ID=H.TIE_ID`, `TT.TIE_ID=H.TIE_ID`, `CO.CPT_ID=H.CPT_ID`, `C.CIP_ID=H.CIP_ID` (y C1..C4), `D.MUN_ID=H.PAI_ID` | **Joins** entre tablas. Son obligatorios. |
| `T.TIE_ANO IN ('2026',...,'1954')` | Años |
| `TT.TIE_MES IN ('1',...,'12')` | Meses |
| `H.CPT_ID IN ('12')` | Nivel de ingresos: **"Total Nivel de Ingresos"**, el promedio ponderado de todos los estratos. |
| **`H.PAI_ID = '50'`** | **El total nacional.** No es "ciudades menos una lista": es una fila propia del modelo. |
| **`C.CIP_NIVEL_ESTRUCTURA IN ('14')`** | **El nivel agregado de la canasta. Solo el 14.** |

Resultado de esta combinación: **una fila por mes, total nacional**.

> ### ❗ Por qué solo el nivel 14
>
> Este es el punto donde la captura del navegador lleva a error, y el error es
> **silencioso**: los números salen con aspecto normal.
>
> El tablero envía `CIP_NIVEL_ESTRUCTURA IN ('14','15','16','17','18')` junto con
> `CIP_N18 IN ('437')`. Eso selecciona una **porción de la estructura de la
> canasta**, no el IPC. Se comprobó ejecutando ambas:
>
> | Filtro | Diciembre 2024 | Correcto |
> |---|---|---|
> | Niveles 14–18 con `CIP_N18 = '437'` | **10,80 %** | ❌ |
> | Solo nivel `14` | **5,20 %** | ✅ |
>
> El nivel 14 es el agregado ("Total"). Los niveles 15 a 18 son divisiones,
> grupos, clases y subclases, y filtrar por ellos devuelve una serie distinta.
>
> Con solo el nivel 14, la serie nacional reproduce exactamente las cifras
> oficiales del DANE, que es el criterio de aceptación.

> **Sobre `qryCiudad`:** devuelve 23 ciudades y su `PAI_ID` llega **como número**.
> `8001` es Barranquilla y `5001` es Medellín. Sin rellenar a cinco dígitos, el
> filtro escribe un código que el CDA no reconoce y devuelve cero filas sin error.

### ⚠️ Seguridad: esto es SQL enviado desde el cliente

El servicio ejecuta el SQL que le mandas. Reglas para tu app:

1. **Nunca** metas texto de un usuario en `parsWhere` ni en ningún campo `pars*`.
2. Arma las consultas solo con **plantillas fijas** y valores **validados o casteados** (`(int)`).
3. Úsalo únicamente para lecturas de la serie del IPC, igual que lo hace el tablero. No explores otras tablas.
4. Haz pocas peticiones (una sincronización mensual basta).

---

## 4. Probar la petición

### Opción rápida: repetir la captura del navegador

1. Abre la consulta dinámica del IPC en el navegador.
2. DevTools → pestaña **Red** → filtra por `doQuery`.
3. Clic derecho sobre la petición → **Copiar como cURL** (o **Copiar como PowerShell**).
4. Pégala en la terminal. Debe responder `200` con JSON y `totalRows` > 0.

### Verificar el resultado

| Resultado | Significado |
|---|---|
| JSON con `metadata` y `resultset` | Funciona |
| HTML | Ruta equivocada, error de Pentaho o redirección |
| 405 | Método incorrecto: debe ser POST |
| `totalRows: "0"` | Los filtros no coinciden con ninguna fila |
| Error de SQL en el cuerpo | Un fragmento `pars*` está mal armado |

---

## 5. Integración en Laravel

### 5.1 Configuración

`.env`

```env
DANE_IPC_BASE_URL=https://apps.dane.gov.co/pentaho/plugin/cda/api
DANE_IPC_CDA_PATH=/public/IPC/IPCV7.cda
```

`config/services.php`

```php
'dane_ipc' => [
    'base_url' => env('DANE_IPC_BASE_URL', 'https://apps.dane.gov.co/pentaho/plugin/cda/api'),
    'cda_path' => env('DANE_IPC_CDA_PATH', '/public/IPC/IPCV7.cda'),
    'timeout'  => 60,
],
```

### 5.2 Plantillas SQL fijas

`config/dane_ipc_sql.php`: son las constantes capturadas del tablero. Tras cualquier cambio, valida que sigue devolviendo las mismas filas (333 a septiembre de 2026).

```php
<?php

return [

    'select' => 'T.TIE_NOM_ANO "Año",TT.TIE_NOM_MES "Mes",CO.CPT_NOMBRE "Nivel de ingresos",C.CIP_TITULO_NIVEL "Niveles de Canasta",C1.CIP_N15_NOMBRE "División",C2.CIP_N16_NOMBRE "Grupo",C3.CIP_N17_NOMBRE "Clase",C4.CIP_N18_NOMBRE "Subclase",D.MUN_NOM "Ciudad", numero_indice "Número Índice" , IPC_VARIACION_MENSUAL "Variación Mensual" , VARIACION_ANO_CORRIDO "Variación Año Corrido" , VARIACION_ANUAL "Variación Anual" , IPC_VARIACION_TRIMESTRAL "Variación Trimestral IPC" , IPC_VARIACION_SEMESTRAL "Variacion Semestral IPC" , IPC_CONTRIBUCION_MENSUAL "Contribución Mensual IPC" , CONTRIBUCION_ANO_CORRIDO "Contribución Año corrido" , CONTRIBUCION_ANUAL "Contribución Anual" , IPC_CONTRIBUCION_TRIMESTRAL "Contribución Trimestral IPC" , IPC_CONTRIBUCION_SEMESTRAL "Contribución Semestral IPC" , IPC_PARTICIPACION_MENSUAL "Participación Mensual IPC" , PARTICIPACION_ANO_CORRIDO "Participación Año Corrido" , IPC_PARTICIPACION_TRIMESTRAL "Participación Trimestral IPC" , IPC_PARTICIPACION_SEMESTRAL "Participación Semestral IPC"',

    'from' => 'VWIPC_PAIS_CIUDAD H,DIM_TIEMPO T,DIM_TIEMPO TT,DIM_IPC_CONCEPTO CO,VWDIM_IPC_CANASTA C,VWDIM_IPC_CANASTA C1,VWDIM_IPC_CANASTA C2,VWDIM_IPC_CANASTA C3,VWDIM_IPC_CANASTA C4,VWDIM_IPC_CIUDAD D',

    'orderby' => ' T.TIE_ANO, T.TIE_MES, TT.TIE_MES',

    // Códigos de ciudad excluidos para quedarse con el total nacional.
    'excluir_pai' => ['15001','18001','19001','20001','44001','47001','63001','70001','73001','87001','05001','91001','81001','11001','08001','68001','13001','54001','17001','23001','41001','52001','66001','50001','76001'],

    // Filtros de canasta tal como los envía el tablero.
    'cip_n15' => ['317','390','402','419','443','475','496','528','535','575','592','605'],
    'cip_n16' => ['318','379','391','399','403','414','420','423','426','429','438','444','449','453','460','464','467','476','483','493','497','504','521','529','532','536','542','553','560','572','576','579','582','587','593','602','606','617','626','631','636','639'],
    'cip_n17' => ['319','328','333','335','340','344','352','364','371','380','384','392','395','397','400','404','410','412','415','421','424','427','430','432','434','436','439','441','445','450','454','458','461','465','468','473','477','479','481','484','487','489','494','498','500','502','505','510','513','517','522','524','530','533','537','540','543','546','548','551','554','557','561','564','566','568','573','577','580','583','588','594','600','603','607','609','611','618','622','627','632','634','637','640'],
    'cip_n18' => ['437'],
];
```

### 5.3 Cliente del DANE

`app/Services/DaneIpcClient.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DaneIpcClient
{
    /**
     * Serie mensual nacional (total nivel de ingresos) desde 1999.
     * Devuelve filas asociativas: ['Año' => '1999', 'Mes' => 'Ene', 'Número Índice' => ..., ...]
     */
    public function serieNacional(): array
    {
        $sql = config('dane_ipc_sql');

        $fields = [
            'path'             => config('services.dane_ipc.cda_path'),
            'dataAccessId'     => 'ktlDinamico',
            'outputIndexId'    => 1,
            'pageSize'         => 0,
            'pageStart'        => 0,
            'sortBy'           => '',
            'paramparsSelect'  => $sql['select'],
            'paramparsFrom'    => $sql['from'],
            'paramparsWhere'   => $this->whereNacional($sql),
            'paramparsOrderby' => $sql['orderby'],
            'paramsearchBox'   => '',
        ];

        return $this->doQuery($fields);
    }

    /** WHERE fijo: solo constantes y enteros casteados. NUNCA interpolar entrada de usuarios. */
    private function whereNacional(array $sql): string
    {
        $anios = "'" . implode("','", range(1954, (int) now()->year)) . "'";
        $meses = "'" . implode("','", range(1, 12)) . "'";
        $q = fn (array $a) => "'" . implode("','", $a) . "'";

        return "T.TIE_ID=H.TIE_ID AND T.TIE_ANO IN ({$anios})"
            . " AND TT.TIE_ID=H.TIE_ID AND TT.TIE_MES IN ({$meses})"
            . " AND CO.CPT_ID=H.CPT_ID AND H.CPT_ID IN ('12')"
            . " AND H.PAI_ID NOT IN (" . implode(',', $sql['excluir_pai']) . ")"
            . " AND C.CIP_ID=H.CIP_ID AND C.CIP_NIVEL_ESTRUCTURA IN ('14','15','16','17','18')"
            . " AND C1.CIP_ID=H.CIP_ID AND C.CIP_N15 IN ({$q($sql['cip_n15'])})"
            . " AND C2.CIP_ID=H.CIP_ID AND C.CIP_N16 IN ({$q($sql['cip_n16'])})"
            . " AND C3.CIP_ID=H.CIP_ID AND C.CIP_N17 IN ({$q($sql['cip_n17'])})"
            . " AND C4.CIP_ID=H.CIP_ID AND C.CIP_N18 IN ({$q($sql['cip_n18'])})"
            . " AND D.MUN_ID=H.PAI_ID";
    }

    private function doQuery(array $fields): array
    {
        // POST con cuerpo application/x-www-form-urlencoded
        $response = Http::asForm()
            ->timeout(config('services.dane_ipc.timeout'))
            ->retry(3, 2000)
            ->post(config('services.dane_ipc.base_url') . '/doQuery', $fields);

        if ($response->failed()) {
            throw new RuntimeException("DANE respondió HTTP {$response->status()}");
        }

        $json = $response->json();

        if (! is_array($json) || ! isset($json['metadata'], $json['resultset'])) {
            throw new RuntimeException('Respuesta inesperada del DANE (no es JSON CDA).');
        }

        $columnas = array_column($json['metadata'], 'colName');

        return array_map(
            fn (array $fila) => array_combine($columnas, $fila),
            $json['resultset']
        );
    }
}
```

> En el `WHERE` se simplificó el `CPT_ID`: la captura tenía `IN (12,11,10,9,8)` junto a `NOT IN (8,9,10,11,1,2,3,4)`, que combinados dejan solo `12`. También se omitió el segundo `PAI_ID NOT IN (...)` porque repetía un subconjunto del primero. **Compara `count($filas)` contra la captura original (333 a sept. 2026)** para confirmar que quedó equivalente. Si difiere, vuelve a las condiciones exactas de la captura.

### 5.4 Migración y modelo

```bash
php artisan make:migration create_ipc_indices_table
php artisan make:model IpcIndice
```

```php
Schema::create('ipc_indices', function (Blueprint $table) {
    $table->id();
    $table->string('ciudad_cod', 5)->default('NAL');   // 'NAL' = total nacional; o código DIVIPOLA (ej. 20001)
    $table->unsignedSmallInteger('anio');
    $table->unsignedTinyInteger('mes');
    $table->decimal('indice', 14, 4);
    $table->decimal('variacion_mensual', 10, 4)->nullable();
    $table->decimal('variacion_ano_corrido', 10, 4)->nullable();
    $table->decimal('variacion_anual', 10, 4)->nullable();
    $table->timestamps();

    $table->unique(['ciudad_cod', 'anio', 'mes']);
});
```

```php
// app/Models/IpcIndice.php
class IpcIndice extends Model
{
    protected $table = 'ipc_indices';
    protected $fillable = [
        'ciudad_cod', 'anio', 'mes', 'indice',
        'variacion_mensual', 'variacion_ano_corrido', 'variacion_anual',
    ];
}
```

### 5.5 Comando de sincronización

La serie completa son unas 333 filas, así que se descarga entera y se hace `upsert`.

```bash
php artisan make:command SyncIpcDane
```

```php
<?php

namespace App\Console\Commands;

use App\Models\IpcIndice;
use App\Services\DaneIpcClient;
use Illuminate\Console\Command;

class SyncIpcDane extends Command
{
    protected $signature = 'ipc:sync';
    protected $description = 'Sincroniza la serie mensual del IPC (DANE) a la base de datos local';

    private const MESES = [
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    public function handle(DaneIpcClient $dane): int
    {
        $filas = $dane->serieNacional();

        if (empty($filas)) {
            $this->error('El DANE no devolvió filas.');
            return self::FAILURE;
        }

        $registros = [];

        foreach ($filas as $f) {
            $mes = self::MESES[mb_strtolower(mb_substr(trim($f['Mes']), 0, 3))] ?? null;

            if (! $mes || ! is_numeric($f['Año'])) {
                $this->warn("Fila ignorada: {$f['Año']} / {$f['Mes']}");
                continue;
            }

            $registros[] = [
                'ciudad_cod'            => 'NAL',
                'anio'                  => (int) $f['Año'],
                'mes'                   => $mes,
                'indice'                => $this->num($f['Número Índice']),
                'variacion_mensual'     => $this->num($f['Variación Mensual']),
                'variacion_ano_corrido' => $this->num($f['Variación Año Corrido']),
                'variacion_anual'       => $this->num($f['Variación Anual']),
                'created_at'            => now(),
                'updated_at'            => now(),
            ];
        }

        IpcIndice::upsert(
            $registros,
            ['ciudad_cod', 'anio', 'mes'],
            ['indice', 'variacion_mensual', 'variacion_ano_corrido', 'variacion_anual', 'updated_at']
        );

        $this->info(count($registros) . ' periodos sincronizados.');
        return self::SUCCESS;
    }

    /** Convierte a float tolerando coma decimal y valores vacíos. */
    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $v);
    }
}
```

Programación mensual (el DANE publica el IPC en los primeros días del mes):

```php
// routes/console.php (Laravel 11+)  |  app/Console/Kernel.php (versiones anteriores)
Schedule::command('ipc:sync')->monthlyOn(10, '08:00');
```

> La primera vez, imprime `$filas[0]` para confirmar el formato real de los números (si llegan como `"0.52"` o `"0,52"`) y de `Mes` (`Ene`, `Sep` o `Sept`).

---

## 6. Reajuste según la Ley 820 de 2003

> **Texto verificado** contra la versión oficial vigente del Senado
> (actualizada al 30 de septiembre de 2026). También se confirmó con el concepto
> 119493 del Ministerio de Vivienda (23 de octubre de 2009).

### Artículo 20 — texto literal

> *"Cada doce (12) meses de ejecución del contrato bajo un mismo precio, el
> arrendador podrá incrementar el canon hasta en una proporción que no sea
> superior al ciento por ciento (100%) del incremento que haya tenido el índice
> de precios al consumidor en el año calendario inmediatamente anterior a aquél
> en que deba efectuarse el reajuste del canon, siempre y cuando el nuevo canon no
> exceda lo previsto en el artículo 18 de la presente ley.*
>
> *El arrendador que opte por incrementar el canon de arrendamiento, deberá
> informarle al arrendatario el monto del incremento y la fecha en que se hará
> efectivo, a través del **servicio postal autorizado** o mediante el mecanismo de
> notificación personal expresamente establecido en el contrato, **so pena de ser
> inoponible** al arrendatario. El pago por parte del arrendatario de un reajuste
> del canon, no le dará derecho a solicitar el reintegro, alegando la falta de la
> comunicación."*

### Artículo 18 — texto literal

> *"El precio mensual del arrendamiento será fijado por las partes en moneda
> legal pero no podrá exceder el uno por ciento (1%) del valor comercial del
> inmueble o de la parte de él que se dé en arriendo.*
>
> *La estimación comercial para efectos del presente artículo **no podrá exceder
> el equivalente a dos (2) veces el avalúo catastral vigente**."*

### Artículo 1 — alcance

> *"La presente ley tiene como objeto fijar los criterios que deben servir de base
> para regular los contratos de arrendamiento de los inmuebles urbanos destinados
> a vivienda..."*

Un **local comercial no está sujeto a la Ley 820**: se rige por el Código de
Comercio y por la Ley 1111 de 2006, donde el canon lo fija el mercado.

### Consecuencia técnica

| Dato | Regla |
|---|---|
| Qué IPC se usa | La **variación anual de diciembre del año anterior** |
| Cuándo se aplica | **12 meses después del último cambio de canon**, nunca automáticamente en enero |
| Sobre qué canon | **Sobre el canon que rigió**, no sobre el canon original |
| Ejemplo | Reajuste en octubre de 2026 → IPC 2025 = **5,10 %** |
| Con 900.000 | 900.000 × 1,051 = **945.900** |

En tu tabla local, ese dato es `variation_year` del registro
`year = (año del reajuste − 1)`, `month = 12`, `geography_code = '50'`.

### ⚠️ Sobre "bajo un mismo precio"

Esa frase es la que más se pasa por alto y la que cambia el cálculo:

> El plazo **no** corre desde el inicio del contrato. Corre desde el **último
> cambio de precio**. Un alquiler reajustado en octubre de 2025 vuelve a cumplir
> doce meses en octubre de 2026, no en la fecha original.

Y su corolario aritmético: el canon de entrada de cada reajuste es el canon que
**rigió**, no el canon original. Encadenar desde el original equivaldría a
renunciar al IPC del último año.

### ⚠️ Sobre "hasta el 100 %"

El texto **admite las dos lecturas**: como límite o como parámetro de
referencia. No se pudo verificar la jurisprudencia al escribir esto (el entorno
no tenía búsqueda web).

Si tu app **bloquea** un incremento por encima del IPC, estás adoptando la
lectura del límite. Si **permite y avisa**, la del parámetro. Ambas son
defendibles; lo que no es admisible es una de las dos **en silencio**.

Lo que sí es condición del artículo, y conviene no pasar por alto: la
**comunicación**. Sin ella el incremento es *inoponible*, y pagar no lo sana.

### Servicio

> **Nota:** el código siguiente es la propuesta original de esta guía. En el
> proyecto **no se implementó así**, porque el cálculo sobre el canon vigente
> queda obsoleto en cuanto se edita un reajuste anterior y no hay forma de
> recalcular.
>
> La implementación real está en `app/Services/RentAdjustmentService.php` +
> `app/Services/Adjustment/AdjustmentChainCalculator.php`, con un **ancla** de
> canon base en el alquiler y un **reproceso determinista** de la cadena. Ver
> [PATRONES-IPC-REAJUSTES.md](PATRONES-IPC-REAJUSTES.md).
>
> Se conserva aquí porque ilustra el problema que motivó el rediseño.

`app/Services/ReajusteLey820.php`

```php
<?php

namespace App\Services;

use App\Models\IpcIndice;
use Carbon\CarbonInterface;
use RuntimeException;

class ReajusteLey820
{
    /** Tope legal en %: variación anual de diciembre del año anterior (IPC nacional). */
    public function topePorcentaje(int $anioReajuste): float
    {
        $reg = IpcIndice::where([
            'ciudad_cod' => 'NAL',
            'anio'       => $anioReajuste - 1,
            'mes'        => 12,
        ])->first();

        if (! $reg || $reg->variacion_anual === null) {
            throw new RuntimeException(
                'No hay IPC de diciembre de ' . ($anioReajuste - 1) . '. Ejecuta ipc:sync.'
            );
        }

        return (float) $reg->variacion_anual;
    }

    /** Fecha desde la cual se puede reajustar: 12 meses después de la fecha base. */
    public function proximoReajuste(CarbonInterface $fechaBase): CarbonInterface
    {
        // fechaBase = inicio del contrato o fecha del último reajuste aplicado
        return $fechaBase->copy()->addYear();
    }

    /**
     * @param float|null $pctPactado   % que las partes acordaron (si es menor al tope se respeta)
     * @param float|null $valorComercial valor comercial del inmueble (para validar el art. 18)
     */
    public function calcular(
        float $canonActual,
        CarbonInterface $fechaReajuste,
        ?float $pctPactado = null,
        ?float $valorComercial = null,
    ): array {
        $tope = $this->topePorcentaje($fechaReajuste->year);
        $pct  = $pctPactado === null ? $tope : min($pctPactado, $tope);

        $nuevo = round($canonActual * (1 + $pct / 100));
        $avisos = [];

        // Art. 18: canon mensual <= 1 % del valor comercial (verifica el texto vigente)
        if ($valorComercial !== null && $nuevo > $valorComercial * 0.01) {
            $nuevo = round($valorComercial * 0.01);
            $avisos[] = 'El canon se limitó al 1 % del valor comercial (art. 18).';
        }

        return [
            'tope_pct'    => $tope,
            'aplicado_pct'=> $pct,
            'canon_nuevo' => $nuevo,
            'incremento'  => $nuevo - $canonActual,
            'fuente'      => "DANE, IPC dic. " . ($fechaReajuste->year - 1),
            'avisos'      => $avisos,
        ];
    }
}
```

Ejemplo:

```php
$r = app(ReajusteLey820::class)->calcular(900000, Carbon::parse('2026-10-15'));
// tope_pct 5.1  ->  canon_nuevo 945900
```

> Si tu app ofrece también el cálculo "por índices" (índice final / índice inicial), exponlo solo como **informativo**; el tope legal sale de la variación anual de diciembre.

---

## 7. Ciudades, Valledupar y códigos

### Cobertura del IPC

Desde 2019 el DANE calcula el IPC en **38 ciudades** (antes 24). Valledupar está incluida.

> **Lo que devuelve este CDA en particular:** 23 ciudades, no 38. Es la serie
> **legada de 24 ciudades** (22 + "Otras áreas urbanas"). Se verificó que
> Valledupar sí está, así que no bloquea el uso, pero conviene saberlo: una
> consulta a este `.cda` con una ciudad de las 38 más nuevas **no devolverá filas**
> porque el modelo no las tiene.

### Códigos DIVIPOLA

DIVIPOLA es la codificación oficial del DANE: **2 dígitos** para departamentos y **5 dígitos** para municipios (los dos primeros son el departamento).

| Ciudad | Código DIVIPOLA |
|---|---|
| **Valledupar** (Cesar = `20`) | **`20001`** |
| Bogotá D.C. | `11001` |
| Medellín | `05001` |
| Cali | `76001` |
| Barranquilla | `08001` |
| Cartagena | `13001` |

> **Guarda siempre el código como texto de 5 caracteres.** `qryCiudad` lo
> devuelve **como número** y el cero se pierde: `8001` es Barranquilla y `5001` es
> Medellín. Sin rellenear, el filtro escribe un código que el CDA no reconoce y
> devuelve cero filas **sin dar error**.

### Cómo obtener las ciudades (3 caminos)

**A. Desde el propio CDA del DANE: `qryCiudad`** — ✅ **funciona, verificado**

```bash
curl -X POST "https://apps.dane.gov.co/pentaho/plugin/cda/api/doQuery" \
  --data-urlencode "path=/public/IPC/IPCV7.cda" \
  --data-urlencode "dataAccessId=qryCiudad" \
  --data-urlencode "outputIndexId=1" \
  --data-urlencode "pageSize=0" \
  --data-urlencode "pageStart=0"
```

Respuesta real (HTTP 200, `totalRows = 23`):

```json
{"queryInfo":{"totalRows":"23"},
 "resultset":[[63001,"ARMENIA"],[8001,"BARRANQUILLA"],[11001,"BOGOTÁ, D.C."],
              [...], [20001,"VALLEDUPAR"], [50001,"VILLAVICENCIO"]],
 "metadata":[{"colName":"PAI_ID","colType":"Numeric"},
             {"colName":"MUN_NOM","colType":"String"}]}
```

Fíjate en `"colType":"Numeric"` en `PAI_ID`: **es el aviso del cero perdido**.

> Esta es la **única fuente válida** para saber qué ciudades tienen IPC. No la
> deduzcas del DIVIPOLA: de 1.122 municipios, solo unos pocos tienen IPC, y eso
> solo el DANE lo sabe.

**B. Filtrar la serie de una ciudad** — ✅ **probado y funcionando**

Solo cambia una línea del `WHERE`:

```sql
H.PAI_ID = '50'      -- total nacional
H.PAI_ID = '20001'   -- Valledupar
```

Verificado: `20001` devuelve **213 periodos** (2018-09 → 2026-09). Diciembre de
2025: índice `155,58`, variación anual **3,49 %**, frente al **5,10 %** nacional.

> **Sobre el artículo 20:** el texto dice "el índice de precios al consumidor"
> **sin mencionar ciudades**, y así lo cita el concepto 119493 del Ministerio de
> Vivienda. Que exista la serie por ciudad no significa que el artículo la
> contemple: usarla es **apartarse del texto**, y conviene que quede escrito por
> qué.

**C. Catálogo oficial de municipios (datos abiertos)** — ✅ **verificado**

**C. Catálogo oficial de municipios (datos abiertos)**

Dataset **"DIVIPOLA - Códigos municipios"** en datos.gov.co (id `gdxc-w37w`, plataforma Socrata, ~1.122 filas):

```
https://www.datos.gov.co/resource/gdxc-w37w.json?$limit=5000
```

Notas: Socrata devuelve **1.000 filas por defecto**, por eso hay que pasar `$limit`. La API distingue mayúsculas en los nombres de columnas y no acepta tildes ni ñ. Un paquete de terceros usa la columna `cod_mpio`; **imprime una fila para confirmar los nombres reales**. Alternativa oficial: `Listados_DIVIPOLA.xlsx` en el Geoportal del DANE.

### Tablas y comando en Laravel

```php
Schema::create('ciudades', function (Blueprint $table) {
    $table->id();
    $table->string('codigo', 5)->unique();    // DIVIPOLA, ej. 20001
    $table->string('nombre');
    $table->string('depto_codigo', 2)->nullable();
    $table->string('departamento')->nullable();
    $table->boolean('tiene_ipc')->default(false);
    $table->timestamps();
});
```

```php
// php artisan make:command SyncDivipola
public function handle(): int
{
    $filas = Http::timeout(60)->retry(3, 2000)
        ->get('https://www.datos.gov.co/resource/gdxc-w37w.json', ['$limit' => 5000])
        ->throw()
        ->json();

    // Verifica los nombres reales de columna la primera vez:
    $this->line(json_encode($filas[0] ?? null, JSON_UNESCAPED_UNICODE));

    $registros = collect($filas)->map(fn ($f) => [
        'codigo'       => str_pad((string) ($f['cod_mpio'] ?? ''), 5, '0', STR_PAD_LEFT),
        'nombre'       => $f['nom_mpio']  ?? null,   // ← confirmar nombres de columna
        'depto_codigo' => isset($f['cod_depto']) ? str_pad((string) $f['cod_depto'], 2, '0', STR_PAD_LEFT) : null,
        'departamento' => $f['dpto']      ?? null,
        'created_at'   => now(),
        'updated_at'   => now(),
    ])->filter(fn ($r) => $r['codigo'] !== '00000' && $r['nombre'])->values()->all();

    Ciudad::upsert($registros, ['codigo'], ['nombre', 'depto_codigo', 'departamento', 'updated_at']);

    $this->info(count($registros) . ' municipios sincronizados.');
    return self::SUCCESS;
}
```

Luego marca `tiene_ipc = true` en las que devuelva `qryCiudad` (cruzando por código o nombre).

### Métodos extra para el cliente DANE

Agrégalos a `DaneIpcClient` (sección 5.3):

```php
/** Consulta simple del CDA (listas auxiliares: qryCiudad, qryAnio...). */
public function consulta(string $dataAccessId, array $params = []): array
{
    $fields = [
        'path'          => config('services.dane_ipc.cda_path'),
        'dataAccessId'  => $dataAccessId,
        'outputIndexId' => 1,
        'pageSize'      => 0,
        'pageStart'     => 0,
        'sortBy'        => '',
    ];

    foreach ($params as $nombre => $valor) {
        $fields['param' . $nombre] = $valor;   // prefijo "param"
    }

    return $this->doQuery($fields);
}

public function ciudades(): array
{
    return $this->consulta('qryCiudad');
}
```

### IPC de una ciudad (opcional, no probado)

Para un precio por ciudad habría que cambiar `H.PAI_ID NOT IN (...)` por `H.PAI_ID IN ('20001')` y validar contra la captura del camino B. Valida el código (`preg_match('/^\d{5}$/', $codigo)`) antes de armar el SQL y guarda el resultado con `ciudad_cod = '20001'`.

> **Para reajustar arriendos usa el IPC nacional.** Las fuentes consultadas hablan del IPC certificado por el DANE para el año anterior, sin distinguir ciudad. El IPC de Valledupar sirve como dato informativo (p. ej. comparar si la ciudad está por encima o por debajo del nacional).

---

## 8. Servicios relacionados con arriendos en Colombia

### Del propio CDA del DANE (`/public/IPC/IPCV7.cda`)

| `dataAccessId` | Para qué sirve en tu app |
|---|---|
| **`ktlDinamico`** | **Serie del IPC** (la que ya funciona). Imprescindible. |
| **`qryCiudad`** | Ciudades con IPC y sus códigos. Útil. |
| `qryAnio`, `qryMes` | Años y meses disponibles. Opcionales: puedes validar con tu tabla local. |
| `qryConcepto`, `qryClasificacion`, `qryCobertura` | Niveles de ingreso, clasificaciones y cobertura. Solo si quieres otros cortes. |
| `qryGrupoGasto`, `qrySubGrupoGasto`, `qryClaseGasto`, `qryGastoBasico` | Desagregación por gasto. El rubro de **arriendo** de la canasta se ubica aquí. |
| `qryValor`, `qryTipoGrafica`, `...Chk` | Soporte de la interfaz (gráficas, filtros múltiples). No se necesitan. |

> **Rubro "arriendo" del IPC:** el DANE publica el índice de los gastos de vivienda y alquiler. Sirve para análisis o comparar el mercado, **no para el reajuste legal**, que usa el IPC total. Localiza su código con `qryGastoBasico` o capturando el tablero con ese rubro seleccionado.

### Otras fuentes (no DANE-CDA)

| Fuente | Uso |
|---|---|
| **datos.gov.co (Socrata), "DIVIPOLA - Códigos municipios"** (`gdxc-w37w`) | Catálogo oficial de departamentos y municipios con código |
| **Geoportal DANE, `Listados_DIVIPOLA.xlsx`** | Mismo catálogo, descarga oficial |
| **Banco de la República, series históricas de precios e inflación** | **Respaldo** del IPC si el CDA del DANE deja de responder |
| **Excel oficiales de índices y variaciones del DANE** | Respaldo manual/automatizable |
| **Superfinanciera, tasa de usura** (certificación mensual) | Solo si la app calcula **intereses de mora**; no entra en el reajuste |

> **No hay una API oficial de "precios de arriendo"** equivalente al IPC que debas consumir. El tope legal de reajuste sale del IPC; el valor comercial del inmueble (art. 18) lo debe ingresar el usuario.

---

## 9. Reglas de negocio de arriendos que la app debe modelar

Resumen de las fuentes consultadas (Ley 820 de 2003, conceptos del Ministerio de Vivienda y medios especializados). **Verifica el texto vigente con un abogado.**

| Regla | Detalle |
|---|---|
| Frecuencia | Solo **una vez cada 12 meses** de ejecución bajo el mismo precio |
| Tope | Hasta el **100 % del IPC del año calendario anterior** (variación de diciembre) |
| Pacto menor | Las partes pueden acordar un incremento menor o mantener el canon |
| Límite del canon (art. 18) | Canon mensual no superior al **1 % del valor comercial** del inmueble (o de la parte arrendada); la estimación comercial tiene su propio techo ligado al avalúo catastral |
| Notificación | El aumento y la fecha desde la que rige deben comunicarse **por escrito** y de forma verificable; sin notificación adecuada, no es oponible |
| Alcance | La Ley 820 regula **vivienda urbana**. En contratos **comerciales** el arrendador puede fijar el canon con más libertad |
| Contratos anteriores al 10 de julio de 2003 | Según una fuente consultada, rige la Ley 56 de 1985 (hasta el 90 % del IPC). Confirmar antes de implementarlo |
| Duración sin pacto | Si no se acordó plazo, se entiende de 1 año (art. 5) |
| No usar | El **salario mínimo** no es la base del reajuste |

### Qué datos debe guardar tu app por contrato

- Fecha de inicio y **fecha del último reajuste** (base para el próximo aniversario)
- Canon vigente y **valor comercial** declarado (para validar el 1 %)
- Tipo: vivienda urbana / comercial (cambia el cálculo)
- Código de la ciudad (DIVIPOLA, p. ej. `20001`)
- % pactado, si existe
- Prueba de notificación (fecha, medio, evidencia)
- Cálculo guardado: IPC usado, mes y fecha de sincronización (auditoría)

### Sobre arriendos informales

Que el contrato sea verbal o informal no tiene por qué cambiar el cálculo, pero **sí afecta la prueba**: guarda en la app el canon acordado, la fecha de inicio y las notificaciones. Confirma con un abogado cómo opera la Ley 820 en contratos sin escrito.

---

## 10. Riesgos y buenas prácticas

1. **Sincroniza y guarda localmente.** Los cálculos dependen de tu base de datos, no del DANE en tiempo real.
2. **Usa POST con `Http::asForm()`.** No mandes JSON en el cuerpo ni parámetros en la URL.
3. **Valida el tipo de respuesta.** Si llega HTML, cambió la ruta o hubo un error.
4. **Esquema interno expuesto:** nombres de tablas y columnas (`VWIPC_PAIS_CIUDAD`, `CIP_N18`...) pueden cambiar sin aviso. Si `ipc:sync` falla, vuelve a capturar la petición del tablero y compara.
5. **Seguridad:** SQL fijo, sin entrada de usuarios (sección 3). Valida con regex cualquier código de ciudad antes de usarlo.
6. **Planes de respaldo:** Excel oficiales del DANE y series del Banco de la República.
7. **Auditoría:** guarda `updated_at` y el IPC usado en cada reajuste.
8. **Fuente visible:** muestra "Fuente: DANE, IPC dic. [año]" al usuario.
9. **Calendario de publicación:** el DANE publica el IPC en los primeros días de cada mes (el dato de 2025 salió el 8 de enero de 2026). Programa la sincronización para los días 8 a 15.
10. **Pruebas automáticas:** con `Http::fake()` simula la respuesta CDA y verifica parseo de `Mes`, números y la regla de diciembre.
11. **No es asesoría legal:** la app calcula un tope, no reemplaza el criterio de un abogado.

Programación sugerida:

```php
Schedule::command('ipc:sync')->cron('0 8 8-15 * *');   // días 8 a 15, 8:00 a. m.
Schedule::command('divipola:sync')->monthly();
```

---

## 11. Checklist

Lo marcado con ✅ se verificó ejecutando el comando contra el endpoint real.

**Datos**

- [x] Repetir la petición y confirmar `200` y `totalRows`
- [x] **Corregir el filtro de la canasta a solo `CIP_NIVEL_ESTRUCTURA IN ('14')`**
- [x] **Cambiar el filtro de geografía a `H.PAI_ID = '50'`** para el total nacional
- [x] Confirmar que la serie nacional reproduce las cifras oficiales (2023 = 9,28 · 2024 = 5,20 · 2025 = 5,10)
- [x] Confirmar que los números llegan con **coma decimal** y el mes abreviado
- [x] `qryCiudad`: 23 ciudades, `PAI_ID` numérico → rellenar a 5 dígitos
- [x] DIVIPOLA (`gdxc-w37w`): 1.122 municipios, columnas `cod_mpio`, `nom_mpio`, `dpto`
- [x] IPC por ciudad: `H.PAI_ID IN ('20001')` devuelve la serie de Valledupar

**Estructura**

- [ ] `ipc_series` (geografía × año × mes) en vez de un IPC anual suelto
- [ ] `geographies` como catálogo, para que el filtro no tenga códigos escritos en el código
- [ ] `ipc_rates` como tabla **derivada** de la serie, no escrita a mano
- [ ] Comando `ipc:doctor` que verifique la copia contra las cifras oficiales
- [ ] Programar la sincronización entre los días 8 y 15

**Cálculo**

- [ ] Ancla de canon base en el alquiler, congelada
- [ ] `previous_rent` **derivado** por replay, nunca leído del canon vigente
- [ ] Reproceso idempotente de la cadena al editar o borrar un reajuste
- [ ] La vigencia propuesta es el **aniversario**, no el 1 de enero
- [ ] Las 9 reglas del artículo 1, 18 y 20 separadas y trazables a su artículo

**Datos del contrato**

- [ ] Tipo: vivienda urbana / comercial (artículo 1)
- [ ] Valor comercial y avalúo catastral (artículo 18, incluido el techo de 2×)
- [ ] Fecha del último reajuste, para los 12 meses "bajo un mismo precio"
- [ ] Medio de comunicación, no solo la fecha (artículo 20 ¶2)

**Robustez**

- [ ] Respaldo del IPC (Excel del DANE / Banco de la República) por si el CDA deja de responder
- [ ] `Http::fake()` con los payloads reales capturados como prueba del adaptador

---

## 12. Referencias

### Legales

- **Ley 820 de 2003**, texto oficial vigente (Senado, actualización del 30 de septiembre de 2026): http://www.secretariasenado.gov.co/senado/basedoc/ley_0820_2003.html
- Ministerio de Vivienda, concepto 119493 de 2009 (artículo 20): https://www.minvivienda.gov.co/concepto_juridico/concepto-119493-del-23-de-octubre-de-2009-aumento-canon-de-arrendamiento

> Los artículos 1, 18, 19 y 20 que se citan arriba se transcribieron del texto
> oficial, no de versiones de prensa.

### Técnicas

- DANE, consulta dinámica del IPC: `https://apps.dane.gov.co/pentaho/api/repos/:public:IPC:IPCV7.wcdf/generatedContent`
- Servicio CDA: `https://apps.dane.gov.co/pentaho/plugin/cda/api/doQuery` · Archivo: `/public/IPC/IPCV7.cda`
- DANE, evolución de cobertura del IPC (24 → 38 ciudades): https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/indice-de-precios-al-consumidor-ipc/ipc-actualizacion-metodologica-2019/ipc-evolucion-de-cobertura
- Datos abiertos, DIVIPOLA - Códigos municipios: https://www.datos.gov.co/d/gdxc-w37w
- DANE, listados DIVIPOLA (Geoportal): https://geoportal.dane.gov.co/servicios/descarga-y-metadatos/datos-geoestadisticos/?cod=112
- Banco de la República, series históricas de precios e inflación: https://banrep.gov.co/estadisticas-economicas/series-historicas/precios-inflacion
- Tope de reajuste 2026 (IPC 2025 = 5,10 %): https://www.portafolio.co/mis-finanzas/vivienda/arriendos-subiran-desde-agosto-para-contratos-que-cumplan-un-ano-asi-se-aplicara-el-aumento-maximo-del-5-499168

### Internas

- [PATRONES-IPC-REAJUSTES.md](PATRONES-IPC-REAJUSTES.md) — los patrones del módulo
- [ADAPTER-DIAS-HABILES.md](ADAPTER-DIAS-HABILES.md) — el otro Adapter del proyecto, y en qué se diferencia de este
- [DOCUMENTACION-PATRONES.md](DOCUMENTACION-PATRONES.md) — los patrones de la aplicación base

- DANE, consulta dinámica del IPC: `https://apps.dane.gov.co/pentaho/api/repos/:public:IPC:IPCV7.wcdf/generatedContent`
- Servicio CDA: `https://apps.dane.gov.co/pentaho/plugin/cda/api/doQuery` · Archivo: `/public/IPC/IPCV7.cda`
- DANE, evolución de cobertura del IPC (24 → 38 ciudades): https://www.dane.gov.co/index.php/estadisticas-por-tema/precios-y-costos/indice-de-precios-al-consumidor-ipc/ipc-actualizacion-metodologica-2019/ipc-evolucion-de-cobertura
- Banco de la República, series históricas de precios e inflación: https://banrep.gov.co/estadisticas-economicas/series-historicas/precios-inflacion
- Ministerio de Vivienda, concepto 119493 de 2009 (art. 20 Ley 820): https://www.minvivienda.gov.co/concepto_juridico/concepto-119493-del-23-de-octubre-de-2009-aumento-canon-de-arrendamiento
- Datos abiertos, DIVIPOLA - Códigos municipios: https://www.datos.gov.co/d/gdxc-w37w
- DANE, listados DIVIPOLA (Geoportal): https://geoportal.dane.gov.co/servicios/descarga-y-metadatos/datos-geoestadisticos/?cod=112
- Tope de reajuste 2026 (IPC 2025 = 5,10 %): https://www.portafolio.co/mis-finanzas/vivienda/arriendos-subiran-desde-agosto-para-contratos-que-cumplan-un-ano-asi-se-aplicara-el-aumento-maximo-del-5-499168
