# MASTERINTECH 10X FULL BUSINESS E2E REPORT

**E2E ID:** `MIT-E2E-10X-20260928-050527`
**Test identity:** MIT E2E 10X Student — `mit.e2e.10x.21020260928050527@example.test` / `9928044326` (unique, test-only)

---

## Environment

| Item | Value |
|---|---|
| Frontend URL | `http://localhost:5173` (Vite dev server) |
| Backend URL | `http://127.0.0.1:8001/api` (Laravel 11, SQLite, PHP 8.3.30) |
| Frontend framework | React 19 + Vite 8 + react-router-dom 7 |
| Browser | Chromium — **HEADED** (`headless: false`, `slowMo: 0`) |
| Playwright version | 1.63.0 |
| Recording | `video: on` (640×360, 6 files / 8.3 MB), `trace: retain-on-failure` |
| Date/time | 2026-09-28 05:05 → 05:09 local |
| Node / driver | Node v24.19.0 |
| Auth used | `admin@example.com` (seeded demo admin) |

**Backend was not running at test start.** It was started as a dev server only:
`php -S 127.0.0.1:8001 -t public public/index.php` with `PHP_CLI_SERVER_WORKERS=3`.
No production service, no `.env` change, no config change.

---

## Business Journey

| # | Stage | Result | Evidence |
|---|-------|--------|----------|
| 1 | Public home | **PASS** | `01-public-home.png` — HTTP 200, 8 nav links, `/courses` link + "Enquire Now" CTA |
| 2 | Course catalog | **PASS** | `02a-course-catalog.png`, `02-course.png` — 107 course cards; search "Cyber Security" → 2 results |
| 3 | Course detail | **PASS** | `03-course.png` — "Cyber Security Fundamentals", 6 Weeks / 12 Lessons, curriculum modules, Enquire + Enroll CTAs |
| 4 | Lead (public enquiry) | **PASS** | `04-lead-created.png` — `POST /api/enquiries` **HTTP 201**, "Enquiry Received!" |
| 5 | CRM lead | **PASS** | `05-crm-lead.png` — lead visible with name/email/phone/course/source/status (UI-proven) |
| 6 | Telecaller | **BLOCKED** | `06-telecaller.png` — workflow executed (call + follow-up + stage change all recorded), but no `telecaller` account exists |
| 7 | Course advisor | **BLOCKED** | `07-advisor.png` — role implemented in code, but no `course_advisor` account provisioned |
| 8 | Enrollment | **PASS** | `08-enrollment.png` — CRM convert HTTP 200 → student 22 (STU-1022), enrollment 16, course + batch linked |
| 9 | Batch | **BLOCKED** | `09-enrollment-batch.png`, `09b-enrollment-activation.png` — **PAYMENT ENVIRONMENT BLOCKED**, enrollment stays `pending` |
| 10 | Student login | **PASS** | `10-student-dashboard.png` — authenticated; password set via admin UI (HTTP 200) |
| 11 | Single active session | **PASS** | `11-single-session.png` — newer session invalidated the older one (`/login?session_expired=1`, old token → HTTP 401) |
| 12 | Learning | **BLOCKED** | `12-learning.png` — `GET /api/courses/4/lms-progress` **HTTP 403** `enrollment_required: true` |
| 13 | Video | **BLOCKED** | `13-video.png` — lesson player unreachable (same 403) |
| 14 | Progress | **BLOCKED** | `14-progress.png` — no lesson can be completed |
| 15 | Completion | **BLOCKED** | `15-completion.png` — completion path unreachable |
| 16 | Certificate | **BLOCKED** | `16-certificate.png` — no completed course → not certificate-eligible |
| 17 | Placement profile | **PASS** | `17-placement-profile.png`, `17b-placement-profile-saved.png` — profile loaded, saved (HTTP 200) |
| 18 | Job | **PASS** | `18-job.png`, `18b-job-detail.png` — "E2E Junior Security Analyst" (id 1) with Apply + eligibility |
| 19 | Application | **BLOCKED** | `19-application.png`, `19b-mock-interview-eligibility.png` — `POST .../apply` **HTTP 403 `MOCK_INTERVIEW_REQUIRED`** |
| 20 | Placement admin | **BLOCKED** | `20-placement-admin.png` — admin applications table loaded, but no application record exists |
| 21 | Final job state | **BLOCKED** | `21-final-state.png` — no application to transition; `selected` is the furthest supported state |

