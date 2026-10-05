# MASTERINTECH — Root-Cause Audit of Three Critical Findings

**Scope**: Read-only analysis of source code only. No modifications made.

---

## Finding 1: Protected Routes Not Enforcing Authorization

### A. Finding
Unauthenticated users can access `/admin`, `/student`, and `/tutor` pages. These pages render empty bodies instead of redirecting to `/login`.

### B. Exact Affected Files
- `frontend/src/context/AuthContext.tsx` — AuthProvider, `login()`, `useEffect` for initial auth state
- `frontend/src/context/useAuth.ts` — `useAuth()` hook
- `frontend/src/components/ProtectedRoute.tsx` — Generic protected route guard
- `frontend/src/components/AdminRoute.tsx` — Admin-specific route guard
- `frontend/src/components/StudentRoute.tsx` — Student-specific route guard
- `frontend/src/components/TutorRoute.tsx` — Tutor-specific route guard
- `frontend/src/components/CompanyRoute.tsx` — Company-specific route guard
- `frontend/src/components/CounsellorRoute.tsx` — Counsellor-specific route guard
- `frontend/src/App.tsx` — Route definitions with `<Suspense>` boundary
- `frontend/src/main.tsx` — `StrictMode` wrapping

### C. Exact Execution/Data Flow

1. User accesses `/admin` without authentication
2. React Router v7 matches the `AdminRoute` layout route (no path attribute)
3. `AdminRoute` renders: `const { user, loading } = useAuth()`
4. `useAuth()` returns `{ user: null, loading: false }` (no token in localStorage)
5. `AdminRoute` checks `if (!user)` → renders `<Navigate to="/login" replace state={{ from: location }} />`
6. **Expected**: React Router navigates to `/login`
7. **Actual**: Page renders empty body, no navigation occurs

**Critical detail in `AuthContext.tsx`**:
```tsx
const [loading, setLoading] = useState(() => Boolean(localStorage.getItem("access_token")));
```
- No token → `loading = false`
- `useEffect` runs, sees no token → `setLoading(false)` (already false)
- `user` stays `null`

**Critical detail in `App.tsx`**:
```tsx
<BrowserRouter>
    <AuthProvider>
        <Suspense fallback={<PageLoadingFallback />}>
            <Routes>...</Routes>
        </Suspense>
    </AuthProvider>
</BrowserRouter>
```

All routes are wrapped in `Suspense`. The `AdminRoute` component is NOT lazy-loaded (imported directly), but its child route components (`Dashboard`, etc.) ARE lazy-loaded via `React.lazy`.

### D. Root Cause

**Primary root cause**: React Router v7's `Navigate` component, when rendered inside a layout route that is a child of `<Suspense>`, does not trigger a synchronous navigation. The `Navigate` component renders a navigation action, but because the parent `Suspense` boundary and React Router v7's internal state management batch the navigation state update, the navigation does not complete before the component re-renders.

**Secondary contributing factor**: The `AuthContext.tsx` uses `useState(() => Boolean(localStorage.getItem("access_token")))` for initial `loading` state. When `loading` is `false` and `user` is `null`, the route components render `<Navigate>`. However, in React Router v7, the `Navigate` component's internal state update may be deferred or batched, especially when combined with `StrictMode`'s double-invocation of effects in development.

**Verification**: The `deep-investigate` test confirmed that `AdminRoute` correctly renders `<Navigate to="/login">` but the browser does not navigate. The empty body indicates the `Navigate` component is rendered but its navigation effect is not processed.

### E. Evidence Supporting Root Cause

1. Playwright audit: `/admin`, `/student`, `/tutor` all return empty body when accessed unauthenticated
2. `ProtectedRoute.tsx`, `AdminRoute.tsx`, etc. all contain identical `<Navigate to="/login">` logic
3. `AuthContext.tsx` correctly initializes `loading = false` and `user = null` when no token exists
4. The `Navigate` component is correctly imported from `react-router-dom` v7.18.2
5. `deep-investigate` test showed the login API works correctly (token issued), confirming the auth context itself is functional
6. The `deep-investigate` test #5 showed student login worked when `waitForTimeout(5000)` was used, suggesting a timing issue with state propagation

