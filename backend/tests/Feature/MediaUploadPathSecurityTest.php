<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NEW-SEC-01 regression: media-upload path traversal + SVG policy.
 *
 * The `folder` input is allowlisted (alpha_dash) so it can never escape
 * `uploads/media/...`; SVG uploads are rejected; stored extensions are
 * derived from sniffed content, never the client filename.
 */
class MediaUploadPathSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_valid_folder_upload_succeeds_within_namespace(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->image('banner.jpg', 800, 600),
            'title' => 'Course Banner',
            'folder' => 'courses',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('media.folder', 'courses');

        $path = $response->json('media.file_path');
        $this->assertStringStartsWith('uploads/media/courses/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_underscore_hyphen_folder_convention_succeeds(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->image('banner.jpg', 800, 600),
            'title' => 'Banner',
            'folder' => 'course-banners_2026',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('media.folder', 'course-banners_2026');
        $this->assertStringStartsWith('uploads/media/course-banners_2026/', $response->json('media.file_path'));
    }

    #[DataProvider('traversalFolderProvider')]
    public function test_traversal_and_unsafe_folder_rejected_without_touching_storage(string $folder): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->image('banner.jpg', 800, 600),
            'title' => 'Probe',
            'folder' => $folder,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('media_assets', ['title' => 'Probe']);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public static function traversalFolderProvider(): array
    {
        return [
            'dotdot slash' => ['../x'],
            'nested dotdot' => ['../../x'],
            'dotdot backslash' => ['..\\x'],
            'absolute path' => ['/absolute'],
            'embedded slash' => ['a/b'],
            'embedded backslash' => ['a\\b'],
            'dot segment' => ['.'],
            'dotdot' => ['..'],
            'space' => ['a b'],
            'special chars' => ['folder!name'],
            'overlong' => [str_repeat('a', 51)],
            'encoded dots' => ['%2e%2e%2f'],
            'encoded backslash' => ['..%2f', '..%5c'],
        ];
    }

    public function test_update_media_rejects_traversal_folder(): void
    {
        $media = MediaAsset::create([
            'file_name' => 'logo.png',
            'title' => 'Logo',
            'file_path' => 'uploads/media/branding/logo.png',
            'disk' => 'public',
            'folder' => 'branding',
            'mime_type' => 'image/png',
            'file_size' => 50000,
            'url' => 'http://localhost/storage/uploads/media/branding/logo.png',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/admin/media/{$media->id}", [
            'folder' => '../x',
        ])->assertStatus(422);

        $this->assertDatabaseHas('media_assets', [
            'id' => $media->id,
            'folder' => 'branding',
        ]);
    }

    public function test_svg_upload_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => UploadedFile::fake()->create('logo.svg', 200, 'image/svg+xml'),
            'title' => 'Vector Logo',
            'folder' => 'branding',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('media_assets', ['title' => 'Vector Logo']);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_stored_extension_comes_from_content_not_client_filename(): void
    {
        Storage::fake('public');

        // JPEG bytes named *.htaccess: mimes validation passes on content
        // (framework blocks only php-family client extensions), but the
        // stored file must not keep the client-supplied servermeaningful
        // extension — it must follow the sniffed content type instead.
        $jpeg = UploadedFile::fake()->image('probe.jpg', 800, 600);
        $file = new UploadedFile($jpeg->getPathname(), 'shell.htaccess', 'image/jpeg', null, true);
        $response = $this->actingAs($this->admin)->postJson('/api/admin/media', [
            'file' => $file,
            'title' => 'Extension Probe',
            'folder' => 'courses',
        ]);

        $response->assertStatus(201);

        $fileName = $response->json('media.file_name');
        $this->assertDoesNotMatchRegularExpression('/\.htaccess$/i', $fileName);
        $this->assertMatchesRegularExpression('/\.jpe?g$/i', $fileName);
        $this->assertStringStartsWith('uploads/media/courses/', $response->json('media.file_path'));
    }
}
