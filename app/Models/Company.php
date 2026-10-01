<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $registration_number
 * @property string|null $country
 * @property string|null $billing_details
 * @property string|null $notes
 */
#[Fillable(['parent_id', 'name', 'registration_number', 'country', 'billing_details', 'notes'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_id');
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_id');
    }

    /**
     * @return HasMany<Merchant, $this>
     */
    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * This company and all of its descendants — never siblings or parents.
     *
     * @return list<int>
     */
    public function descendantIdsWithSelf(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = Company::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * This company and its parents up to the top, nearest first.
     *
     * @return list<Company>
     */
    public function ancestorsWithSelf(): array
    {
        $chain = [$this];
        $seen = [$this->id];
        $current = $this;

        while ($current->parent_id !== null && ! in_array($current->parent_id, $seen, true)) {
            $current = Company::query()->find($current->parent_id);
            if ($current === null) {
                break;
            }
            $chain[] = $current;
            $seen[] = $current->id;
        }

        return $chain;
    }
}
