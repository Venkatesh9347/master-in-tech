# ROLE_BASELINE.md — Phase 0 Evidence Report

**Status:** READ-ONLY INSPECTION COMPLETE. **No source file was modified.**
**Branch:** `integration/master-intech-complete`
**HEAD:** `0c84297c823ea47c3234c45e099c6eb6e3a82505`
**Remote:** in sync (0 ahead / 0 behind)

> This document is the Phase 0 deliverable. Every claim below was read from the
> repository or produced by executing a command. Nothing is inferred. Where the
> evidence is incomplete it says so explicitly.

---

## 1. Git Baseline

| Item | Value |
|---|---|
| Branch | `integration/master-intech-complete` |
| HEAD | `0c84297c823ea47c3234c45e099c6eb6e3a82505` |
| `origin/…` | `0c84297c823ea47c3234c45e099c6eb6e3a82505` (in sync) |
| Staged | 0 |
| Modified | 0 |
| Deleted | 0 |
| Untracked | 4 (+1 gitignored) |

Untracked artifacts (must never be committed):
`lords-desktop-1440.png`, `lords-mobile-390.png`,
`masterintech-working-changes.patch`, `untracked-files.txt`.
`masterintech-untracked-backup.zip` is present but ignored by the `*.zip` rule.

Recent commits:
```
0c84297 fix: provide valid mail address in environment template
aefe192 feat: complete production hardening, accessibility and SEO audit
7fa3ec9 fix: complete audit-02 remediation
```

## 2. Executed Baseline Results

| Gate | Command | Result |
|---|---|---|
| Backend tests | `php artisan test --do-not-cache-result` | **PASS** — 1109/1109 tests, **6124 assertions**, 319.2s |
| Frontend tests | `npm test -- --run` | **PASS** — **152/152** across 21 files, 66.0s |
| Typecheck | `npx tsc -b` | **exit 0** |
| Lint | `npm run lint` | **exit 0** — 0 errors, 12 warnings (all pre-existing `no-explicit-any` in test files) |
| Build | `npm run build` | **exit 0** (emits chunk-size advisory only) |
| Migrations | `php artisan migrate:status` | **0 pending**, 8 applied, local SQLite |

Backend test file count: **121**.

## 3. Role Vocabulary (Authoritative)

### 3.1 Database — `roles` lookup table (11 rows)

| name | label |
|---|---|
| `admin` | Admin |
| `company` | Company |
| `counsellor` | Counsellor |
| `course_advisor` | Course Advisor |
| `faculty` | Faculty |
| `instructor` | Instructor |
| `recruiter` | Recruiter |
| `student` | Student |
| `super_admin` | Super Admin |
| `telecaller` | Telecaller |
| `tutor` | Tutor |

**`placement_advisor` does not exist** — confirmed 3 ways: not in the `roles`
table, `0` references in `backend/tests/**`, and absent from every
authorization list in `app/`.

### 3.2 `users.role` column + constraint

| Property | Value |
|---|---|
| Type | `varchar(255)` **NOT NULL** |
| Default | `'student'` |
| Foreign key | `users.role → roles.name`, `ON DELETE RESTRICT` |

Enforced by migration `2026_10_04_000001_add_role_constraint_to_users_table.php`
(B18). Vocabulary is defined in a `private const ROLES` array inside that
migration and seeded idempotently via `updateOrInsert`.

### 3.3 Live user distribution (local dev DB)

| role | users |
|---|---|
| `admin` | 3 |
| `student` | 15 |
| `tutor` | 1 |
| **total** | **19** |

No `counsellor` / `telecaller` / `course_advisor` / `company` / `faculty`
/ `instructor` / `recruiter` / `super_admin` user exists locally. **Phase 21
browser smoke tests will require seeded accounts for these roles.**

## 4. Middleware Inventory

`backend/bootstrap/app.php` registers these aliases (plus two global middleware
appends: `SecurityHeaders`, `TrustProxies`):