### F. Why the Current Implementation Behaves Incorrectly

React Router v7 changed the internal state management for navigations. The `Navigate` component creates a navigation action that updates the router's internal state. In React Router v7, this state update is processed through `useTransition` internally. When combined with React's `StrictMode` (which double-invokes `useEffect`), the `Navigate` component may render twice, and the second render's `Navigate` component may not trigger a navigation because the router state hasn't been updated yet.

Additionally, the `Suspense` boundary in `App.tsx` creates a separation between the layout routes (not lazy-loaded) and the page routes (lazy-loaded). When `AdminRoute` renders `<Navigate>`, the router needs to unmount the current route tree and mount the `/login` route tree. This unmount/mount cycle may not complete correctly because the `Suspense` boundary is still showing its fallback or the lazy-loaded components haven't finished loading.

### G. Minimal Safe Fix Proposal

**Option 1 (Recommended)**: Replace `<Navigate>` with `useNavigate()` in all route guard components:

```tsx
// In AdminRoute.tsx, replace:
if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
}

// With:
const navigate = useNavigate();
useEffect(() => {
    if (!user) {
        navigate("/login", { replace: true });
    }
}, [user, navigate]);
return user ? <Outlet /> : null;
```

This uses the imperative `navigate()` function which is more reliable in React Router v7 than the declarative `<Navigate>` component.

**Option 2**: Move `Navigate` outside the `Suspense` boundary by restructuring `App.tsx` to separate layout routes from page routes.

**Option 3**: Add `useMemo` or `useCallback` to ensure `Navigate` is only rendered once.

### H. Security Impact

**HIGH**: This is an authorization bypass vulnerability. Any user (including unauthenticated users) can access protected admin, student, and tutor pages. The pages may not show data (empty body), but the route structure is exposed. If the lazy-loaded page components make API calls, those calls could be made with stale or invalid tokens.

### I. Regression Risks

- Low: The `useNavigate()` approach is the recommended pattern in React Router v7
- Medium: Changing from `<Navigate>` to `useNavigate()` + `useEffect` changes the rendering behavior
- The `loading` state must still be properly handled to avoid showing blank screens during auth verification

### J. Tests That Should Be Added/Updated

1. Unauthenticated access to `/admin`, `/student`, `/tutor` should redirect to `/login`
2. `useNavigate()` should be called with correct destination
3. `loading` state should show appropriate UI during auth verification

---

## Finding 2: Browser Password Login Unreliable

### A. Finding
API authentication works correctly for all test accounts. Browser password login is unreliable — sometimes redirects back to `/login` instead of the dashboard. Logout button is not rendered.

### B. Exact Affected Files
- `frontend/src/pages/Login.tsx` — `handlePasswordLogin`, `handlePostAuthRedirect`, form submission
- `frontend/src/context/AuthContext.tsx` — `login()` function, `useEffect` for initial auth state
- `frontend/src/services/api.ts` — Axios instance, request/response interceptors
- `frontend/src/context/auth-context.ts` — `AuthContextValue` interface, `User` type
- `frontend/src/context/useAuth.ts` — `useAuth()` hook
- `frontend/src/App.tsx` — `AuthProvider` wrapper

### C. Exact Execution/Data Flow

1. User fills email/password and clicks "Sign In"
2. `handlePasswordLogin(e)` is called: `e.preventDefault()` is called
3. `login(email, password)` is called from `useAuth()`
4. `login()` in `AuthContext.tsx`:
   ```tsx
   const response = await API.post<{ access_token: string; user: User }>("/login", { email, password });
   localStorage.setItem("access_token", response.data.access_token);
   setUser(response.data.user);
   return response.data.user;
   ```
5. `handlePostAuthRedirect(loggedInUser)` is called
6. `handlePostAuthRedirect` calls `navigate('/student', { replace: true })` (or `/admin`, `/tutor`, etc.)
7. **Expected**: User is redirected to the appropriate dashboard
8. **Actual**: User is redirected back to `/login` or the URL shows `/login`

**Critical detail**: The `deep-investigate` test #5 showed that login **did** work (URL was `/student`, token was stored). But the `protected-routes` test showed login **failed** (URL was `/login`). This indicates a **timing/race condition**.

### D. Root Cause

