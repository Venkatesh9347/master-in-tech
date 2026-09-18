<?php

namespace App\Console\Commands;

use App\Models\ClassMaterial;
use App\Services\Infrastructure\RedisHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Move legacy class-material files off the web-reachable public disk.
 *
 * New uploads already land hashed on the private `materials` disk, but older
 * records may reference files that exist only under `storage/app/public`
 * (reachable via `/storage` when the deployment symlinks it). The download
 * endpoint prefers the private copy yet falls back to the public one, so a
 * legacy file stays directly URL-addressable until it is moved.
 *
 * Safety model (P2-1):
 * - Paths are derived deterministically from the stored record using the
 *   same normalization as the download endpoint; anything else is skipped.
 * - `..` / empty / absolute segments are rejected: deletion can only ever
 *   address the exact relative path that was existence-checked.
 * - A public file is deleted ONLY after byte-identical content is verified
 *   on the private disk (SHA-256 before and after the copy, plus a
 *   re-read immediately before deletion to close the check/delete window).
 *   Worst case of any defect is therefore deleting bytes that provably
 *   exist elsewhere — never data loss, never an app behavior change
 *   (the private copy already wins the download lookup).
 * - Twin files with differing bytes are left alone and reported (ambiguous
 *   provenance is an operator decision, not a guess).
 * - Per-row isolation: one bad row logs and continues; records are never
 *   modified (the relative path is unchanged, so lookups keep working).
 * - Single-runner safe via the shared distributed lock.
 */
class MigrateLegacyMaterialsToPrivate extends Command
{
    protected $signature = 'mit:migrate-legacy-materials
        {--apply : Copy verified files to private storage and remove public originals. Without --apply, only reports (dry run).}';

    protected $description = 'Move legacy public class-material files to private storage (dry run by default)';

    public function handle(RedisHealthService $redis): int
    {
        $lockKey = 'locks:migrate-legacy-materials';

        if (! $redis->acquireLock($lockKey, 600)) {
            $this->warn('Another scheduler runner already holds the migration lock; skipping this tick.');

            return self::SUCCESS;
        }

        try {
            $apply = (bool) $this->option('apply');

            $counts = [
                'examined' => 0,
                'migrated' => 0,
                'twins_removed' => 0,
                'already_private' => 0,
                'missing' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];

            ClassMaterial::whereNotNull('file_path')->chunkById(200, function ($materials) use ($apply, &$counts): void {
                foreach ($materials as $material) {
                    $counts['examined']++;
                    $this->processMaterial($material, $apply, $counts);
                }
            });

            $mode = $apply ? 'apply' : 'dry-run';

            $this->info(sprintf(
                'Legacy materials migration (%s): examined=%d migrated=%d twins_removed=%d already_private=%d missing=%d skipped=%d failed=%d',
                $mode,
                $counts['examined'],
                $counts['migrated'],
                $counts['twins_removed'],
                $counts['already_private'],
                $counts['missing'],
                $counts['skipped'],
                $counts['failed']
            ));

            return self::SUCCESS;
        } finally {
            $redis->releaseLock($lockKey);
        }
    }

    private function processMaterial(ClassMaterial $material, bool $apply, array &$counts): void
    {
        $relative = self::candidateRelativePath($material->file_path);

        if ($relative === null) {
            $counts['skipped']++;
            Log::warning('materials.migrate.skipped_path', ['material_id' => $material->id]);

            return;
        }

        $private = Storage::disk('materials');
        $public = Storage::disk('public');

        try {
            $onPrivate = $private->exists($relative);
            $onPublic = $public->exists($relative);
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('materials.migrate.failed', ['material_id' => $material->id, 'error' => $e->getMessage()]);

            return;
        }

        if (! $onPublic) {
            $counts[$onPrivate ? 'already_private' : 'missing']++;

            return;
        }

        if ($onPrivate) {
            $this->removeVerifiedTwin($material, $relative, $apply, $counts, 'twins_removed');

            return;
        }

        // Public-only legacy file: copy, verify, then remove the original.
        try {
            $bytes = $public->get($relative);

            if (! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Unreadable or empty source file.');
            }

            $expectedHash = hash('sha256', $bytes);

            if (! $apply) {
                $counts['migrated']++;

                return;
            }

            $private->put($relative, $bytes);

            $stored = $private->get($relative);

            if (! is_string($stored) || hash('sha256', $stored) !== $expectedHash) {
                // Never leave a corrupt partial behind to shadow the original.
                $private->delete($relative);

                throw new \RuntimeException('Private copy verification mismatch.');
            }

            // Re-read the public original immediately before deletion to
            // close the check/delete window.
            $current = $public->get($relative);

            if (! is_string($current) || hash('sha256', $current) !== $expectedHash) {
                $private->delete($relative);

                throw new \RuntimeException('Public original changed during migration.');
            }

            $public->delete($relative);
            $counts['migrated']++;
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('materials.migrate.failed', [
                'material_id' => $material->id,
                'path' => $relative,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a public twin only when byte-identical content is verified on
     * the private disk (re-read at deletion time).
     */
    private function removeVerifiedTwin(ClassMaterial $material, string $relative, bool $apply, array &$counts, string $counter): void
    {
        try {
            $privateBytes = Storage::disk('materials')->get($relative);
            $publicBytes = Storage::disk('public')->get($relative);

            if (! is_string($privateBytes) || ! is_string($publicBytes)
                || hash('sha256', $privateBytes) !== hash('sha256', $publicBytes)) {
                // Ambiguous provenance: leave both copies and report.
                $counts['skipped']++;
                Log::warning('materials.migrate.collision', [
                    'material_id' => $material->id,
                    'path' => $relative,
                ]);

                return;
            }

            if ($apply) {
                Storage::disk('public')->delete($relative);
            }

            $counts[$counter]++;
        } catch (\Throwable $e) {
            $counts['failed']++;
            Log::warning('materials.migrate.failed', [
                'material_id' => $material->id,
                'path' => $relative,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize a stored file_path to the relative form the download
     * endpoint resolves. Returns null for anything that is not exactly
     * addressable ( traversal segments, empty parts, absolute paths).
     */
    public static function candidateRelativePath(?string $filePath): ?string
    {
        if (! is_string($filePath) || $filePath === '') {
            return null;
        }

        $path = $filePath;

        if (preg_match('#^https?://#i', $path)) {
            $path = (string) preg_replace('#^https?://[^/]+#i', '', $path);
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if ($path === '') {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }
}
