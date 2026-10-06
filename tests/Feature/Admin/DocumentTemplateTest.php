<?php

namespace Tests\Feature\Admin;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->create();
    }

    public function test_template_is_uploaded_listed_and_downloaded()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.document-templates.store'), [
                'name' => 'Merchant agreement',
                'description' => 'Standard MSA',
                'file' => UploadedFile::fake()->create('msa.docx', 50),
            ])
            ->assertSessionHasNoErrors();

        $template = DocumentTemplate::query()->sole();
        Storage::disk('local')->assertExists($template->path);
        $this->assertSame('msa.docx', $template->original_name);

        $this->actingAs($this->admin)->get(route('admin.document-templates.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/document-templates/index')->has('templates', 1));

        $this->actingAs($this->admin)->get(route('admin.document-templates.download', $template))
            ->assertOk()
            ->assertDownload('msa.docx');
    }

    public function test_file_is_required_and_type_is_checked()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.document-templates.store'), ['name' => 'No file'])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->admin)
            ->post(route('admin.document-templates.store'), [
                'name' => 'Script',
                'file' => UploadedFile::fake()->create('run.php', 1),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_replacing_the_file_removes_the_old_one()
    {
        $this->actingAs($this->admin)->post(route('admin.document-templates.store'), [
            'name' => 'NDA',
            'file' => UploadedFile::fake()->create('nda-v1.pdf', 10),
        ]);
        $template = DocumentTemplate::query()->sole();
        $old = $template->path;

        $this->actingAs($this->admin)->put(route('admin.document-templates.update', $template), [
            'name' => 'NDA v2',
            'file' => UploadedFile::fake()->create('nda-v2.pdf', 10),
        ])->assertSessionHasNoErrors();

        $template->refresh();
        $this->assertSame('NDA v2', $template->name);
        $this->assertSame('nda-v2.pdf', $template->original_name);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($template->path);
    }

    public function test_template_can_be_deleted()
    {
        $this->actingAs($this->admin)->post(route('admin.document-templates.store'), [
            'name' => 'Old',
            'file' => UploadedFile::fake()->create('old.pdf', 10),
        ]);
        $template = DocumentTemplate::query()->sole();

        $this->actingAs($this->admin)->delete(route('admin.document-templates.destroy', $template))->assertRedirect();

        $this->assertDatabaseCount('document_templates', 0);
        Storage::disk('local')->assertMissing($template->path);
    }
}