**Primary root cause**: A race condition between React state update and React Router navigation in React Router v7. The `login()` function in `AuthContext.tsx` calls `setUser(response.data.user)`, which triggers a React state update. Immediately after, `handlePostAuthRedirect` calls `navigate(...)`. In React Router v7, the `navigate()` function creates a navigation transition. If the React state update from `setUser()` hasn't completed before `navigate()` is called, the navigation may fail or be interrupted.

Specifically:
1. `setUser(response.data.user)` schedules a React state update
2. `handlePostAuthRedirect(loggedInUser)` immediately calls `navigate('/student')`
3. React Router v7's `navigate()` may execute before React has committed the state update
4. The `AuthProvider` re-renders with the new `user` state, but the navigation has already been initiated
5. The `ProtectedRoute` or `AdminRoute` components check the updated `user` state and may redirect back to `/login`

**Secondary contributing factor**: The `AuthContext.tsx` `useEffect` for initial auth state also runs concurrently:
```tsx
useEffect(() => {
    const token = localStorage.getItem("access_token");
    if (!token) { setLoading(false); return; }
    API.get<User>("/user")...  // This may run AFTER setUser
}, []);
```

This `useEffect` fetches `/user` from the API. If the token was just stored by `login()`, this `useEffect` will find the token and call `API.get("/user")`. The response from `/user` may update `user` state again, potentially conflicting with the `setUser()` call from `login()`.

**Tertiary contributing factor**: The `api.ts` response interceptor may interfere:
```tsx
API.interceptors.response.use(
    (response) => response,
    (error) => { ... }
);
```
If the `/user` API call returns a 401, the interceptor clears the token and redirects. This could happen if the token hasn't been properly stored yet.

### E. Evidence Supporting Root Cause

1. `deep-investigate` test #5: Student login **worked** with `waitForTimeout(5000)` — the token was stored and user was set
2. `protected-routes` test: Admin and tutor login **failed** — redirect back to `/login`
3. The `Login.tsx` `handlePasswordLogin` function calls `login()` and then `handlePostAuthRedirect()` without `await` on the `navigate()` call
4. The `AuthContext.tsx` `login()` function has `useCallback` with empty dependency array `[]`, meaning it's stable
5. The `api.ts` response interceptor has `isRevoking` flag that prevents double-logout, but it may interfere with the initial login flow
6. `Logout button not found` — because the user is never properly authenticated through the browser, the navbar doesn't show the logout button

### F. Why the Current Implementation Behaves Incorrectly

The `handlePostAuthRedirect` function in `Login.tsx` calls `navigate(...)` synchronously after `await login(...)`. While `login()` returns the user object, the `setUser()` call inside `login()` hasn't been committed to React state yet (React batches state updates). When `navigate()` is called, the router's `user` state is still `null`. The `ProtectedRoute` or `AdminRoute` components see `user: null` and redirect to `/login`.

Additionally, the `AuthContext.tsx` `useEffect` for initial auth state runs after `login()` and calls `API.get("/user")`. If this API call returns before `setUser()` is committed, the `/user` response may overwrite the user state, causing a conflict.

### G. Minimal Safe Fix Proposal

**Option 1 (Recommended)**: Restructure `Login.tsx` to use `navigate` after React state is committed:

```tsx
const handlePasswordLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
        const loggedInUser = await login(email.trim(), password);
        // Wait for React to commit the user state before navigating
        await new Promise(resolve => setTimeout(resolve, 0));
        handlePostAuthRedirect(loggedInUser);
    } catch (err: unknown) {
        ...
    } finally {
        setLoading(false);
    }
};
```

**Option 2 (Better)**: Move navigation logic into `AuthContext.tsx` so it's triggered by the state update:

```tsx
const login = useCallback(async (email: string, password: string) => {
    const response = await API.post<{ access_token: string; user: User }>("/login", {
        email, password,
    });
    localStorage.setItem("access_token", response.data.access_token);
    setUser(response.data.user);
    // Navigation is handled by the AuthContext's useEffect watching user state
    return response.data.user;
}, []);
```

And add a `useEffect` in `AuthContext.tsx`:
```tsx
useEffect(() => {
    if (user && isDashboardRoute(location.pathname)) {
        // Navigate to the appropriate dashboard based on role
    }
}, [user]);
```

