<?php

namespace App\Services;

use App\Contracts\AgreementStorageInterface;

class AgreementStorageService
{
    private AgreementStorageInterface $storage;

    public function __construct(AgreementStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Persist agreement file from temp path to final location.
     */
    public function persist(string $tempPath, int $userId, int $rentalId): string
    {
        return $this->storage->persist($tempPath, $userId, $rentalId);
    }

    /**
     * Remove agreement file.
     */
    public function remove(string $path): void
    {
        $this->storage->remove($path);
    }

    /**
     * Remove all files for a rental.
     */
    public function removeAllFor(int $userId, int $rentalId): void
    {
        $this->storage->removeAllFor($userId, $rentalId);
    }
}