<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class AgreementStorageService
{
    /**
     * Persist agreement file from temp path to final location.
     */
    public function persist(string $tempPath, int $userId, int $rentalId): string
    {
        $newPath = "users/{$userId}/rentals/{$rentalId}/agreement/" . basename($tempPath);
        Storage::disk('public')->move($tempPath, $newPath);

        return $newPath;
    }

    /**
     * Remove agreement file.
     */
    public function remove(string $path): void
    {
        Storage::disk('public')->delete($path);
    }

    /**
     * Remove all files for a rental.
     */
    public function removeAllFor(int $userId, int $rentalId): void
    {
        Storage::disk('public')->deleteDirectory("users/{$userId}/rentals/{$rentalId}");
    }
}