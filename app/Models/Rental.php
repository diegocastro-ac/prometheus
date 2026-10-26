<?php

namespace App\Models;

use App\Enums\RentalType;
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
        'rental_type',
        'commercial_value',
        'catastral_appraisal',
        'anchor_monthly_amount',
        'anchor_established_on',
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
        'rental_type' => RentalType::class,
        'commercial_value' => 'float',
        'catastral_appraisal' => 'float',
        'anchor_monthly_amount' => 'float',
        'anchor_established_on' => 'date',
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

    /**
     * El canon desde el que se encadena la cadena de reajustes.
     *
     * Es el punto de partida del calculo, no el canon de hoy. En un alquiler con
     * reajustes el ancla es el canon que rigio antes del primero, de modo que
     * reprocesar la cadena devuelve las mismas cifras que ya se comunicaron.
     *
     * Si el ancla no esta fijada todavia, se usa el canon vigente y se marca
     * para que la sincronizacion la fije. Devolver null en vez de un numero
     * inventado delata el problema antes de que un reajuste salga mal calculado.
     */
    public function anchorRent(): ?float
    {
        $anchor = $this->anchor_monthly_amount ?? $this->monthly_amount;

        return $anchor > 0 ? (float) $anchor : null;
    }

    public function hasAnchor(): bool
    {
        return $this->anchor_monthly_amount !== null;
    }

    /**
     * Congela el ancla de calculo.
     *
     * Una vez fijada no se toca sola: cambiarla reprocesaria toda la cadena y
     * las cartas ya emitidas dejarian de coincidir con lo que el sistema
     * muestra. Por eso es una operacion explicita.
     */
    public function establishAnchor(?float $amount = null, ?\DateTimeInterface $on = null): bool
    {
        $amount ??= $this->monthly_amount;

        if ($amount <= 0) {
            return false;
        }

        $this->forceFill([
            'anchor_monthly_amount' => round((float) $amount, 2),
            'anchor_established_on' => $on ?? now(),
        ])->saveQuietly();

        return true;
    }

    /**
     * Los reajustes que forman la cadena, en el orden en que se aplicaron.
     *
     * El desempate por sequence importa: dos reajustes pueden compartir
     * effective_from, y sin un orden explicito el reproceso no seria
     * reproducible.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RentAdjustment>
     */
    public function adjustmentChain()
    {
        return $this->rentAdjustments()
            ->orderBy('effective_from')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * El ultimo reajuste cuyo canon quedo vigente.
     *
     * El articulo 20 cuenta los doce meses "bajo un mismo precio", asi que lo
     * que reinicia el reloj es el ultimo cambio de precio, no el inicio del
     * contrato.
     */
    public function lastAppliedAdjustment(): ?RentAdjustment
    {
        return $this->rentAdjustments()
            ->whereNotNull('applied_on')
            ->orderByDesc('effective_from')
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * El canon que rigio justo antes del primer reajuste, o el canon original.
     */
    public function firstAdjustmentDate(): ?\DateTimeInterface
    {
        return $this->rentAdjustments()
            ->orderBy('effective_from')
            ->orderBy('sequence')
            ->orderBy('id')
            ->value('effective_from');
    }

    /**
     * La fecha en que este alquiler cumple los doce meses bajo el mismo precio.
     *
     * Con el articulo 20, el plazo corre desde el ultimo cambio de canon, no
     * desde el arranque del contrato. Si nunca se reajusto, corre desde el
     * inicio.
     */
    public function nextAdjustmentDueDate(): \DateTimeInterface
    {
        $last = $this->lastAppliedAdjustment();

        return ($last?->effective_from ?? $this->start_date)
            ->copy()
            ->addYear();
    }

    /**
     * La fecha que el formulario propone.
     *
     * Es el aniversario, o hoy si ese aniversario ya paso. Proponer una fecha en
     * el pasado no es solo incomoda: el formulario la rechazaria por su propia
     * validacion y el usuario veria un error sin entender de donde salio la
     * fecha que el sistema mismo le puso.
     *
     * Ojo con lo que esto NO hace: no reinicia el computo. El plazo legal sigue
     * corriendo desde el ultimo cambio de canon aunque la propuesta sea hoy, y
     * por eso la comprobacion de los doce meses avisa cuando se aplica antes.
     */
    public function suggestedAdjustmentDate(): \DateTimeInterface
    {
        return max($this->nextAdjustmentDueDate()->startOfDay(), today());
    }

    public function isDueForAdjustment(): bool
    {
        return $this->nextAdjustmentDueDate()->startOfDay()->lessThanOrEqualTo(today());
    }

    /**
     * El limite del articulo 18: el canon no puede pasar del 1 % del valor
     * comercial.
     *
     * Devuelve null cuando no se declaro valor comercial, porque en ese caso no
     * hay limite que aplicar. Un limite inventado seria peor que ninguno.
     */
    public function monthlyCapFromArticle18(): ?float
    {
        if ($this->commercial_value === null || $this->commercial_value <= 0) {
            return null;
        }

        return round($this->commercial_value * 0.01);
    }

    /**
     * La segunda mitad del articulo 18: la estimacion comercial no puede
     * exceder dos veces el avaluo catastral.
     *
     * Esta regla acota a la anterior, y es la que mas se olvida: sin ella, el
     * 1 % se calcula sobre un valor comercial que nadie acota.
     */
    public function commercialValueExceedsCatastralCeiling(): bool
    {
        if ($this->commercial_value === null || $this->catastral_appraisal === null) {
            return false;
        }

        return $this->commercial_value > ($this->catastral_appraisal * 2);
    }

    /**
     * El valor comercial mas alto que el articulo 18 permite declarar.
     */
    public function commercialValueCeiling(): ?float
    {
        if ($this->catastral_appraisal === null || $this->catastral_appraisal <= 0) {
            return null;
        }

        return round($this->catastral_appraisal * 2);
    }

    /**
     * Solo la vivienda urbana esta sometida a la Ley 820.
     */
    public function isSubjectToLey820(): bool
    {
        return ($this->rental_type ?? RentalType::default())->isSubjectToLey820();
    }
}
