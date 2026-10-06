# ROLE_CAPABILITIES.md — Capability Matrix (Authoritative)

**Status:** Current implementation. Every claim is cited to middleware, route surface, controller/service, or an existing test.
**Baseline:** Phase 1 commit `777d5fb334fa4a17ca06b7c9d149e954677f698a`
**Companion:** `ROLE_BASELINE.md` — the role-system baseline (role vocabulary, routing, gaps). This document is the **capability** documentation and does not replace it.
**Machine-checked by:** `backend/tests/Feature/RoleCapabilitiesDocumentationTest.php`

## Status legend

| Marker | Meaning |
|---|---|
| **CURRENTLY ENFORCED** | Verified in code and reachable through a named middleware/guard |
| **TARGET / BUSINESS INTENT** | Documented intent from the specification. **NOT enforced.** No code grants it. |
| **NOT AVAILABLE** | Deliberately withheld; denied by a named guard |
| **RESERVED / UNPROVISIONED** | Role exists in the vocabulary but has no active portal or capability surface |

> Target intent is never presented as current functionality. Sections marked
> *TARGET* contain no enforced behaviour.

---

## 1. Canonical role registry

Machine-parsed by the consistency test. Every individual role is explicit even where
behaviour is shared.

| Role | Status | Spec group | Portal | Primary gate |
|---|---|---|---|---|
| `super_admin` | CURRENTLY ENFORCED | SUPER ADMIN | `/admin` | `admin` alias (administrative tier) |
| `admin` | CURRENTLY ENFORCED | ADMIN | `/admin` | `admin` alias |
| `tutor` | CURRENTLY ENFORCED | TUTOR / FACULTY | `/tutor` | `tutor` alias |
| `faculty` | CURRENTLY ENFORCED | TUTOR / FACULTY | `/tutor` | `tutor` alias |
| `counsellor` | CURRENTLY ENFORCED | COUNSELLOR | `/crm/counsellor` | `crm` alias + scoped tier |
| `telecaller` | CURRENTLY ENFORCED | TELECALLER | `/crm/telecaller` | `crm` alias + scoped tier |
| `course_advisor` | CURRENTLY ENFORCED | COURSE ADVISOR | `/crm/course-advisor` | `crm` alias + scoped tier |
| `placement_advisor` | CURRENTLY ENFORCED | PLACEMENT ADVISOR | `/placement` | `placement` alias |
| `company` | CURRENTLY ENFORCED | COMPANY / RECRUITER | `/company` | `company` alias + approved profile |
| `recruiter` | CURRENTLY ENFORCED | COMPANY / RECRUITER | `/company` | `company` alias + approved profile |
| `student` | CURRENTLY ENFORCED | STUDENT | `/student` | authenticated; published-only placement scope |
| `instructor` | RESERVED / UNPROVISIONED | *(none — reserved)* | **none** | **none** |

**12 roles: 11 enforced + 1 reserved.** This matches the specification's enumeration
exactly, and matches `User::ROLES` exactly. `instructor` is present in the vocabulary
but unprovisioned — see §9.

---

## 2. Enforcement footprint (measured)

Alias route counts from `php artisan route:list --path=api`:

| Alias | Middleware | Routes | Accepts |
|---|---|---:|---|
| `admin` | `EnsureUserIsAdmin` | **158** | `admin`, `super_admin` |
| `tutor` | `EnsureUserIsTutorOrAdmin` | **61** | `tutor`, `faculty`, `admin`, `super_admin` |
| `placement` | `EnsureUserIsPlacementStaff` | **46** | `placement_advisor`, `admin`, `super_admin` |
| `crm` | `EnsureUserCanAccessCrm` | **26** | `super_admin`, `admin`, `counsellor`, `telecaller`, `course_advisor` |
| `company` | `EnsureUserIsCompany` | **13** | `company`, `recruiter` **AND** approved profile |
| `super_admin` | `EnsureUserIsSuperAdmin` | **0** | `super_admin` — **alias registered but unused on any route** |
| | **Role-guarded total** | **304** | |
| | **Authenticated-only** | **118** | |
| | **Total API routes** | **422** | |

Registry: `backend/bootstrap/app.php` L23–29.

---

## 3. Capability matrix

