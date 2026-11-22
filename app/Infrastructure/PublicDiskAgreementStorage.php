<?php

namespace App\Infrastructure;

use App\Contracts\AgreementStorageInterface;
use Illuminate\Support\Facades\Storage;

class PublicDiskAgreementStorage implements AgreementStorageInterface
{
    public function persist(string $tempPath, int $userId, int $rentalId): string
    {
        $newPath = "users/{$userId}/rentals/{$rentalId}/agreement/" . basename($tempPath);
        Storage::disk('public')->move($tempPath, $newPath);

        return $newPath;
    }

    public function remove(string $path): void
    {
        Storage::disk('public')->delete($path);
    }

    public function removeAllFor(int $userId, int $rentalId): void
    {
        Storage::disk('public')->deleteDirectory("users/{$userId}/rentals/{$rentalId}");
    }
}