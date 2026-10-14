<?php

namespace App\Services\Ipc;

use App\Dto\Geography;
use App\Models\IpcSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Acceso a la copia local del IPC (patron Repository).
 *
 * Toda lectura de datos del IPC pasa por aqui. La razon es que el calculo del
 * reajuste no debe saber de donde viene el dato: si lee del modelo directamente,
 * un test necesita sembrar la tabla y una pantalla necesita conocer el esquema.
 *
 * Lo mas importante que expone son los dos listados que alimentan los filtros
 * dinamicos. Ninguno de los dos esta escrito en el codigo: salen de lo que hay
 * sincronizado, de modo que aparecen las ciudades que el DANE publica y los
 * anos que tienen dato de diciembre, sin mantenimiento.
 */
class IpcSeriesRepository
{
    /**
     * El dato de un periodo exacto.
     */
    public function find(string $geographyCode, int $year, int $month): ?IpcSeries
    {
        return IpcSeries::query()
            ->forGeography($geographyCode)
            ->forPeriod($year, $month)
            ->first();
    }

    /**
     * La serie completa de una geografia.
     *
     * @return Collection<int, IpcSeries>
     */
    public function series(string $geographyCode, ?int $fromYear = null, ?int $toYear = null): Collection
    {
        return IpcSeries::query()
            ->forGeography($geographyCode)
            ->when($fromYear !== null, fn ($query) => $query->where('year', '>=', $fromYear))
            ->when($toYear !== null, fn ($query) => $query->where('year', '<=', $toYear))
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * El dato de diciembre de un ano, que es el que aplica el articulo 20.
     */
    public function decemberOf(string $geographyCode, int $year): ?IpcSeries
    {
        return $this->find($geographyCode, $year, 12);
    }

    /**
     * El IPC legal para reajustar en un ano dado.
     *
     * El articulo 20 toma "el incremento que haya tenido el indice de precios
     * al consumidor en el ano calendario inmediatamente anterior". Para un
     * reajuste que rige en N, ese ano es N - 1, y el dato que lo representa es
     * el de diciembre.
     */
    public function statutoryForAdjustmentYear(string $geographyCode, int $adjustmentYear): ?IpcSeries
    {
        return $this->decemberOf($geographyCode, $adjustmentYear - 1);
    }

    /**
     * El ultimo dato de diciembre disponible, que es el IPC mas reciente que se
     * puede aplicar a un reajuste de este ano.
     *
     * Se limita a los anos ya cerrados a proposito. El dato de diciembre del ano
     * en curso solo existe si ya se publico, y usarlo para un reajuste del mismo
     * ano seria aplicar el IPC de un ano que todavia no termina.
     */
    public function latestDecember(?string $geographyCode = null): ?IpcSeries
    {
        $currentYear = (int) now()->year;

        return IpcSeries::query()
            ->withDecember()
            ->when($geographyCode !== null, fn ($query) => $query->forGeography($geographyCode))
            ->where('year', '<=', $currentYear - 1)
            ->orderByDesc('year')
            ->first();
    }

    /**
     * Los anos que sirven para reajustar, del mas reciente al mas antiguo.
     *
     * Solo los que tienen dato de diciembre y solo los ya cerrados. Devolver
     * cualquier otro ano llevaria al usuario a elegir un periodo sin IPC y a
     * descubrirlo al intentar calcular.
     *
     * @return list<int>
     */
    public function availableYears(?string $geographyCode = null): array
    {
        return IpcSeries::query()
            ->withDecember()
            ->when($geographyCode !== null, fn ($query) => $query->forGeography($geographyCode))
            ->where('year', '<=', (int) now()->year - 1)
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    /**
     * Las opciones del selector de ano, con la etiqueta del porcentaje.
     *
     * Mostrar el IPC al lado del ano evita el error tipico de leer "2024" y no
     * saber que el que se aplico es el de 2023.
     *
     * @return array<int, string>
     */
    public function yearOptions(?string $geographyCode = null): array
    {
        $code = $geographyCode ?? Geography::nationalCode();

        $rows = IpcSeries::query()
            ->withDecember()
            ->forGeography($code)
            ->where('year', '<=', (int) now()->year - 1)
            ->orderByDesc('year')
            ->get(['year', 'variation_year', 'variation_yoy']);

        return $rows
            ->filter(fn (IpcSeries $row): bool => $row->calendarYearVariation() !== null)
            ->mapWithKeys(fn (IpcSeries $row): array => [
                $row->year => sprintf('%d — %s %%', $row->year, number_format(
                    (float) $row->calendarYearVariation(),
                    2,
                    ',',
                    '.',
                )),
            ])
            ->all();
    }

    /**
     * La ultima fecha en que se sincronizo esta geografia.
     *
     * max() devuelve el VALOR CRUDO de la columna, no un modelo, asi que sale
     * como string. Sin castearlo, quien lo use recibe una fecha que no se puede
     * formatear ni comparar.
     */
    public function lastSyncedAt(string $geographyCode): ?\DateTimeInterface
    {
        $raw = IpcSeries::query()
            ->forGeography($geographyCode)
            ->max('synced_at');

        return $raw === null ? null : Carbon::parse($raw);
    }

    /**
     * Cuantos periodos hay guardados de una geografia.
     */
    public function countFor(string $geographyCode): int
    {
        return IpcSeries::query()->forGeography($geographyCode)->count();
    }

    public function hasAnyData(): bool
    {
        return IpcSeries::query()->exists();
    }

    /**
     * Las geografias que tienen al menos un dato guardado.
     *
     * @return list<string>
     */
    public function geographiesWithData(): array
    {
        return IpcSeries::query()
            ->distinct()
            ->orderBy('geography_code')
            ->pluck('geography_code')
            ->all();
    }
}