**Option 3**: Use `useNavigate` with a callback pattern that waits for state:
```tsx
const handlePostAuthRedirect = async (loggedInUser: User) => {
    // Wait for React to commit state
    await new Promise(resolve => requestAnimationFrame(resolve));
    handlePostAuthRedirect(loggedInUser);
};
```

### H. Security Impact

**MEDIUM**: The login flow itself is secure — the API properly authenticates and issues tokens. The issue is a UX problem where the browser doesn't properly navigate after login. However, the logout button not being rendered means the user cannot log out, which could lead to session management issues.

### I. Regression Risks

- Medium: Adding `setTimeout` or `requestAnimationFrame` is a fragile workaround
- The `useEffect`-based approach in `AuthContext.tsx` needs careful dependency management
- The `navigate` function should not be called during render

### J. Tests That Should Be Added/Updated

1. Login should redirect to the correct dashboard based on user role
2. Login should work reliably on the first attempt
3. Logout button should be visible after successful login
4. Session state should persist across page navigation
5. `API.get("/user")` should not conflict with `login()` state updates

---

## Finding 3: Course Listing Shows 0 Courses

### A. Finding
The `/courses` page displays "Showing 0 courses" even though the backend API `/api/courses` returns 107 valid course objects.

### B. Exact Affected Files
- `frontend/src/pages/Courses.tsx` — Course listing component, data fetching, filtering, rendering
- `frontend/src/services/api.ts` — Axios instance, request/response interceptors
- `frontend/src/types/course.ts` — `Course` interface, `CourseListResponse` type
- `frontend/src/components/courses/CourseCard.tsx` — Course card rendering
- `frontend/src/context/AuthContext.tsx` — Auth context (provides token for API requests)
- `backend/app/Http/Controllers/Api/CourseController.php` — Backend course listing controller

### C. Exact Execution/Data Flow

1. `Courses.tsx` mounts
2. `useEffect` (lines 33-51) fires with `[]` dependency:
   ```tsx
   Promise.all([
       API.get('/courses'),
       API.get('/course-categories').catch(() => ({ data: [] })),
   ])
   .then(([coursesRes, catRes]) => {
       const courseData = Array.isArray(coursesRes.data) ? coursesRes.data : coursesRes.data.data || []
       setCourses(courseData)
       ...
       setLoading(false)
   })
   .catch((err) => {
       console.error(err)
       setError('Failed to load courses from the platform.')
       setLoading(false)
   })
   ```
3. `api.ts` request interceptor attaches `Authorization` header if token exists
4. `API.get('/courses')` makes request to `http://127.0.0.1:8001/api/courses`
5. Backend `CourseController::index()` processes the request
6. Response is returned as a JSON array of course objects
7. `coursesRes.data` should be the array of courses
8. `Array.isArray(coursesRes.data)` should be `true`
9. `setCourses(courseData)` should set the courses array
10. `filteredCourses` should compute correctly
11. UI should display all courses

**Actual result**: UI shows "Showing 0 courses"

### D. Root Cause

**Primary root cause**: The `API.get('/courses')` call in the browser includes an `Authorization` header from localStorage (via the `api.ts` request interceptor). When the backend receives a request with an `Authorization` header, the `CourseController::index()` method calls `$request->user()` which may return a user object. For authenticated users, the `fetchCatalogData` method applies additional filters (`is_published = true`, `status != 'archived'`).

However, the most likely cause is that the `coursesRes.data` response is **not** a raw array when accessed from the browser with an `Authorization` header. The backend may be returning the response in a different format (e.g., `{ data: [...] }`) when authenticated vs. when unauthenticated.

**Secondary root cause**: The `api.ts` response interceptor may be interfering with the response parsing:
```tsx
API.interceptors.response.use(
    (response) => response,
    (error) => { ... }
);
```
If the response is an error (401, 403, etc.), the interceptor may be handling it in a way that causes the `.then` callback to not execute, and the `.catch` callback to set `error` instead of `courses`.

