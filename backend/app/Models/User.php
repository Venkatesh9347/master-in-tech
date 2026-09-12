<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

#[Fillable(['name', 'email', 'student_id', 'password', 'company_id', 'status', 'phone', 'headline', 'expertise', 'bio', 'location', 'google_id', 'avatar'])]
#[Hidden(['password', 'remember_token', 'current_session_id', 'current_session_created_at', 'google_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'current_session_created_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    /**
     * Default permissions matrix for tutors/faculty.
     */
    public static function defaultTutorPermissions(): array
    {
        return [
            'view_assigned_courses' => true,
            'view_students' => true,
            'upload_materials' => true,
            'manage_materials' => true,
            'create_quizzes' => false,
            'edit_quizzes' => false,
            'delete_quizzes' => false,
            'publish_quizzes' => false,
            'view_quiz_results' => true,
        ];
    }

    /**
     * Map legacy permission keys to the canonical matrix used by Admin UI and enforcement.
     *
     * @return array<string, string>
     */
    public static function permissionKeyMap(): array
    {
        return [
            'view_assigned_courses' => 'view_assigned_courses',
            'upload_materials' => 'upload_materials',
            'manage_materials' => 'manage_materials',
            'create_quizzes' => 'create_quizzes',
            'edit_quizzes' => 'edit_quizzes',
            'delete_quizzes' => 'delete_quizzes',
            'publish_quizzes' => 'publish_quizzes',
            'view_quiz_results' => 'view_quiz_results',
        ];
    }

    public static function canonicalizePermissionKey(string $permission): string
    {
        return static::permissionKeyMap()[$permission] ?? $permission;
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, bool>
     */
    public static function canonicalizePermissions(array $permissions): array
    {
        $canonical = [];

        foreach ($permissions as $key => $value) {
            $canonicalKey = static::canonicalizePermissionKey((string) $key);
            $canonical[$canonicalKey] = (bool) $value;
        }

        return $canonical;
    }

    /**
     * Unusable random password for provisioned students who authenticate via Google/OTP.
     */
    public static function generateUnusablePassword(): string
    {
        return Str::password(32);
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'admin' || $this->role === 'super_admin') {
            return true;
        }

        $canonical = static::canonicalizePermissionKey($permission);
        $resolved = $this->getResolvedPermissions();

        return (bool) ($resolved[$canonical] ?? false);
    }

    /**
     * Get all resolved permissions as a complete associative array (canonical keys only).
     *
     * @return array<string, bool>
     */
    public function getResolvedPermissions(): array
    {
        if ($this->role === 'admin' || $this->role === 'super_admin') {
            return array_fill_keys(array_keys(static::defaultTutorPermissions()), true);
        }

        $stored = is_array($this->permissions) ? $this->permissions : [];

        return array_merge(static::defaultTutorPermissions(), static::canonicalizePermissions($stored));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['permissions'] = $this->getResolvedPermissions();

        return $array;
    }

    /**
     * Generate a new single active session and issue a Sanctum token linked to this session.
     * Invalidates any previous active session across all devices.
     */
    public function startNewActiveSession(string $tokenName = 'auth_token'): NewAccessToken
    {
        $sessionId = (string) Str::uuid();

        // Atomically assign the new current session identifier
        $this->forceFill([
            'current_session_id' => $sessionId,
            'current_session_created_at' => now(),
        ])->save();

        // Issue new Sanctum token embedding the session identifier in abilities
        return $this->createToken($tokenName, ['session:' . $sessionId]);
    }

    /**
     * Get the course enrollments for this user.
     */
    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Get the courses taught by this user (if tutor/admin).
     */
    public function taughtCourses()
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    /**
     * Get certificates earned by this user.
     */
    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Get the OTP login sessions for this student.
     */
    public function studentLoginOtps()
    {
        return $this->hasMany(StudentLoginOtp::class);
    }

    /**
     * Get live class attendances for this student.
     */
    public function liveClassAttendances()
    {
        return $this->hasMany(LiveClassAttendance::class);
    }

    /**
     * Get live classes hosted by this instructor/admin.
     */
    public function hostedLiveClasses()
    {
        return $this->hasMany(LiveClass::class, 'instructor_id');
    }

    public function assignedClassSessions()
    {
        return $this->hasMany(ClassSession::class, 'tutor_id');
    }

    public function classSessionAttendances()
    {
        return $this->hasMany(ClassSessionAttendance::class, 'user_id');
    }

    public function uploadedClassMaterials()
    {
        return $this->hasMany(ClassMaterial::class, 'uploaded_by');
    }

    public function batchMemberships()
    {
        return $this->hasMany(BatchStudent::class);
    }

    public function batches()
    {
        return $this->belongsToMany(Batch::class, 'batch_students')
            ->withPivot(['id', 'status', 'joined_at', 'left_at', 'discontinued_at', 'discontinuation_reason', 'notes'])
            ->withTimestamps();
    }

    public function activeBatches()
    {
        return $this->belongsToMany(Batch::class, 'batch_students')
            ->wherePivot('status', 'active')
            ->withPivot(['id', 'status', 'joined_at', 'left_at', 'discontinued_at', 'discontinuation_reason', 'notes'])
            ->withTimestamps();
    }

    public function taughtBatches()
    {
        return $this->hasMany(Batch::class, 'tutor_id');
    }

    public function batchTransfers()
    {
        return $this->hasMany(BatchTransfer::class, 'user_id');
    }

    public function liveClassroomSessions()
    {
        return $this->hasMany(LiveClassroomSession::class, 'tutor_id');
    }

    public function liveClassroomParticipations()
    {
        return $this->hasMany(LiveClassroomParticipant::class, 'user_id');
    }

    public function isStudent(): bool
    {
        return $this->role === 'student' || empty($this->role);
    }

    public function isTutor(): bool
    {
        return $this->role === 'tutor';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->role === 'super_admin';
    }

    public function isCounsellor(): bool
    {
        return $this->role === 'counsellor';
    }

    public function isTelecaller(): bool
    {
        return $this->role === 'telecaller';
    }

    public function isCourseAdvisor(): bool
    {
        return $this->role === 'course_advisor';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isCompany(): bool
    {
        return $this->role === 'company' || $this->role === 'recruiter';
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function canAccessCrm(): bool
    {
        return in_array($this->role, ['super_admin', 'admin', 'counsellor', 'telecaller', 'course_advisor'], true);
    }

    /**
     * CRM staff roles with record-scoped (own + unassigned) lead visibility.
     * Admins/super_admin see the full pipeline.
     */
    public function hasScopedCrmAccess(): bool
    {
        return in_array($this->role, ['counsellor', 'telecaller', 'course_advisor'], true);
    }

    public function assignedLeads()
    {
        return $this->hasMany(Enquiry::class, 'assigned_counsellor_id');
    }

    public function crmActivities()
    {
        return $this->hasMany(CrmActivity::class, 'user_id');
    }

    public function assignedFollowUps()
    {
        return $this->hasMany(CrmFollowUp::class, 'assigned_to');
    }

    public function placementApplications()
    {
        return $this->hasMany(PlacementApplication::class, 'user_id');
    }

    public function interviews()
    {
        return $this->hasMany(PlacementInterview::class, 'candidate_id');
    }

    public function mockInterviews()
    {
        return $this->hasMany(MockInterview::class, 'student_id');
    }

    public function mockEvaluations()
    {
        return $this->hasMany(MockInterviewEvaluation::class, 'student_id');
    }

    public function interviewerProfile()
    {
        return $this->hasOne(MockInterviewer::class, 'user_id');
    }

    public function placementEligibility()
    {
        return $this->hasOne(StudentPlacementEligibility::class, 'user_id');
    }
}