| Alias | Class | Accepted roles | Rejection |
|---|---|---|---|
| `admin` | `EnsureUserIsAdmin` | `admin`, `super_admin` | 403 JSON |
| `super_admin` | `EnsureUserIsSuperAdmin` | `super_admin` (exact `!==`) | 403 JSON |
| `tutor` | `EnsureUserIsTutorOrAdmin` | `tutor`, `faculty`, `admin`, `super_admin` | 403 JSON |
| `crm` | `EnsureUserCanAccessCrm` | delegates to `User::canAccessCrm()` | 403 JSON |
| `company` | `EnsureUserIsCompany` | `company`/`recruiter` **AND** `company_id` set **AND** `company->isApproved()` | 403 JSON |
| `single.session` | `ValidateSingleActiveSession` | — | — |

Global: `SecurityHeaders`, `TrustProxies`.

### 4.1 Usage across all 420 API routes

| Guard | Routes |
|---|---|
| `EnsureUserIsAdmin` | **179** |
| `EnsureUserIsTutorOrAdmin` | 61 |
| `EnsureUserCanAccessCrm` | 26 |
| `EnsureUserIsCompany` | 13 |
| **Total guarded** | **279** |
| Authenticated-only (no role guard) | **141** |

### 4.2 `User` model capability helpers

```php
isStudent()        => $role === 'student' || empty($role)
isTutor()          => $role === 'tutor'
isAdmin()          => in_array($role, ['admin','super_admin'])
isCounsellor()     => (verified in source)
isCompany()        => in_array($role, ['company','recruiter'])
canAccessCrm()     => ['super_admin','admin','counsellor','telecaller','course_advisor']
hasScopedCrmAccess() => ['counsellor','telecaller','course_advisor']   // own + unassigned only
```

Note `isStudent()` treats an **empty/null role as student**. Several placement
guards rely on this (`$user->role === 'student' || $user->role === null`).

## 5. Authentication Architecture (Phase 1 evidence)

**Verdict: the existing architecture already matches the required model.**

```
USER CREDENTIALS → BACKEND AUTH → Sanctum token → server-side users.role → guards
```

| Mechanism | Endpoint | Notes |
|---|---|---|
| Email/password | `POST api/login` | `Hash::check`, `startNewActiveSession('auth_token')`, returns `access_token` + full `user` (incl. `role`) |
| Google | `POST api/auth/google` | Verifies Google token, provisions/locates student, dispatches hashed 30s OTP. **Deliberately returns no Sanctum token** |
| Mobile OTP | `POST api/auth/mobile/send-otp` | |
| OTP verify | `POST api/auth/mobile/verify-otp`, `api/auth/otp/verify` | |
| OTP resend | `POST api/auth/otp/resend` | |
| Logout | `POST api/logout` | |
| Password reset | `POST api/forgot-password`, `api/reset-password` | |

- Sanctum bearer tokens; single active session enforced via
  `ValidateSingleActiveSession` on all API routes.
- **Role is server-authoritative.** The frontend never sends a role; it reads
  `GET /api/user` into `AuthContext` (`AuthContext.tsx` L48) and uses
  `response.data.user` from login (L135).
- Login blocks disabled/inactive/suspended users and unapproved companies.
- Known open finding (pre-existing, unchanged): login returns **422**, not 401,
  for bad credentials.

## 6. Placement Subsystem (Phase 4 evidence)

### 6.1 State machine already exists at the model layer

`backend/app/Models/PlacementOpportunity.php`:

```php
STATUS_PUBLISHED        = 'published'         // L18
STATUS_PENDING_APPROVAL = 'pending_approval'  // L19
STATUS_DRAFT            = 'draft'             // L20
STATUS_CLOSED           = 'closed'            // L21
STATUS_REJECTED         = 'rejected'          // L22
```

