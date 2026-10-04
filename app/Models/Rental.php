<?php

namespace App\Models;

use App\Services\AgreementStorageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Rental extends Model
{
    /** @use HasFactory<\Database\Factories\RentalFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'total_months',
        'total_persons',
        'monthly_amount',
        'agreement_path',
        'is_active',
        'billing_cadence',
        'user_id',
        'tenant_id',
        'property_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'monthly_amount' => 'float',
        'billing_cadence' => \App\Enums\BillingCadence::class,
    ];

    protected static function booted()
    {
        static::created(function (Rental $rental) {
            if ($rental->agreement_path && str_starts_with($rental->agreement_path, 'temp/uploads')) {
                $storageService = app(AgreementStorageService::class);
                $newPath = $storageService->persist($rental->agreement_path, $rental->user_id, $rental->id);
                $rental->updateQuietly(['agreement_path' => $newPath]);
            }
        });

        static::updating(function (Rental $rental) {
            $original = $rental->getOriginal('agreement_path');
            $current = $rental->agreement_path;

            if ($original && $original !== $current) {
                $storageService = app(AgreementStorageService::class);
                $storageService->remove($original);
            }
        });

        static::deleted(function (Rental $rental) {
            $storageService = app(AgreementStorageService::class);
            $storageService->removeAllFor($rental->user_id, $rental->id);
        });
    }

    public function getAgreementUrlAttribute(): string
    {
        return Storage::url($this->agreement_path);
    }

    /**
     * Get the user that owns the rental.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tenant associated with the rental.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the property associated with the rental.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Get all payments associated with the rental.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Reajustes del canon propuestos para este alquiler.
     */
    public function rentAdjustments(): HasMany
    {
        return $this->hasMany(RentAdjustment::class);
    }

    /**
     * Facturas del alquiler en un periodo concreto (YYYY-MM).
     *
     * El estado de cuenta se arma con este metodo, que es el unico lugar donde
     * se decide que facturas pertenecen a un periodo.
     *
     * El filtro por user_id parece redundante porque el alquiler ya tiene
     * dueno, pero hace explicita la invariante: una factura es de un
     * arrendador y de su alquiler. Sin esta condicion, una fila con datos
     * inconsistentes se colaria en el estado de cuenta de otro.
     *
     * @return HasMany<Invoice>
     */
    public function invoicesForPeriod(string $period): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->where('user_id', $this->user_id)
            ->where('period', $period)
            ->orderBy('number');
    }
}