`✔` = CURRENTLY ENFORCED · `✖` = NOT AVAILABLE · `—` = not applicable

| Capability | super_admin | admin | tutor | faculty | student | telecaller | counsellor | course_advisor | placement_advisor | company | recruiter | instructor |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Platform administration (users, CMS, billing, refunds, certificates, audit) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Super-admin grant / revoke | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Teaching (assigned courses, curriculum, live classes) | ✔ | ✔ | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Quiz authoring (create/edit/delete/publish) | ✔ | ✔ | per-permission | per-permission | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Materials (upload/manage) | ✔ | ✔ | per-permission | per-permission | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Learning (courses, lessons, progress, assignments) | ✔ | ✔ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Certificates (own) | ✔ | ✔ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| CRM — read/write own + unassigned leads | ✔ | ✔ | ✖ | ✖ | ✖ | ✔ | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ |
| CRM — follow-ups (own) | ✔ | ✔ | ✖ | ✖ | ✖ | ✔ | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ |
| CRM — call recordings (own) | ✔ | ✔ | ✖ | ✖ | ✖ | ✔ | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ |
| CRM — delete leads / recordings | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| CRM — reassign leads | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| CRM — finance / payment amounts | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| CRM — confirm admission | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Placement — corporate partner approve/reject/suspend/reactivate | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — vacancy review + approve/reject (publish boundary) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — opportunities CRUD | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — applications + status (incl. shortlisted) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — stats + settings | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — interview pipeline (read-only) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — audit events (read-only, allowlisted) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ |
| Placement — mock-interview administration | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Placement — participate (student view of published) | ✔ | ✔ | ✖ | ✖ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ |
| Corporate — own profile | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✔ | ✖ |
| Corporate — own vacancies (draft/pending/closed) | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✔ | ✖ |
| Corporate — own applications / interviews / feedback | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✔ | ✔ | ✖ |
| Corporate — approve or publish own vacancy | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | **✖** | **✖** | **✖** | ✖ |
| Corporate — another company's resources | ✔ | ✔ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | ✖ | **✖** | **✖** | ✖ |

---

## 4. SUPER ADMIN — full system administration

**CURRENTLY ENFORCED**

- Shares the administrative tier with `admin` via the `admin` alias (158 routes). Evidence: `EnsureUserIsAdmin` → `in_array($user->role, ['admin', 'super_admin'], true)`.
- `isAdmin()` returns true for `super_admin`; `hasPermission()` returns true unconditionally for `admin`/`super_admin`; `getResolvedPermissions()` returns every canonical tutor permission as `true`.
- `canAccessCrm()` returns true → full CRM tier (all 14 capabilities).
- `EnsureUserIsTutorOrAdmin` accepts `super_admin` → full tutor surface (61 routes).
- `EnsureUserIsPlacementStaff` accepts `super_admin` via its `isAdmin()` branch → placement surface (46 routes).
- Holds super-admin-only privileges over role assignment: `denyUnlessSuperAdminGrantAllowed()` blocks **granting** `super_admin` to a non-super-admin; `updateRole()` symmetrically blocks **revoking** it.

**Documented accurately:** the dedicated `super_admin` alias (`EnsureUserIsSuperAdmin`)
is **registered in `bootstrap/app.php` L24 but applied to 0 routes**. `super_admin` reaches
administrative surfaces through the `admin` alias, not through a distinct surface. There is
**no separate super-admin portal or route group** in this implementation.

Tests: `AdminAuthorizationTest`, `PlacementAdvisorAuthorizationTest::test_admin_and_super_admin_retain_full_access`.

---

## 5. ADMIN — platform administration

**CURRENTLY ENFORCED**

- `admin` alias, **158 routes** — users, courses, categories, CMS, events, media, navigation, settings, audit logs, enrollments, batches, refunds, certificates, payments.
- Full CRM tier via `canAccessCrm()` (26 `crm` routes, 14 capabilities).
- Tutor tier via `EnsureUserIsTutorOrAdmin` (61 routes).
- Placement tier via `EnsureUserIsPlacementStaff` (`isAdmin()` branch, 44 routes).
- **NOT** able to grant `super_admin` — `denyUnlessSuperAdminGrantAllowed()` → 403.
- **NOT** able to change their own role — `updateRole()` → 403.
- Last-privileged-account demotion blocked → 403.

