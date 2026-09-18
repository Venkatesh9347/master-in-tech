<?php

namespace Tests\Feature;

use App\Models\ClassMaterial;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2-1: legacy class-material files move off the web-reachable public disk
 * only after byte-identical content is verified on the private disk.
 *
 * Records are never modified (the relative path is unchanged); authorized
 * downloads keep working throughout.
 */
class LegacyMaterialMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('materials');

        $this->admin = User::factory()->create(['role' => 'admin']);

        $title = 'Course ' . Str::random(6);

        $this->course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Legacy migration fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);
    }

    private function makeMaterial(string $filePath): ClassMaterial
    {
        return ClassMaterial::create([
            'course_id' => $this->course->id,
            'uploaded_by' => $this->admin->id,
            'title' => 'Legacy Doc',
            'file_path' => $filePath,
            'file_name' => 'legacy-doc.pdf',
            'file_type' => 'pdf',
            'file_size' => 11,
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $material = $this->makeMaterial('materials/legacy-doc.pdf');
        Storage::disk('public')->put('materials/legacy-doc.pdf', 'hello-world');

        $this->artisan('mit:migrate-legacy-materials')->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('materials/legacy-doc.pdf'));
        $this->assertFalse(Storage::disk('materials')->exists('materials/legacy-doc.pdf'));
        $this->assertSame('materials/legacy-doc.pdf', $material->fresh()->file_path);
    }

    public function test_apply_migrates_public_only_file(): void
    {
        $material = $this->makeMaterial('materials/legacy-doc.pdf');
        Storage::disk('public')->put('materials/legacy-doc.pdf', 'hello-world');

        $this->artisan('mit:migrate-legacy-materials', ['--apply' => true])->assertSuccessful();

        // Byte-identical private replacement, public original removed.
        $this->assertSame('hello-world', Storage::disk('materials')->get('materials/legacy-doc.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('materials/legacy-doc.pdf'));

        // Record untouched, authorized download still serves the bytes.
        $this->assertSame('materials/legacy-doc.pdf', $material->fresh()->file_path);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/materials/{$material->id}/download");

        $response->assertOk()->assertDownload('legacy-doc.pdf');
    }

    public function test_apply_migrates_url_form_legacy_path(): void
    {
        $material = $this->makeMaterial('/storage/materials/url-doc.pdf');
        Storage::disk('public')->put('materials/url-doc.pdf', 'url-bytes');

        $this->artisan('mit:migrate-legacy-materials', ['--apply' => true])->assertSuccessful();

        $this->assertSame('url-bytes', Storage::disk('materials')->get('materials/url-doc.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('materials/url-doc.pdf'));
        $this->assertSame('/storage/materials/url-doc.pdf', $material->fresh()->file_path);
    }

    public function test_identical_twin_public_copy_is_removed(): void
    {
        $this->makeMaterial('materials/twin-doc.pdf');
        Storage::disk('materials')->put('materials/twin-doc.pdf', 'same-bytes');
        Storage::disk('public')->put('materials/twin-doc.pdf', 'same-bytes');

        $this->artisan('mit:migrate-legacy-materials', ['--apply' => true])->assertSuccessful();

        $this->assertSame('same-bytes', Storage::disk('materials')->get('materials/twin-doc.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('materials/twin-doc.pdf'));
    }

    public function test_differing_twin_is_left_alone(): void
    {
        $this->makeMaterial('materials/clash-doc.pdf');
        Storage::disk('materials')->put('materials/clash-doc.pdf', 'private-version');
        Storage::disk('public')->put('materials/clash-doc.pdf', 'public-version');

        $this->artisan('mit:migrate-legacy-materials', ['--apply' => true])->assertSuccessful();

        // Ambiguous provenance: both copies preserved for an operator.
        $this->assertSame('private-version', Storage::disk('materials')->get('materials/clash-doc.pdf'));
        $this->assertSame('public-version', Storage::disk('public')->get('materials/clash-doc.pdf'));
    }

    public function test_already_private_and_missing_files_are_safe(): void
    {
        $this->makeMaterial('materials/only-private.pdf');
        Storage::disk('materials')->put('materials/only-private.pdf', 'private-only');

        $this->makeMaterial('materials/gone.pdf');

        $this->artisan('mit:migrate-legacy-materials', ['--apply' => true])->assertSuccessful();

        $this->assertSame('private-only', Storage::disk('materials')->get('materials/only-private.pdf'));
        $this->assertFalse(Storage::disk('public')->exists('materials/gone.pdf'));
    }
}
