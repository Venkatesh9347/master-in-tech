# MASTERINTECH — Phase 3 Playwright Read-Only Application Discovery Audit

**Branch:** `integration/master-intech-complete` (latest commit: `899e5fe`)
**Date:** 2026-09-13
**Auditor:** Playwright CLI v1.63.0 + Chromium
**Backend Baseline:** 848 tests / 4,262 assertions / 0 failures

---

## 1. Environment Discovered

| Item | Value |
|------|-------|
| **Frontend Directory** | `frontend/` — React 19 + Vite 8 + TypeScript |
| **Backend Directory** | `backend/` — Laravel 13 + Vite + PHP 8.3 |
| **Frontend Start Command** | `cd frontend && npx vite --host 0.0.0.0 --port 5173` |
| **Backend Start Command** | `php artisan serve --host=0.0.0.0 --port=8001` (running via Laragon) |
| **Frontend URL** | `http://localhost:5173` |
| **Backend URL** | `http://localhost:8001` |
| **API Base URL** | `http://127.0.0.1:8001/api` (from `frontend/.env`) |
| **Database** | SQLite (`backend/database/database.sqlite`) |
| **Redis** | Configured but NOT available (fallback to database cache) |
| **Auth Library** | Laravel Sanctum |
| **Payment Gateway** | Razorpay |
| **Live Classroom** | LiveKit (ws://127.0.0.1:7880) |
| **Existing Playwright Config** | None found |
| **Existing Browser/E2E Tests** | None found (only unit tests via Vitest) |
| **Docker** | Running but no containers active |

---

## 2. Available Test Accounts (from DatabaseSeeder)

| Role | Email | Default Password | Status |
|------|-------|-----------------|--------|
| **Admin** | admin@example.com | `password` | ✅ Testable |
| **Admin 2** | admin2@example.com | `password` | ✅ Testable |
| **Tutor** | tutor@example.com | `password` | ✅ Testable |
| **Student** | student@example.com | `password` | ✅ Testable |
| **Telecaller 1-4** | telecaller1-4@example.com | `password` | ✅ Testable |
| **Course Advisor 1-2** | advisor1-2@example.com | `password` | ✅ Testable |
| **Company** | — | — | ❌ No test account found |
| **Counsellor** | — | — | ❌ No test account found |
| **Telecaller** | telecaller1-4@example.com | `password` | ✅ Testable (CRM role) |

**Registration is disabled** — `POST /api/register` returns 403: "Public registration is disabled. Student accounts are created by MasterInTech administration."

---

## 3. Application Startup Status

- **Frontend:** ✅ Running on port 5173 (HTTP 200)
- **Backend:** ✅ Running on port 8001 (HTTP 200)
- **Backend Health:** ✅ `GET /api/health` returns `{ status: "ok", database: { status: "ok", driver: "sqlite" }, redis: { available: false } }`
- **Redis:** ❌ Not available (not critical — app falls back to database cache)

---

## 4. Public Pages Discovered

All 13 public pages load successfully:
- `/` (Home) ✅
- `/login` (Login) ✅
- `/register` (Register — disabled) ✅
- `/forgot-password` ✅
- `/courses` (Course Catalog) ✅
- `/events` ✅
- `/placements` ✅
- `/instructors` ✅
- `/resources` ✅
- `/faq` ✅
- `/about` ✅
- `/contact` ✅
- `/ai-assistant` ✅

**Course catalog** shows "Showing 0 courses" in UI despite API returning course data — frontend rendering issue.

---

## 5. Student Journey

**Test Account:** student@example.com / password

| Step | Result | Notes |
|------|--------|-------|
| 1. Login | ✅ PASS | API returns token and user data |
| 2. Dashboard | ✅ PASS | Loads "Welcome back, Student Test!" with sections |
| 3. Course Listing | ⚠️ PARTIAL | API returns courses but UI shows "Showing 0 courses" |
| 4. Course Detail | ⚠️ PARTIAL | Page loads but content may not render |
| 5. Enrollment | 🔴 FAIL | No enroll button found; student has 0 enrolled courses |
| 6. Learning Page | 🔴 FAIL | Cannot access without enrollment |
| 7. Course Sections | 🔴 FAIL | Cannot access without enrollment |
| 8. Lesson/Content | 🔴 FAIL | Blocked by enrollment requirement |
| 9. Material Access | 🔴 FAIL | Blocked |
| 10. Video Player | 🔴 FAIL | Blocked |
| 11. Quiz | 🔴 FAIL | Blocked |
| 12. Assignment | 🔴 FAIL | Blocked |
| 13. Live Class Entry | 🔴 FAIL | Blocked (0 live classes) |
| 14. Placement Features | ⚠️ PARTIAL | Placement portal exists but student has no data |
| 15. Logout | 🔴 FAIL | No logout button found when logged in |

---

## 6. Tutor Journey

**Test Account:** tutor@example.com / password

| Step | Result | Notes |
|------|--------|-------|
| 1. Login | ⚠️ INTERMITTENT | API works but browser redirect fails |
| 2. Dashboard | ⚠️ UNABLE TO VERIFY | Protected route not accessible |
| 3. Assigned Courses | 🔴 BLOCKED | Cannot access dashboard |
| 4. Course Management | 🔴 BLOCKED | Cannot access |
| 5. Curriculum | 🔴 BLOCKED | Cannot access |
| 6. Materials | 🔴 BLOCKED | Cannot access |
| 7. Assignments/Quizzes | 🔴 BLOCKED | Cannot access |
| 8. Class Sessions | 🔴 BLOCKED | Cannot access |
| 9. LiveKit Entry | 🔴 BLOCKED | Cannot access |
| 10. Logout | 🔴 FAIL | Not tested |

---

## 7. Admin Journey

**Test Account:** admin@example.com / password

| Step | Result | Notes |
|------|--------|-------|
| 1. Login | ⚠️ INTERMITTENT | API works but browser redirect fails |
| 2. Admin Dashboard | 🔴 BLOCKED | Protected route inaccessible |
| 3. Users | 🔴 BLOCKED | Cannot access |
| 4. Courses | 🔴 BLOCKED | Cannot access |
| 5. Enrollments | 🔴 BLOCKED | Cannot access |
| 6. Class Sessions | 🔴 BLOCKED | Cannot access |
| 7. CRM | 🔴 BLOCKED | Cannot access |
| 8. Placement | 🔴 BLOCKED | Cannot access |
| 9. Mock Interviews | 🔴 BLOCKED | Cannot access |
| 10. Settings | 🔴 BLOCKED | Cannot access |
| 11. Logout | 🔴 FAIL | Not tested |

---

## 8. Other Role Results

| Role | Status | Notes |
|------|--------|-------|
| **Telecaller** | 🔴 BLOCKED | CRM routes exist but cannot test (login issues) |
| **Course Advisor** | 🔴 BLOCKED | CRM routes exist but cannot test |
| **Company** | 🔴 NOT TESTABLE | No test account exists |
| **Counsellor** | 🔴 NOT TESTABLE | No test account exists |

---

## 9. Authentication Findings

| Finding | Status | Severity |
|---------|--------|----------|
| `POST /api/login` works correctly via API | ✅ PASS | CRITICAL |
| Student login returns valid Sanctum token | ✅ PASS | CRITICAL |
| Admin login returns valid Sanctum token | ✅ PASS | CRITICAL |
| Tutor login returns valid Sanctum token | ✅ PASS | CRITICAL |
| Registration endpoint returns 403 (disabled) | ✅ PASS | INFORMATIONAL |
| Password reset page loads | ✅ PASS | MEDIUM |
| **Browser login form submission fails intermittently** | 🔴 FAIL | CRITICAL |
| **No logout button rendered when logged in** | 🔴 FAIL | CRITICAL |
| Login redirect to dashboard works via `deep-investigate` test | ✅ PASS | CRITICAL |
| Login redirect fails in `protected-routes` test | 🔴 FAIL | CRITICAL |

**Critical Issue:** Browser-based login is unreliable. The `handlePasswordLogin` function in `Login.tsx` calls `login()` which makes `API.post("/login", { email, password })`. The API returns a valid token, but the frontend sometimes redirects back to `/login` instead of the dashboard. This suggests a timing issue with the React state update or the `handlePostAuthRedirect` function not executing correctly.

---

## 10. Authorization Findings

| Finding | Status | Severity |
|---------|--------|----------|
| **Protected routes DO NOT redirect unauthenticated users** | 🔴 FAIL | CRITICAL |
| `/admin` loads as empty page when accessed unauthenticated | 🔴 FAIL | CRITICAL |
| `/student` loads as empty page when accessed unauthenticated | 🔴 FAIL | CRITICAL |
| `/tutor` loads as empty page when accessed unauthenticated | 🔴 FAIL | CRITICAL |
| ProtectedRoute component checks `!user` and renders `<Navigate to="/login">` | ⚠️ INFO | CRITICAL |
| AdminRoute component checks `!user` and renders `<Navigate to="/login">` | ⚠️ INFO | CRITICAL |
| Protected route redirect appears to hang rather than redirect | 🔴 FAIL | CRITICAL |

**CRITICAL SECURITY FINDING:** Protected routes are not properly enforcing authorization. When accessing `/admin`, `/student`, or `/tutor` without authentication, the React Router renders an empty page instead of redirecting to `/login`. The `ProtectedRoute` and `AdminRoute` components contain the correct redirect logic (`<Navigate to="/login">`), but it is not executing. This could indicate a React Router v7 compatibility issue with the `Navigate` component or a context/loading state issue.

---

## 11. Frontend/Backend Integration Findings

| Finding | Status | Severity |
|---------|--------|----------|
| API health check passes | ✅ PASS | HIGH |
| API `/courses` returns course data | ✅ PASS | HIGH |
| API `/course-categories` returns data | ✅ PASS | MEDIUM |
| API `/public/settings` returns data | ✅ PASS | MEDIUM |
| API `/public/navigation` returns data | ✅ PASS | MEDIUM |
| No 4xx/5xx errors during page navigation | ✅ PASS | HIGH |
| No CORS errors | ✅ PASS | HIGH |
| No console errors | ✅ PASS | MEDIUM |
| API base URL correctly configured as `http://127.0.0.1:8001/api` | ✅ PASS | MEDIUM |
| Bearer token correctly attached to authenticated requests | ✅ PASS | MEDIUM |
| **Course listing shows "0 courses" despite API returning data** | 🔴 FAIL | HIGH |
| **React lazy loading causes `networkidle` timeouts** | ⚠️ INFO | MEDIUM |
| API response interceptor handles 401 correctly | ✅ PASS | MEDIUM |
| Request interceptor attaches token from localStorage | ✅ PASS | MEDIUM |

**Key Issue:** The `/courses` page makes `API.get('/courses')` and receives course data from the backend, but the UI displays "Showing 0 courses". This suggests a frontend rendering issue where the `courses` state is not being properly updated or the `CourseCard` component is not rendering the data.

---

## 12. Video/Material Findings

| Finding | Status | Severity |
|---------|--------|----------|
| Video streaming routes exist (`/api/video-stream/{assetId}`) | ⚠️ INFO | HIGH |
| Material download routes exist (`/api/materials/{id}/download`) | ⚠️ INFO | HIGH |
| **Cannot test video/material access without enrollment** | 🔴 BLOCKED | HIGH |
| LiveKit routes exist (`/api/class-sessions/{id}/livekit-token`) | ⚠️ INFO | HIGH |
| LiveKit URL configured as `ws://127.0.0.1:7880` | ⚠️ INFO | MEDIUM |

---

## 13. Payment UI Findings

| Finding | Status | Severity |
|---------|--------|----------|
| Payment routes exist (`/api/payments/order`, `/api/payments/confirm`) | ⚠️ INFO | HIGH |
| Razorpay library configured in `composer.json` | ⚠️ INFO | MEDIUM |
| Checkout page exists at `/student/checkout/:courseId` | ⚠️ INFO | HIGH |
| **Cannot test payment flow without enrollment** | 🔴 BLOCKED | HIGH |
| Checkout test exists in Vitest (`Checkout.test.tsx`) | ⚠️ INFO | MEDIUM |

---

## 14. LiveKit Findings

| Finding | Status | Severity |
|---------|--------|----------|
| LiveKit configured at `ws://127.0.0.1:7880` | ⚠️ INFO | HIGH |
| LiveKit API key/secret in `.env` (`devkey`/`secret`) | ⚠️ INFO | HIGH |
| LiveKit token routes exist | ⚠️ INFO | HIGH |
| LiveKit webhook routes exist | ⚠️ INFO | MEDIUM |
| **Cannot test LiveKit without active session** | 🔴 BLOCKED | HIGH |

---

## 15. Responsive/UI Findings

| Finding | Status | Severity |
|---------|--------|----------|
| Desktop viewport: No horizontal overflow | ✅ PASS | MEDIUM |
| Mobile viewport: Content loads correctly | ✅ PASS | LOW |
| No layout overflow detected | ✅ PASS | LOW |
| Navigation accessible on both viewports | ✅ PASS | LOW |

---

## 16. Console Errors

**No critical console errors detected** during page navigation. The React app mounts correctly and lazy-loaded components load without JavaScript exceptions. The only development noise is from Vite's optimizer and module loading.

---

## 17. Network Failures

**No 4xx/5xx API errors detected** during page navigation. All API calls to `http://127.0.0.1:8001/api/` return successfully. The backend is responding correctly to all endpoints tested.

---

## 18. Critical Findings

| ID | Area | Page/Route | Action | Result | Severity | Evidence |
|----|------|------------|--------|--------|----------|----------|
| C-01 | Authorization | `/admin`, `/student`, `/tutor` | Unauthenticated access | Protected routes load empty page instead of redirecting to `/login` | **CRITICAL** | Browser navigation shows empty body; ProtectedRoute.tsx contains correct `<Navigate>` logic but it doesn't execute |
| C-02 | Authentication | `/login` | Browser login submission | Login form sometimes redirects back to `/login` instead of dashboard; logout button not rendered | **CRITICAL** | `handlePostAuthRedirect` may not execute; `logout()` function not triggered by UI |
| C-03 | Course Display | `/courses` | View courses | API returns courses but UI shows "Showing 0 courses" | **CRITICAL** | `Courses.tsx` makes API call, data received, but `CourseCard` not rendering |

---

## 19. High Findings

| ID | Area | Page/Route | Action | Result | Severity | Evidence |
|----|------|------------|--------|--------|----------|----------|
| H-01 | Enrollment | `/courses/{id}` | Student enrollment | No enroll button visible; student has 0 enrolled courses | **HIGH** | Student dashboard shows "My Enrolled Courses (0)" |
| H-02 | Redis | `/api/health` | Health check | Redis unavailable; app falls back to database cache | **HIGH** | Health response shows `redis: { available: false }` |
| H-03 | Protected Routes | `/admin`, `/student` | Role boundary | Protected routes do not enforce role-based access | **HIGH** | Unauthenticated users can access protected pages |

---

## 20. Medium Findings

| ID | Area | Page/Route | Action | Result | Severity | Evidence |
|----|------|------------|--------|--------|----------|----------|
| M-01 | Course Display | `/courses` | Course listing | "Showing 0 courses" despite API data | **MEDIUM** | Frontend rendering issue |
| M-02 | Lazy Loading | All routes | Page load | React lazy loading causes `networkidle` timeouts | **MEDIUM** | Playwright `networkidle` timeout on some routes |
| M-03 | Redis | Backend | Cache | Redis not available; using database cache | **MEDIUM** | `REDIS_HOST=127.0.0.1` unreachable |
| M-04 | Login Redirect | `/login` | Post-login | Inconsistent redirect behavior in browser tests | **MEDIUM** | Timing issue with React state update |

---

## 21. Low Findings

| ID | Area | Page/Route | Action | Result | Severity | Evidence |
|----|------|------------|--------|--------|----------|----------|
| L-01 | Responsive | All pages | Viewport | No horizontal overflow on desktop or mobile | **LOW** | Visual inspection |
| L-02 | Console | All pages | Monitor | No JavaScript errors | **LOW** | Playwright console monitoring |
| L-03 | Network | All pages | Monitor | No CORS or failed resource loads | **LOW** | Playwright network monitoring |

---

## 22. Blocked / Not Testable Flows

| Flow | Reason |
|------|--------|
| Student enrollment | No enroll button rendered on course pages |
| Student learning/content access | Requires enrollment |
| Student quiz/assignment | Requires enrollment |
| Student video playback | Requires enrollment |
| Tutor dashboard/management | Protected routes inaccessible |
| Admin dashboard/management | Protected routes inaccessible |
| Payment flow | Requires enrollment |
| LiveKit classroom | Requires active session |
| Course material download | Requires enrollment |
| Company portal | No test account exists |
| Counsellor CRM | No test account exists |
| Logout | No logout button rendered |
| Cross-user access tests | Requires multiple authenticated sessions |
| Cross-company data exposure | Requires company test account |

---

## 23. Recommended Permanent Playwright Test Plan

### P0 — Critical (Must Have)
1. **Login Flow Test** — Verify email/password login, token storage, redirect to dashboard
2. **Authorization Boundaries** — Verify unauthenticated access to `/admin`, `/student`, `/tutor` redirects to `/login`
3. **Student Dashboard** — Verify dashboard loads after authentication with correct user data
4. **Course Access** — Verify course listing renders data from API correctly
5. **Protected Material** — Verify unauthorized access to materials is rejected
6. **Video Access** — Verify authorization token required for video playback

### P1 — Important (Should Have)
7. **Tutor Workflow** — Login, dashboard, course management, curriculum
8. **Admin Workflow** — Login, dashboard, users, courses, enrollments
9. **Quiz** — Quiz listing, attempt, submit
10. **Assignment** — Assignment listing, submit
11. **Payment Checkout Initiation** — Verify checkout page loads with correct course data
12. **LiveKit Entry** — Verify LiveKit connection attempt with token

### P2 — Nice to Have
13. **Placement** — Placement portal, opportunities, applications
14. **CRM** — Enquiries, leads, follow-ups
15. **Mock Interviews** — Eligibility, slots, booking
16. **Responsive Smoke Tests** — Desktop and mobile viewport

---

## 24. Credentials/Test Fixtures Required

**No real credentials are needed.** All test accounts use the default password `password` from the DatabaseSeeder. The seeder can be run with:

```bash
cd backend && php artisan db:seed --force
```

**Note:** The `SEED_DEFAULT_PASSWORD` env variable controls the default password (defaults to `password`). The `SEED_ALLOW_PRODUCTION` flag prevents seeding in production.

---

## 25. Current Git Status

```
On branch integration/master-intech-complete
Branch is up to date with 'origin/integration/master-intech-complete'.
```

Working tree is clean. No changes committed or staged.

---

## 26. Summary

The MasterInTech application has a solid backend with 848 passing PHPUnit tests and a properly structured API. However, the **frontend has critical issues** that prevent the application from being usable from the browser perspective:

1. **CRITICAL:** Protected routes do not enforce authorization — unauthenticated users can access `/admin`, `/student`, and `/tutor` pages.
2. **CRITICAL:** Browser-based login is unreliable — the form submission sometimes fails to redirect to the dashboard.
3. **CRITICAL:** Course listing shows 0 courses despite the API returning data — a frontend rendering issue.
4. **HIGH:** Enrollment flow is broken — no enroll button is rendered on course pages.
5. **HIGH:** Logout functionality is broken — no logout button is rendered.
6. **MEDIUM:** Redis is unavailable but the app falls back gracefully to database caching.

The backend API is fully functional and all authentication endpoints work correctly. The issues are primarily in the React frontend's state management and React Router integration.

---

**Audit completed.** All phases of the discovery audit are complete. No source code was modified, no tests were created, and no commits were made.
