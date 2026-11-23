<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Describe el esquema real de la base de datos para el prompt de Gemini.
 *
 * Sin esto el modelo solo conoce el "modelo de dominio" narrativo de la
 * guia y no puede escribir SQL correcto para `query_data`. Con el digest
 * ve tablas, columnas, tipos y enums reales, asi que puede investigar
 * CUALQUIER dato del negocio aunque ninguna herramienta especifica lo
 * cubra.
 *
 * Se genera una vez por request (cache estatico): introspectar ~20
 * tablas en cada mensaje seria desperdicio.
 */
class DatabaseSchemaDigest
{
    /**
     * Tablas que el asistente puede consultar y su scope de usuario.
     * 'user' => exige filtro user_id en el SQL; 'global' => datos de
     * referencia compartidos (IPC, geografia, ajustes del sistema).
     * Las tablas de infraestructura (cache, jobs, sessions...) quedan
     * fuera a proposito: no aportan al negocio y filtran ruido.
     *
     * @var array<string, 'user'|'global'>
     */
    private const TABLES = [
        'users' => 'user',
        'tenants' => 'user',
        'properties' => 'user',
        'spaces' => 'user',
        'master_inventory_items' => 'user',
        'rentals' => 'user',
        'invoices' => 'user',
        'invoice_sequences' => 'user',
        'payments' => 'user',
        'rent_adjustments' => 'user',
        'delivery_acts' => 'user',
        'act_items' => 'user',
        'document_accesses' => 'user',
        'app_settings' => 'global',
        'geographies' => 'global',
        'ipc_rates' => 'global',
        'ipc_series' => 'global',
    ];

    /**
     * Valores validos de columnas enum: el modelo los necesita para
     * filtrar/ordenar bien (status='vencida', method='nequi'...).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const ENUMS = [
        'invoices' => ['status' => ['emitida', 'pagada', 'vencida', 'anulada']],
        'payments' => ['method' => ['efectivo', 'transferencia', 'consignacion']],
        'rentals' => [
            'billing_cadence' => ['MENSUAL', 'QUINCENAL', 'ANTICIPO'],
            'rental_type' => ['residential', 'commercial'],
        ],
        'rent_adjustments' => [
            'ipc_basis' => ['nacional', 'ciudad'],
            'notification_method' => ['postal', 'contractual', 'in_person', 'email'],
        ],
        'delivery_acts' => ['type' => ['entrega', 'recepcion', 'cambio_arrendatario']],
        'act_items' => ['state' => ['nuevo', 'bueno', 'reparable', 'por_reemplazar', 'destruido']],
        'users' => ['document_type' => ['CC', 'TI', 'CE', 'PAS', 'NIT']],
    ];

    /** Relaciones conocidas que el modelo no puede inferir del DDL. */
    private const RELATIONS = [
        'rentals.tenant_id -> tenants.id',
        'rentals.property_id -> properties.id',
        'invoices.rental_id -> rentals.id',
        'invoices.user_id -> users.id',
        'payments.rental_id -> rentals.id',
        'payments.invoice_id -> invoices.id',
        'rent_adjustments.rental_id -> rentals.id',
        'rent_adjustments.ipc_rate_id -> ipc_rates.id',
        'delivery_acts.rental_id -> rentals.id',
        'act_items.delivery_act_id -> delivery_acts.id',
        'act_items.space_id -> spaces.id',
        'properties.geography_code -> geographies.code',
        'spaces.parent_id -> spaces.id',
        'ipc_series.geography_code -> geographies.code',
    ];

    /**
     * Semantica de negocio que el DDL no puede expresar: campos
     * calculados, unidades, trampas conocidas. Sin esto el modelo puede
     * escribir SQL correcto pero con interpretacion erronea (ej: sumar
     * invoices.amount como "deuda" cuando incluye pagadas y anuladas).
     */
    private const SEMANTICS = [
        'Dinero: todas las columnas amount/monthly_amount/new_rent/balance son pesos COP. No formatees en SQL; el chat las muestra crudas.',
        'invoices: amount = total facturado del periodo. NO hay columna balance: el saldo real = amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = invoices.id), 0). period es "YYYY-MM". status: emitida=pendiente de pago, vencida=vencida (deuda real), pagada=cancelada, anulada=anulada (NO es deuda). paid_at solo tiene valor cuando status=pagada.',
        'payments: amount es lo abonado a invoice_id (puede ser pago parcial). method: efectivo|transferencia|consignacion (billeteras digitales como nequi/daviplata se registran como transferencia). date = fecha del pago (no la de la factura). Un invoice puede tener varios payments.',
        'rentals: monthly_amount = canon pactado base. OJO: el canon vigente puede ser mayor por reajustes aplicados (rent_adjustments.new_rent es el canon tras cada ajuste; el vigente es el de mayor effective_from ya aplicado). is_active=false = contrato terminado/inactivo (no facturar). billing_cadence: MENSUAL|QUINCENAL|ANTICIPO. rental_type: residential|commercial. start_date/end_date delimitan el contrato; total_months su duracion.',
        'rent_adjustments: previous_rent -> new_rent canon antes/despues; effective_from = desde cuando aplica; ipc_year/ipc_percentage = indice usado; applied_on NOT NULL = ya aplicado; notified_on NOT NULL = ya notificado al inquilino; notification_method: postal|contractual|in_person|email; ipc_basis: nacional|ciudad.',
        'delivery_acts: acta de entrega/recepcion/inventario. signed_at NOT NULL = acta firmada por ambas partes. type: entrega|recepcion|cambio_arrendatario. water/energy/gas_reading = lecturas de medidores.',
        'act_items: elementos del inventario dentro de un acta. state: nuevo|bueno|reparable|por_reemplazar|destruido. Se relaciona via delivery_act_id.',
        'tenants/properties/spaces: datos basicos. La ciudad/municipio de una propiedad NO es una columna: es properties.geography_code -> geographies.code -> geographies.name. Para "propiedades de X ciudad" filtra p.geography_code IN (SELECT code FROM geographies WHERE name LIKE ...). spaces es jerarquia interna por parent_id (sin propiedad asociada).',
        'IPC: ipc_rates = IPC anual por year/percentage; ipc_series = indice mensual detallado (geography_code + year + month -> index_value). Para "el IPC de X" busca en ipc_series por geografia y periodo.',
        'users: el arrendador mismo (TU usuario). Sus datos personales via users.id = userId. NO uses esta tabla para datos de inquilinos.',
        'Fechas: columnas date/datetime en formato ISO "YYYY-MM-DD". Para periodos mensuales usa "YYYY-MM" y compara con substr(fechas,1,7) o strftime/julianday segun el motor.',
        '"Deuda/cartera" = invoices con status vencida o emitida (emitida ya puede estar por vencer: usa due_at < hoy para vencidas reales). "Recaudo" = SUM(payments.amount). "Canon esperado" = rentals.monthly_amount (o canon vigente tras ajustes) de rentals activos.',
    ];

