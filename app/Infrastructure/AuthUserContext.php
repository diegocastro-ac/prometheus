<?php

namespace App\Infrastructure;

use App\Contracts\CurrentUserContextInterface;
use Illuminate\Support\Facades\Auth;

class AuthUserContext implements CurrentUserContextInterface
{
    public function id(): int|string|null
    {
        return Auth::id();
    }
}