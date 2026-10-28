<?php

namespace App\Models;

use App\Support\Totp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $service
 * @property string $secret
 * @property int $digits
 * @property int $period
 * @property string $algorithm
 * @property string|null $notes
 * @property bool $is_active
 */
#[Fillable(['name', 'service', 'secret', 'digits', 'period', 'algorithm', 'notes', 'is_active'])]
#[Hidden(['secret'])]
class AuthenticatorCode extends Model
{
    /** Link target types, as stored in `authenticator_code_links.target_type`. */
    public const TARGETS = [
        'client' => Client::class,
        'company' => Company::class,
        'merchant' => Merchant::class,
        'provider' => Provider::class,
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'digits' => 'integer',
            'period' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The code valid right now and the seconds it has left.
     *
     * @return array{code: string, remaining: int, period: int}
     */
    public function current(): array
    {
        return [
            'code' => Totp::code($this->secret, $this->digits, $this->period, $this->algorithm),
            'remaining' => Totp::remaining($this->period),
            'period' => $this->period,
        ];
    }

    /**
     * @return HasMany<AuthenticatorCodeLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(AuthenticatorCodeLink::class);
    }
}
