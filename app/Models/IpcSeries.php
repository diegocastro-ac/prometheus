<?php

namespace App\Models;

use App\Dto\IpcObservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un dato del IPC, tal como lo publica el DANE.
 *
 * La tabla es una copia local del CDA. Existe porque el servicio del DANE no
 * tiene garantia de estabilidad ni de disponibilidad: se comprobó que devuelve
 * 502 de forma intermitente. Calcular un reajuste contra el origen en tiempo real
 * significaria que un fallo de red del DANE impide Adjustir un canon, y que una
 * correccion del DANE podria cambiar el resultado de una carta ya emitida.
 *
 * Por eso el flujo es de una sola via: se sincroniza, se guarda, y todo calculo
 * posterior lee de aqui.
 */
class IpcSeries extends Model
{
    use HasFactory;

    protected $fillable = [
        'geography_code',
        'geography_name',
        'year',
        'month',
        'index_value',
        'variation_monthly',
        'variation_yoy',
        'variation_year',
        'source_name',
        'source_url',
        'synced_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'index_value' => 'float',
        'variation_monthly' => 'float',
        'variation_yoy' => 'float',
        'variation_year' => 'float',
        'synced_at' => 'datetime',
    ];

    /**
     * La serie de una geografia en un rango de anos.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForGeography(Builder $query, string $geographyCode): Builder
    {
        return $query->where('geography_code', $geographyCode);
    }

    /**
     * Un dato concreto de un periodo.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Solo los años que tienen dato de diciembre.
     *
     * Es el filtro que usan los selectores. El articulo 20 toma la variacion del
     * ano calendario, que es la de diciembre: un año sin ese dato no sirve para
     * reajustar aunque tenga once meses guardados, y ofrecerlo en el selector
     * llevaria a elegir un año que despues no tiene IPC que aplicar.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithDecember(Builder $query): Builder
    {
        return $query->where('month', 12);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeNational(Builder $query): Builder
    {
        return $query->where('geography_code', \App\Dto\Geography::nationalCode());
    }

    public function isNational(): bool
    {
        return $this->geography_code === \App\Dto\Geography::nationalCode();
    }

    /**
     * La variacion del ano calendario, que es la que aplica el articulo 20.
     */
    public function calendarYearVariation(): ?float
    {
        return $this->variation_year ?? $this->variation_yoy;
    }

    public function periodKey(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function toObservation(): IpcObservation
    {
        return new IpcObservation(
            geographyCode: $this->geography_code,
            geographyName: $this->geography_name,
            year: $this->year,
            month: $this->month,
            index: (float) $this->index_value,
            variationMonthly: $this->variation_monthly === null ? null : (float) $this->variation_monthly,
            variationYoY: $this->variation_yoy === null ? null : (float) $this->variation_yoy,
            variationYear: $this->variation_year === null ? null : (float) $this->variation_year,
        );
    }
}