Scopes: `forCompany`, `pendingApproval`, `published`, `search`, `active`.

**Schema** (`2026_09_17_000001_create_placement_portal_tables.php`):
`$table->string('status')->default('published'); // published, draft, closed`
plus `index('status')`. Free-text column with a **stale inline comment** — the
model supports 5 states, the comment names 3. No DB enum/check, so adding
states is additive and portable across SQLite/MySQL/PostgreSQL.

Live data: 1 opportunity, `status = 'published'`.

### 6.2 Approval endpoints — fully implemented, admin-gated

All under `EnsureUserIsAdmin`, handled by `AdminCorporatePartnerController`:

| Endpoint | Action | Effect |
|---|---|---|
| `GET api/admin/placements/jobs/pending` | `pendingJobs` | `where('status', STATUS_PENDING_APPROVAL)`, paginated |
| `POST api/admin/placements/jobs/{job}/approve` | `approveJob` | → `STATUS_PUBLISHED`; `AuditLog::log('approved_company_job', …)` |
| `POST api/admin/placements/jobs/{job}/reject` | `rejectJob` | → `STATUS_REJECTED`; `AuditLog::log('rejected_company_job', …, reason)` |
| `GET api/admin/placements/partners` | `partners` | |
| `GET api/admin/placements/partners/{company}` | `showPartner` | |
| `POST …/partners/{company}/approve` | `approvePartner` | |
| `POST …/partners/{company}/reject` | `rejectPartner` | |
| `POST …/partners/{company}/suspend` | `suspendPartner` | |
| `POST …/partners/{company}/reactivate` | `reactivatePartner` | |

`AdminPlacementController` adds: opportunities CRUD (5), `GET applications`,
`PUT applications/{application}/status`, `GET/PUT settings`, `GET stats`.

`AdminMockInterviewController` adds 23 mock-interview routes under
`/admin/placements/mock-interviews/*` including `dashboard-control`.

### 6.3 Company cannot self-approve or self-publish — CONFIRMED

`CompanyPortalController::storeJob` (L190):
`'status' => 'nullable|string|in:draft,pending_approval'` — `published` is not
offerable.

`CompanyPortalController::updateJob` (L274):
`'status' => 'nullable|string|in:draft,pending_approval,closed'` — again
`published` is not offerable.

So the required boundary (company → `draft`/`pending_approval` only; only
`admin`/`super_admin` can reach `published`) **already holds at the API layer**.

### 6.4 IDOR protection on company job ownership — CONFIRMED

`showJob` L239, `updateJob` L254 (and `destroyJob` follows the same pattern):
```php
if ($job->company_id !== $company->id) {
    return response()->json(['message' => 'Unauthorized access to job posting.'], 403);
}
```
`$company = $request->user()->company` — so cross-company access is blocked.

### 6.5 Company lifecycle

`Company`: `STATUS_PENDING`, `STATUS_UNDER_REVIEW`, `STATUS_APPROVED`,
`STATUS_REJECTED`, `STATUS_SUSPENDED`; helpers `isApproved()`, `scopeApproved()`,
`scopePending()`. `EnsureUserIsCompany` rejects pending/suspended companies with
403, so the "pending company cannot use the portal" requirement already holds.

### 6.6 Students only see published opportunities — CONFIRMED

- `opportunities()` L149 → `PlacementOpportunity::published()->withCount('applications')`
- `showOpportunity()` L199 → non-admins blocked unless `STATUS_PUBLISHED`
- `apply()` L303 → requires `STATUS_PUBLISHED`

### 6.7 GAP: placement endpoints have no role middleware

The following are **authenticated-only** (no role guard in the route group);
role is checked ad-hoc inside the controller body:

