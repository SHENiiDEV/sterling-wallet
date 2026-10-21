<?php

namespace App\Support;

use App\Enums\TariffProduct;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The product the signed-in staff member is restricted to (a C2B partner
 * sees C2B only), or null for unrestricted staff, merchants, jobs and commands.
 */
final class ProductScope
{
    public static function current(): ?TariffProduct
    {
        $user = Auth::user();

        return $user instanceof User ? $user->productScope() : null;
    }
}
