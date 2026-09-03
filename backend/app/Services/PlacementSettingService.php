<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\Cache;

class PlacementSettingService
{
    const CACHE_KEY = 'placement_settings';
    const CACHE_TTL = 86400; // 24 hours

    /**
     * Default placement configuration values.
     */
    const DEFAULTS = [
        'placement_enabled' => true,
        'mock_interview_required' => true,
        'job_applications_enabled' => true,
    ];

    /**
     * Get consolidated placement settings with caching.
     */
    public static function getSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $placementEnabled = self::parseBoolean(WebsiteSetting::get('placement_enabled', self::DEFAULTS['placement_enabled']));
            $mockInterviewRequired = self::parseBoolean(WebsiteSetting::get('mock_interview_required', self::DEFAULTS['mock_interview_required']));
            $jobApplicationsEnabled = self::parseBoolean(WebsiteSetting::get('job_applications_enabled', self::DEFAULTS['job_applications_enabled']));

            return [
                'placement_enabled' => $placementEnabled,
                'mock_interview_required' => $mockInterviewRequired,
                'job_applications_enabled' => $jobApplicationsEnabled,
                'placementEnabled' => $placementEnabled,
                'mockInterviewRequired' => $mockInterviewRequired,
                'jobApplicationsEnabled' => $jobApplicationsEnabled,
            ];
        });
    }

    /**
     * Update placement settings, invalidate cache, and log audit.
     */
    public static function updateSettings(array $values, ?User $user = null): array
    {
        $oldSettings = self::getSettings();

        if (array_key_exists('placement_enabled', $values) || array_key_exists('placementEnabled', $values)) {
            $val = $values['placement_enabled'] ?? $values['placementEnabled'];
            WebsiteSetting::set('placement_enabled', self::parseBoolean($val) ? '1' : '0', 'placement');
        }

        if (array_key_exists('mock_interview_required', $values) || array_key_exists('mockInterviewRequired', $values)) {
            $val = $values['mock_interview_required'] ?? $values['mockInterviewRequired'];
            WebsiteSetting::set('mock_interview_required', self::parseBoolean($val) ? '1' : '0', 'placement');
        }

        if (array_key_exists('job_applications_enabled', $values) || array_key_exists('jobApplicationsEnabled', $values)) {
            $val = $values['job_applications_enabled'] ?? $values['jobApplicationsEnabled'];
            WebsiteSetting::set('job_applications_enabled', self::parseBoolean($val) ? '1' : '0', 'placement');
        }

        Cache::forget(self::CACHE_KEY);

        $newSettings = self::getSettings();

        AuditLog::log('updated_placement_settings', null, $oldSettings, $newSettings);

        return $newSettings;
    }

    /**
     * Is the Placement Portal globally enabled?
     */
    public static function isPlacementEnabled(): bool
    {
        return (bool) (self::getSettings()['placement_enabled'] ?? true);
    }

    /**
     * Is mock interview mandatory before placement eligibility?
     */
    public static function isMockInterviewRequired(): bool
    {
        return (bool) (self::getSettings()['mock_interview_required'] ?? true);
    }

    /**
     * Are student job applications currently enabled?
     */
    public static function isJobApplicationsEnabled(): bool
    {
        return (bool) (self::getSettings()['job_applications_enabled'] ?? true);
    }

    /**
     * Safely parse mixed value to boolean.
     */
    private static function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