| Endpoint |
|---|
| `GET api/placements/opportunities` |
| `GET api/placements/opportunities/{opportunity}` |
| `POST api/placements/opportunities/{opportunity}/apply` |
| `GET api/placements/my-applications` |
| `GET api/placements/settings` |
| `GET api/placements/profile-prefill` |
| `GET api/placements/student-status` |
| `GET api/student/placement-dashboard/status` |
| `GET api/student/placement-status` |
| 13 × `api/student/mock-interview*` |

Consequence: any authenticated role — including `tutor`, `telecaller`,
`counsellor` — can call these. Student-only behaviour is enforced by inline
`$user->role === 'student' || $user->role === null` checks, not by middleware.

## 7. CRM Subsystem (Phases 6/7/8 evidence)

26 routes under `EnsureUserCanAccessCrm` (`api/admin/crm/*`) handled by
`AdminCrmController`. Two distinct role lists in that controller:

- `[admin, super_admin, counsellor, telecaller, course_advisor]`
- `[admin, super_admin, tutor, faculty, counsellor, telecaller, course_advisor, company, recruiter]`

`EnquiryController` staff roles:
`[admin, super_admin, tutor, faculty, counsellor, telecaller, course_advisor, company, recruiter]`

**Record scoping is real** — `hasScopedCrmAccess()` (counsellor, telecaller,
course_advisor) drives:
- own + unassigned lead visibility (admins see the full pipeline)
- `denyUnlessReassignAllowed()` (L362, L368)
- finance-field write protection (L467)
- extra filtering at L493 and L637

**The three CRM frontline roles are treated as ONE group.** There is no
per-role differentiation between `counsellor`, `telecaller` and
`course_advisor` anywhere in the backend. Phases 6–8 (separate Telecaller and
Course Advisor dashboards with distinct KPIs/queues) therefore require
introducing per-role scoping that does not exist today. See §13 Conflict C4.

## 8. Existing Role Lists Across `app/` (completeness audit)

```
(5x) AdminUserController.php          [admin,super_admin]
(3x) PlacementPortalController.php    [admin,super_admin]
(2x) AdminClassSessionController.php  [tutor,admin]
(2x) CourseController.php             [admin,super_admin]
(2x) TutorController.php              [instructor]
(2x) AdminTutorPermissionController   [tutor,faculty,instructor]
(1x) SectionController.php            [admin,super_admin]
(1x) EnsureUserIsAdmin.php            [admin,super_admin]
(1x) User.php                         [counsellor,telecaller,course_advisor]
(1x) EnsureUserIsTutorOrAdmin.php     [tutor,faculty,admin,super_admin]
(1x) User.php                         [super_admin,admin,counsellor,telecaller,course_advisor]
(1x) PlacementPortalController.php    [admin,super_admin,recruiter]
(1x) AdminCrmController.php           [admin,super_admin,counsellor,telecaller,course_advisor]
(1x) AdminCrmController.php           [admin,super_admin,tutor,faculty,counsellor,telecaller,course_advisor,company,recruiter]
(1x) AdminClassSessionController.php  [tutor]
(1x) AdminUserController.php          [student,tutor,faculty,admin,super_admin,counsellor,telecaller,course_advisor]
(1x) EnquiryController.php            [admin,super_admin,tutor,faculty,counsellor,telecaller,course_advisor,company,recruiter]
(1x) CourseController.php             [tutor,faculty,admin,super_admin]
(1x) CertificateController.php        [admin,super_admin]
```

### 8.1 Admin user-create/update role validation — MUST BE EXTENDED

`AdminUserController.php` restricts assignable roles to **7** values at three
sites:

- L101 (store): `'role' => 'required|string|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin'`
- L209 (update): `'role' => 'sometimes|required|string|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin'`
- L253 (index/filter): `'role' => 'required|in:student,tutor,counsellor,telecaller,course_advisor,admin,super_admin'`

This list already **omits** `faculty`, `instructor`, `company`, `recruiter`
(4 of the 11 DB roles), so the admin UI cannot assign those today. Adding
`placement_advisor` here is required for Phase 2 + Phase 20 usability.