Retains full placement oversight *after* Phase 1 introduced the placement tier. Phase 1 did
not remove any admin capability.

Tests: `AdminAuthorizationTest`, `PlacementAdvisorAuthorizationTest::test_admin_and_super_admin_retain_full_access`, `RoleMismatchAndDashboardAccessSecurityTest`.

---

## 6. TUTOR / FACULTY — teaching and assigned academic operations

**CURRENTLY ENFORCED**

- `tutor` alias, **61 routes**. Evidence: `EnsureUserIsTutorOrAdmin` → `['tutor','faculty','admin','super_admin']`.
- Note: `User::isTutor()` returns true only for `tutor` (not `faculty`). Portal access is governed by the middleware list, so `faculty` has equal route access; the helper is narrower.
- **Tutor permission surface — `User::defaultTutorPermissions()`, 9 canonical keys:**

  | Permission | Default | Admin/`super_admin` |
  |---|:--:|:--:|
  | `view_assigned_courses` | true | true |
  | `view_students` | true | true |
  | `upload_materials` | true | true |
  | `manage_materials` | true | true |
  | `create_quizzes` | **false** | true |
  | `edit_quizzes` | **false** | true |
  | `delete_quizzes` | **false** | true |
  | `publish_quizzes` | **false** | true |
  | `view_quiz_results` | true | true |

  `User::hasPermission()` resolves these per-user (stored in the `permissions` JSON column,
  canonicalised through `permissionKeyMap()`); admin/`super_admin` receive all-true.
- **NOT platform administration** — `EnsureUserIsAdmin` excludes `tutor`/`faculty`; a tutor
  hitting an admin route receives 403.

Tests: `Goal8TutorPermissionsTest`, `TutorAndRoleSecurityTest`, `BatchB2QuizAuthoringProtectionTest`.

---

## 7. STUDENT — learning, courses, assignments, certificates, placement participation

**CURRENTLY ENFORCED**

- **No dedicated role alias accepts `student`** — verified: none of the six role middleware
  contains the literal `'student'`. The student portal is gated by frontend `StudentRoute`
  plus Sanctum authentication, and by per-resource ownership checks in controllers.
- Learning surface: courses, lessons, progress, assignments, quizzes, certificates (`/student/*`).
- **Placement participation is restricted to published opportunities only:**
  - `PlacementPortalController::opportunities()` → `PlacementOpportunity::published()`
  - `showOpportunity()` → non-`published` returns **404** to non-admins (existence not confirmed)
  - `apply()` → requires `STATUS_PUBLISHED`
- Placement dashboard suspension respected via `StudentPlacementEligibility` (`STATUS_SUSPENDED` → 403).
- Mock-interview booking/cancellation/rescheduling under `/api/student/mock-interview*`.
- Cannot reach any admin, tutor, CRM, company or placement-administration surface.

Tests: `PlacementPortalTest`, `AdminAuthorizationTest`, `AuthenticatedStudentEnquiryTest`, `StudentGoogleOtpAuthTest`.

---

## 8. TELECALLER / COUNSELLOR / COURSE ADVISOR — CRM

### 8.1 CURRENTLY ENFORCED — one shared scoped tier

All three roles receive **identical** capabilities. Evidence: every check in
`AdminCrmController`, `CallRecordingController` and `EnquiryController` is written against
`User::hasScopedCrmAccess()`, which returns true for exactly `counsellor`, `telecaller`,
`course_advisor`.