    private static ?string $cached = null;

    /**
     * Texto compacto "tabla(col:type, col:type)" + enums + relaciones.
     * Devuelve '' si la introspeccion falla (la guia de dominio sigue).
     */
    public function forPrompt(): string
    {
        return self::$cached ??= $this->build();
    }

    /**
     * Diccionario semantico: que SIGNIFICAN los datos, no solo como se
     * llaman. Va junto al esquema en el prompt.
     */
    public function semanticsForPrompt(): string
    {
        return "- ".implode("\n- ", self::SEMANTICS);
    }

    /**
     * Nombres de las tablas del dominio consultables por el asistente.
     *
     * @return list<string>
     */
    public static function domainTables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Ficha completa de una tabla del negocio para la herramienta de
     * exploracion del asistente (lookup_data con params.table): el
     * modelo la pide cuando duda de una columna, una relacion o
     * necesita aprender una tabla mas alla del resumen del prompt.
     * Devuelve null si la tabla no es del dominio.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $table, int $userId): ?array
    {
        $table = strtolower(trim($table));
        $scope = self::TABLES[$table] ?? null;

        if ($scope === null || ! Schema::hasTable($table)) {
            return null;
        }

        try {
            $rawColumns = Schema::getColumns($table);
        } catch (\Throwable) {
            return null;
        }

        $columns = [];

        foreach ($rawColumns as $column) {
            if (in_array($column['name'], ['created_at', 'updated_at'], true)) {
                continue;
            }

            $columns[] = [
                'name' => $column['name'],
                'type' => $column['type_name'] ?? 'text',
                'nullable' => (bool) ($column['nullable'] ?? true),
                'default' => $column['default'] ?? null,
            ];
        }

        $totalRows = null;

        try {
            $count = DB::table($table);

            // `users` no tiene user_id: es la fila del propio arrendador.
            if ($table === 'users') {
                $count->where('id', $userId);
            } elseif ($scope === 'user') {
                $count->where('user_id', $userId);
            }

            $totalRows = $count->count();
        } catch (\Throwable) {
            // El conteo es informativo: si falla la ficha igual sirve.
        }

        return [
            'table' => $table,
            'scope' => $scope === 'global' ? 'global' : 'user',
            'columns' => $columns,
            'enums' => self::ENUMS[$table] ?? [],
            'relations' => array_values(array_filter(
                self::RELATIONS,
                fn (string $relation): bool => str_starts_with($relation, $table.'.')
                    || str_contains($relation, '-> '.$table.'.'),
            )),
            'total_rows' => $totalRows,
        ];
    }

    private function build(): string
    {
        $lines = [];

        foreach (self::TABLES as $table => $scope) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumns($table);
            } catch (\Throwable) {
                continue;
            }

            $parts = [];

            foreach ($columns as $column) {
                $name = $column['name'];

                // created_at/updated_at son ruido para el modelo.
                if (in_array($name, ['created_at', 'updated_at'], true)) {
                    continue;
                }

                $type = $column['type_name'] ?? 'text';
                $parts[] = "{$name}:{$type}";
            }

            // `users` es la tabla del propio arrendador: no tiene user_id,
            // se identifica por id (el userId del prompt).
            $scopeNote = match (true) {
                $scope === 'global' => ' [global, sin user_id]',
                $table === 'users' => ' [filtrar users.id = id del arrendador]',
                default => ' [filtrar user_id]',
            };
            $enums = self::ENUMS[$table] ?? [];
            $enumNote = $enums === [] ? '' : ' ENUMS: '.json_encode($enums, JSON_UNESCAPED_UNICODE);

            $lines[] = "- {$table}{$scopeNote}(".implode(', ', $parts)."){$enumNote}";
        }

        if ($lines === []) {
            return '';
        }

        $lines[] = '- Relaciones: '.implode('; ', self::RELATIONS);

        return implode("\n", $lines);
    }
}
