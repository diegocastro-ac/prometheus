<?php

namespace App\Contracts;

interface CurrentUserContextInterface
{
    public function id(): int|string|null;
}