**Totals: 10 PASS · 11 BLOCKED · 0 FAIL**

---

## First Failure

There is **no application failure**. The first and only blocker is environmental/business-gating:

```
stage:    6 — Telecaller (dedicated account)
URL:      http://localhost:5173/login
expected: a `telecaller`-role account can authenticate and work the lead
actual:   login did not authenticate
evidence: GET /api/admin/users lists 7 users — roles present are
          admin(3), student(3), tutor(1). No telecaller / course_advisor /
          counsellor account exists. GET /api/admin/crm/counsellors returns
          admins only.
```

The first **functional** blocker (the one that gates the rest of the journey) is:

```
stage:    9 — Batch / enrollment activation
URL:      http://localhost:5173/admin/enrollments
expected: enrollment reaches status "active" and grants classroom access
actual:   enrollment 16 remains "pending"
evidence: EnrollmentPaymentGate::resolveStatus() requires either
          (a) PaymentTransaction(user_id, course_id, status='paid') — i.e. a real
              Razorpay payment, or
          (b) an explicit `override_reason` of >= 10 chars from an admin.
          AdminEnrollmentController::update() accepts override_reason, BUT the
          AdminEnrollments React page renders no override_reason field, so there
          is NO UI path to activate. Razorpay was NOT activated and no payment
          was faked.
```

## Last Successful Stage

**Stage 18 — Job discovery.** Also fully verified: stages 1–5, 8, 10, 11, 17.

## Root Evidence

A single coherent causal chain blocks stages 9 and 12–21:

```
Razorpay not activated in this environment
  → EnrollmentPaymentGate denies "active" (no verified PaymentTransaction)
  → enrollment 16 stays "pending"
  → LessonController:49-59 requires status in (active, completed) → HTTP 403
  → no lesson can be opened, watched, completed, or counted toward progress
  → course never completes → no certificate eligibility
  → MockInterviewService::isStudentPlacementEligible() requires
    "Student must complete 100% of lessons in at least one enrolled course"
  → PlacementPortalController::apply() returns HTTP 403 MOCK_INTERVIEW_REQUIRED
  → no application record → nothing for placement admin or the final state to act on
```

The API states the reason verbatim:
`GET /api/admin/mock-interviews/eligibility` →
`"reasons": ["Student must complete 100% of lessons in at least one enrolled course to qualify."]`,
`courses[0] = {status: "pending", total_lessons: 12, completed_lessons: 0, progress_percentage: 0}`.

## Dependent Stages Blocked

`9, 12, 13, 14, 15, 16, 19, 20, 21` — all downstream of enrollment activation.
`6, 7` — blocked by missing role accounts, independent of payment.

---

## Error Summary

| Class | Count | Detail |
|---|---|---|
| HTTP 5xx | **0** | none observed |
| HTTP 4xx | 5 | all **expected business rules**, listed below |
| Uncaught page errors | **0** | — |
| `console.error` | 5 | all are the browser's own "Failed to load resource" lines for the 4xx/5xx above |
| Failed network requests | 1 | `images.unsplash.com/... ?ad99a` → `net::ERR_BLOCKED_BY_ORB` (third-party CDN Opaque Response Blocking) |
| Playwright assertion failures | **0** | — |

Every 4xx, with meaning:

