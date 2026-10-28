<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $authenticator_code_id
 * @property string $target_type
 * @property int $target_id
 */
#[Fillable(['authenticator_code_id', 'target_type', 'target_id'])]
class AuthenticatorCodeLink extends Model
{
    /**
     * @return BelongsTo<AuthenticatorCode, $this>
     */
    public function code(): BelongsTo
    {
        return $this->belongsTo(AuthenticatorCode::class, 'authenticator_code_id');
    }
}
