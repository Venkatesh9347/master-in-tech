# MasterInTech — End-to-End Readiness

**Branch:** `integration/master-intech-complete` · **Latest committed HEAD:** `94013b1bf33d4f9d594a20a46db45d14408b79ee` ("feat: add new enquiry first follow-up automation"). Checkpoint chain P1-A → P1-C → P1-E → P1-F → P1-G → P1-B → P2-1 (see §1c) plus, since `1a71b84`: API exception hardening, migration compatibility hardening, the Admin Refund Console + Admin Certificate Revocation Console (backend read endpoints + frontend desks), the AI-0 foundation incl. legacy-chat kill-switch remediation, the DB portability chain (migration compatibility, boolean/raw-SQL, webhook ledger, event-status and classroom room-name fixes), and Phase 9 outbound webhooks + domain event bus + CRM automation (C1/C2/D1) — all committed. No production infrastructure exists; no production deployment has occurred. P12 is PARTIAL; P13 is NOT STARTED.
**Scope:** Repository-only readiness. This document records what is **verified in-repo**, what is **pending**, and the **external/deployment blockers** that cannot be resolved inside this repository (no credentials/infrastructure provisioned here).

> Security/correctness posture: every P0/P1 finding remediated in-repo is applied, and the S-01/S-02A/S-03/S-04 + Admin-Exception-Disclosure + NEW-SEC-01/02/03 remediation queue is complete and verified (see §7). Test suite is green. No `.env`/real secrets are committed (all `.env` files are gitignored and untracked).

---

## 1. Verified in-repo items (FIXED)

Automated evidence (latest verified working-tree baseline): **backend** `php artisan test` → **1079 tests / 5967 assertions, 0 failures, 0 errors**; **frontend** `vitest run` → **117 tests / 18 files, 117 passed**, `npx tsc -b` → clean (exit 0), `npm run lint` → 0 errors (12 pre-existing warnings in `src/`), `npm run build` → succeeds. Exact-SHA CI for the current HEAD has not been independently observed from this environment; remote workflow results must be checked before any release.

Scope of the counts above: they are **whole-working-tree totals**, not the delta of any single commit or change set. Test files accumulate across many commits, so these figures must not be read as "what one change added". The backend figure is the PHPUnit-reported runtime count (1079); it exceeds a naive static count of test *methods* because a `#[DataProvider]` test expands one method into several runtime cases. The frontend figure is the 117 `it()`/`test()` cases Vitest reports across the 18 files matching `src/**/*.test.{ts,tsx}`.

