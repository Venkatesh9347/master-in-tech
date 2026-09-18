<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2-3: orphaned per-asset HLS directories are reaped only when no
 * VideoAsset row references them, they look like asset directories, they
 * resolve inside the video root, and they are older than the grace window.
 */
class PruneOrphanVideoDirsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['video.storage_disk' => 'local']);
    }

    private function makeAsset(string $assetId, string $status = 'ready'): VideoAsset
    {
        $title = 'Course ' . Str::random(6);

        $course = Course::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'description' => 'Prune fixture.',
            'category' => 'Engineering',
            'instructor' => 'Fixture',
            'duration' => '4 weeks',
            'difficulty' => 'Beginner',
            'is_published' => true,
            'price' => 100.00,
        ]);

        $section = Section::create([
            'course_id' => $course->id,
            'title' => 'Module 1',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Video Lesson',
            'type' => 'video',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        return VideoAsset::create([
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'title' => 'Fixture Asset',
            'asset_id' => $assetId,
            'status' => $status,
        ]);
    }

    private function runPrune(array $options = []): void
    {
        $this->artisan('mit:prune-orphan-video-dirs', $options)->assertSuccessful();
    }

    public function test_directory_with_live_row_is_kept(): void
    {
        $this->makeAsset('vasset_keepme1');
        Storage::disk('local')->put('videos/vasset_keepme1/master.m3u8', 'playlist');
        Storage::disk('local')->put('videos/vasset_keepme1/seg-000.ts', 'segment');

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_keepme1/master.m3u8'));
        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_keepme1/seg-000.ts'));
    }

    public function test_processing_asset_is_kept_even_without_grace(): void
    {
        $this->makeAsset('vasset_processing1', 'processing');
        Storage::disk('local')->put('videos/vasset_processing1/source.mp4', 'source');

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_processing1/source.mp4'));
    }

    public function test_orphan_directory_is_removed_after_row_deletion(): void
    {
        $asset = $this->makeAsset('vasset_orphan1');
        Storage::disk('local')->put('videos/vasset_orphan1/master.m3u8', 'playlist');
        Storage::disk('local')->put('videos/vasset_orphan1/seg-000.ts', 'segment');

        $asset->delete();

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertFalse(Storage::disk('local')->exists('videos/vasset_orphan1/master.m3u8'));
        $this->assertFalse(Storage::disk('local')->exists('videos/vasset_orphan1'));
    }

    public function test_recent_orphan_is_kept_until_grace_expires(): void
    {
        Storage::disk('local')->put('videos/vasset_fresh1/master.m3u8', 'playlist');

        // Default 24h grace: a just-created row-less directory is untouched.
        $this->runPrune(['--apply' => true]);

        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_fresh1/master.m3u8'));
    }

    public function test_dry_run_removes_nothing(): void
    {
        Storage::disk('local')->put('videos/vasset_dryrun1/master.m3u8', 'playlist');

        $this->runPrune(['--grace-minutes' => 0]);

        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_dryrun1/master.m3u8'));
    }

    public function test_missing_directory_is_harmless(): void
    {
        $this->makeAsset('vasset_nodir1');

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertDatabaseHas('video_assets', ['asset_id' => 'vasset_nodir1']);
    }

    public function test_non_asset_names_and_other_paths_are_untouched(): void
    {
        // Dots are outside the asset-id shape: never touched.
        Storage::disk('local')->put('videos/bad.name/master.m3u8', 'playlist');
        // Outside the videos root entirely.
        Storage::disk('local')->put('unrelated/keep.txt', 'keep');

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertTrue(Storage::disk('local')->exists('videos/bad.name/master.m3u8'));
        $this->assertTrue(Storage::disk('local')->exists('unrelated/keep.txt'));
    }

    public function test_sibling_asset_directory_survives_orphan_removal(): void
    {
        $this->makeAsset('vasset_sibling1');
        Storage::disk('local')->put('videos/vasset_sibling1/master.m3u8', 'playlist');
        Storage::disk('local')->put('videos/vasset_gone99/master.m3u8', 'playlist');

        $this->runPrune(['--apply' => true, '--grace-minutes' => 0]);

        $this->assertTrue(Storage::disk('local')->exists('videos/vasset_sibling1/master.m3u8'));
        $this->assertFalse(Storage::disk('local')->exists('videos/vasset_gone99'));
    }
}
