<?php

namespace App\Models;

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
        'rent_voucher_path',
        'is_water_paid',
        'water_voucher_path',
        'is_energy_paid',
        'energy_voucher_path',
        'is_gas_paid',
        'gas_voucher_path',
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
