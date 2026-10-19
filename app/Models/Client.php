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
 * @property string|null $invoice_name
 * @property string|null $invoice_address
 * @property string|null $invoice_email
 * @property string|null $notes
 */
#[Fillable(['name', 'invoice_name', 'invoice_address', 'invoice_email', 'notes'])]
class Client extends Model
{
    /**
     * The legal name printed on reports and e-mails; `name` is for the admin only.
     */
    public function invoiceName(): string
    {
        return filled($this->invoice_name) ? (string) $this->invoice_name : $this->name;
    }

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
