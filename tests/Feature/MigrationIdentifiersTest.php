<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests run on SQLite, production on MySQL, which rejects identifiers longer
 * than 64 characters. Keep every index and foreign key name within that.
 */
class MigrationIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_foreign_key_names_fit_mysql()
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $names = [
                ...array_column(Schema::getIndexes($table), 'name'),
                // SQLite doesn't name foreign keys; build the name Laravel gives them on MySQL.
                ...array_map(fn (array $fk) => $fk['name'] ?? $table.'_'.implode('_', $fk['columns']).'_foreign', Schema::getForeignKeys($table)),
            ];

            foreach ($names as $name) {
                if (strlen((string) $name) > 64) {
                    $tooLong[] = "{$table}: {$name}";
                }
            }
        }

        $this->assertSame([], $tooLong);
    }
}
