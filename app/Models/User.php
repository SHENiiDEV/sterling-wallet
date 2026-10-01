<?php

namespace App\Models;

use App\Enums\Module;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property list<string>|null $permissions
 * @property int|null $company_id
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'is_active', 'company_id', 'created_by'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'permissions' => 'array',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isStaff(): bool
    {
        return $this->is_active && $this->role->isStaff();
    }

    /**
     * A merchant portal user: active and linked to a company.
     */
    public function isMerchantUser(): bool
    {
        return $this->is_active && $this->role === UserRole::Merchant && $this->company_id !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->role === UserRole::SuperAdmin;
    }

    /**
     * Super admins are defined by role alone; admins get exactly the modules
     * listed on them, so an empty list means no access (issue #2).
     */
    public function canAccess(Module $module): bool
    {
        if (! $this->isStaff()) {
            return false;
        }

        return $this->role === UserRole::SuperAdmin || in_array($module->value, $this->permissions ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function accessibleModules(): array
    {
        return array_values(array_map(
            fn (Module $m) => $m->value,
            array_filter(Module::cases(), fn (Module $m) => $this->canAccess($m)),
        ));
    }
}