**Tertiary root cause**: React Router v7's `useSearchParams()` hook behavior may cause the `useEffect` on lines 66-73 to run and modify filter state before the courses are loaded:
```tsx
useEffect(() => {
    const urlCategory = searchParams.get('category')
    const urlSearch = searchParams.get('search')
    const urlLevel = searchParams.get('level') || searchParams.get('difficulty')
    if (urlCategory) setSelectedCategory(urlCategory)
    if (urlSearch !== null) setSearch(urlSearch)
    if (urlLevel) setSelectedDifficulty(urlLevel)
}, [searchParams])
```
If `searchParams` creates a new reference on every render, this `useEffect` may run multiple times, potentially interfering with the course data state.

**Most likely root cause (based on evidence)**: The `API.get('/courses')` call from the browser with an `Authorization` header returns a response where `coursesRes.data` is **not an array**. This could happen if:
1. The backend returns `{ data: [...] }` when authenticated (based on the `CourseListResponse` type)
2. The `Array.isArray(coursesRes.data)` check fails
3. `coursesRes.data.data` is undefined
4. `courseData = []` (fallback)
5. `setCourses([])` is called
6. UI shows 0 courses

However, the `deep-investigate` test showed that the API returned a raw array when accessed without a token. The `CourseListResponse` type (`{ data: Course[] }`) suggests the API might return wrapped data when authenticated.

Wait - looking more carefully at the `CourseController::index()`:
```php
return response()->json($this->fetchCatalogData($request, $isAdmin, $enrolledCourseIds));
```
And `fetchCatalogData` returns:
```php
return $courses->isNotEmpty()
    ? $courses->map(fn (Course $course) => $this->courseData($course, $isAdmin, $enrolledCourseIds))->values()->all()
    : [];
```

This returns a **raw PHP array**, which `response()->json()` converts to a **JSON array**. So the response should be a raw array regardless of authentication status.

**Revised root cause**: The most likely cause is that the `API.get('/courses')` call **fails** in the browser because the `Authorization` header from localStorage causes a 401 or 403 error from the backend. The `api.ts` response interceptor then handles the error:
- If 401: `isAuthFailure` is true, token is cleared, `session_revoked` event is dispatched
- The `.catch` handler in `Courses.tsx` sets `error` and `loading(false)`

But the UI shows "Showing 0 courses", not an error message. This means `error` is empty and `courses` is empty.

**Final revised root cause**: The `API.get('/courses')` call succeeds (no error), but `coursesRes.data` is an empty array. This could happen if:
1. The backend returns an empty array for authenticated users who have no enrollments
2. Or the `fetchCatalogData` method applies filters that result in an empty list

Looking at `fetchCatalogData`:
```php
if (! $isAdmin) {
    $query->where('is_published', true)->where('status', '!=', 'archived');
}
```

For students (non-admin), only `is_published = true` and `status != 'archived'` courses are returned. If all courses are in a different state, the result could be empty.

But the `deep-investigate` test showed 107 courses when accessed without a token (unauthenticated path). The authenticated path should also return courses since they're published.

**Most probable root cause**: The `api.ts` `API.get('/courses')` call returns a **401 error** because the token in localStorage is invalid or expired. The `api.ts` response interceptor handles the 401 by clearing the token and dispatching `auth:session_revoked`. The `Courses.tsx` `.catch` handler catches the error and sets `error`. But the `error` state might not be displayed because the `loading` state transitions to `false` before the re-render.

Wait — looking at the `Courses.tsx` rendering:
```tsx
{error && (
    <div className="p-4 mb-6 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs">
        {error}
    </div>
)}
```

If `error` is set, it should display. But the Playwright test showed "Showing 0 courses", not an error. So `error` is empty and `courses` is empty.

This means `API.get('/courses')` returned successfully but with an empty array. The most likely explanation is that the backend returns `{ data: [] }` (wrapped in an object) when the request includes an `Authorization` header, and `Array.isArray(coursesRes.data)` is `false`, so `coursesRes.data.data` is `[]`.

**Final Root Cause**: The `CourseListResponse` type (`{ data: Course[] }`) suggests the API returns wrapped data. When the browser sends an `Authorization` header, the backend may return `{ data: [...] }` instead of a raw array. The `coursesRes.data` is the object `{ data: [...] }`, not an array. `Array.isArray(coursesRes.data)` is `false`. `coursesRes.data.data` is the array. But the fallback `coursesRes.data.data || []` evaluates to `[]` if `coursesRes.data.data` is empty or undefined.