| Area | Finding | Fix (file) |
|------|---------|-----------|
| **P0 · Debug mode** | `APP_DEBUG` left on | `.env.example` sets `APP_DEBUG=false` |
| **P0 · Session hardening** | Secure cookie not guaranteed | `.env.example` → `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax` |
| **P0 · Token expiry** | Sanctum tokens unbounded | `config/sanctum.php` → `expiration` = `720m`; `.env.example` → `SANCTUM_TOKEN_EXPIRATION=720` |
| **P0 · CORS `*`** | Wide-open CORS on video key/segment endpoints | `VideoPlaybackController` → `corsHeaders()` allow-list (replaces `*`) |
| **P0 · Corporate partner pw** | Random password + default all-same | `AdminCorporatePartnerController:63` random-password fallback |
| **P0 · LiveKit defaults** | Published `devkey`/`secret`/URL baked into config | `config/services.php` defaults stripped |
| **P0 · OTP storage** | Plaintext temp/hash fields exposed | `StudentLoginOtp` → `$hidden` (`otp_hash`, `temp_token_hash`) |
| **P0 · JSON error leak** | HTML error page returned to API | `bootstrap/app.php` JSON exception handler |
| **P0 · Seeders** | Live/production creds in seeders | seeders env-driven (`SEED_DEFAULT_PASSWORD`, `SEED_LIVE_CLASS_PASSCODE`) |
| **P0 · Admin UI defaults** | Placeholder/sample records in admin UI | `AdminEnquiries.tsx`, `AdminPlacements.tsx` empty defaults |
| **P1 · Rate limiting** | Public + auth endpoints unthrottled | `routes/api.php` throttles (`public-submission`, `auth-forgot`/`auth-reset`); `AppServiceProvider` defines limiter |
| **P1 · Security headers** | Missing baseline headers | NEW `app/Http/Middleware/SecurityHeaders.php`: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `X-XSS-Protection: 0`, `Strict-Transport-Security` (registered globally) |
| **P1 · Cross-course Q&A** | Unenrolled student could post in any course discussion | `LessonDiscussionController` → `authorizeCourseAccess()` on index/store/reply; new test added |
| **P1 · Role redirect** | Non-students reached student dashboard | `StudentRoute.tsx` → company/recruiter→`/company`, non-student→`/login` |
| **P1 · Mass assignment** | `permissions` field fillable | `User.php` removed `permissions` from `#[Fillable]` |
| **P1 · Duplicate column** | `ClassSession` duplicate `current_host_id` | removed |
| **P1 · Dead route** | Unrouted/unused `adminEnroll()` | removed from `EnrollmentController` |
| **Secrets in API serialization** | `current_session_id` / `google_id` / `current_session_created_at` leaked in `/user` | `User.php` `#[Hidden]` now includes those fields |
| **Bloat · presigned heartbeat** | Frontend polled `/user` every 2s (constant load) | `AuthContext.tsx` heartbeat `2000` → `30000` ms |
| **Type drift** | `UserRole` union missing backend roles | `auth-context.ts` union expanded (`super_admin|faculty|company|recruiter|counsellor`) |
| **Duplicate routes** | Auth endpoints registered twice (first-match-wins) | `routes/api.php` duplicates removed |
| **CORS preflight** | `max_age=0` forced re-preflight every call | `config/cors.php` → `max_age=7200` |
| **LiveKit fallback secrets** | `LiveKitTokenService` silently used published `'devkey'`/`'secret'`/`example.com` when env missing | fallbacks removed (fail-loud); `phpunit.xml` sets explicit **test-only** livekit values |
| **Weak admin-set password** | `enroll()` accepted `min:6` + redundant weak-password list | `EnquiryController` → `min:8`, deduped/expanded deny-list |

### Preserved (verified non-regression)
- Payment **idempotency**: provider idempotency-key ledger with unique `(provider, idempotency_key)`; webhook event ledger dedupes replays (`PaymentService`, `PaymentWebhookController`).
- Payment webhook **signature verification**: HMAC-SHA256 verification enforced; fail-closed in production (`RazorpayProvider`, `PaymentService::ensureProductionPaymentConfigured`).
- **Health checks**: `/api/health` (`api.php:75`) and `/up` (`bootstrap/app.php:12`) intact.
- `SendOtpEmailJob`, all seeders, all P0/P1 fixes.

## 1b. Security remediation queue (CLOSED — verified in-repo)

| ID | Finding | Fix (file) + regression test |
|----|---------|------------------------------|
| **S-01** | Verbose API exception disclosure | `bootstrap/app.php` JSON sanitizer; `ApiExceptionSanitizationTest` |
| **S-02A** | LiveKit webhook trust | JWT claims + sha256 body binding; `LiveKitWebhookTest` |
| **S-03** | HLS session replay after logout/revocation | logout + enrollment-revocation invalidation, AES-key enrollment re-check (`AuthController`, `AdminEnrollmentController`, `VideoPlaybackController`); 19 tests in `RecordedVideoSecurityTest` |
| **S-04** | HLS segment path traversal | strict segment allowlist in `LocalHlsAes128Driver` (inherited by `S3HlsDriver`); 10 tests in `RecordedVideoSecurityTest` |
| **Disclosure follow-up** | Raw `$e->getMessage()` in 3 transactional 500s | fixed generic 500s + server-side `Log::error` (`AdminCrm/AdminEnrollment/EnquiryController`); `AdminExceptionDisclosureTest` (3 tests) |
| **NEW-SEC-01** | Media-upload folder traversal + SVG/public-disk handling | `alpha_dash` folder rules, SVG removed from upload mimes, content-sniffed extensions (`AdminCmsController`); `MediaUploadPathSecurityTest` (18 tests) |
| **NEW-SEC-02** | Unthrottled authenticated write/token endpoints | 4 named per-user limiters (`AppServiceProvider`), 15 throttled routes (`routes/api.php`); `RateLimitEnforcementTest` (6 tests) |
| **NEW-SEC-03** | Login account-enumeration oracle | unified generic 422 for bad-credentials/blocked/unapproved states (`AuthController::login`); `LoginEnumerationTest` (5 tests) |