| Capability | admin / super_admin | counsellor | telecaller | course_advisor | Enforcement site |
|---|:--:|:--:|:--:|:--:|---|
| `crm.read_leads` | ✔ | ✔ | ✔ | ✔ | record scope |
| `crm.write_leads` | ✔ | ✔ | ✔ | ✔ | record scope |
| `crm.claim_leads` | ✔ | ✔ | ✔ | ✔ | `AdminCrmController` L1115–1125 |
| `crm.convert_leads` | ✔ | ✔ | ✔ | ✔ | `AdminCrmController@convert` |
| `crm.read_follow_ups` | ✔ | ✔ | ✔ | ✔ | record scope |
| `crm.write_own_follow_ups` | ✔ | ✔ | ✔ | ✔ | `AdminCrmController` L647, L710 |
| `crm.read_call_recordings` | ✔ | ✔ | ✔ | ✔ | `CallRecordingController` L122 |
| `crm.write_call_recordings` | ✔ | ✔ | ✔ | ✔ | `CallRecordingController` L77 |
| `crm.delete_leads` | ✔ | ✖ | ✖ | ✖ | `AdminCrmController` L493–494 |
| `crm.reassign_leads` | ✔ | ✖ | ✖ | ✖ | `denyUnlessReassignAllowed` L368 |
| `crm.write_any_follow_up` | ✔ | ✖ | ✖ | ✖ | `AdminCrmController` L647, L710 |
| `crm.delete_call_recordings` | ✔ | ✖ | ✖ | ✖ | `CallRecordingController` L234–235 |
| `crm.write_finance` | ✔ | ✖ | ✖ | ✖ | `AdminCrmController` L1136–1149 |
| `crm.confirm_admission` | ✔ | ✖ | ✖ | ✖ | `EnquiryController` L286–287 |

**Record scoping:** scoped staff see own + unassigned leads only; admins see the full pipeline.
Denials return 403.

Route surface: `crm` alias, **26 routes** under `/api/admin/crm/*` and `/api/admin/enquiries`.

Tests: `CrmCapabilityMatrixTest` (15), `CrmFrontlineRolesTest`, `CounsellorAuthorizationTest`, `CrmModuleTest`.

### 8.2 TARGET / BUSINESS INTENT — NOT YET ENFORCED

The specification assigns three differentiated responsibilities:

| Role | Target responsibility | Enforced today? |
|---|---|:--:|
| `telecaller` | CRM lead contact and follow-up operations | **NO** |
| `counsellor` | CRM counselling / admission operations | **NO** |
| `course_advisor` | Course recommendation, admissions and conversion | **NO** |

**This is not implemented.** No code distinguishes the three roles. Their enforced capability
set is byte-identical (§8.1). Any claim that a telecaller can perform a capability a course
advisor cannot would be false.

Introducing these distinctions requires new per-role scoping in `AdminCrmController` (2061
lines), `CallRecordingController` and `EnquiryController`, plus revisiting the deliberate
design decision recorded in `CrmCapabilities` that the three roles share one tier. **Out of
scope for this phase; no CRM code was modified.**

### 8.3 CRM roles are not platform administrators

`canAccessCrm()` returns true for `counsellor`/`telecaller`/`course_advisor`, but
`EnsureUserIsAdmin` excludes all three. They cannot reach user administration, CMS, billing,
role assignment, security settings, or the placement tier.

### 8.4 Per-role CRM desks — Phases 6, 7, 8 (PRESENTATION ONLY)

Each frontline CRM role now has its **own dashboard** and its own KPI set:

| Role | Landing route | Dashboard | Headline KPIs |
|---|---|---|---|
| `telecaller` | `/crm/telecaller` | Telecaller Desk | New Leads, My Leads, Today's Calls, Pending / Overdue Follow-ups, Demo Requests, Demo Scheduled, Interested |
| `course_advisor` | `/crm/course-advisor` | Course Advisor Desk | Assigned / Qualified Leads, Demo Scheduled & Completed, Interested, Payment Pending, Admission Confirmed, Enrolled, Follow-ups Due, Conversion Rate |
| `counsellor` | `/crm/counsellor` | Counsellor Desk | Lead Pipeline, Counselling Queue, Today's / Overdue Follow-ups, Demo Schedule, Interested Students, Admission Pipeline, Conversion Metrics |

**This changes presentation only. It grants no capability and changes no scoping.** Every
figure is read from the endpoints the role could already call
(`GET /admin/crm/stats`, `GET /admin/crm/leads`), and every one of those applies
`Enquiry::visibleTo($user)` — the same single shared tier described in §8.1. §8.2's three-way
split remains **TARGET / NOT YET ENFORCED**.

