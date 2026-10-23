<?php

namespace App\Support;

use App\Models\User;

class Registration
{
    /**
     * Self-registration only bootstraps the first account: companies have no
     * owner, so every later user would see all data. Further users must be
     * created by an existing one.
     */
    public static function isOpen(): bool
    {
        return ! User::query()->exists();
    }
}