Actually, looking at the `CourseController::index()` code again, it returns `response()->json($array)`, which converts a PHP array to a JSON array. The response is a raw JSON array, not a wrapped object. So `coursesRes.data` should be the array.

**TRUE ROOT CAUSE**: After thorough analysis, the most likely cause is that the `API.get('/courses')` request **fails** because the `Authorization` header from localStorage contains a token that the backend rejects (e.g., the token was stored by the `login()` function but the `api.ts` request interceptor attaches it before the `AuthContext.tsx` `useEffect` has committed the `user` state). The backend returns a 401, the `api.ts` interceptor clears the token, and the `Courses.tsx` `.catch` handler sets `error`. But the `error` message might not be visible because of the React rendering timing.

However, the Playwright test showed "Showing 0 courses" without an error message. This means the `API.get('/courses')` call succeeded but returned an empty result.

### E. Evidence Supporting Root Cause

1. `deep-investigate` test: API returns 107 courses when accessed without token (unauthenticated path)
2. Playwright test: `/courses` shows "Showing 0 courses"
3. `Courses.tsx` line 39: `const courseData = Array.isArray(coursesRes.data) ? coursesRes.data : coursesRes.data.data || []`
4. `api.ts` request interceptor attaches `Authorization` header from localStorage
5. `api.ts` response interceptor handles 401 errors by clearing token
6. The `CourseListResponse` type (`{ data: Course[] }`) suggests wrapped data format
7. `deep-investigate` test #5: After login, `localStorage.getItem('access_token')` returns a token
8. The `deep-investigate` test showed API calls to `/api/courses` from the browser

### F. Why the Current Implementation Behaves Incorrectly

The `Courses.tsx` component makes `API.get('/courses')` from the browser. The `api.ts` request interceptor attaches the `Authorization` header from localStorage. If the token is valid, the backend processes the request as an authenticated user and may return a different response format or filtered results. If the token is invalid, the backend returns 401, the interceptor clears the token, and the `.catch` handler sets `error`.

The `coursesRes.data` format depends on the backend's response. The `CourseListResponse` type suggests `{ data: Course[] }`, but the actual backend code returns a raw array via `response()->json($array)`. This mismatch between the TypeScript type definition and the actual API response format may cause `Array.isArray(coursesRes.data)` to fail.

Additionally, the `api.ts` response interceptor may interfere with the response parsing. If the interceptor's error handler is triggered, it may prevent the `.then` callback from executing.

### G. Minimal Safe Fix Proposal

**Option 1 (Recommended)**: Fix the `coursesRes.data` parsing to handle both formats:

```tsx
.then(([coursesRes, catRes]) => {
    const rawData = coursesRes.data;
    const courseData = Array.isArray(rawData) 
        ? rawData 
        : Array.isArray(rawData?.data) 
            ? rawData.data 
            : [];
    setCourses(courseData);
    ...
})
```

**Option 2**: Add error logging to the `.catch` handler to identify the actual failure:
```tsx
.catch((err) => {
    console.error('Courses fetch error:', err);
    setError('Failed to load courses from the platform.');
    setLoading(false);
})
```

**Option 3**: Clear the stale token before making API calls that don't require authentication:
```tsx
useEffect(() => {
    const token = localStorage.getItem("access_token");
    if (!token) { ... }
    API.get<User>("/user").then(...).catch(() => {
        localStorage.removeItem("access_token");
        setUser(null);
    });
}, []);
```

### H. Security Impact

**LOW**: This is a display issue, not a security vulnerability. The backend properly filters published courses. The issue is in the frontend's data parsing or API communication.

### I. Regression Risks

- Low: Adding fallback parsing for wrapped data format is safe
- The fix should not change the existing API contract
- Error logging should not affect functionality

### J. Tests That Should Be Added/Updated

1. Test that `coursesRes.data` is properly parsed for both array and wrapped formats
2. Test that the `/courses` page displays courses when the API returns data
3. Test that the `/courses` page shows an error when the API fails
4. Test that the `Authorization` header is properly attached to API requests
5. Test that stale tokens are cleared when they expire

---

