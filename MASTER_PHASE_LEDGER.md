# MASTER_PHASE_LEDGER.md — MasterInTech Programme Execution Ledger

**Programme:** MasterInTech — ROLE/RBAC + MULTI-DASHBOARD BUILD
**Repository:** MasterInTech
**Branch:** `integration/master-intech-complete`
**Ledger created:** Phase 4 completion audit
**Purpose:** Persistent execution ledger. Every phase status below is backed by executable evidence or an explicit BLOCKED/NEEDS-DECISION marker.

---

## 0. AUTHORITATIVE PROGRAMME SOURCE

> **SOURCE: conversation-supplied authoritative programme brief**
>
> The ROLE/RBAC 23-phase programme is **NOT present in the repository.** Verified:
>
> | Probe | Hits |
> |---|---|
> | `ROLE/RBAC` | 0 |
> | `MULTI-DASHBOARD` | 0 |
> | `ROLE CAPABILITIES` | 0 |
> | `PHASE 15`–`PHASE 23` | 0 each |
> | `MASTER_PHASE_LEDGER` | 0 |
>
> All `Phase N` references found in the repository are **second-hand quotes** inside
> `ROLE_BASELINE.md` (Phases 4, 5, 13, 14, 20, 21) or belong to **unrelated taxonomies**:
> classroom feature phases, Playwright P0/P1 execution phases, B-series audit IDs
> (B16/B17/B18), S-series security IDs (S-02/S-03), deployment phases.
>
> **This ledger reconstructs phase intent from the conversation brief and from verified
> repository evidence. No phase text has been invented. Where the brief is silent,
> the phase is recorded as sourced from the brief's phase title plus evidence, and
> any ambiguity is recorded in §7 rather than silently resolved.**

---

## 1. CURRENT VERIFIED STATE

| Metric | Value |
|---|---|
| HEAD | `777d5fb334fa4a17ca06b7c9d149e954677f698a` (local == remote) |
| Working tree | 2 modified (Phase 4), 3 Phase-3/4 untracked artifacts, 4 pre-existing artifacts |
| Backend tests | **1218 passed / 1218**, 6682 assertions |
| Frontend tests | **209 passed / 209** (24 files) |
| TypeScript | exit 0 |
| Lint | 0 errors, 12 pre-existing warnings |
| Build | exit 0 |
| Migrations | 91 applied, max batch 9, **0 pending** |
| Role checksum | `62cb694bbd2979e3e2e153a5981d851b` |

### Route inventory (verified, corrected)

| Classification | Routes |
|---|---:|
| **Total API routes** | **422** |
| public (no Sanctum auth) | 44 |
| authenticated (Sanctum) | 378 |
| **role-guarded (distinct routes)** | **281** |
| **authenticated-only, no role guard** | **97** |

Per middleware alias (a route may carry more than one):

| Alias | Routes |
|---|---:|
| `EnsureUserIsAdmin` | 158 |
| `EnsureUserIsTutorOrAdmin` | 61 |
| `EnsureUserIsPlacementStaff` | 46 |
| `EnsureUserCanAccessCrm` | 26 |
| `EnsureUserIsCompany` | 13 |
| super_admin-dedicated | 0 |

**Correction to a previously recorded figure.** Phases 0–4 reported "authenticated-only = 118",
computed as `422 − 304` where 304 is the *sum* of the alias counts. That sum double-counts the
**23 routes guarded by both `EnsureUserIsAdmin` and `EnsureUserIsPlacementStaff`**, so 118
was overstated. The deduplicated figures are **281 role-guarded** and **97
authenticated-only**; `44 + 281 + 97 = 422` reconciles exactly. No per-alias count changed, and
the Phase 4 endpoints are intact:
`GET /api/admin/placements/interviews` and `GET /api/admin/placements/audit-events`.

### Database baseline (verified read-only)

users 19 · roles 12 · courses 107 · certificates 1 · companies 0 · enquiries 19 ·
placement_opportunities 1 · audit_logs 136 · batches 1 · migrations 91 · max batch 9 ·
pending 0 · orphan records 0 · tables 86 · `PRAGMA integrity_check` = ok

Role registry content matches the documented 12 roles exactly (names and labels), with none
missing and none unexpected. **The previously recorded checksum
`62cb694bbd2979e3e2e153a5981d851b` could not be reproduced** — its original definition is not
recorded anywhere in the repository, and none of eight plausible definitions (md5 over names,
labels, rowid pairs, or full-row JSON) matched. Registry integrity is therefore verified by
**content comparison**, not by that checksum, and the checksum is no longer claimed as a
baseline.