| Status | Request | Meaning |
|---|---|---|
| 401 | `GET /api/user` (old student context) | **Expected behaviour** — proves single-session invalidation |
| 403 | `GET /api/courses/4/lms-progress` | Pay-before-classroom gate, `enrollment_required: true` |
| 403 | `GET /api/courses/4/lms-progress` (video stage) | Same gate |
| 403 | `GET /api/placements/profile-prefill` | "Placement Dashboard is not activated for your account." |
| 403 | `POST /api/placements/opportunities/1/apply` | `MOCK_INTERVIEW_REQUIRED` — placement eligibility prerequisite |

**No 5xx, no uncaught exceptions, no frontend crashes were observed in the final run.**

---

## Environment Blockers

1. **PAYMENT ENVIRONMENT BLOCKED — Razorpay not activated.** The only legitimate route to an `active` enrollment is a verified `PaymentTransaction` or an admin `override_reason`; the admin UI exposes neither. Razorpay was **not** activated. *(Stage 9)*
2. **No `telecaller` account.** The role is fully implemented (`User::canAccessCrm`, `Enquiry::scopeVisibleTo`, `EnsureUserCanAccessCrm`, `Login.tsx` redirect, `DatabaseSeeder` creates `telecaller1@example.com`), but no such user exists in this database and the documented seed password is rejected (HTTP 422, deliberately normalized). The telecaller **workflow** was still exercised end-to-end with the admin account. *(Stage 6)*
3. **No `course_advisor` account.** Same situation. Per-role lead scoping could not be verified. *(Stage 7)*
4. **No admin UI for the placement eligibility override.** `POST /api/admin/mock-interviews/eligibility-override` exists in the backend but has **no frontend caller** — grep across `frontend/src` returns nothing. Without a real mock-interview evaluation, placement application is unreachable. *(Stage 19)*
5. **Dev-server instability (resolved before the recorded run).** `php artisan serve` (default file-watching, single worker) crashed repeatedly mid-run with `ERR_CONNECTION_RESET` / `ERR_CONNECTION_REFUSED`, which made the course-detail page intermittently render "Course Unavailable". The recorded run used `php -S --no-reload` semantics with 3 workers, after which `/api/courses/4` returned 200 on 6/6 attempts. This is a **local dev-tooling** limitation, not an application defect.
6. **Host memory pressure.** 8 GB total with a competing desktop browser left ~1.4–2.3 GB free. Chromium was OOM-killed twice during early harness iterations before the footprint was reduced (1280×720 viewport, 640×360 video).

## Application Defects

1. **`override_reason` is implemented in the API but absent from the admin UI.** `AdminEnrollmentController::update()` accepts `override_reason` (min 10 chars) and `EnrollmentPaymentGate::isOverrideActor()` explicitly permits admins to use it, but `frontend/src/pages/admin/AdminEnrollments.tsx` renders no such field. Result: an emergency administrative admission is impossible through the product. **Severity: high operational impact** (no legitimate way to admit a student whose payment failed).
2. **No UI for the placement eligibility override.** `AdminMockInterviewController::overrideEligibility()` has no frontend caller, so the "administrator activation" half of the placement gate is unreachable. **Severity: medium.**
3. **Placement apply rejects students whose course is not 100 % complete, but `/placements` is fully reachable for them.** The student sees "Apply Now", completes the whole form, and only then receives `403 MOCK_INTERVIEW_REQUIRED`. The `Placements.tsx` UI reads `mock_interview_required` from `/placements/settings` but does not use the per-student eligibility from `/placements/profile-prefill` to pre-disable the button or explain the requirement. **Severity: medium (UX).**
4. **The public enquiry form has no message field, and the two public enquiry forms disagree on phone format.** `PublicAccessGateModal` (course page, catalog, home) offers only Full Name / Email / Mobile / Program and hardcodes `message: 'Course admission enquiry'`. The `EnquiryModal` component — which *does* have a free-text message and a `preferred_time` selector — is only reachable from inside `StudentLessons.tsx`, i.e. never from the public site. Additionally `PublicAccessGateModal`'s placeholder is `"e.g. 9876543210"` (10 digits) while `EnquiryModal`'s is `"+91 98765 43210"`; the stricter regex `^(\+?\d{1,3})?[6-9]\d{9}$|^\d{10,12}$` rejects a `+91`-prefixed 13-digit number, so a user copying the other form's example gets "Please enter a valid 10-digit mobile number." **Severity: low (verified as correct validation, inconsistent guidance).**
5. **Performance: the public catalog takes ~3.1 s to render and admin consoles 3–4 s to become interactive** under the dev API (measured repeatedly, see Runtime). Single API calls take 0.5–3.5 s. This is far above a usable budget and forced a real transport retry during the enquiry POST in 3 of 7 runs. **Severity: medium.**
6. **The CRM lead drawer's overlay makes the row-level Convert button permanently unclickable while the drawer is open.** Playwright recorded `<div class="fixed inset-0 z-50">` intercepting pointer events over the identical `title="Convert Lead to Enrolled LMS Student"` control in the table. Arguably correct modal behaviour, but the duplicated control in an occluded layer is a trap for both users and automation. **Severity: low.**

