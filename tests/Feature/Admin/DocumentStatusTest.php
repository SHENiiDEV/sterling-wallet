<?php

namespace Tests\Feature\Admin;

use App\Models\Document;
use App\Models\DocumentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_status()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.document-statuses.store'), [
                'name' => 'Legal review',
                'color' => 'cyan',
                'is_default' => false,
                'is_final' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('document_statuses', ['name' => 'Legal review', 'color' => 'cyan']);
    }

    public function test_only_one_status_is_default()
    {
        $old = DocumentStatus::factory()->asDefault()->create();
        $new = DocumentStatus::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('admin.document-statuses.update', $new), [
                'name' => $new->name,
                'color' => $new->color,
                'is_default' => true,
                'is_final' => false,
            ]);

        $this->assertFalse($old->refresh()->is_default);
        $this->assertTrue($new->refresh()->is_default);
    }

    public function test_status_in_use_cannot_be_deleted()
    {
        $status = DocumentStatus::factory()->create();
        Document::factory()->create(['document_status_id' => $status->id]);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.document-statuses.destroy', $status))
            ->assertSessionHasErrors('status');

        $this->assertModelExists($status);
    }

    public function test_unused_status_can_be_deleted()
    {
        $status = DocumentStatus::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.document-statuses.destroy', $status))
            ->assertRedirect();

        $this->assertModelMissing($status);
    }

    public function test_invalid_colour_is_rejected()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.document-statuses.store'), ['name' => 'X', 'color' => 'pink'])
            ->assertSessionHasErrors('color');
    }
}
