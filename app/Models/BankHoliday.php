<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property string $name
 */
#[Fillable(['date', 'name'])]
class BankHoliday extends Model
{
    protected function casts(): array
    {
        return ['date' => DateOnly::class];
    }
}
