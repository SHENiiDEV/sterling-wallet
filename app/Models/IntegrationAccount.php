<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $provider_id
 * @property string $connector
 * @property string $name
 * @property string|null $login_url
 * @property string|null $username
 * @property string|null $password
 * @property string|null $totp_secret
 * @property array<string, mixed>|null $settings
 * @property list<int>|null $mid_ids
 * @property bool $is_active
 * @property-read Provider $provider
 */
#[Fillable(['provider_id', 'connector', 'name', 'login_url', 'username', 'password', 'totp_secret', 'settings', 'mid_ids', 'is_active'])]
#[Hidden(['username', 'password', 'totp_secret'])]
class IntegrationAccount extends Model
{
    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'totp_secret' => 'encrypted',
            'settings' => 'array',
            'mid_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return HasMany<BotRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BotRun::class);
    }

    /**
     * MIDs this account can fetch reports for.
     *
     * @return Builder<MerchantMid>
     */
    public function coveredMids(): Builder
    {
        return $this->provider->servedMids()
            ->when($this->mid_ids, fn (Builder $q, array $ids) => $q->whereIn('id', $ids));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
