<?php

namespace App\Contracts;

interface AgreementStorageInterface
{
    public function persist(string $tempPath, int $userId, int $rentalId): string;
    public function remove(string $path): void;
    public function removeAllFor(int $userId, int $rentalId): void;
}