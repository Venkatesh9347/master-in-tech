# MasterInTech — End-to-End Readiness

**Branch:** `integration/master-intech-complete` · **Release HEAD:** `1a71b84` ("chore: harden legacy materials and video storage"; checkpoint chain P1-A → P1-C → P1-E → P1-F → P1-G → P1-B → P2-1, see §1c)
**Scope:** Repository-only readiness. This document records what is **verified in-repo**, what is **pending**, and the **external/deployment blockers** that cannot be resolved inside this repository (no credentials/infrastructure provisioned here).

> Security/correctness posture: every P0/P1 finding remediated in-repo is applied, and the S-01/S-02A/S-03/S-04 + Admin-Exception-Disclosure + NEW-SEC-01/02/03 remediation queue is complete and verified (see §7). Test suite is green. No `.env`/real secrets are committed (all `.env` files are gitignored and untracked).

---

## 1. Verified in-repo items (FIXED)

Automated evidence (latest verified repository baseline): **backend** `phpunit` → **909 tests / 5240 assertions passing**; **frontend** `vitest run` → **73 tests / 11 files passing**, `tsc -b` → clean, `vite build` → succeeds.

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
| **Local AI model inference** (Ollama) | `AI_PROVIDER=ollama`, `OLLAMA_BASE_URL`, `OLLAMA_MODEL` (default `http://localhost:11434`) | backend `.env`; add `ollama` provider to `AiOrchestratorService`/`config/ai.php` |
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
php vendor/bin/phpunit            # expect 909 tests / 5186 assertions

# frontend
cd ../frontend
npm install
npx tsc --noEmit                  # expect exit 0
npx vitest run                    # expect 73 tests / 11 files passing
npm run dev
```