| Aspect | Detail |
|---|---|
| Component | `frontend/src/pages/crm/CrmRoleDashboard.tsx` |
| Routes | `/crm/telecaller`, `/crm/counsellor`, `/crm/course-advisor` |
| Destination rule | `frontend/src/utils/roleDestinations.ts` — single source of truth, shared by `Login` and all six guards |
| Backend change | **none** (verified: `git diff --stat -- backend` unchanged from Phase 4) |
| Tests | 16 vitest (`CrmRoleDashboard.test.tsx`) + 8 vitest (`roleDestinations.test.ts`) |
| Browser | 15/15 Playwright (`phase21.spec.ts`), including per-role landing and refresh survival |

**Defect found and fixed by the Phase 21 browser smoke test.** Phases 6/7/8 built the desks,
but `Login.tsx` and five route guards each carried their *own* copy of the role → destination
rule, and those copies still sent all three CRM roles to the shared `/admin/crm` page. The new
desks were unreachable without typing a URL. The duplicated rule was consolidated into
`roleDestinations.ts` so it cannot drift again.

---

## 9. INSTRUCTOR — RESERVED / UNPROVISIONED

**RESERVED / UNPROVISIONED**

- Present in `User::ROLES` (so the `roles` lookup table and `users.role` foreign key accept it)
  and in `User::ASSIGNABLE_ROLES`.
- **No middleware accepts it** — `EnsureUserIsTutorOrAdmin` accepts `['tutor','faculty','admin','super_admin']`;
  no other role middleware contains the literal.
- **No active portal** — `TutorRoute` admits `tutor`/`faculty` only. An `instructor`
  has no active portal and no active middleware capability surface.
- **No tutor permissions granted** — `defaultTutorPermissions()` is role-agnostic and resolved
  through `hasPermission()`; nothing grants `instructor` tutor access.
- Referenced as a literal in 12 files (`CourseController`, `TutorController`,
  `AdminTutorPermissionController`, `AdminEnrollmentController`, `CertificateController`,
  `StudentClassSessionController`, `AdminDashboardController`, `AdminCmsController`,
  `Course`, `User`, `CertificatePdfService`, `AdminEnrollmentController`) — i.e. the role is
  *named* in teaching/permission code but never *granted* a capability surface.

**Consequence:** an `instructor` account can be provisioned and stored but cannot sign in to
any role portal. It is **not** merged with `faculty` and **not** given tutor capabilities.
This is a pre-existing vocabulary gap recorded in `ROLE_BASELINE.md` §3.3, deliberately left
unresolved. **No code changed in this phase.**

---

## 10. PLACEMENT ADVISOR — placement operations and corporate recruitment operations

**CURRENTLY ENFORCED**

`placement` alias, **46 routes**, via `EnsureUserIsPlacementStaff` — a dedicated guard, **not**
`EnsureUserIsAdmin`.

| Capability | Evidence |
|---|---|
| Corporate partner review | `GET /admin/placements/partners` — `AdminCorporatePartnerController@partners` |
| Partner approve / reject | `@approvePartner`, `@rejectPartner` |
| Partner suspend / reactivate | `@suspendPartner`, `@reactivatePartner` |
| Vacancy review (pending) | `GET /jobs/pending` — `where('status', STATUS_PENDING_APPROVAL)` |
| Vacancy approve → **PUBLISHED** | `@approveJob` sets `STATUS_PUBLISHED`, audits `approved_company_job` |
| Vacancy reject → **REJECTED** | `@rejectJob` sets `STATUS_REJECTED`, audits `rejected_company_job` |
| Opportunities CRUD | `AdminPlacementController` 5 routes |
| Applications + status (incl. `shortlisted`) | `@applications`, `@updateApplicationStatus` (7 allowed statuses) |
| Placement stats | `@stats` |
| Placement settings | `@getSettings`, `@updateSettings` (+ singular alias) |
| **View interview pipeline (read-only)** | `GET /admin/placements/interviews` → `@interviewPipeline` (Phase 4) |
| **View placement audit events (read-only)** | `GET /admin/placements/audit-events` → `@auditEvents` (Phase 4) |

### 10.1 Interview pipeline — Phase 4 capability #17

**CURRENTLY ENFORCED** (Phase 4)

- Endpoint `GET /api/admin/placements/interviews`, action `AdminPlacementController@interviewPipeline`.
- Gate: the existing `placement` alias (`EnsureUserIsPlacementStaff`). **Not** `EnsureUserIsAdmin`.
- Reuses the existing `PlacementInterview` model and its existing
  `opportunity` / `company` / `candidate` / `application` relationships.
  **No new model, table, or migration.**
