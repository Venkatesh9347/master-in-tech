<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'course_id',
        'tutor_id',
        'start_date',
        'end_date',
        'status',
        'schedule_type',
        'schedule_time',
        'max_students',
        'meeting_link',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'max_students' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function batchStudents(): HasMany
    {
        return $this->hasMany(BatchStudent::class);
    }

    public function activeBatchStudents(): HasMany
    {
        return $this->hasMany(BatchStudent::class)->where('status', 'active');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_students')
            ->withPivot(['id', 'status', 'joined_at', 'left_at', 'discontinued_at', 'discontinuation_reason', 'notes'])
            ->withTimestamps();
    }

    public function activeStudents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_students')
            ->wherePivot('status', 'active')
            ->withPivot(['id', 'status', 'joined_at', 'left_at', 'discontinued_at', 'discontinuation_reason', 'notes'])
            ->withTimestamps();
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(BatchTransfer::class, 'from_batch_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(BatchTransfer::class, 'to_batch_id');
    }

    public function liveClassroomSessions(): HasMany
    {
        return $this->hasMany(LiveClassroomSession::class);
    }

    /**
     * Extract or derive standard uppercase course code.
     */
    public static function getCourseCode(Course $course): string
    {
        if (! empty($course->code)) {
            return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($course->code)));
        }

        $title = trim($course->title);
        $cleanTitle = preg_replace('/[^\w\s]/', ' ', $title);
        $words = array_values(array_filter(preg_split('/\s+/', $cleanTitle), function ($w) {
            return ! empty($w) && ! in_array(strtolower($w), ['and', '&', 'the', 'in', 'for', 'of', 'to', 'a', 'with', 'by']);
        }));

        if (count($words) >= 2) {
            $code = '';
            foreach ($words as $w) {
                $code .= strtoupper($w[0]);
            }
            return $code;
        }

        if (count($words) === 1) {
            $single = strtoupper($words[0]);
            if ($single === 'PYTHON') return 'PY';
            if ($single === 'JAVASCRIPT') return 'JS';
            if ($single === 'TYPESCRIPT') return 'TS';
            if ($single === 'KUBERNETES') return 'K8S';
            if ($single === 'FLUTTER') return 'FL';
            if ($single === 'DJANGO') return 'DJ';
            if ($single === 'JAVA') return 'JAVA';
            if ($single === 'REACT') return 'REACT';
            if ($single === 'DEVOPS') return 'DEVOPS';
            if ($single === 'DOCKER') return 'DOCKER';
            if (strlen($single) <= 4) return $single;
            return substr($single, 0, 3);
        }

        return 'COURSE';
    }

    /**
     * Format start date to DDMMYY string with leading zeroes.
     */
    public static function formatBatchDate(Carbon|string $startDate): string
    {
        if (is_string($startDate) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($startDate), $matches)) {
            $year = substr($matches[1], -2);
            $month = $matches[2];
            $day = $matches[3];
            return "{$day}{$month}{$year}";
        }

        $dateObj = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
        return $dateObj->format('dmy'); // DDMMYY
    }

    /**
     * Generate standard automatic Batch Code in format: RIT(COURSE_CODE)BCDDMMYY
     */
    public static function generateBatchCode(Course $course, Carbon|string $startDate, bool $checkUnique = true): string
    {
        $code = static::getCourseCode($course);
        $dateStr = static::formatBatchDate($startDate);

        $baseCode = "RIT({$code})BC{$dateStr}";

        if (! $checkUnique) {
            return $baseCode;
        }

        // Ensure unique batch code if another exists with same code
        $candidateCode = $baseCode;
        $counter = 1;
        while (static::where('code', $candidateCode)->exists()) {
            $counter++;
            $candidateCode = "{$baseCode}-" . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
        }

        return $candidateCode;
    }

    /**
     * Scope for intelligent search including 6-digit date (DDMMYY), code, title, and instructor.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            // Check for 6-digit date pattern DDMMYY (e.g. 230826)
            if (preg_match('/^\d{6}$/', $search)) {
                $day = (int) substr($search, 0, 2);
                $month = (int) substr($search, 2, 2);
                $year = (int) ('20' . substr($search, 4, 2));

                if (checkdate($month, $day, $year)) {
                    $formattedDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $q->whereDate('start_date', $formattedDate);
                }
            }

            // Also check for standard partial matches on code, name, description
            $q->orWhere('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('schedule_time', 'like', "%{$search}%")
                ->orWhereHas('course', function ($cq) use ($search) {
                    $cq->where('title', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                })
                ->orWhereHas('tutor', function ($tq) use ($search) {
                    $tq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }

    public function scopeForCourse(Builder $query, ?int $courseId): Builder
    {
        if ($courseId) {
            return $query->where('course_id', $courseId);
        }
        return $query;
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status);
        }
        return $query;
    }

    public function scopeForTutor(Builder $query, ?int $tutorId): Builder
    {
        if ($tutorId) {
            return $query->where('tutor_id', $tutorId);
        }
        return $query;
    }

    public function placementApplications()
    {
        return $this->hasMany(PlacementApplication::class, 'batch_id');
    }
}