---

## 1c. Later checkpoints (verified, newest first)

| Checkpoint | Scope | Evidence |
|---|---|---|
| AI-0 foundation + legacy-chat kill switch (committed) | provider gateway (OpenAI/Gemini/Anthropic/Ollama/Stub), usage/cost tracking, admin AI status; legacy `/api/ai/chat` gated with single usage row | `AiGatewayTest` (28 tests) + `AiChatTest` kill-switch/usage additions (4 tests); full suite green |
| P2 certificate console (committed) | admin certificate list/detail read endpoints + revocation desk UI | `AdminCertificateListTest` (10 tests) + `AdminCertificates.test.tsx` (12 tests); full suite green |
| P2 refund console (committed) | admin payment list/detail read endpoints + refund desk UI | `AdminPaymentListTest` + `AdminRefunds.test.tsx`; full suite green |
| API exception hardening | sanitized API error envelope independent of `APP_DEBUG` | committed HEAD `58a5faa`; full suite green |
| Migration compatibility | course_id migration legacy-compatible | committed `c333551`; full suite green |
| P2-1 storage hardening | legacy materials migration command, orphan HLS-dir reaper, live-chat 150 cap | 17 focused tests; full suite green |
| P1-B pagination | server-paginated high-growth admin lists + shared pager | `PaginationTest` (20 tests); full suite green |
| P1-G revoked certificates | active-only certificate counting in mock eligibility | 8 focused tests; full suite green |
| P1-F eligibility pagination | roster pagination + batched N+1 + exact filtered pagination | 19 + 11 + 4 tests; full suite green |
| P1-E assignment pagination | submissions index pagination | 12 + 4 tests; full suite green |
| P1-C certificate revocation | active → revoked, verification/download guards, audit | 18 tests; full suite green |
| P1-A outbound refunds | provider abstraction, idempotency, reconciliation, admin endpoint | 27 tests; full suite green |

## 1d. Product decision required (NOT implemented)

**Course prerequisites — PRODUCT DECISION REQUIRED.** `courses.prerequisites`
is free-text/JSON marketing data only; the repository defines no
course-to-course prerequisite relationship, no ALL-vs-ANY semantics, no
prerequisite completion rule, and no administrator override behavior.
Do not implement prerequisite enforcement speculatively.

## 2. Classification: NOT-A-REAL-ISSUE / BY-DESIGN (no change)

| Item | Ruling |
|------|--------|
| "`.env` committed with secrets" | **False** — all `.env` untracked + gitignored (`backend/.gitignore:3`, `frontend/.gitignore:2`) |
| `User → role/company_id/status` fillable | Keep — every write is a validated whitelist (no `$request->all()`); removal breaks provisioning |
| `PlacementApplication → status/admin_notes` fillable | Intentional, validated + scoped to owning company (review feature) |
| `meeting_password`, `MockInterviewer → internal_notes`, `AuditLog values` | Intentional / column-selected / write-only |
| Video HLS routes missing `auth:sanctum` | **By design** — hls.js can't attach bearer; short-lived HMAC `?token=` gates key/segment; playback-auth POST is `auth:sanctum` |
| `SESSION_ENCRYPT=false` | Encrypts cookie payload, not DB; no DB-at-rest read — not a vuln |
| Duplicate host columns on `ClassSession` | Deferred schema consolidation; not a security issue |