- **Read-only.** No create, reschedule, cancel or delete path exists on this
  endpoint or any sub-path; company-side scheduling remains the only write path
  behind `EnsureUserIsCompany`.
- Optional filters: `status`, `placement_opportunity_id`, `company_id`,
  `per_page` (capped 100).
- **Withheld by design** — excluded by the serialiser, not merely unused:

  | Field | Reason |
  |---|---|
  | `meeting_link` | join URLs can embed credentials |
  | `admin_notes` | internal administrative notes |
  | `interviewer_notes` | company-internal interviewer notes |
  | `instructions` | company-internal instructions |
  | candidate `email` / `phone` / `resume_url` | not needed for pipeline oversight |

- Tests: `PlacementAdvisorRemainingCapabilitiesTest` (34 tests, 101 assertions).

### 10.2 Placement audit events — Phase 4 capability #20

**CURRENTLY ENFORCED** (Phase 4)

- Endpoint `GET /api/admin/placements/audit-events`, action `AdminPlacementController@auditEvents`.
- Gate: the existing `placement` alias. **Not** `EnsureUserIsAdmin`.
- **Server-side filter only.** `AdminPlacementController::PLACEMENT_AUDIT_ACTIONS`
  allowlists **20 real event names**, each derived from an exhaustive scan of
  every `AuditLog::log()` call site in the application. Nothing is invented.
  A secondary `auditable_type` clause restricts rows to `PlacementOpportunity`,
  `PlacementApplication`, `PlacementInterview`, `Company`,
  `StudentPlacementEligibility`, or `NULL` (the settings event legitimately audits
  a null model).

  | Business area | Events |
  |---|---|
  | corporate partner lifecycle | `company_partnership_requested`, `approved_corporate_partner`, `rejected_corporate_partner`, `suspended_corporate_partner`, `reactivated_corporate_partner` |
  | company vacancy lifecycle | `company_job_created`, `approved_company_job`, `rejected_company_job` |
  | placement opportunities | `created_placement_opportunity`, `updated_placement_opportunity`, `deleted_placement_opportunity` |
  | applications / recruitment pipeline | `submitted_placement_application`, `updated_placement_application_status`, `company_updated_application_status`, `company_scheduled_interview` |
  | placement configuration | `updated_placement_settings` |
  | student placement access | `placement_dashboard_disabled`, `placement_dashboard_suspended`, `overrode_student_placement_eligibility` |

- **Excluded by the allowlist:** user administration and role assignment
  (`created_user`, `updated_user`, `deleted_user`, `updated_user_role`,
  `updated_tutor_permissions`); CRM and call recordings (`*crm*`,
  `converted_crm_lead_to_student`, `recorded_lead_payment`,
  `accessed_call_recording`); **mock-interview administration**
  (`created/updated/deleted_mock_interviewer`, `_mock_interview_slot`,
  `booked/rescheduled/cancelled_mock_interview`,
  `submitted_mock_interview_evaluation`, `reassigned_mock_interview_interviewer`,
  `deactivated_mock_interviewer`, `updated_mock_interview_status`) — those
  endpoints are admin-only, so surfacing their trail here would leak an
  administration surface placement_advisor is deliberately denied; payments,
  refunds, certificates, CMS, batches, live classes, webhooks.
- **Credential defence in depth.** `redactAuditPayload()` recursively strips any
  key matching `password|passwd|token|secret|api_key|authorization|bearer|
  credential|session_id|remember_token` from `old_values` / `new_values`.
- `ip_address` and `user_agent` are **not** returned (security-sensitive metadata).
- Requesting `?action=` outside the allowlist returns **422**.
- **Read-only.** POST/PUT/PATCH/DELETE all return 405. No create/update/delete path.
- The pre-existing `/admin/audit-logs` surface is **untouched** — it still reads
  `LearningActivityLog` and is still admin-only. The `activityLogs`/`AuditLog`
  mismatch is a known pre-existing defect, deliberately **not** repaired here.
- Tests: `PlacementAdvisorRemainingCapabilitiesTest`.