## 9. Frontend Routing (Phase 13 evidence)

### 9.1 Guard components

| Component | Gate | Redirect behaviour |
|---|---|---|
| `ProtectedRoute.tsx` | authenticated only | → `/login` with `state.from` |
| `AdminRoute.tsx` | `admin`, `super_admin` | tutor/faculty→`/tutor`; CRM staff→`/admin/crm`; **anything else→`/student`** |
| `TutorRoute.tsx` | `tutor`, `faculty` | admin→`/admin`; **anything else→`/student`** |
| `StudentRoute.tsx` | `student` | admin→`/admin`; tutor/faculty→`/tutor`; company/recruiter→`/company`; **anything else→`/login`** |
| `CompanyRoute.tsx` | `company`, `recruiter` | admin→`/admin`; tutor/faculty→`/tutor`; **anything else→`/student`** |
| `CounsellorRoute.tsx` | `[admin, super_admin, counsellor, telecaller, course_advisor]` | tutor/faculty→`/tutor`; **everything else→`/student`** |

No `PlacementAdvisorRoute` exists.

### 9.2 Route map (`App.tsx`, 282 lines)

| Group | Mount | Routes |
|---|---|---|
| Public | — | `/`, `/login`, `/register`, `/forgot-password`, `/reset-password`, `/courses`, `/events`, `/verify-certificate`, `/about`, `/contact`, `/instructors`, `/resources`, `/placements`, `/corporate-partner`, `/faq` |
| Company | `CompanyRoute` → `CompanyLayout` | `/company` (index dashboard), `jobs`, `applications`, `interviews`, `profile` |
| Student | `StudentRoute` | `/student`, `mock-interview(s)`, `class-sessions/:id`, `courses/:courseId(/lessons\|/live/:liveClassId)`, `classroom/:sessionId`, `events`, `certificates/:code`, `checkout/:courseId`, `profile`; `/ai-assistant` |
| Tutor | `TutorRoute` → `TutorLayout` | `/tutor` index, `courses`, `curriculum`, `live`, `analytics`, `students`, `materials`, `quizzes`, `submissions`, `profile` + alias redirects |
| Admin | `AdminRoute` → `AdminLayout` | `/admin` index/`dashboard`, `class-sessions`, `class-history`, `tutor-permissions`, `categories`, `home-cms`, `instructors`, `learning-paths`, `testimonials`, `faqs`, `resources`, `settings`, `navigation`, `media`, `audit-logs`, **`placements`**, `users`, `enrollments`, `batches`, `events`, `courses/:courseId/curriculum`, `submissions`, `refunds`, `certificates`, `webhooks` |
| CRM | `CounsellorRoute` → `AdminLayout` | `/admin/crm`, `/admin/enquiries` |
| Legacy | — | `/cpanel`, `/cpanel/*`, `/c-panel`, `/c-panel/*` → `/admin` |
| 404 | — | `*` → `NotFound` |

Note: `/admin` is mounted **twice** — once under `AdminRoute` (L224) and once
under `CounsellorRoute` (L266). Both render `AdminLayout`; the second adds
`crm` and `enquiries`. This is pre-existing.

### 9.3 Post-login redirect (`Login.tsx` L113–122)

```ts
admin | super_admin      → /admin
tutor | faculty          → /tutor
counsellor|telecaller|course_advisor → /admin/crm
company | recruiter      → /company
everything else          → /student
```

### 9.4 GAP: `placement_advisor` has no frontend destination

With `placement_advisor` added to the DB vocabulary:
1. `Login.tsx` falls through to `navigate('/student')`.
2. `StudentRoute` sees `user.role !== 'student'` and navigates to `/login`.
3. `Login.tsx` → `/student` → `/login` …

**This produces a redirect loop** and is a required fix (Phase 13), not an
optional one.

## 10. Existing Dashboard Pages

