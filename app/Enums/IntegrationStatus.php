<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IntegrationStatus: string
{
    use HasOptions;

    case NeedToDo = 'need_to_do';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::NeedToDo => 'Need to do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
        };
    }
}
