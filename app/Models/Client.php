<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One client, several legal entities: the daily report is consolidated
 * over every merchant of every company of the client.
 *
 * @property int $id
 * @property string $name
 * @property string|null $invoice_email
 * @property string|null $notes
 */
#[Fillable(['name', 'invoice_email', 'notes'])]
class Client extends Model
{
    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * @return HasManyThrough<Merchant, Company, $this>
     */
    public function merchants(): HasManyThrough
    {
        return $this->hasManyThrough(Merchant::class, Company::class);
    }
}