| Page | Path | Lines |
|---|---|---|
| `AdminCrm.tsx` | `src/pages/admin/` | 2061 |
| `AdminPlacements.tsx` | `src/pages/admin/` | 1640 |
| `AdminEnquiries.tsx` | `src/pages/admin/` | 989 |
| `StudentDashboard.tsx` | `src/pages/` | 1177 |
| `TutorDashboard.tsx` | `src/pages/tutor/` | 961 |
| `CompanyDashboard.tsx` | `src/pages/company/` | 212 |

No dedicated Telecaller, Counsellor or Course Advisor dashboard page exists —
all three share `AdminCrm.tsx`.

Layout components: `AdminLayout`, `TutorLayout`, `CompanyLayout`.

## 11. Audit Infrastructure

`app/Models/AuditLog.php` L40:
```php
public static function log(string $action, $model = null, ?array $oldValues = null,
                            ?array $newValues = null, ?array $actor = null): self
```
Already used by `approveJob` (`approved_company_job`), `rejectJob`
(`rejected_company_job`), `storeJob` (`company_job_created`). Exposed read-only
at `/admin/audit-logs`. **Reusable as-is — no new audit infrastructure needed.**

## 12. Existing Tests (121 files) — Role/Auth/Placement subset

| Test file | Covers |
|---|---|
| `AdminAuthorizationTest.php` | admin gating |
| `RoleMismatchAndDashboardAccessSecurityTest.php` | cross-role dashboard access |
| `TutorAndRoleSecurityTest.php` | tutor/role security |
| `Goal8TutorPermissionsTest.php` | tutor permission matrix |
| `CounsellorAuthorizationTest.php` | counsellor gating |
| `CrmFrontlineRolesTest.php` | telecaller/counsellor/course_advisor |
| `CrmModuleTest.php`, `CrmAutomationTest.php`, `CrmFollowUpSchedulerTest.php` | CRM |
| `PlacementControlTest.php`, `PlacementPortalTest.php`, `PlacementDashboardActivationTest.php` | placement |
| `CompanyPortalTest.php` | company portal |
| `UserRoleConstraintTest.php`, `UserRoleMigrationTest.php` | B18 role FK |
| `AuthenticatedStudentEnquiryTest.php`, `Goal3AAdminStudentAuthFlowTest.php`, `StudentGoogleOtpAuthTest.php` | auth flows |

`placement_advisor` references in tests: **0**.

## 13. CONFLICTS BETWEEN THE SPEC AND EXISTING ARCHITECTURE

Reported as required, **before** any implementation.

### C1 — "APPROVED" is not a distinct state; approve and publish are merged
Phase 4 item 11 lists "Publish approved vacancy LIVE" as a separate capability,
implying `APPROVED → PUBLISHED` as two transitions. The existing
`approveJob` sets `STATUS_PUBLISHED` **directly**, collapsing approve+publish.
The model has no `STATUS_APPROVED`.
**Needed decision:** keep the existing single-step approve→publish (smaller
change, already audited and tested) or introduce an explicit `approved` state
plus a separate publish endpoint. I have not changed anything.

### C2 — Placement approval endpoints are admin-gated, so `placement_advisor` would 403
All 25 `/admin/placements/*` routes and 9 `/admin/companies/*`-equivalent
partner routes use `EnsureUserIsAdmin`. Adding the role to the DB alone grants
nothing. Phase 4 requires a **dedicated placement authorization layer**, which
means either a new `EnsureUserIsPlacementStaff` middleware or a policy/gate —
and it must not widen `EnsureUserIsAdmin` (Phase 4 explicitly forbids giving
`placement_advisor` admin privileges, and `EnsureUserIsAdmin` covers all 179
admin routes including users, courses, CMS, billing).

