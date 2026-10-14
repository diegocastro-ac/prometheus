<?php

namespace App\Console\Commands;

use App\Dto\Geography;
use App\Infrastructure\Ipc\DaneCdaIpcProvider;
use App\Models\Geography as GeographyModel;
use App\Services\Ipc\IpcSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sincroniza el catalogo de ciudades con IPC.
 *
 * Son dos fuentes distintas y se combinan a proposito.
 *
 * La del DANE es la que manda: qryCiudad devuelve las 23 ciudades para las que
 * el DANE publica IPC, con su PAI_ID. Ese es el unico origen de verdad sobre
 * cuales se pueden filtrar, y por eso el comando falla si no la consigue. No se
 * deduce del DIVIPOLA porque un municipio puede no tener IPC y eso solo el DANE
 * lo sabe.
 *
 * La de datos abiertos (DIVIPOLA) aporta el nombre del departamento, que el CDA
 * no trae. Es un enriquecimiento opcional: si falla, el catalogo se queda sin
 * departamento y el selector sigue funcionando, solo que sin el agrupamiento.
 */
class SyncIpcGeographies extends Command
{
    protected $signature = 'ipc:sync:geographies {--skip-divipola : No intenta enriquecer con el departamento}';

    protected $description = 'Sincroniza el catalogo de ciudades con IPC que publica el DANE';

    public function handle(DaneCdaIpcProvider $provider): int
    {
        $this->info('Consultando el catalogo de ciudades al DANE (qryCiudad)...');

        try {
            $geographies = $provider->fetchGeographies();
        } catch (\Throwable $exception) {
            $this->error('No se pudo consultar el catalogo al DANE: '.$exception->getMessage());
            $this->line('  Sin esta lista no se puede saber que ciudades tienen IPC, y el filtro');
            $this->line('  de ciudad se quedaria vacio o, peor, ofreceria ciudades sin dato.');

            return self::FAILURE;
        }

        if ($geographies === []) {
            $this->error('El DANE devolvio un catalogo vacio. No se toco el catalogo local.');

            return self::FAILURE;
        }

        $this->line(sprintf('  El DANE publica IPC para %d ciudades.', count($geographies)));

        $departments = $this->option('skip-divipola')
            ? []
            : $this->fetchDepartments();

        if ($departments !== []) {
            $this->applyDepartments($geographies, $departments);
        }

        $synchronizer = new IpcSynchronizer($provider);
        $stored = $synchronizer->syncGeographies($geographies);

        // El total nacional tambien es una geografia seleccionable, y no viene
        // en qryCiudad porque no es una ciudad. Se agrega aparte para que el
        // filtro tenga siempre la opcion legal.
        $this->ensureNational();

        $this->info(sprintf('Catalogo guardado: %d ciudades con IPC.', $stored));

        return self::SUCCESS;
    }

    /**
     * El catalogo DIVIPOLA de datos abiertos, indexado por codigo de municipio.
     *
     * @return array<string, string>
     */
    private function fetchDepartments(): array
    {
        $this->line('  Enriqueciendo con el departamento desde datos.gov.co (DIVIPOLA)...');

        try {
            $rows = Http::timeout(60)
                ->retry(2, 1500)
                ->get('https://www.datos.gov.co/resource/gdxc-w37w.json', ['$limit' => 5000])
                ->throw()
                ->json();
        } catch (\Throwable $exception) {
            $this->warn('  No se pudo consultar el DIVIPOLA: '.$exception->getMessage());
            $this->line('  Se guarda el catalogo sin departamento. El selector sigue funcionando.');

            return [];
        }

        $departments = [];

        foreach ((array) $rows as $row) {
            $code = Geography::normaliseCode($row['cod_mpio'] ?? null);
            $department = trim((string) ($row['dpto'] ?? ''));

            if ($code !== null && $department !== '') {
                $departments[$code] = $department;
            }
        }

        $this->line(sprintf('  DIVIPOLA: %d municipios con departamento.', count($departments)));

        return $departments;
    }

    /**
     * @param  list<Geography>  $geographies
     * @param  array<string, string>  $departments
     */
    private function applyDepartments(array &$geographies, array $departments): void
    {
        foreach ($geographies as $index => $geography) {
            $department = $departments[$geography->code] ?? null;

            if ($department !== null && $geography->department === null) {
                $geographies[$index] = new Geography(
                    code: $geography->code,
                    name: $geography->name,
                    department: $department,
                    hasIpc: $geography->hasIpc,
                    source: $geography->source,
                );
            }
        }
    }

    private function ensureNational(): void
    {
        GeographyModel::updateOrCreate(
            ['code' => Geography::nationalCode()],
            [
                'name' => (string) config('dane_ipc_sql.national_name', 'NACIONAL'),
                'department_code' => null,
                'department' => null,
                'has_ipc' => true,
                'source' => 'cda',
            ],
        );
    }
}