**Explicitly NOT granted — NOT AVAILABLE:**

| Withheld | Why it stays withheld |
|---|---|
| Platform administration (users, CMS, billing, refunds, certificates, audit) | `EnsureUserIsAdmin` unchanged — 158 routes untouched |
| Role assignment | `EnsureUserIsAdmin` only |
| Unrestricted CRM | `canAccessCrm()` excludes `placement_advisor` |
| Mock-interview administration | 23 routes re-assert `->middleware('admin')` inside the placement group |

Mock-interview administration is **not** in the placement-advisor requirement list, so it
remains admin-only. Phase 1 verified `placement_advisor` receives 403 on
`/api/admin/placements/mock-interviews/*`.

**Business boundary preserved:** a company cannot publish or approve its own vacancy —
`CompanyPortalController` restricts `status` to `draft,pending_approval` (`storeJob`) and
`draft,pending_approval,closed` (`updateJob`). `placement_advisor` approval is the only
non-admin path to `published`.

Tests: `PlacementAdvisorAuthorizationTest` (41), `PlacementControlTest`, `CompanyPortalTest`, `PlacementPortalTest`.

---

## 11. COMPANY / RECRUITER — own corporate profile, vacancies, candidates, interviews

**CURRENTLY ENFORCED**

`company` alias, **13 routes**, via `EnsureUserIsCompany`, which requires **three** conditions:
1. `isCompany()` → role is `company` or `recruiter`
2. `company_id` is set and resolves to a `Company`
3. `company->isApproved()` → status `approved`

| Capability | Evidence |
|---|---|
| Own profile read/update | `@profile`, `@updateProfile` |
| Own vacancies — create draft / submit | `@storeJob`, `status ∈ {draft, pending_approval}` |
| Own vacancies — edit / delete | `@updateJob` (`status ∈ {draft,pending_approval,closed}`), `@destroyJob` |
| Own applications | `@applications` |
| Application status | `@updateApplicationStatus` |
| Own interviews | `@interviews`, `@scheduleInterview` |
| Interview feedback | `@submitFeedback` |
| Dashboard KPIs | `@dashboard` |

**Restrictions — enforced, not assumed:**

| Restriction | Enforcement |
|---|---|
| Cannot approve own vacancy | Not offered by any company route; `POST /admin/placements/jobs/{job}/approve` requires `EnsureUserIsPlacementStaff` → 403 |
| Cannot publish own vacancy | `status` validation excludes `published` (422) |
| Cannot access another company's vacancy | `if ($job->company_id !== $company->id) → 403` in `@showJob`, `@updateJob`, `@destroyJob` |
| Cannot access another company's applications | `if ($application->opportunity->company_id !== $company->id) → 403` |
| Cannot access another company's interviews | `if ($interview->company_id !== $company->id) → 403` |
| Unapproved / suspended cannot use portal | `EnsureUserIsCompany` → 403 when `!isApproved()` |
| Cannot reach placement administration | `canOperatePlacement()` false, `isAdmin()` false |

Tests: `CompanyPortalTest`, `PlacementAdvisorAuthorizationTest` (company boundary cases), `PlacementControlTest`.

---

## 12. Security boundaries (must not be weakened)

Each is enforced in code and covered by an existing test.