---

## 3. Pending / partially deferred (in-repo optional)
- **CSP (Content-Security-Policy):** intentionally not added — high risk of breaking the SPA/livekit classroom; avoid inventing a report-uri/monitoring endpoint. Revisit when the app is hardened behind a CDN/WAF.
- **httpOnly cookie + CSRF rework for API auth:** deferred by design (P0-8). Current bearer-token-in-storage works; cookie auth is a larger architecture change.
- **Schema consolidation** (legacy dual host/meeting columns): deferred to a dedicated migration, needs a test-covered release.

---

## 4. External / deployment blockers (NOT provisionable in-repo)

These require real infrastructure and credential provisioning — deliberately **not** invented or stubbed in this repo. They must be supplied at deploy time:

| Blocker | Required values | Where to set |
|---------|-----------------|--------------|
| **LiveKit** (realtime classroom) | `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` | backend `.env` (production) |
| **Razorpay** (payments) | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` | backend `.env` (production) + `PAYMENT_PROVIDER=razorpay`; webhook signature verification, idempotency ledger, and amount binding already implemented in code |
| **SMTP / email** (OTP, session invites) | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | backend `.env` (production) |
| **Local AI model inference** (Ollama) | `AI_PROVIDER=ollama`, `OLLAMA_BASE_URL`, `OLLAMA_MODEL` (default `http://localhost:11434`) | backend `.env` (provider implemented: `OllamaProvider` + `config/ai.php` entries) |
| **Google OAuth** | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | backend `.env` + frontend `VITE_GOOGLE_CLIENT_ID` |
| **Frontend build origin** | `VITE_API_URL` must be **HTTPS** API origin in production | frontend `.env.production` (not committed) |
| **CORS production origins** | `CORS_ALLOWED_ORIGINS` → real frontend domain(,s) | backend `.env` |
| **Prod TLS/cookies** | `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_TOKEN_EXPIRATION=720`, force HTTPS (HSTS activates over TLS) | backend `.env` + server/proxy config |
| **Object storage / DRM** | `AWS_*` (or Mux `MUX_*`) for commercial video DRM; `VIDEO_SECURITY_DRIVER=mux` | backend `.env` |
| **Redis (queue/cache)** | `REDIS_CLIENT=phpredis`, `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`; switch `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` | backend `.env` (optional for single-node) |
| **Credential rotation** | Any previously-exposed local secrets should be rotated in the real credential system | operations |

> **No secrets are committed.** The `.env.example` files contain **blank placeholders** only, so the above can be provisioned without leaking real values.

---

## 5. Sanitized env inventory (`.env.example`)

**Backend** (`backend/.env.example`) — blank/placeholder for all required keys:
`APP_KEY`, `FRONTEND_URL`, DB (`DB_*`), Session/cookie, **Redis** (`REDIS_*`), **SMTP** (`MAIL_*`), AWS/Mux DRM, **Google OAuth**, OTP/Sanctum/CORS, **LiveKit** (`LIVEKIT_*`), **AI** (`OPENAI_*`) + **Ollama** (`OLLAMA_*`), **Razorpay** (`RAZORPAY_*`), seed-only vars.

**Frontend** (`frontend/.env.example`) — `VITE_API_URL`, `VITE_GOOGLE_CLIENT_ID` (public VITE vars only; backend secrets never live here).

---

## 6. Reproduction
```bash
# backend
cd backend
composer install
php artisan migrate:fresh --seed
php vendor/bin/phpunit            # expect 1079 tests / 5967 assertions, 0 failures, 0 errors

# frontend
cd ../frontend
npm install
npx tsc --noEmit                  # expect exit 0
npx vitest run                    # expect 117 tests / 18 files, 117 passed
npm run dev
```