### C3 — Student placement endpoints have no role middleware
Phase 10 requires students never see draft/pending. That currently holds via
the `published()` scope, not via route guards, and the routes are reachable by
*any* authenticated role. Tightening this is a behaviour change for existing
roles (tutors/CRM staff currently can call them). Needs explicit scope
definition before changing.

### C4 — `counsellor`, `telecaller` and `course_advisor` are one undifferentiated group
Phases 6–8 want three distinct dashboards with distinct KPIs and queues. Today
`hasScopedCrmAccess()` returns `true` for all three and drives identical
scoping. Separating them requires new per-role scoping logic in
`AdminCrmController` (2061 lines) and new API surface. Phase 8 explicitly says
do **not** merge counsellor into course_advisor — so they must stay distinct,
which means real new work, not a relabel.

### C5 — Role-assignment UI vocabulary is already incomplete
`AdminUserController` allows only 7 of the 11 DB roles. `faculty`,
`instructor`, `company`, `recruiter` cannot be assigned through the admin API.
Pre-existing; I will extend the list for `placement_advisor` but am not
silently fixing the other four unless authorized.

### C6 — `/admin` is mounted twice (AdminRoute + CounsellorRoute)
Pre-existing route shadowing. Any Phase 14 dashboard-shell refactor must not
duplicate this.

---

## 14. Required Mapping Table

### `super_admin`

- **Middleware:** `super_admin` (exact), `admin`, `tutor`, `crm`
- **API permissions:** Full — 179 admin routes + 61 tutor routes + 26 CRM routes
- **Frontend route:** `/admin` (via `AdminRoute`)
- **Dashboard:** `AdminLayout` → `Dashboard`
- **Existing:** users, courses, tutors, LMS, CRM, placement, companies, CMS, certificates, settings, audit logs
- **Missing:** Nothing required. Do **not** add a second super-admin mechanism (Phase 12)

### `admin`

- **Middleware:** `admin`, `tutor`, `crm`
- **API permissions:** All admin (179), tutor (61), CRM (26) routes
- **Frontend route:** `/admin` (via `AdminRoute`)
- **Dashboard:** `AdminLayout` → `Dashboard`
- **Existing:** same surface as super_admin except `super_admin`-only routes
- **Missing:** Must retain placement oversight after Phase 4 adds the advisor layer

### `tutor` / `faculty`

- **Middleware:** `tutor` (`EnsureUserIsTutorOrAdmin`); `faculty` accepted but **no `faculty` alias exists** — it only works via the `tutor` alias
- **API permissions:** 61 tutor routes; CRM list includes tutor/faculty
- **Frontend route:** `/tutor` (via `TutorRoute`)
- **Dashboard:** `TutorLayout` → `TutorDashboard` (961 lines)
- **Existing:** courses, curriculum, live classes, analytics, students, materials, quizzes, submissions, profile
- **Missing:** No `faculty` route/middleware alias (pre-existing)

### `instructor`

- **Middleware:** none of its own; appears in `AdminTutorPermissionController` `[tutor,faculty,instructor]` and `TutorController [instructor]`
- **API permissions:** Narrow — tutor-adjacent only
- **Frontend route:** **none** — `TutorRoute` accepts only `tutor`/`faculty`, so an `instructor` is redirected to `/student`
- **Dashboard:** none
- **Existing:** permission rows, class-session teaching
- **Missing:** Frontend route/dashboard. **Pre-existing inconsistency** — the DB allows the role but the frontend and middleware do not admit it. Not in scope for this brief; flagged.

### `student`

- **Middleware:** none by role (placement routes are authenticated-only with inline checks)
- **API permissions:** `/api/placements/*` (published only), `/api/student/*`, LMS/courses/assignments/certificates
- **Frontend route:** `/student` (via `StudentRoute`)
- **Dashboard:** `StudentDashboard` (1177 lines)
- **Existing:** learning, courses, progress, classes, assignments, quizzes, certificates, placement participation, mock interviews
- **Missing:** Eligibility + live placements are present but **unguarded by middleware** (C3)

