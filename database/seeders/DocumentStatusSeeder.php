<?php

namespace Database\Seeders;

use App\Models\DocumentStatus;
use Illuminate\Database\Seeder;

class DocumentStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'Draft', 'color' => 'slate', 'is_default' => true],
            ['name' => 'WIP - work in progress', 'color' => 'blue'],
            ['name' => 'Passed to merchant', 'color' => 'amber'],
            ['name' => 'Signing', 'color' => 'violet'],
            ['name' => 'Signed', 'color' => 'emerald', 'is_final' => true],
            ['name' => 'Cancelled', 'color' => 'rose', 'is_final' => true],
        ];

        foreach ($statuses as $order => $status) {
            DocumentStatus::query()->firstOrCreate(
                ['name' => $status['name']],
                [...$status, 'sort_order' => ($order + 1) * 10],
            );
        }
    }
}
