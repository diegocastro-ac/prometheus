<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'amount',
        'is_rent_paid',
        'is_water_paid',
        'is_energy_paid',
        'is_gas_paid',
        'rental_id',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
        'is_rent_paid' => 'boolean',
        'is_water_paid' => 'boolean',
        'is_energy_paid' => 'boolean',
        'is_gas_paid' => 'boolean',
    ];

    /**
     * Get the payment status derived from the individual payment flags.
     */
    public function status(): PaymentStatus
    {
        $flags = [
            $this->is_rent_paid,
            $this->is_water_paid,
            $this->is_energy_paid,
            $this->is_gas_paid,
        ];

        $paidCount = count(array_filter($flags, fn($flag) => (bool) $flag));

        if ($paidCount === count($flags)) {
            return PaymentStatus::PAID;
        }

        // La mora se evalua antes que el pago parcial. Si un pago esta a medias
        // y su fecha ya paso, sigue debiendo dinero, por lo que lo que
        // corresponde reportar es OVERDUE y no PARTIAL.
        // La comparacion es por dia completo: un pago que vence hoy no esta
        // vencido aunque la fecha se guarde a las 00:00.
        $isPastDue = $this->date->startOfDay()->isBefore(today()->startOfDay());

        if ($isPastDue) {
            return PaymentStatus::OVERDUE;
        }

        if ($paidCount > 0) {
            return PaymentStatus::PARTIAL;
        }

        return PaymentStatus::PENDING;
    }

    /**
     * Get the user that owns the rental.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the rental associated with the payment.
     */
    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }
}