---

## 2. PHASE COMPLETION MATRIX

| Phase | Title (brief) | Status | Evidence |
|---|---|---|---|
| 0 | BASELINE | **COMPLETE** | `ROLE_BASELINE.md`, 29 acceptance-table rows, 0 source modified |
| 1 | AUTHENTICATION ARCHITECTURE | **COMPLETE** | Verified: email/password, OTP, Google, Sanctum, single-session, logout, role-based redirect. Backend server-authoritative. |
| 2 | ROLE VOCABULARY | **COMPLETE** | Migration `2026_10_04_000002_add_placement_advisor_to_roles_table.php`; roles=12; FK RESTRICT intact; CI-verified on MySQL 8.4 + PostgreSQL 16 (run #24) |
| 3 | ROLE CAPABILITIES | **COMPLETE** | `ROLE_CAPABILITIES.md` (28,951 B); `RoleCapabilitiesDocumentationTest` 19/19, 244 assertions; mutation-proven |
| 4 | PLACEMENT ADVISOR | **COMPLETE** | **20/20 capabilities.** 41 + 34 tests; 4 mutations caught; placement 44→46 routes; `ROLE_CAPABILITIES.md` §10.1/§10.2 |
| 5 | COMPANY PORTAL | **COMPLETE** | 13 routes + `CompanyPortalTest` 13 tests / 104 assertions: unapproved-company block, multi-tenant isolation, cross-company application prevention, cross-portal security, 18-step lifecycle |
| 6 | TELECALLER DASHBOARD | **COMPLETE** | `/crm/telecaller` — dedicated Telecaller Desk, 8 KPIs from brief. Presentation only over the shared scoped tier. 16 vitest + browser-verified |
| 7 | COURSE ADVISOR DASHBOARD | **COMPLETE** | `/crm/course-advisor` — Course Advisor Desk, 10 KPIs incl. conversion rate. Presentation only. 16 vitest + browser-verified |
| 8 | COUNSELLOR DASHBOARD | **COMPLETE** | `/crm/counsellor` — Counsellor Desk, 8 KPIs. Presentation only. 16 vitest + browser-verified |
| 9 | TUTOR DASHBOARD | **COMPLETE** | `TutorDashboard.tsx` (46 KB), 61 tutor routes; `TutorAndRoleSecurityTest` + `Goal8TutorPermissionsTest` in the 149-test role suite |
| 10 | STUDENT DASHBOARD | **COMPLETE** | `StudentDashboard.tsx` (61 KB); `Goal7StudentDashboardTest`; published-only placement access preserved |
| 11 | ADMIN DASHBOARD | **COMPLETE** | `/admin` → `AdminLayout` console, 158 role-guarded admin routes; `AdminAuthorizationTest` + `AdminDashboardTest` |
| 12 | SUPER ADMIN | **COMPLETE** | Administrative tier; 0 dedicated routes (by design); grant/revoke + last-admin guards verified in the 149-test role suite |
| 13 | FRONTEND ROUTING | **COMPLETE** | `roleDestinations.ts` = single source of truth for all 12 roles; consumed by `Login` + 6 guards. 8 vitest + browser-verified for every role |
| 14 | DASHBOARD DESIGN | **COMPLETE** | **All 12 components implemented** in `components/dashboard/index.tsx`; 20 vitest; single-`<main>` guarantee; `ROLE_CAPABILITIES.md` §14a |
| 15 | SECURITY | **COMPLETE** | 13 boundaries in `ROLE_CAPABILITIES.md` §12; all PASS post-Phase-4 |
| 16 | AUDIT LOGGING | **COMPLETE** | Placement approval actions audited (`approved_company_job`, `rejected_company_job`); placement-scoped read-only audit view (Phase 4) |
| 17 | TESTS | **COMPLETE** | Backend 1218/1218, 6682 assertions |
| 18 | FRONTEND TESTS | **COMPLETE** | 209/209 across 24 files |
| 19 | BUILD VERIFICATION | **COMPLETE** | `npm run build` exit 0; `npx tsc -b` exit 0; lint 0 errors (12 pre-existing warnings); `php artisan route:list` verified |
| 20 | DATABASE VERIFICATION | **COMPLETE** | roles=12 verified; 0 pending migrations; FK intact; 0 orphans |
| 21 | BROWSER SMOKE TEST | **COMPLETE** | **15/15 Playwright.** 11 roles land on their own dashboard + survive hard refresh with no loop; invalid credentials rejected; telecaller blocked from `/admin`; student blocked from `/placement`; placement endpoints return no 4xx/5xx. **Found and fixed a real routing defect** (§8.4) |
| 22 | DO NOT BREAK PRODUCTION | **COMPLIANT** | No schema change, no migration edit. Application data baselines identical. See §22a for the session-row incident and restoration |
| 23 | GIT DISCIPLINE | **COMPLIANT** | HEAD unchanged; 0 staged; 4 pre-existing artifacts untouched; **no commit, no push** |

---

## 21a. Phase 21 — browser smoke test evidence

Harness written **outside tracked source**:
`C:\Users\Preetish\AppData\Local\Temp\opencode\smoke\{playwright.config.ts,phase21.spec.ts}`.
Run against an isolated database copy (see §22a).

| # | Assertion | Result |
|---|---|---|
| 11 | one per role: login → correct dashboard → hard refresh → no `/login` bounce → no 5xx → no console errors | PASS |
| 1 | invalid credentials are rejected and do not authenticate | PASS |
| 1 | telecaller navigating to `/admin` is redirected away | PASS |
| 1 | student navigating to `/placement` is redirected away | PASS |
| 1 | `placement_advisor` dashboard loads with no 4xx/5xx from `/api/admin/placements/*` | PASS |
| | **total** | **15/15 PASS** |

### Defect found by this phase (fixed)

Phases 6/7/8 created three dedicated CRM desks, but **the login redirect still sent all three
CRM roles to the shared `/admin/crm` page**, so the new dashboards were unreachable without
typing a URL. Cause: `Login.tsx` and five route guards each held their own copy of the
role → destination rule. Fix: consolidated into `frontend/src/utils/roleDestinations.ts`,
consumed by every guard and by `Login`, so the rule has exactly one definition. Two existing
tests that pinned the old shared destination were updated **to the new requirement** (not
weakened) and one was strengthened to additionally assert the guard never redirects to
`/admin`.

---

## 22a. Phase 21 — database incident and restoration (disclosed)

While standing up the smoke environment I started the backend with
`php artisan serve`. **On this Windows host `variables_order` does not include `E`, so PHP's
`$_ENV` is empty; `artisan serve` spawns its `php -S` child with that empty array and the
`DB_DATABASE` override was silently lost.** The server therefore fell back to Laravel's
default and used the **real** development database.

Exactly **one** row was written: an anonymous `sessions` row (`user_id = NULL`) created by one
failed login POST. No user, no `personal_access_tokens` row (`max(created_at)` remained
`2026-10-05 02:43`), and no application data was altered.

| Action | Detail |
|---|---|
| Detected | real DB SHA256 changed `D3C1A126…` → `DD64AFC1…`; sessions 8 → 9 |
| Scoped hunt | every baseline count matched; zero rows anywhere with a timestamp in the last 2 hours; identified the one new session by exact `last_activity` |
| Restored | that single row deleted under an exact triple predicate (`id` + `user_id IS NULL` + `last_activity`), guarded by a pre-check that aborted unless it matched **exactly one** row |
| Verified | sessions back to 8; users 19, courses 107, certificates 1, companies 0, migrations 91, roles 12; newest session again `2026-10-04`; 0 smoke accounts; 0 tokens created |
| **Honest caveat** | the file is **logically** restored but **not byte-identical** (`B1A2452E…`): SQLite reused pages differently after insert-then-delete. No data differs. |
| Root cause fixed | switched to `php -S … public/index.php` started directly, which inherits the environment. Isolation then **proved**, not assumed: a smoke-only account authenticated (it exists solely in the copy), the real DB hash was unchanged, and the session/token writes landed in the copy. |
| Pre-existing server | a Laravel server already occupied port **8000** before this work. It was left running and untouched; only my own port-8001 processes were stopped. |

**No application record was created, modified, or deleted in the real database. No temp users
were ever created there** — every smoke account lives in the isolated copy.

---

## 3. FILES CHANGED PER PHASE

| Phase | Files | Nature |
|---|---|---|
| 0 | `ROLE_BASELINE.md` | new doc (untracked) |
| 1 | `backend/app/Models/User.php`, `AdminUserController.php`, `bootstrap/app.php`, `routes/api.php`, `EnsureUserIsPlacementStaff.php`, `CrmCapabilities.php`, migration `…000002…`, `PlacementAdvisorAuthorizationTest.php`, `CrmCapabilityMatrixTest.php`, `UserRoleConstraintTest.php`, `frontend/src/App.tsx`, `PlacementRoute.tsx`, 5 guards, `Login.tsx`, 2 test files, `ROLE_BASELINE.md` | committed `777d5fb` |
| 2 | **none** (verification only; temp harness outside repo) | none |
| 3 | `ROLE_CAPABILITIES.md`, `RoleCapabilitiesDocumentationTest.php` | untracked |
| 4 | `AdminPlacementController.php` (+247), `routes/api.php` (+6), `PlacementAdvisorRemainingCapabilitiesTest.php` (new), `RoleCapabilitiesDocumentationTest.php` (pinned counts), `ROLE_CAPABILITIES.md` | 2 modified, 2 untracked |
| 6, 7, 8 | `frontend/src/pages/crm/CrmRoleDashboard.tsx` (new), `__tests__/CrmRoleDashboard.test.tsx` (new), `frontend/src/App.tsx` (3 routes) | **frontend only** |
| 13 | `frontend/src/utils/roleDestinations.ts` (new), `Login.tsx`, `AdminRoute.tsx`, `StudentRoute.tsx`, `CompanyRoute.tsx`, `PlacementRoute.tsx`, `CounsellorRoute.tsx`, `utils/__tests__/roleDestinations.test.ts` (new) | **frontend only** |
| 14 | `frontend/src/components/dashboard/index.tsx` (new), `__tests__/dashboardComponents.test.tsx` (new) | **frontend only** |
| 21 | harness in `%TEMP%\opencode\smoke\` — **outside tracked source**; no repo file added | none |

**Backend changes across Phases 6/7/8/13/14/21: NONE.** Verified by
`git diff --stat -- backend`, which still shows exactly the Phase 4 delta (+253 lines across
2 files).

---

## 4. TESTS PER PHASE

| Phase | Added | Suite result at completion |
|---|---:|---|
| 1 | 56 (41 + 15) | backend 1165/1165, 6331 assertions; CI #24 all 4 jobs PASS |
| 2 | 0 | browser 9/9 PASS |
| 3 | 19 | backend 1184/1184, 6581 assertions |
| 4 | 34 | backend 1218/1218, 6682 assertions |
| 6, 7, 8 | 16 vitest | frontend 201/201 |
| 13 | 8 vitest (+ 2 existing expectations updated, 1 strengthened) | frontend 209/209, 24 files |
| 14 | 20 vitest | included above |
| 21 | 15 Playwright | **15/15 PASS** |

Frontend total moved **165 → 209** (+44); backend total is unchanged at **1218** because
Phases 6/7/8/13/14/21 changed no backend code.

---

## 5. ROUTE CHANGES PER PHASE

| Phase | Before | After | Δ |
|---|---:|---:|:--:|
| 1 | 420 | 420 | 0 (middleware swapped on 23 existing routes; `/placement` + `/crm/*` are frontend-only) |
| 3 | 420 | 420 | 0 |
| 4 | 420 | **422** | **+2** (`GET /admin/placements/interviews`, `GET /admin/placements/audit-events`) |

---

## 6. MIGRATION CHANGES PER PHASE

| Phase | Migrations | Reason |
|---|---:|---|
| 2 | +1 | `…000002_add_placement_advisor_to_roles_table.php` — forward-only, idempotent, FK untouched |
| 3, 4 | 0 | existing schema sufficed |

**No migration rewritten. No historical migration edited.**

---

## 7. SECURITY CHANGES PER PHASE

| Phase | Change | Mutation-verified |
|---|---|---|
| 1 | New `EnsureUserIsPlacementStaff`; CRM capability matrix; role-assignment policy | Yes |
| 3 | None (documentation only) | Yes — 7 doc mutations caught |
| 4 | 2 read-only placement endpoints; `PLACEMENT_AUDIT_ACTIONS` allowlist (20 events); `redactAuditPayload()`; withheld `ip_address`/`user_agent`/`meeting_link`/`admin_notes`/`interviewer_notes`/`instructions` | **Yes — 4/4 mutations caught** |
| 6, 7, 8 | **No authorization change.** Presentation-only dashboards over the existing shared scoped CRM tier | Yes — **M1** |
| 13, 14, 21 | No authorization change (routing + presentation). CRM desks consume only endpoints the role could already call | Yes — **M2** |

### Mutations run in this session

| ID | Mutation | Expected | Actual | Restoration |
|---|---|---|---|---|
| **M1** | `Enquiry::visibleTo()` — removed the `assigned_counsellor_id = user OR NULL` scoping condition, so scoped CRM roles would see every lead | CRM suite fails | **1 failure**: `CrmFrontlineRolesTest::test_scoped_listing_and_stats` — `other-crm@example.com` leaked into a scoped role's listing | Restored, **SHA256 byte-identical**, 76/76 pass |
| **M2** | `EnsureUserIsPlacementStaff` — removed the `canOperatePlacement() \|\| isAdmin()` role check | placement suites fail | **21 failures**, incl. `test_student_cannot_access_placement_administration` (200 vs 403) and `test_company_cannot_approve_its_own_vacancy` | Restored, **SHA256 byte-identical**, 75/75 pass |

**No mutation artifact remains.** Both mutated files were verified back to their pristine
SHA256 and the mutation marker text confirmed absent.

### Preserved baseline (all verified PASS after Phase 4)
admin vs ordinary-user · tutor ≠ admin · CRM scoped · placement ≠ admin · company cannot self-approve · company cannot self-publish · company cannot cross-company · approved-company requirement · student published-only · super_admin grant/revoke · no self-role-change · last-admin protection · forceFill role writes · instructor RESERVED · CRM three-way TARGET-only · admin audit endpoint independent

---

## 8. DECISIONS RECORDED

| ID | Decision | Outcome |
|---|---|---|
| C1 | Placement approval: do **not** add an `approved` DB state | `approveJob` keeps `PENDING_APPROVAL → PUBLISHED` |
| C5 | `placement_advisor` via **new migration**, not by editing deployed migration | `…000002…` forward-only |
| — | Role-assignment policy: all 12 roles assignable; privilege gated by escalation guards, not by list membership | `Rule::in(User::ASSIGNABLE_ROLES)` + 4 guards |
| A1 | CRM: document shared tier as current, three-way split as **TARGET** | §8.1 / §8.2 |
| A2 | `instructor` = **RESERVED / UNPROVISIONED** | §9 |
| A4 | `ROLE_CAPABILITIES.md` is the capability artifact; `ROLE_BASELINE.md` stays the baseline | both exist |
| D3 | Interview pipeline: **IMPLEMENT** read-only, placement-guarded | Phase 4 |
| D4 | Audit events: **IMPLEMENT** allowlisted, read-only, placement-guarded | Phase 4 |
| — | `CrmCapabilities` placed at `backend/app/Services/` (approved; `app/Support` does not exist) | — |

---

## 9. UNRESOLVED AMBIGUITIES

| ID | Ambiguity | Status |
|---|---|---|
| U1 | Phases 6/7/8 require role-specific dashboards with **distinct KPI sets** while §10 forbids the CRM three-way split | **RESOLVED.** Implemented as **presentation-only** over the unchanged shared scoped tier — distinct information architecture, identical and unchanged backend authorization. No CRM enforcement was touched (§8.4) |
| U2 | Phase 14 requires 12 named shared components that do not exist | **RESOLVED** — all 12 implemented, 20 tests, §14a |
| U3 | Phase 21 requires browser verification of Phase 4 endpoints | **RESOLVED** — 15/15 Playwright, including the Phase 4 placement endpoints |
| U4 | Phase 5/9/10/11/12 acceptance criteria exist only as brief prose | **RESOLVED** — verified against existing code and their test suites; nothing rebuilt |
| U5 | Does "distinct dashboards" imply distinct *authorization*? | **NO — decided deliberately.** The brief for Phase 6 explicitly says telecaller "must see only the records permitted by the existing CRM scoping rules", so dashboards were built without widening or narrowing any scope. This is a presentation decision, not a business decision, and needs no ruling |
| U6 | The recorded role checksum `62cb694b…` is not reproducible | **DISCLOSED.** Its definition is not stored anywhere in the repo; 8 plausible definitions all failed to match. Registry integrity is now verified by content comparison (12 names + labels exact) |

---

## 10. KNOWN NON-PHASE ITEMS

| # | Item | Classification |
|---|---|---|
| 1 | CRM three-way split | PRODUCT REQUIREMENT — **deliberately not authorized.** Phases 6/7/8 delivered distinct dashboards without touching scoping (§8.4); the authorization split itself remains TARGET |
| 2 | `instructor` portal | RESERVED — no authoritative definition. Deliberately left unprovisioned; `destinationForRole` sends it to the default rather than granting a desk |
| 3 | `activityLogs` reads `LearningActivityLog`, not `AuditLog` | TECHNICAL DEBT — deliberately unrepaired |
| 4 | 97 authenticated-only routes have no role guard | TECHNICAL DEBT — pre-existing (was recorded as 118; corrected, see §1) |
| 5 | `User::isTutor()` narrower than `tutor` middleware | INTENTIONAL — documented; `faculty` is `isTutor() === false` yet passes `EnsureUserIsTutorOrAdmin` |
| 6 | Phase 1–4 artifacts uncommitted | PROCESS — awaiting commit authorization |
| 7 | No frontend UI for Phase 4's two endpoints | OPTIONAL UX — backend is the security boundary; both endpoints browser-verified to return no 4xx/5xx |
| 8 | VPS deployment | EXTERNAL BLOCKER - no SSH credential for the production VPS; ports 80/443 unreachable |
| 9 | 44 public API routes | BY DESIGN — courses, webhooks, auth, video streaming. `ThrottleRequests` present on write/public surfaces |

---

## 11. FINAL VERIFICATION STATE

| Gate | Command | Result |
|---|---|---|
| Backend suite | `php artisan test` | **PASS** — 1218/1218, 6682 assertions, exit 0 |
| Frontend suite | `npm test -- --run` | **PASS** — 209/209, 24 files, exit 0 |
| TypeScript | `npx tsc -b` | **PASS** — exit 0 |
| Lint | `npm run lint` | **PASS** — exit 0, 0 errors, 12 pre-existing warnings (unchanged baseline) |
| Production build | `npm run build` | **PASS** — exit 0 |
| Route inventory | `php artisan route:list --path=api --json` | 422 total; 44 public / 281 role-guarded / 97 auth-only; reconciles to 422 |
| Database audit | read-only PDO | `integrity_check` ok; 86 tables; 91 migrations, 0 pending; 12 roles exact; 0 orphans |
| Browser smoke | Playwright (outside repo) | **15/15 PASS** |
| Mutation M1 | `Enquiry::visibleTo` scoping removed | **CAUGHT** — 1 failure; restored byte-identical |
| Mutation M2 | `EnsureUserIsPlacementStaff` role check removed | **CAUGHT** — 21 failures; restored byte-identical |
| Git | `git status` / `git diff --stat` | Backend diff = Phase 4 only (+253 / 2 files). **No commit, no push.** 4 pre-existing artifacts untouched |

### Authorization matrix (measured from the model, no DB writes)

| Role | canAccessCrm | scoped CRM | canOperatePlacement | isAdmin | Verdict |
|---|---|---|---|---|---|
| `super_admin` | ALLOW | — | DENY | yes | ALLOW (administrative tier) |
| `admin` | ALLOW | — | DENY | yes | ALLOW (administrative tier) |
| `tutor` | DENY | — | DENY | no | ALLOW (tutor tier only) |
| `faculty` | DENY | — | DENY | no | ALLOW (tutor tier only) |
| `student` | DENY | — | DENY | no | ALLOW (published-only placement scope) |
| `telecaller` | ALLOW | SCOPED | DENY | no | SCOPED |
| `counsellor` | ALLOW | SCOPED | DENY | no | SCOPED |
| `course_advisor` | ALLOW | SCOPED | DENY | no | SCOPED |
| `placement_advisor` | DENY | — | ALLOW | no | SCOPED (placement only) |
| `company` | DENY | — | DENY | no | SCOPED (own company, approved) |
| `recruiter` | DENY | — | DENY | no | SCOPED (own company, approved) |
| `instructor` | DENY | — | DENY | no | **RESERVED / UNPROVISIONED** |

No role appears in two tiers at once except `admin`/`super_admin`, which is the intended
administrative tier. `placement_advisor` cannot reach CRM or admin; CRM roles cannot reach
placement or admin; `company`/`recruiter` cannot reach admin, CRM or placement.

**Phase baseline unchanged from Phase 4**, except the two documented route additions, the two
new frontend dashboards plus the Phase 14 design system, and the routing consolidation.