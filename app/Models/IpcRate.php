<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variacion anual del IPC publicada por el DANE.
 *
 * El reajuste del canon se calcula con el dato del ano calendario anterior al
 * en que se aplica, segun el articulo 20 de la Ley 820 de 2003.
 */
class IpcRate extends Model
{
    /** @use HasFactory<\Database\Factories\IpcRateFactory> */
    use HasFactory;

    protected $fillable = [
        'year',
        'percentage',
        'source_name',
        'source_url',
        'published_on',
    ];

    protected $casts = [
        'year' => 'integer',
        'percentage' => 'float',
        'published_on' => 'date',
    ];

    public function rentalAdjustments(): HasMany
    {
        return $this->hasMany(RentAdjustment::class);
    }

    /**
     * El IPC que corresponde para reajuste un ano dado: el del ano anterior.
     */
    public static function forAdjustmentOfYear(int $year): ?self
    {
        return self::query()->where('year', $year - 1)->first();
    }
}