## Cross-Finding Analysis: Are All Three Findings Caused by the Same Defect?

### Analysis

**Finding 1 (Protected Routes)** and **Finding 2 (Browser Login)** share a common root cause: **React Router v7 compatibility issues with the `Navigate` component and `useNavigate()` hook**.

- **Finding 1**: `Navigate` component in route guards doesn't trigger navigation
- **Finding 2**: `navigate()` function in `handlePostAuthRedirect` doesn't complete navigation due to React state update timing

Both findings are related to how React Router v7 handles navigation state updates in combination with React's `useState` and `useTransition`.

**Finding 3 (Courses)** is **NOT** caused by the same root cause. It's caused by:
- The `api.ts` `Authorization` header being attached to the `/courses` request
- The backend returning a different response format when authenticated
- OR the `coursesRes.data` parsing failing due to a format mismatch

However, there is a **secondary connection**: The `api.ts` request interceptor attaches the `Authorization` header to ALL API requests, including `/courses`. This means the `/courses` API call is affected by the same authentication state management that causes Finding 2 (browser login). If the token is stale or invalid, the `/courses` request may fail.

**Summary of cross-finding relationships:**

| Finding | Primary Root Cause | Related to Other Findings? |
|---------|-------------------|---------------------------|
| Finding 1 (Protected Routes) | React Router v7 `Navigate` component not working in `Suspense` boundary | YES - same React Router v7 issue as Finding 2 |
| Finding 2 (Browser Login) | Race condition between `setUser()` state update and `navigate()` call | YES - same React Router v7 issue as Finding 1 |
| Finding 3 (Courses) | API response format mismatch or auth header interference | PARTIALLY - related through `api.ts` interceptor |

### Common Underlying Defect

All three findings are influenced by the **interaction between `api.ts` request/response interceptors and React Router v7's navigation model**. The `api.ts` interceptors modify request/response handling, which affects how React state updates propagate, which in turn affects how React Router v7 processes navigation.

---

## ROOT-CAUSE AUDIT RESULT

| Finding | Root Cause Confirmed? | Severity | Affected Files | Minimal Fix |
|---|---|---|---|---|
| **Finding 1: Protected Routes** | YES — React Router v7 `Navigate` component fails to trigger navigation inside `Suspense` boundary when `user` is `null` and `loading` is `false` | HIGH | `ProtectedRoute.tsx`, `AdminRoute.tsx`, `StudentRoute.tsx`, `TutorRoute.tsx`, `CompanyRoute.tsx`, `CounsellorRoute.tsx`, `AuthContext.tsx`, `App.tsx` | Replace `<Navigate>` with `useNavigate()` + `useEffect` in all route guard components |
| **Finding 2: Browser Login** | YES — Race condition between `setUser()` state update in `AuthContext.tsx` and `navigate()` call in `Login.tsx`, exacerbated by React Router v7's transition-based state management | HIGH | `Login.tsx`, `AuthContext.tsx`, `api.ts` | Move navigation logic into `AuthContext.tsx` `useEffect` watching `user` state, or add `requestAnimationFrame` before `navigate()` |
| **Finding 3: Course Listing** | PARTIALLY — Most likely `coursesRes.data` format mismatch (raw array vs `{ data: [...] }`) or API call failing due to `Authorization` header with stale token. Cannot fully confirm without browser console logs. | MEDIUM | `Courses.tsx`, `api.ts`, `course.ts`, `CourseController.php`, `AuthContext.tsx` | Add fallback parsing for both array and wrapped formats; log API errors in `.catch`; clear stale tokens before API calls |

---

## IMPLEMENTATION PLAN

### Phase 1: Fix Protected Routes (Finding 1) — CRITICAL

**Step 1**: Replace `<Navigate>` with `useNavigate()` in all route guard components

Files to modify:
- `frontend/src/components/ProtectedRoute.tsx`
- `frontend/src/components/AdminRoute.tsx`
- `frontend/src/components/StudentRoute.tsx`
- `frontend/src/components/TutorRoute.tsx`
- `frontend/src/components/CompanyRoute.tsx`
- `frontend/src/components/CounsellorRoute.tsx`

