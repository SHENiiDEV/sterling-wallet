<?php

namespace Tests\Feature\Admin;

use App\Enums\DocumentActivityType;
use App\Models\Document;
use App\Models\DocumentStatus;
use App\Models\User;
use Database\Seeders\DocumentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentStatusSeeder::class);
        $this->admin = User::factory()->create();
        Storage::fake('local');
    }

    public function test_index_lists_documents_with_status_counts()
    {
        Document::factory()->count(3)->create(['document_status_id' => $this->statusNamed('Signing')->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.documents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/documents/index')
                ->has('documents.data', 3)
                ->where('totals.all', 3)
                ->where('statuses', fn ($statuses) => collect($statuses)->firstWhere('name', 'Signing')['documents_count'] === 3));
    }

    public function test_index_filters_by_status_and_search()
    {
        Document::factory()->create(['title' => 'Acme MSA', 'document_status_id' => $this->statusNamed('Signing')->id]);
        Document::factory()->create(['title' => 'Globex MSA', 'document_status_id' => $this->statusNamed('Draft')->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.documents.index', ['status' => $this->statusNamed('Signing')->id, 'search' => 'MSA']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Acme MSA'));
    }

    public function test_document_is_created_in_default_status_with_files()
    {
        $response = $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'title' => 'Merchant agreement',
            'type' => 'contract',
            'counterparty' => 'Acme Ltd',
            'files' => [UploadedFile::fake()->create('agreement.pdf', 120, 'application/pdf')],
        ]);

        $document = Document::query()->sole();
        $response->assertRedirect(route('admin.documents.show', $document));

        $this->assertSame('Draft', $document->status->name);
        $this->assertSame($this->admin->id, $document->created_by);
        $this->assertCount(1, $document->files);
        Storage::disk('local')->assertExists($document->files->first()->path);
        $this->assertTrue($document->activities()->where('type', DocumentActivityType::Created)->exists());
    }

    public function test_status_change_is_logged_with_comment()
    {
        $document = Document::factory()->create(['document_status_id' => $this->statusNamed('WIP - work in progress')->id]);
        $target = $this->statusNamed('Passed to merchant');

        $this->actingAs($this->admin)
            ->put(route('admin.documents.status', $document), [
                'document_status_id' => $target->id,
                'comment' => 'Sent by email',
            ])
            ->assertRedirect();

        $this->assertSame($target->id, $document->refresh()->document_status_id);

        $activity = $document->activities()->where('type', DocumentActivityType::StatusChanged)->sole();
        $this->assertSame($target->id, $activity->to_status_id);
        $this->assertSame('Sent by email', $activity->comment);
    }

    public function test_update_cannot_change_status_directly()
    {
        $document = Document::factory()->create(['document_status_id' => $this->statusNamed('Draft')->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.documents.update', $document), [
                'title' => 'Renamed',
                'type' => 'contract',
                'document_status_id' => $this->statusNamed('Signed')->id,
            ])
            ->assertSessionHasErrors('document_status_id');
    }

    public function test_overdue_ignores_final_statuses()
    {
        Document::factory()->create(['due_date' => now()->subDays(3), 'document_status_id' => $this->statusNamed('Signing')->id]);
        Document::factory()->create(['due_date' => now()->subDays(3), 'document_status_id' => $this->statusNamed('Signed')->id]);
        Document::factory()->create(['due_date' => now()->addDays(3), 'document_status_id' => $this->statusNamed('Signing')->id]);

        $this->assertSame(1, Document::query()->overdue()->count());
    }

    public function test_files_can_be_downloaded_and_removed()
    {
        $document = Document::factory()->create(['document_status_id' => $this->statusNamed('Draft')->id]);

        $this->actingAs($this->admin)->post(route('admin.documents.files.store', $document), [
            'files' => [UploadedFile::fake()->create('kyb.zip', 50, 'application/zip')],
        ]);
        $file = $document->files()->sole();

        $this->get(route('admin.documents.files.show', [$document, $file]))
            ->assertOk()
            ->assertDownload('kyb.zip');

        $this->delete(route('admin.documents.files.destroy', [$document, $file]))->assertRedirect();
        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame(0, $document->files()->count());
    }

    public function test_files_are_scoped_to_their_document()
    {
        $document = Document::factory()->create(['document_status_id' => $this->statusNamed('Draft')->id]);
        $other = Document::factory()->create(['document_status_id' => $this->statusNamed('Draft')->id]);
        $file = $other->files()->create(['path' => 'documents/x.pdf', 'original_name' => 'x.pdf', 'size' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.documents.files.show', [$document, $file]))
            ->assertNotFound();
    }

    public function test_rejects_disallowed_file_types()
    {
        $this->actingAs($this->admin)->post(route('admin.documents.store'), [
            'title' => 'Bad upload',
            'type' => 'other',
            'files' => [UploadedFile::fake()->create('run.php', 1, 'application/x-php')],
        ])->assertSessionHasErrors('files.0');
    }

    public function test_merchants_cannot_access_documents()
    {
        $this->actingAs(User::factory()->merchant()->create())
            ->get(route('admin.documents.index'))
            ->assertForbidden();
    }

    private function statusNamed(string $name): DocumentStatus
    {
        return DocumentStatus::query()->where('name', $name)->sole();
    }
}
