<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Merchant = 'merchant';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Admin => 'Admin',
            self::Merchant => 'Merchant',
        };
    }

    public function isStaff(): bool
    {
        return $this !== self::Merchant;
    }
}
