<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueActiveRentalRule implements ValidationRule
{
    private string $field;
    private ?int $ignoreId;

    public function __construct(string $field, ?int $ignoreId = null)
    {
        $this->field = $field;
        $this->ignoreId = $ignoreId;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = DB::table('rentals')
            ->where($this->field, $value)
            ->where('is_active', true)
            ->when($this->ignoreId, fn($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail("The selected {$this->field} is already in an active rental.");
        }
    }
}