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

        $paidCount = count(array_filter($flags, fn($flag) => $flag === true));

        if ($paidCount === count($flags)) {
            return PaymentStatus::PAID;
        }

        if ($paidCount > 0) {
            return PaymentStatus::PARTIAL;
        }

        if ($this->date->isPast()) {
            return PaymentStatus::OVERDUE;
        }

        return PaymentStatus::PENDING;
    }

    /**
     * Check if the payment is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->status() === PaymentStatus::PAID;
    }

    /**
     * Check if the payment is partially paid.
     */
    public function isPartial(): bool
    {
        return $this->status() === PaymentStatus::PARTIAL;
    }

    /**
     * Check if the payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status() === PaymentStatus::PENDING;
    }

    /**
     * Check if the payment is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->status() === PaymentStatus::OVERDUE;
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