### Correctly-implemented behaviour verified (not defects)

- `single.session` middleware: a newer login invalidates the older session (`/login?session_expired=1`, old bearer → 401). **Expected.**
- Pay-before-classroom gate, CORS preflight (204 with correct `Access-Control-Allow-*`), Sanctum auth, public registration correctly disabled (403), deliberate credential-error normalization (422), duplicate-enquiry protection, client-side phone validation.

---

## Business Data

All IDs were read from the UI/network responses only. No database queries were used to prove any stage, and no password or secret is recorded.

| Entity | Value |
|---|---|
| E2E ID | `MIT-E2E-10X-20260928-050527` |
| Lead ID | **20** (`POST /api/enquiries` → HTTP 201) |
| Student ID | **22** — public id `STU-1022` |
| Course ID | **4** — "Cyber Security Fundamentals" (published, 6 Weeks, 12 Lessons) |
| Enrollment ID | **16** — status **`pending`** (pay-before-classroom gate) |
| Batch ID | **3** — `RIT(CSF)BC051026` (course 4) |
| Certificate ID | **none** — not eligible (no completed course) |
| Job / Opportunity ID | **1** — "E2E Junior Security Analyst" (published) |
| Application ID | **none** — rejected `403 MOCK_INTERVIEW_REQUIRED` |

Recorded CRM activity for the lead (from the drawer timeline): call logged (`INTERESTED`), follow-up task created for `2026-10-06 11:30`, automated first follow-up, stage → `contacted`, priority → `hot`, source `WEBSITE`, payment status `UNPAID $0.00`.

---

## Runtime

| Metric | Value |
|---|---|
| Total execution time | **211 s** (3 m 31 s) for 21 sequential business stages |
| Approximate speed target | ~10× human speed, `slowMo: 0`, zero `waitForTimeout` calls |
| Actual speed | **Not 10×.** The run is bounded by application latency, not interaction: 36 UI actions, **353 API requests**, 6 browser contexts, and median page readiness of ~1.9 s. Measured readiness: catalog cards 3129 ms · admin login 4075 ms · `/admin/placements` 4400 ms · `/student/profile` 27250 ms (full stage) · placement profile save 26.7 s. |
| Actions / pages / contexts | 36 · 6 · 6 |
| HTTP requests | 353 (5 × 4xx, 0 × 5xx) |
| Playwright assertion failures | 0 |

**Note on speed:** `slowMo: 0` and event-driven waits were used throughout, but the dominant cost is the application itself. A human clicking through this journey would wait far longer per *interaction*; the test removed all avoidable waiting yet still took 3.5 minutes because ~70 % of it is server render time. Claiming 10× would be false.

### Measured application latency (final run, worst offenders)