The counts in this block are whole-tree totals, not a per-commit delta (see the scope note in §1).

---

## 7. Pre-infrastructure closure (AUDIT-02 onward, documentation only)

Product decisions recorded so follow-up work does not re-litigate or
speculatively implement them. No code was changed for this section,
except F-03, whose remediation has since landed and is recorded below.

### F-I — admin course-provisioning UI: NOT REQUIRED
No route, test, or requirements document establishes an admin course
create/update/delete UI. Course provisioning is covered by the tutor
authoring workflow (`TutorCourses` → `POST /tutor/courses`, ownership
scoped) plus the existing admin API (`POST/PUT/DELETE /api/courses`,
admin-gated, tested). Do not invent an admin course desk without an
explicit product requirement.

### F-03 — tutor/admin rating fallbacks: proven defect class, REMEDIATED in this working tree
The fabricated rating fallbacks described here have been removed; `average_rating`
is now genuinely `null` where no reviews exist. Backend and frontend were changed
together, as this section specified:

| Location | Was | Now |
|---|---|---|
| `TutorController.php:47` (`stats` aggregate) | `?? 5.0` | `null` when no reviews |
| `TutorController.php:226` (`courseAnalytics`) | `: 5.0` | `: null` |
| `AdminDashboardController.php:109` (course overview) | `: 4.9` | `: null` |
| `TutorDashboard.tsx` (type + seeded state) | `number`; `average_rating: 5.0` | `number \| null`; `average_rating: null` |
| `TutorCourseAnalytics.tsx` (metric card + feedback header) | `number`; rendered a star rating unconditionally | `number \| null`; renders an explicit "No ratings yet" neutral state |

`Dashboard.tsx` carries the matching `number | null` type widening. `CourseReviewController`
follows the same rule (`null` instead of a fabricated `5.0`), which remains the reference
pattern this change brought the tutor/admin paths into parity with; its `store` action
likewise reports the real `201`/`200` outcome rather than a constant `201`.

This visibly alters tutor/admin metrics — unreviewed courses previously displayed a
perfect score and now display no rating. That display change is the intended outcome,
not a regression. Coverage: `TutorAndRoleSecurityTest` (tutor stats + per-course
analytics, with and without reviews), `AdminDashboardTest` (course overview),
`StudentLearningAndProgressTest` (empty catalog), and `TutorCourseAnalytics.test.tsx`
(null-state and rated rendering).

### Video strategy — pilot: local disk + FFmpeg + HLS
The pilot runs the local filesystem video pipeline (`VIDEO_SECURITY_DRIVER=local_hls`,
`VIDEO_STORAGE_DISK=local`, FFmpeg/FFprobe on PATH, AES-128 HLS with
tokenized playback + watermarking). S3-compatible object storage is a
later scalability migration: `S3HlsDriver` exists but the transcode job
aborts on non-local disks, and the Mux driver is an unprovisioned stub.
Do not select Mux or S3-backed transcoding without pipeline work.

### DNS rebinding — accepted residual
`WebhookDispatcherService::isAllowedTargetUrl` blocks literal
loopback/private/link-local/reserved targets and never follows
redirects; hostname→internal-IP rebinding after validation is
explicitly out of scope by design (code comment). Accepted: admin-only
subscription surface + short-lived signed deliveries bound this residual.

### VITE_APP_NAME — unused, left in place
`VITE_APP_NAME` appears only in `backend/.env.example:271` (plus the
`backend-incomplete/` mirror). No backend `env('VITE_*')` read and no
frontend reference exist. Disposition: report-only; the line is left
untouched so unknown external tooling does not break.

### Mailpit vs Gmail SMTP — two dev paths, one production rule
`docker-compose.yml` provides Mailpit (`MAIL_HOST=mailpit`, port 1025)
for containerized development; the local `.env` uses real Gmail SMTP.
Both are development configurations. Production requires a real SMTP
credential plus a verified `MAIL_FROM_ADDRESS`; nothing in code assumes
either dev path.
