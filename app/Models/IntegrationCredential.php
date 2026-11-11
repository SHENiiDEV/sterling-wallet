<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The API key of one commerce account (`key` = coma_…) of a bot account. Write-only: never sent to the browser.
 *
 * @property int $id
 * @property int $integration_account_id
 * @property string $key
 * @property string $secret
 */
#[Fillable(['integration_account_id', 'key', 'secret'])]
#[Hidden(['secret'])]
class IntegrationCredential extends Model
{
    protected function casts(): array
    {
        return ['secret' => 'encrypted'];
    }

    /**
     * @return BelongsTo<IntegrationAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }
}
