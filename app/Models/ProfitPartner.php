<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property bool $is_active
 * @property string|null $notes
 */
#[Fillable(['name', 'email', 'is_active', 'notes'])]
class ProfitPartner extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ProfitShareRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ProfitShareRule::class);
    }
}
