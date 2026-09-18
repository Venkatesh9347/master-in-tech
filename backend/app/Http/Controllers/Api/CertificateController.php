<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\CertificatePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    /**
     * Generate or fetch certificate of completion for the authenticated student.
     */
    public function generate(Request $request, $courseId)
    {
        $user = $request->user();
        $course = Course::where('id', $courseId)->orWhere('slug', $courseId)->first();

        if (! $course) {
            return response()->json(['message' => 'Course not found'], 404);
        }

        // Check if certificate already generated
        $existing = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            if ($existing->isRevoked()) {
                return response()->json([
                    'message' => 'This certificate has been revoked.',
                    'status' => Certificate::STATUS_REVOKED,
                    'certificate_code' => $existing->certificate_code,
                ], 422);
            }

            return response()->json([
                'message' => 'Certificate already issued.',
                'certificate' => $this->payload($existing),
            ]);
        }

        // Verify enrollment and completion inside a transaction with a lock so
        // concurrent requests cannot mint duplicate certificates (M2).
        $issuedCertificate = null;

        $response = DB::transaction(function () use ($user, $course, &$issuedCertificate) {
            // B3 pay-before-classroom: only active/completed enrollments qualify.
            $enrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->whereIn('status', ['active', 'completed'])
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                return response()->json(['message' => 'You must be enrolled in this course.'], 403);
            }

            // Re-check under lock in case another request already issued one.
            $existing = Certificate::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($existing) {
                if ($existing->isRevoked()) {
                    return response()->json([
                        'message' => 'This certificate has been revoked.',
                        'status' => Certificate::STATUS_REVOKED,
                        'certificate_code' => $existing->certificate_code,
                    ], 422);
                }

                return response()->json([
                    'message' => 'Certificate already issued.',
                    'certificate' => $this->payload($existing),
                ]);
            }

            $totalLessons = Lesson::where('course_id', $course->id)->where('is_published', true)->count();
            $completedLessons = LessonProgress::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('completed', true)
                ->count();

            // HIGH-1: A course with no published lessons has nothing to complete.
            // Reject the request instead of skipping the completion check and
            // minting a certificate for an empty course.
            if ($totalLessons === 0) {
                return response()->json([
                    'message' => 'This course has no lessons available to complete. Certificate cannot be issued.',
                    'completed' => 0,
                    'total' => 0,
                ], 422);
            }

            // HIGH-1: require every published lesson to be completed.
            if ($completedLessons < $totalLessons) {
                return response()->json([
                    'message' => 'Please complete all lessons in the course to unlock your certificate.',
                    'completed' => $completedLessons,
                    'total' => $totalLessons,
                ], 422);
            }

            // M1: cryptographically unpredictable, collision-checked code.
            $code = $this->uniqueCertificateCode();

            $certificate = Certificate::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'certificate_code' => $code,
                'issued_at' => now(),
            ]);

            $enrollment->update([
                'status' => 'completed',
                'progress_percentage' => 100,
            ]);

            $issuedCertificate = $certificate;

            AuditLog::log('generated_certificate', $certificate, null, [
                'id' => $certificate->id,
                'user_id' => $certificate->user_id,
                'course_id' => $certificate->course_id,
                'certificate_code' => $certificate->certificate_code,
            ]);

            // Best-effort PDF artifact generation. Failures are non-fatal; the
            // download endpoint re-attempts on demand (CertificatePdfService).
            (new CertificatePdfService())->attempt($certificate);

            return response()->json([
                'message' => 'Congratulations! Certificate generated successfully.',
                'certificate' => $this->payload($certificate),
            ], 201);
        });

        // F1: transaction committed (or no certificate was minted); notify
        // only on actual issuance.
        if ($issuedCertificate instanceof Certificate) {
            \App\Services\NotificationService::certificateIssued($issuedCertificate);
        }

        return $response;
    }

    /**
     * Generate a unique, cryptographically unpredictable certificate code.
     */
    private function uniqueCertificateCode(): string
    {
        do {
            $code = 'MIT-' . now()->year . '-' . strtoupper(Str::random(12));
        } while (Certificate::where('certificate_code', $code)->exists());

        return $code;
    }

    /**
     * Get certificate details by code (authenticated user or public).
     *
     * The payload is flattened to public-safe fields only — the issuing user's
     * email is never exposed (GA blocker R2). Owners download the PDF through
     * the authenticated, ownership-checked download endpoint instead.
     */
    public function show($code)
    {
        $certificate = Certificate::with(['course', 'user:id,name'])
            ->where('certificate_code', $code)
            ->first();

        if (! $certificate) {
            return response()->json(['message' => 'Certificate not found'], 404);
        }

        return response()->json($this->payload($certificate));
    }

    /**
     * Public verification of certificate authenticity.
     *
     * A revoked certificate is recognized but never reported as valid; the
     * revoked state is distinguished without exposing admin ids, audit
     * metadata, or internal implementation details.
     */
    public function verify($code)
    {
        $certificate = Certificate::where('certificate_code', $code)
            ->with(['course', 'user:id,name'])
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid or unrecognized certificate ID.',
            ], 404);
        }

        if ($certificate->isRevoked()) {
            return response()->json([
                'valid' => false,
                'revoked' => true,
                'message' => 'This certificate has been revoked and is no longer valid.',
                'certificate_code' => $certificate->certificate_code,
                'recipient_name' => $certificate->user?->name,
                'course_title' => $certificate->course?->title,
                'issued_at' => $certificate->issued_at,
                'revoked_at' => $certificate->revoked_at,
            ]);
        }

        return response()->json([
            'valid' => true,
            'certificate_code' => $certificate->certificate_code,
            'recipient_name' => $certificate->user?->name,
            'course_title' => $certificate->course?->title,
            'instructor' => $certificate->course?->instructor,
            'issued_at' => $certificate->issued_at,
        ]);
    }

    /**
     * Download the certificate PDF (owner or admin only).
     *
     * The artifact lives on the private 'local' disk and is only ever served
     * through this authenticated, ownership-checked endpoint.
     *
     * Revoked certificates are never served as valid artifacts (410): the
     * historical record is preserved, but no valid PDF is represented to
     * the user and the revocation is never silently undone.
     */
    public function download(Request $request, $code)
    {
        $certificate = Certificate::where('certificate_code', $code)->first();

        if (! $certificate) {
            return response()->json(['message' => 'Certificate not found'], 404);
        }

        $user = $request->user();

        $isOwner = (int) $certificate->user_id === (int) $user->id;
        $isAdmin = in_array($user->role, ['admin', 'super_admin'], true);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'You do not have access to this certificate.'], 403);
        }

        if ($certificate->isRevoked()) {
            return response()->json([
                'message' => 'This certificate has been revoked and is no longer available for download.',
                'status' => Certificate::STATUS_REVOKED,
                'certificate_code' => $certificate->certificate_code,
            ], 410);
        }

        $pdfService = new CertificatePdfService();
        $path = $pdfService->ensureFor($certificate);

        return (new Response(
            Storage::disk($pdfService->disk())->get($path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $certificate->certificate_code . '.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        ));
    }

    /**
     * Admin certificate listing backing the revocation console (read-only).
     *
     * Safe projection only: internal id, code, status, timestamps, reason,
     * revoking admin id, and user/course identity. Never exposes pdf_path,
     * audit blobs, or storage internals. Filters apply before pagination;
     * ordering is deterministic (id desc), matching the admin ledger
     * convention.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'course_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Certificate::query()
            ->with(['user:id,name,email', 'course:id,title'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['course_id'] ?? null, fn ($q, $courseId) => $q->where('course_id', $courseId))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(fn ($w) => $w->where('certificate_code', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('course', fn ($c) => $c->where('title', 'like', $like)));
            })
            ->orderBy('id', 'desc');

        $paginator = $query->paginate($this->perPage($request));

        $paginator->getCollection()->transform(fn (Certificate $certificate) => $this->adminCertificatePayload($certificate));

        return response()->json($paginator);
    }

    /**
     * Admin certificate detail backing the revocation console (read-only).
     *
     * Explicit lookup (not implicit binding) so unknown ids return a generic
     * 404 without disclosing model internals. Same safe projection as the
     * listing plus the revoking administrator's display name.
     */
    public function adminShow($certificateId)
    {
        $certificate = Certificate::with(['user:id,name,email', 'course:id,title', 'revokedBy:id,name'])
            ->where('id', $certificateId)
            ->first();

        if ($certificate === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $payload = $this->adminCertificatePayload($certificate);
        $payload['revoked_by_user'] = $certificate->revokedBy
            ? $certificate->revokedBy->only(['id', 'name'])
            : null;

        return response()->json(['certificate' => $payload]);
    }

    /**
     * Revoke a certificate (admin-only, route middleware).
     *
     * Explicit, append-only active -> revoked transition under a row lock so
     * concurrent revocations converge on `revoked` and can never reactivate
     * the certificate. Replaying the revocation is deterministic (returns
     * the revoked state without a second audit event). The record is never
     * deleted; attribution comes from the authenticated administrator, never
     * from client input.
     */
    public function revoke(Request $request, $certificateId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $reason = trim($validated['reason']);

        if ($reason === '') {
            return response()->json([
                'message' => 'The reason field is required.',
                'errors' => ['reason' => ['A revocation reason is required.']],
            ], 422);
        }

        // Explicit lookup (not implicit binding) so unknown ids return a
        // generic 404 without disclosing model internals.
        $certificate = Certificate::where('id', $certificateId)->first();

        if ($certificate === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return DB::transaction(function () use ($certificate, $request, $reason) {
            $locked = Certificate::where('id', $certificate->id)->lockForUpdate()->first();

            if ($locked === null) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            if ($locked->isRevoked()) {
                return response()->json([
                    'message' => 'Certificate already revoked.',
                    'certificate' => $this->revocationPayload($locked),
                ]);
            }

            $old = $locked->toArray();

            $locked->fill([
                'status' => Certificate::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => (int) $request->user()->id,
                'revocation_reason' => $reason,
            ]);
            $locked->save();

            // Immutable audit event via the existing mechanism: safe
            // operational fields only (no secrets, payloads, or PII beyond
            // the internal certificate identity already stored).
            AuditLog::log('certificate_revoked', $locked, $old, [
                'certificate_id' => $locked->id,
                'certificate_code' => $locked->certificate_code,
                'user_id' => $locked->user_id,
                'course_id' => $locked->course_id,
                'previous_status' => $old['status'] ?? Certificate::STATUS_ACTIVE,
                'new_status' => Certificate::STATUS_REVOKED,
                'revoked_by' => (int) $request->user()->id,
                'revocation_reason' => $reason,
                'revoked_at' => $locked->revoked_at?->toISOString(),
            ]);

            return response()->json([
                'message' => 'Certificate revoked.',
                'certificate' => $this->revocationPayload($locked->fresh() ?? $locked),
            ]);
        });
    }

    /**
     * Admin listing/detail representation (safe fields only: no pdf_path,
     * audit metadata, or storage internals).
     */
    private function adminCertificatePayload(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'certificate_code' => $certificate->certificate_code,
            'status' => $certificate->status ?? Certificate::STATUS_ACTIVE,
            'issued_at' => $certificate->issued_at?->toISOString(),
            'revoked_at' => $certificate->revoked_at?->toISOString(),
            'revoked_by' => $certificate->revoked_by,
            'revocation_reason' => $certificate->revocation_reason,
            'user' => $certificate->user ? $certificate->user->only(['id', 'name', 'email']) : null,
            'course' => $certificate->course ? $certificate->course->only(['id', 'title']) : null,
            'created_at' => $certificate->created_at?->toISOString(),
        ];
    }

    /**
     * Admin revocation representation (no internal audit metadata).
     */
    private function revocationPayload(Certificate $certificate): array
    {
        return [
            'certificate_code' => $certificate->certificate_code,
            'status' => $certificate->status,
            'revoked_at' => $certificate->revoked_at?->toISOString(),
            'revoked_by' => $certificate->revoked_by,
            'revocation_reason' => $certificate->revocation_reason,
        ];
    }

    /**
     * Public-safe certificate payload (never includes user email).
     */
    private function payload(Certificate $certificate): array
    {
        $certificate->loadMissing(['course', 'user:id,name']);
        $path = $certificate->pdf_path ?? '';
        $disk = Storage::disk((new CertificatePdfService())->disk());

        return [
            'certificate_code' => $certificate->certificate_code,
            'status' => $certificate->status ?? Certificate::STATUS_ACTIVE,
            'issued_at' => $certificate->issued_at?->toISOString(),
            'revoked_at' => $certificate->revoked_at?->toISOString(),
            'recipient_name' => $certificate->user?->name,
            'course_title' => $certificate->course?->title,
            'instructor' => $certificate->course?->instructor,
            'has_pdf' => $path !== '' && $disk->exists($path),
        ];
    }
}