<?php

namespace App\Console\Commands;

use App\Infrastructure\Ipc\DaneCdaIpcProvider;
use App\Services\Ipc\IpcSeriesRepository;
use Illuminate\Console\Command;

/**
 * Comprueba que la copia local del IPC sigue siendo el IPC oficial.
 *
 * El CDA del DANE no tiene version ni documentacion: su esquema interno puede
 * cambiar sin aviso y la unica senal seria que las cifras dejen de cuadrar. Este
 * comando convierte esa senal en un mensaje claro.
 *
 * Compara la variacion anual de diciembre de la serie nacional contra las cifras
 * oficiales publicadas por el DANE, que estan en config/dane_ipc_sql.php:
 *
 *   2023 -> 9,28 %      2024 -> 5,20 %      2025 -> 5,10 %
 *
 * Si alguna no cuadra, el diagnostico mas probable es el filtro de la canasta.
 * Filtrar por los niveles 14 a 18 con CIP_N18 devuelve una porcion de la serie y
 * no el IPC, y en ese caso diciembre de 2024 da 10,80 % en vez de 5,20 %. Esa
 * confusion es la que este comando existe para detectar, porque el sintoma es
 * invisible en los numeros intermedios y solo aparece cuando alguien cita una
 * cifra que ya habia leido en otro sitio.
 */
class IpcDoctor extends Command
{
    protected $signature = 'ipc:doctor {--remote : Consulta tambien el DANE para comparar en vivo}';

    protected $description = 'Verifica que la copia local del IPC coincide con las cifras oficiales del DANE';

    public function handle(IpcSeriesRepository $series, DaneCdaIpcProvider $remote): int
    {
        $expected = config('dane_ipc_sql.expected', []);

        if ($expected === []) {
            $this->error('No hay cifras de control configuradas en config/dane_ipc_sql.php.');

            return self::FAILURE;
        }

        if (! $series->hasAnyData()) {
            $this->error('No hay ningun dato de IPC en la base de datos.');
            $this->line('  Ejecute: php artisan ipc:sync');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Copias locales: %d periodos del total nacional.',
            $series->countFor(\App\Dto\Geography::nationalCode()),
        ));

        $this->newLine();
        $this->table(
            ['Ano', 'Esperado', 'En la base', 'Diferencia', 'Estado'],
            $this->rows($series, $expected),
        );

        $remoteRows = [];

        if ($this->option('remote')) {
            $this->newLine();
            $this->line('Consultando el DANE en vivo...');

            try {
                $remoteRows = $this->remoteRows($remote);
            } catch (\Throwable $exception) {
                $this->error('No se pudo consultar el DANE: '.$exception->getMessage());
            }

            if ($remoteRows !== []) {
                $this->table(
                    ['Ano', 'Esperado', 'En el DANE', 'Diferencia', 'Estado'],
                    array_map(
                        fn (array $row): array => [$row[0], $row[1], $row[2], $row[3], $row[4]],
                        $remoteRows,
                    ),
                );
            }
        }

        $failed = $this->failedCount($series, $expected);

        $this->newLine();

        if ($failed === 0) {
            $this->info('La copia local coincide con las cifras oficiales del DANE.');

            if ($this->option('remote') && $remoteRows === []) {
                $this->line('  (No se pudo comparar con el origen remoto.)');
            }

            return self::SUCCESS;
        }

        $this->error(sprintf('%d ano(s) no coinciden con el IPC oficial.', $failed));
        $this->newLine();
        $this->line('Diagnostico mas probable: el filtro CIP_NIVEL_ESTRUCTURA de');
        $this->line('config/dane_ipc_sql.php.');
        $this->newLine();
        $this->line('  Correcto  : C.CIP_NIVEL_ESTRUCTURA IN (\'14\')');
        $this->line('  Incorrecto: C.CIP_NIVEL_ESTRUCTURA IN (\'14\',\'15\',\'16\',\'17\',\'18\')');
        $this->line('              con C.CIP_N18 IN (\'437\')');
        $this->newLine();
        $this->line('El segundo devuelve una porcion de la canasta, no el IPC. En diciembre');
        $this->line('de 2024 da 10,80 % cuando el IPC oficial fue 5,20 %.');

        return self::FAILURE;
    }

    /**
     * @param  array<int, float>  $expected
     * @return list<array<int, string>>
     */
    private function rows(IpcSeriesRepository $series, array $expected): array
    {
        $rows = [];

        foreach ($expected as $year => $value) {
            $observation = $series->decemberOf(\App\Dto\Geography::nationalCode(), (int) $year);
            $actual = $observation?->calendarYearVariation();

            $rows[] = [
                (string) $year,
                number_format($value, 2, ',', '.').' %',
                $actual === null ? 'sin dato' : number_format($actual, 2, ',', '.').' %',
                $actual === null ? '—' : number_format($actual - $value, 2, ',', '.'),
                $actual === null ? 'FALTA' : (abs($actual - $value) <= 0.5 ? 'OK' : 'DESCUADRE'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<int, string>>
     */
    private function remoteRows(DaneCdaIpcProvider $remote): array
    {
        $observations = $remote->fetchSeries(\App\Dto\Geography::nationalCode());
        $rows = [];

        foreach (config('dane_ipc_sql.expected', []) as $year => $value) {
            $match = collect($observations)->first(fn ($o): bool => $o->year === (int) $year);
            $actual = $match?->calendarYearVariation();

            $rows[] = [
                (string) $year,
                number_format($value, 2, ',', '.').' %',
                $actual === null ? 'sin dato' : number_format($actual, 2, ',', '.').' %',
                $actual === null ? '—' : number_format($actual - $value, 2, ',', '.'),
                $actual === null ? 'FALTA' : (abs($actual - $value) <= 0.5 ? 'OK' : 'DESCUADRE'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, float>  $expected
     */
    private function failedCount(IpcSeriesRepository $series, array $expected): int
    {
        $failed = 0;

        foreach ($expected as $year => $value) {
            $actual = $series->decemberOf(\App\Dto\Geography::nationalCode(), (int) $year)?->calendarYearVariation();

            if ($actual === null || abs($actual - $value) > 0.5) {
                $failed++;
            }
        }

        return $failed;
    }
}