### `telecaller`

- **Middleware:** `crm` (`EnsureUserCanAccessCrm` via `canAccessCrm()`)
- **API permissions:** 26 `/admin/crm/*` routes, record-scoped via `hasScopedCrmAccess()`
- **Frontend route:** `/admin/crm`, `/admin/enquiries` (via `CounsellorRoute`)
- **Dashboard:** **shared** `AdminCrm.tsx` (2061 lines)
- **Existing:** lead contact, call, notes, follow-up, demo, status; own + unassigned scope; finance fields write-protected
- **Missing:** dedicated `/crm/telecaller` dashboard and KPIs (Phase 6); per-role scoping (C4)

### `counsellor`

- **Middleware:** `crm`
- **API permissions:** same 26 CRM routes, scoped identically to telecaller
- **Frontend route:** `/admin/crm`, `/admin/enquiries` (via `CounsellorRoute`)
- **Dashboard:** **shared** `AdminCrm.tsx`
- **Existing:** `isCounsellor()` helper, `CounsellorAuthorizationTest.php`
- **Missing:** dedicated counselling-queue dashboard (Phase 8). Must stay **distinct** from `course_advisor`

### `course_advisor`

- **Middleware:** `crm`
- **API permissions:** same 26 CRM routes, scoped identically
- **Frontend route:** `/admin/crm` (via `CounsellorRoute`)
- **Dashboard:** **shared** `AdminCrm.tsx`
- **Existing:** Course recommendation/admission within the shared CRM
- **Missing:** dedicated course-advisor dashboard + conversion metrics (Phase 7)

### `company` / `recruiter`

- **Middleware:** `company` (`EnsureUserIsCompany` + `company->isApproved()`)
- **API permissions:** 13 `/api/company/*` routes; IDOR-checked on `company_id`
- **Frontend route:** `/company` (via `CompanyRoute`)
- **Dashboard:** `CompanyLayout` → `CompanyDashboard` (212 lines)
- **Existing:** dashboard, profile, jobs CRUD, applications, status change, interviews, scheduling, feedback. `status` restricted to `draft,pending_approval,closed` — **cannot self-approve or self-publish**
- **Missing:** Nothing structurally. Verify all Phase 5 constraints at API level

### `placement_advisor` — **NEW, DOES NOT EXIST YET**

- **Middleware:** none
- **API permissions:** **none** — would receive 403 on all placement endpoints (`EnsureUserIsAdmin` only) and 403 on CRM (`canAccessCrm()` excludes it)
- **Frontend route:** **none** — `Login.tsx` sends it to `/student`, `StudentRoute` bounces it to `/login` → **redirect loop**
- **Dashboard:** none
- **Existing functionality:** none. All 20 Phase 4 capabilities are provided by existing `AdminPlacementController` + `AdminCorporatePartnerController` code that is simply unreachable by a non-admin
- **Missing:** DB role row (Phase 2), dedicated placement authorization layer (C2), middleware alias, frontend guard, route, dashboard, login redirect, and all of its test coverage

---

## 15. Phase 0 Conclusion

- The **placement approval workflow, company portal, CRM scoping, authentication
  architecture and role FK all already exist and are already audited.** Phase 4
  and Phase 5 are mostly about *granting a new role reach* to existing code, not
  building it.
- The genuine new work is: the `placement_advisor` DB row + migration, a
  placement-specific authorization layer that does **not** widen admin, a
  frontend guard/route/dashboard, the login-redirect branch, and per-role CRM
  scoping for Phases 6–8.
- **Six conflicts (C1–C6) require a decision before implementation**, per the
  brief's instruction to stop and report rather than make a risky
  architectural change. **C1, C4 and C5 are genuine design decisions; C2, C3 and
  C6 are scoped work items.**

**No implementation has begun. Awaiting authorization to proceed.**