| Surface | Ready in |
|---|---|
| `home` DOM → interactive | 438 ms |
| `course detail` content | 1891 ms |
| `catalog` course cards rendered | 3129 ms |
| admin login → `/admin` | 4075 ms |
| `/admin/crm` search ready | 1883 ms |
| `/admin/placements` search ready | 4400 ms |
| student login → `/student` | 4075 ms |
| `POST /api/enquiries` | 1279 ms (1 attempt) |
| `/placements` profile save stage | 27250 ms |

---

## Defect Evidence Index

| Screenshot | Shows |
|---|---|
| `01-public-home.png` | public site, nav, enquiry CTA |
| `02a-course-catalog.png` / `02-course.png` | 107 cards; search filtering |
| `03-course.png` | course detail, curriculum, CTAs |
| `04-lead-created.png` | enquiry success state |
| `05-crm-lead.png` | CRM drawer with all 6 lead fields |
| `06-telecaller.png` | call + follow-up + stage change recorded in timeline |
| `07-advisor.png` | advisor/lead view (admin) |
| `08-enrollment.png` | convert modal + created student/enrollment |
| `09-enrollment-batch.png` / `09b-enrollment-activation.png` | pending enrollment, no activation control |
| `10a-student-provisioning.png` / `10-student-dashboard.png` | admin password set; student dashboard |
| `11-single-session.png` | old context invalidated |
| `12-learning.png` … `16-certificate.png` | 403 `enrollment_required` and ineligible certificate |
| `17-placement-profile.png` / `17b-placement-profile-saved.png` | placement profile loaded + saved |
| `18-job.png` / `18b-job-detail.png` | job listing, detail, eligibility, apply modal |
| `19-application.png` / `19b-mock-interview-eligibility.png` | 403 `MOCK_INTERVIEW_REQUIRED` + eligibility reason |
| `20-placement-admin.png` | admin applications table (no record) |
| `21-final-state.png` | final state stage |

`FAIL-*.png` files from earlier harness iterations are retained deliberately (no automatic cleanup) and are **not** results of the recorded run.

---

## Git Verification

```
git status --short   → 31 pre-existing modified tracked files + 9 pre-existing untracked
                       + frontend/e2e-10x/  (this test harness, untracked)
git diff --check     → clean (no whitespace or conflict errors)
git diff --cached    → none staged
git diff --stat      → 31 files changed, 574 insertions(+), 57 deletions(-)   [all pre-existing]
branch / HEAD        → integration/master-in-tech-complete @ 7fa3ec9
```

Confirmed:

- **No application source changed by this test.** Verified by timestamp: zero application source files (`*.php`, `*.tsx`, `*.ts`, `*.md`, `*.json` outside `node_modules`/`vendor`/`e2e-10x`) were modified during the session.
- **Payment source untouched** — `PaymentController.php` unmodified.
- **Migrations untouched** — `backend/database/migrations` unmodified.
- **Infrastructure untouched** — `docker-compose.yml`, `deploy/` unmodified.
- **Nothing committed, nothing pushed, nothing deployed.** HEAD is still `7fa3ec9`.
- No database rows were inserted, updated or deleted by hand. All records were created through the product's own UI/API.
- No authentication or authorization was bypassed; no completion, enrollment, certificate or application was faked; Razorpay was not activated.

**New untracked test artifacts (created by this run, not committed):**

```
frontend/e2e-10x/
├── playwright.config.ts        headed, slowMo 0, video on, trace retain-on-failure
├── journey.spec.ts             the 21-stage business journey
├── .e2e-id                     correlation ID
├── api-watchdog.ps1            dev-server supervisor (environment helper)
├── diag-*.mjs                  diagnostics used to root-cause failures
└── artifacts/
    ├── journey.json            machine-readable stage/error/ID record
    ├── results.json            Playwright JSON reporter
    ├── html-report/index.html  Playwright HTML report (with video attachments)
    ├── videos/*.webm           6 videos, 8.3 MB — complete journey recording
    ├── test-results/           traces (none retained: the spec never failed a test)
    └── screenshots/            63 PNGs (milestones + failures)
```
