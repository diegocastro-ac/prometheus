<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'amount' => 'float',
    ];

    protected static function booted()
    {
        static::created(function (Rental $rental) {
            if ($rental->agreement_path && str_starts_with($rental->agreement_path, 'temp/uploads')) {
                $newPath = "users/{$rental->user_id}/rentals/{$rental->id}/agreement/" . basename($rental->agreement_path);
                Storage::disk('public')->move($rental->agreement_path, $newPath);
                $rental->updateQuietly(['agreement_path' => $newPath]);
            }
        });

        static::updating(function (Rental $rental) {
            $original = $rental->getOriginal('agreement_path');
            $current = $rental->agreement_path;

            if ($original && $original !== $current) {
                Storage::disk('public')->delete($original);
            }
        });

        static::deleted(function (Rental $rental) {
            Storage::disk('public')
                ->deleteDirectory("users/{$rental->user_id}/rentals/{$rental->id}");
        });
    }

    // Necessary?
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
    // payments()
}