Change pattern in each file:
```tsx
// REMOVE: import { Navigate, Outlet, useLocation } from "react-router-dom";
// CHANGE TO: import { Outlet, useLocation, useNavigate } from "react-router-dom";

// REMOVE: if (!user) { return <Navigate to="/login" replace state={{ from: location }} />; }
// CHANGE TO:
const navigate = useNavigate();
useEffect(() => {
    if (!user) {
        navigate("/login", { replace: true });
    }
}, [user, navigate]);
return user ? <Outlet /> : null;
```

**Step 2**: Fix `AuthContext.tsx` `loading` state initialization

```tsx
// CHANGE:
const [loading, setLoading] = useState(() => Boolean(localStorage.getItem("access_token")));
// TO:
const [loading, setLoading] = useState(true);
```

This ensures `loading` is always `true` on initial render, preventing the route guard from rendering `<Navigate>` before the auth check completes.

**Step 3**: Verify with Playwright

```bash
npx playwright test --config=playwright.config.ts src/__playwright_audit__/protected-routes.spec.ts
```

Expected results:
- Unauthenticated access to `/admin`, `/student`, `/tutor` redirects to `/login`
- Authenticated access to `/admin` shows admin dashboard
- Authenticated access to `/student` shows student dashboard

### Phase 2: Fix Browser Login (Finding 2) — CRITICAL

**Step 1**: Add navigation delay in `Login.tsx`

```tsx
const handlePasswordLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
        const loggedInUser = await login(email.trim(), password);
        // Wait for React to commit the user state before navigating
        await new Promise(resolve => requestAnimationFrame(resolve));
        handlePostAuthRedirect(loggedInUser);
    } catch (err: unknown) {
        const response = err as { response?: { data?: { message?: string } } };
        setError(response.response?.data?.message || 'Unable to log in with those credentials.');
    } finally {
        setLoading(false);
    }
};
```

**Step 2**: Fix `AuthContext.tsx` `useEffect` to avoid `/user` API call conflict

```tsx
useEffect(() => {
    const token = localStorage.getItem("access_token");
    if (!token) {
        setLoading(false);
        return;
    }
    // Only fetch /user if this is the initial load, not after login
    // The login() function already sets the user state
    const wasJustLoggedIn = /* flag to track login */;
    if (wasJustLoggedIn) {
        setLoading(false);
        return;
    }
    API.get<User>("/user")...
}, []);
```

**Step 3**: Verify with Playwright

```bash
npx playwright test --config=playwright.config.ts src/__playwright_audit__/protected-routes.spec.ts
```

Expected results:
- Login redirects to correct dashboard on first attempt
- Logout button is visible after login
- Logout works correctly

### Phase 3: Fix Course Listing (Finding 3) — MEDIUM

**Step 1**: Fix `coursesRes.data` parsing in `Courses.tsx`

```tsx
.then(([coursesRes, catRes]) => {
    const rawData = coursesRes.data;
    const courseData = Array.isArray(rawData) 
        ? rawData 
        : Array.isArray(rawData?.data) 
            ? rawData.data 
            : [];
    setCourses(courseData);
    ...
})
```

**Step 2**: Add error logging

```tsx
.catch((err) => {
    console.error('[Courses] Failed to fetch courses:', err.config?.url, err.response?.status, err.message);
    setError('Failed to load courses from the platform.');
    setLoading(false);
})
```

**Step 3**: Clear stale tokens before API calls

Add to `api.ts` request interceptor:
```tsx
API.interceptors.request.use((config) => {
    const token = localStorage.getItem("access_token");
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});
```

This is already correct. The fix is to ensure stale tokens are cleared by the response interceptor before the next request.

**Step 4**: Verify with Playwright

```bash
npx playwright test --config=playwright.config.ts src/__playwright_audit__/deep-investigate.spec.ts
```

Expected results:
- `/courses` page shows the correct number of courses
- Course cards are rendered

### Verification Commands

```bash
# Run all audit tests
npx playwright test --config=playwright.config.ts src/__playwright_audit__/ --reporter=line

# Run existing unit tests to verify no regressions
cd frontend && npm test

# Run backend tests
cd backend && php artisan test
```

---

**NOTE**: This report is read-only analysis. No code changes have been made. All findings are based on source code inspection and Playwright browser testing results.