| # | Boundary | Enforcement | Test |
|---|---|---|---|
| 1 | admin/`super_admin` vs ordinary roles | `EnsureUserIsAdmin` `in_array(['admin','super_admin'])` | `AdminAuthorizationTest` |
| 2 | tutor/faculty ≠ platform admin | `EnsureUserIsAdmin` excludes both | `TutorAndRoleSecurityTest` |
| 3 | `placement_advisor` ≠ platform admin | `EnsureUserIsPlacementStaff`, 46 placement routes / 158 admin routes untouched | `PlacementAdvisorAuthorizationTest` |
| 4 | CRM scoped users cannot perform admin-tier CRM operations | `hasScopedCrmAccess()` branches, 403 | `CrmCapabilityMatrixTest`, `CrmFrontlineRolesTest` |
| 5 | company cannot self-approve | approval requires placement/admin gate → 403 | `PlacementAdvisorAuthorizationTest` |
| 6 | company cannot self-publish | `status` validation excludes `published` → 422 | `PlacementAdvisorAuthorizationTest` |
| 7 | company cannot access another company's resources | `company_id` mismatch → 403 | `PlacementAdvisorAuthorizationTest` |
| 8 | unapproved/suspended company cannot use portal | `EnsureUserIsCompany` `isApproved()` → 403 | `CompanyPortalTest` |
| 9 | student sees published placement opportunities only | `->published()`, 404 for non-public | `PlacementPortalTest`, `PlacementAdvisorAuthorizationTest` |
| 10 | `super_admin` role changes protected | `denyUnlessSuperAdminGrantAllowed` symmetric grant/revoke → 403 | `PlacementAdvisorAuthorizationTest` |
| 11 | ordinary users cannot change their own role | `updateRole()` self-target → 403 | `PlacementAdvisorAuthorizationTest` |
| 12 | last-admin protection | `updateRole()` last privileged demotion → 403 | `PlacementAdvisorAuthorizationTest` |
| 13 | role assignment is not mass-assignable | `role` absent from `#[Fillable]`; `forceFill()` only | `PlacementAdvisorAuthorizationTest` |

Boundary 13 is empirically confirmed: constructing `new User(['role' => …])` silently drops
the attribute; only `forceFill()` assigns a role.

---

## 13. Known capability gaps

| Gap | Status |
|---|---|
| `instructor` has no portal or capability surface | RESERVED — §9, pre-existing, unresolved by design |
| Telecom/counsellor/course-advisor capabilities are identical | TARGET not enforced — §8.2 |
| Admin interview **pipeline endpoint** for placement | **RESOLVED in Phase 4** — `GET /admin/placements/interviews`, read-only, §10.1 |
| Placement **audit-event viewing** | **RESOLVED in Phase 4** — `GET /admin/placements/audit-events`, read-only, allowlisted, §10.2 |
| `activityLogs` reads `LearningActivityLog`, not `AuditLog` | Pre-existing defect in `/admin/audit-logs`. Deliberately **not** repaired — Phase 4 added a separate placement-scoped endpoint instead |
| 118 authenticated-only routes have no role guard | Pre-existing; student-facing placement endpoints enforce scope in-controller |
| No shared dashboard design system | **RESOLVED in Phase 14** — all 12 required components implemented, §14a |
| Role → destination rule duplicated across 6 files | **RESOLVED in Phase 21** — consolidated into `roleDestinations.ts`, §8.4 |

---

## 14a. Phase 14 — shared dashboard design system

The 12 components the programme requires, all implemented as **presentation-only** building
blocks in `frontend/src/components/dashboard/index.tsx`. None fetches, authorizes, or decides
what a user may see.

| Component | Role | Notable guarantee |
|---|---|---|
| `DashboardShell` | shell | exactly one `<main>` landmark and one `<h1>` per dashboard |
| `Sidebar` | navigation | labelled `<nav>`; `aria-current="page"` on the active item. **UX only — not a security boundary** |
| `TopBar` | header | identifies the role; does not repeat the page title |
| `StatCard` | KPI tile | `tone` colours the value; unknown input never renders as "good" |
| `DataTable` | table | `<caption>` + `<th scope="col">`; **never re-sorts** — API order is preserved |
| `StatusBadge` | badge | unknown status falls back to neutral, never a positive tone |
| `FilterBar` | filters | every control is `<label for>` bound |
| `EmptyState` | empty | real fallback, not a blank table |
| `LoadingState` | loading | `role="status"` + `aria-live="polite"` |
| `ErrorState` | error | `role="alert"` + retry |
| `ConfirmDialog` | modal | `role="dialog"` + `aria-modal` + labelled |
| `ActivityTimeline` | timeline | timestamp + status per entry |

**Tests:** 20 vitest (`dashboardComponents.test.tsx`), including deterministic ordering,
single-landmark and unknown-status-fallback assertions. **No backend change.**

---

## 14. Verification commands

```powershell
# role vocabulary
php artisan tinker --execute="print_r(App\Models\User::ROLES);"

# alias route counts
php artisan route:list --path=api --json

# migration state
php artisan migrate:status

# documentation consistency (this document vs the implementation)
php artisan test --do-not-cache-result --filter RoleCapabilitiesDocumentationTest
```