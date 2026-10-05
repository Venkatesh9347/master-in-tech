# Playwright Phase P1 — Public Website Validation

## 1. Status

# PASS_WITH_WARNINGS

All 13 reachable public routes render correctly, navigation is fully working (13/13 links, 0 broken), the course catalog and course detail function, all three responsive breakpoints are clean with zero horizontal overflow, and browser history works correctly. **Zero console errors, zero page errors, zero unexpected HTTP 4xx/5xx across the whole pass.**

Two genuine defects were found and are **not** fixed (per P1 rule 25):
- **D-P1-01** — 6 published courses store a corrupted Unsplash photo ID; their images 404 and never render.
- **D-P1-02** — the course catalog intermittently renders 0 course cards (2 of 3 runs).

---

## 2. Environment

| Item | Value |
|---|---|
| Frontend | `http://localhost:5173` (Vite dev server, PID 17508 — pre-existing, not restarted) |
| Backend | `http://127.0.0.1:8001/api` (PHP built-in server, PID 14024) |
| Browser | Chromium, **headed** (`headless: false`, `slowMo: 0`) |
| Playwright | 1.63.0 |
| Playwright config | `frontend/e2e-10x/playwright.config.ts` (existing, **unmodified**) |
| Trace | `--trace on` CLI flag only (config is `retain-on-failure`) |
| Viewports | desktop 1280×720, tablet 768×1024, mobile 390×844 |
| Business writes | **0** — no form submitted, no lead/user/enrollment/payment created |

---

## 3. Route Inventory

Discovered from `frontend/src/App.tsx` (source), then **verified for actual browser reachability** from the rendered header/footer DOM. Only PUBLIC routes were exercised.

| Route | Classification | Tested | Result |
|---|---|---|---|
| `/` | PUBLIC | ✅ | PASS — 200, 4 sections, 12 course cards, ~2.2 s |
| `/courses` | PUBLIC | ✅ | **FLAKY** — 107 cards in 2 of 3 runs; see D-P1-02 |
| `/courses/:id` | PUBLIC | ✅ | PASS — 3 courses (`/4`, `/11`, `/84`) |
| `/about` | PUBLIC | ✅ | PASS — 200, "Transforming Tech Careers Worldwide" |
| `/faq` | PUBLIC | ✅ | PASS — 200, "Frequently Asked Questions" |
| `/contact` | PUBLIC | ✅ | PASS — 200, "Get in Touch with Our Team", 5 required fields |
| `/instructors` | PUBLIC | ✅ | PASS — 200, "Meet Our Industry Faculty" |
| `/resources` | PUBLIC | ✅ | PASS — 200, "Engineering & Learning Resources" |
| `/corporate-partner` | PUBLIC | ✅ | PASS — 200, "Partner With MasterInTech" |
| `/events` | PUBLIC | ✅ | PASS — 200, "Master In Tech Events" |
| `/verify-certificate` | PUBLIC | ✅ | PASS — 200 (render only; no certificate verified) |
| `/placements` | PUBLIC | ✅ | PASS — 200, "Placement Portal & Hiring Drives" (**render/nav validity only**; job + apply workflow deliberately out of P1 scope) |
| `/login` | AUTHENTICATION_BOUNDARY | ✅ | PASS — 200, "Student Sign In" (**no login performed**) |
| `/register` | PUBLIC | ❌ | NOT TESTED — declared in source, not linked from any nav/footer element |
| `/forgot-password` | PUBLIC | ❌ | NOT TESTED — declared in source, not linked from any nav/footer element |
| `/events/:id` | PUBLIC | ❌ | NOT TESTED — no event-detail link surfaced in the rendered `/events` list (page body only 735 chars) |
| `*` (404) | NOT_FOUND | ❌ | NOT TESTED |
| `/admin/*`, `/cpanel`, `/c-panel` | ADMIN | ❌ | out of scope |
| `/tutor/*` | AUTHENTICATED | ❌ | out of scope |
| `/student/*`, `/ai-assistant` | AUTHENTICATED | ❌ | out of scope |
| `/company/*` | AUTHENTICATED | ❌ | out of scope |

**Totals: 16 public routes discovered · 13 tested · 3 not tested** (all 3 for a stated, non-blocking reason: not present in any rendered nav element).

---

## 4. Homepage

**Result: PASS**

| Check | Result |
|---|---|
| Page loads / HTTP status | ✅ 200 |
| Title | `frontend` |
| `h1` visible | ✅ "Learn Technology From Fundamentals to Advanced." |
| Header visible | ✅ 8 links |
| Navigation usable | ✅ all 8 hrefs resolve |
| Hero renders | ✅ eyebrow, headline, search box, rotating hero visual |
| Hero CTAs render | ✅ 3 × "Enquire Now" + "Search" + "Explore Courses" |
| Content sections | ✅ 4 sections: hero, "Explore Our Courses", career specializations + student stories + FAQ, CTA |
| Course cards | ✅ 12 cards with title, description, domain tag, duration, lesson count, instructor, "Syllabus Info" + "Download Brochure" |
| Pagination | ✅ "Load More Courses (2 remaining)" → "Browse All Courses (14)" |
| Footer renders | ✅ 5 columns + social links + copyright |
| Uncaught page errors | ✅ 0 |

**Verified as NOT a defect:** a full-page screenshot taken ~3 s after load showed 6 grey skeleton cards in "Explore Our Courses". This was checked rather than assumed — after an event-driven settle the section renders **24 course links and 12 complete cards** (`p1-homepage-courses-settled.png`). It is slow, not broken.

**Homepage images:** 0 `<img>`, 0 `<svg>`, 0 `<canvas>`, 0 `<video>`, 0 background-image URLs, 2 CSS gradients. The homepage is deliberately text/gradient-only (logo, icons and hero visuals are CSS/inline-styled). Classified **NOT_A_FAILURE** — nothing expected is missing or broken.

---

## 5. Navigation

**Result: PASS — 13/13 public links WORKING, 0 BROKEN**

Discovered from the rendered DOM (not guessed): 8 header links + 19 footer links → 13 unique hrefs.

| Link | Source | Classification | Result |
|---|---|---|---|
| `/` (logo) | header+footer | PUBLIC | WORKING (200, 1168 ms) |
| `/courses` | header+footer | PUBLIC | WORKING (200, 1071 ms) |
| `/placements` | header | PUBLIC | WORKING (200, 1200 ms) — render only |
| `/corporate-partner` | header ("Hire From Us") | PUBLIC | WORKING (200, 2080 ms) |
| `/events` | header+footer | PUBLIC | WORKING (200, 1735 ms) |
| `/resources` | header+footer | PUBLIC | WORKING (200, 1314 ms) |
| `/instructors` | footer | PUBLIC | WORKING (200, 1716 ms) |
| `/verify-certificate` | footer | PUBLIC | WORKING (200, 1111 ms) |
| `/about` | footer | PUBLIC | WORKING (200, 1815 ms) |
| `/faq` | footer | PUBLIC | WORKING (200, 1657 ms) |
| `/contact` | footer ×2 | PUBLIC | WORKING (200, 1259 ms) |
| `/login` | header ("Sign In") | AUTHENTICATION_BOUNDARY | WORKING (200, 1195 ms) — **stopped at boundary, no login** |
| `tel:+919063627775` | header ("Contact Us") | EXTERNAL | href present, not opened (per P1) |
| 5 × topic links (AI / ML / Data / Full Stack / Cloud) | footer | PUBLIC | All resolve to `/courses` — WORKING (same target) |

---

## 6. Course Catalog

**Result: PASS_WITH_WARNINGS** (functionally complete; see D-P1-02)

| Feature | Classification | Evidence |
|---|---|---|
| Catalog loads | IMPLEMENTED_AND_WORKING | 200, "Explore Course Catalog" |
| Course cards render | IMPLEMENTED_AND_WORKING | **107 cards**, counter "Showing 107 courses" |
| Course titles visible | IMPLEMENTED_AND_WORKING | e.g. "Cyber Security Fundamentals" |
| Card descriptions | IMPLEMENTED_AND_WORKING | present |
| Card images | **IMPLEMENTED_AND_BROKEN** | 107 `<img>` rendered; 6 fail to load → D-P1-01 |
| Search | IMPLEMENTED_AND_WORKING | "Cyber Security" → 2 results (from 107) |
| Level filter | IMPLEMENTED_AND_WORKING | Basic / Intermediate / Advanced buttons filter the grid |
| Category chips | IMPLEMENTED_AND_WORKING | "All", AI, ML, Data Science, Python with AI, SAP, Medical Coding, Web Dev, Full Stack, Cyber Security, Cloud |
| Pagination | NOT_IMPLEMENTED | single page renders all 107; no pager control present (client-side grid) |
| Pricing | NOT_IMPLEMENTED | no price field rendered on cards |
| Course links | IMPLEMENTED_AND_WORKING | each card links to `/courses/:id` |
| Load time | — | 15.5 s cold to a populated grid (slow; see §16) |

---

## 7. Course Detail

**Result: PASS**

Tested 3 representative courses — `/courses/4` (Cyber Security Fundamentals), `/courses/11` (Artificial Intelligence Fundamentals), `/courses/84` (Advanced Data Engineering).

| Check | `/courses/4` | `/courses/11` | `/courses/84` |
|---|---|---|---|
| Page loads | ✅ 200 | ✅ 200 | ✅ 200 |
| Course title | ✅ | ✅ | ✅ |
| Description | ✅ (body 3442 chars) | ✅ (2994) | ✅ (2975) |
| Curriculum/lessons | ✅ **4 modules** w/ lesson counts | ✅ 2 modules | ✅ |
| Instructor | ✅ "Sarah Johnson" | ✅ | ✅ |
| Reviews/ratings | ✅ "5 (1 ratings)" | ✅ | ✅ |
| Media | 1 image, loads ✅ | 1 image, **404s** ❌ D-P1-01 | ✅ |
| Enquiry CTA | ✅ "Enquire Now & Get Syllabus" — destination verified only | ✅ | ✅ |
| Enroll CTA | ✅ "Start Learning / Enroll" — **not clicked** | ✅ | ✅ |
| FAQ / related | not rendered on detail page | — | — |

**No enrollment or login was performed** — CTA presence and destination only.

---

## 8. Public Content Pages

**Result: PASS — 8/8 render with meaningful content**

| Page | Status | Time | Body length | Heading |
|---|---|---|---|---|
| `/about` | PASS | 2144 ms | 1812 | "Transforming Tech Careers Worldwide" |
| `/faq` | PASS | 1265 ms | 892 | "Frequently Asked Questions" |
| `/contact` | PASS | 1275 ms | 1144 | "Get in Touch with Our Team" |
| `/instructors` | PASS | 1037 ms | 779 | "Meet Our Industry Faculty" |
| `/resources` | PASS | 1048 ms | 787 | "Engineering & Learning Resources" |
| `/corporate-partner` | PASS | 1309 ms | 1903 | "Partner With MasterInTech" |
| `/events` | PASS | 1159 ms | 735 | "Master In Tech Events" |
| `/verify-certificate` | PASS | 1065 ms | 767 | "Verify Master In Tech Certificate" |

None showed a 404/blank state, broken layout, console error, or failed request. `/verify-certificate` was only loaded — no certificate code was entered or verified.

---

## 9. Contact/Enquiry UI

**Result: PASS — UI validated, deliberately NOT submitted**

> **No valid enquiry was submitted in P1. No CRM lead was created. No email was sent.** Lead generation belongs to P2.

**`/contact` page form** — 5 fields, all with HTML5 `required=true`:

| Type | Placeholder | Required |
|---|---|---|
| text | `e.g. John Doe` | ✅ |
| email | `you@example.com` | ✅ |
| tel | `e.g. 9876543210` | ✅ |
| text | `e.g. AI & ML Program Inquiry` | ✅ |
| textarea | `Tell us how we can help...` | ✅ |

**Homepage "Enquire Now" modal** — opens correctly, 4 fields, submit button `Submit Course Enquiry →`.

| Field | Type | Placeholder | HTML5 `required` |
|---|---|---|---|
| Full Name | text | `e.g. Alex Sharma` | ❌ |
| Email | email | `name@example.com` | ❌ |
| Mobile | tel | `e.g. 9876543210` | ❌ |
| Interested Program | select | `Select a technology track...` | ❌ |

**Validation trigger:** submitting the modal with all fields empty was blocked. Note: the modal's inputs have **no** `required` attribute, so `form.checkValidity()` returns `true`; the block is enforced by the component's own client-side validator, which returned early and left the modal open.

**Write-request proof:** a request listener recorded every `POST/PUT/PATCH/DELETE` for the entire test — **`writeRequests=[]`, i.e. zero network writes.** Nothing was submitted to the API.

**Observation (not classified as a defect):** the two public enquiry forms disagree on phone guidance — `/contact` shows `e.g. 9876543210` (10 digits) while the modal shows `e.g. 9876543210` too, but the modal performs stricter client-side validation than the `required` attribute implies. Recorded as informational only.

---

## 10. Responsive Checks

**Result: PASS — 0 px horizontal overflow at every breakpoint**

| Viewport | Route | Scroll width | Client width | Overflow | Result |
|---|---|---|---|---|---|
| desktop 1280×720 | `/` | 1265 | 1265 | **0 px** | PASS |
| desktop 1280×720 | `/courses` | 1265 | 1265 | **0 px** | PASS |
| desktop 1280×720 | `/courses/4` | 1265 | 1265 | **0 px** | PASS |
| desktop 1280×720 | `/courses/11` | 1265 | 1265 | **0 px** | PASS |
| tablet 768×1024 | `/` | 753 | 753 | **0 px** | PASS |
| tablet 768×1024 | `/courses` | 753 | 753 | **0 px** | PASS |
| tablet 768×1024 | `/courses/4` | 753 | 753 | **0 px** | PASS |
| mobile 390×844 | `/` | 375 | 375 | **0 px** | PASS |
| mobile 390×844 | `/courses` | 375 | 375 | **0 px** | PASS |
| mobile 390×844 | `/courses/4` | 375 | 375 | **0 px** | PASS |

All text visible, cards fit the viewport, hero CTAs usable, footers render, no section clipping. Full-page screenshots captured at each size. No pixel-level scoring performed. Mobile hamburger was not separately asserted (the header nav remained reachable and the layout is overflow-free at 390 px).

---

## 11. Back/Forward Navigation

**Result: PASS** (after correcting my own test timing, not an app change)

Sequence exercised through real UI clicks, not `goto`:

| Step | URL | `h1` | Body length | Result |
|---|---|---|---|---|
| home | `/` | "Learn Technology From Fundamenta…" | 1952 | OK |
| → catalog | `/courses` | "Explore Course Catalog" | 30014 | OK |
| → detail | `/courses/84` | "Advanced Data Engineering" | 2975 | OK |
| ← back | `/courses` | "Explore Course Catalog" | 30014 | ✅ restored |
| → forward | `/courses/84` | "Advanced Data Engineering" | 2975 | ✅ restored |

All three states restore with full content. No blank page, no fatal error, no broken navigation state.

*Note: the first pass reported FAIL, but the harness snapshotted before the SPA finished painting (body length 627 = Suspense fallback). Re-measured with an event-driven readiness wait (the detail CTA), it passes. **This was a test-timing error, not an application defect.***

---

## 12. Console Errors

**None.**

| Count | Value |
|---|---|
| `console.error` | **0** |
| `console.warning` | **0** |
| Uncaught `pageerror` | **0** |

Across all 16 Playwright tests (homepage, navigation, catalog, course detail, 8 content pages, enquiry UI, 3× responsive, back/forward, rechecks).

---

## 13. Network Failures

| # | Request | Failure | Classification |
|---|---|---|---|
| 1 | `GET https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80` | `net::ERR_BLOCKED_BY_ORB` | **APPLICATION_ERROR** — third-party image 404s because the URL stored in the DB is wrong (see D-P1-01). Not a browser/third-party quirk. |

**HTTP 4xx/5xx: 0.** No application API request returned an error status during P1.

**HTTP 404 on the image host (verified out-of-band, read-only):**

| URL | Status |
|---|---|
| `…/photo-1677442136019-21780efad99**a**…` (as stored) | **404** |
| `…/photo-1677442136019-21780efad99**5**…` (correct ID) | **200 image/jpeg** |

ORB is reported by Chromium because Unsplash returns a non-image 404 body for the wrong photo ID — a symptom of the bad URL, not an independent problem.

---

## 14. Broken Links

**None.** All 13 unique public hrefs resolved to a rendered page with HTTP 200 and real content. The `tel:` link was verified by href only and deliberately not opened.

---

## 15. Not Implemented Features

Only features the **existing UI/source actually presents or implies** but which are absent:

| Feature | Status | Basis |
|---|---|---|
| Course catalog pagination control | NOT_IMPLEMENTED | All 107 courses render in one client-side grid; no pager element exists |
| Course pricing / fee display | NOT_IMPLEMENTED | No price field on cards or detail page |
| FAQ / related-courses block on course detail | NOT_IMPLEMENTED | Detail page renders title/description/curriculum/instructor/reviews/CTA only |
| HTML5 `required` on enquiry-modal fields | NOT_IMPLEMENTED | Modal inputs carry no `required`; validation is component-level |
| Root `README.md` | NOT_IMPLEMENTED | Only `DEPLOYMENT.md` documents startup |

Not classified as missing: hero imagery on the homepage (the design is CSS/text-only, nothing is broken).

---

## 16. Environment Blockers

1. **Course catalog intermittently serves 0 cards (D-P1-02) — the only P1 blocker.** The catalog's single `GET /api/courses` (≈155 KB, 107 courses) does not reliably resolve within 45 s when the browser issues its concurrent page-load requests. Reproduced in **2 of 3** P1 runs; the same request returns HTTP 200 with 107 courses when queried directly and the page renders all 107 cards when it succeeds. Earlier phases independently observed `ERR_CONNECTION_RESET` / `ERR_CONNECTION_REFUSED` from this same dev server under headed-browser load. **Not fixed** (P1 rule 25). P2 will be affected wherever a full catalog load is required.
2. **Slow page loads — `ENVIRONMENT_ERROR` (watch item, not a declared defect).** Homepage ~2.2 s to interactive; `/api/courses` 155 KB; catalog 15.5 s cold to a populated grid; homepage course grid needs >10 s to populate; admin-class pages 1.0–2.2 s. No navigation timeout and no page became permanently unusable, so per STEP 14 this is recorded as an observation rather than a performance defect.
3. **Backend not on the documented command — `ENVIRONMENT_ERROR` (carried from P0).** The API is served by `php -S 127.0.0.1:8001 -t public public/index.php` (a process started in an earlier phase), not `php artisan serve` from `DEPLOYMENT.md:56`. Same port; left running per P1 rule 23.
4. **Playwright undeclared in `package.json` — `CONFIGURATION_ERROR` (carried from P0).** `@playwright/test@1.63.0` is present in `frontend/node_modules` but absent from `package.json`/`package-lock.json`, so P1's runner is not reproducible from a clean install.

---

## 17. Evidence

| Artifact | Path |
|---|---|
| HTML report | `frontend/e2e-10x/artifacts/html-report/index.html` |
| JSON report | `frontend/e2e-10x/artifacts/results.json` |
| Video (16 files, 7.7 MB) | `frontend/e2e-10x/artifacts/test-results/*/video.webm` |
| Trace (16 files) | `frontend/e2e-10x/artifacts/test-results/*/trace.zip` |
| Screenshots (34) | `frontend/e2e-10x/artifacts/p1/screenshots/*.png` |
| Machine-readable results | `frontend/e2e-10x/artifacts/p1/p1-results.json`, `p1-recheck.json`, `p1-final-checks.json`, `p1-homepage-courses.json` |

Key screenshots:

| File | Shows |
|---|---|
| `p1-01-homepage.png` | homepage, full page |
| `p1-homepage-courses-settled.png` | homepage course grid **fully loaded** (12 cards) — disproves the skeleton "defect" |
| `p1-homepage-full.png` | homepage captured mid-load (6 skeletons) |
| `p1-recheck-catalog.png` | catalog rendering **107 cards** |
| `p1-image-audit_courses_4.png` | course with a **valid** image (loads) |
| `p1-image-audit_courses_11.png` | course with a **corrupted** image (404) |
| `p1-08-contact.png`, `p1-09-enquiry-modal.png`, `p1-10-enquiry-local-validation.png` | enquiry UI, unsubmitted |
| `p1-11-responsive-*` (9 files) | 3 breakpoints × 3 pages |
| `p1-recheck-back-forward.png` | history navigation restored |

---

## 18. Defect Classification

### D-P1-01 — Corrupted course thumbnail/banner URL on 6 published courses

| Field | Value |
|---|---|
| **ID** | D-P1-01 |
| **Page** | `/courses` (6 cards) and the 6 course detail pages |
| **Action** | Load the catalog, or open any affected course detail |
| **Expected** | Course thumbnail/banner image renders |
| **Actual** | Stored URL is `…/photo-1677442136019-21780efad99**a**…` → Unsplash returns **HTTP 404** → image never renders; Chromium logs `net::ERR_BLOCKED_BY_ORB` |
| **Evidence** | `p1-image-audit_courses_11.png`; `net::ERR_BLOCKED_BY_ORB` in network log; direct HTTP check: stored URL **404**, correct ID (`…efad99**5**…`) returns **200 image/jpeg** |
| **Affected courses** | ids **11** (Artificial Intelligence Fundamentals), **29** (Full Stack Development with AI), **79** (Generative AI Engineering), **80** (LLM Application Engineering), **81** (AI Agents & Agentic AI), **83** (AI System Design) — all `status=published` |
| **Classification** | **APPLICATION_ERROR** (bad seed/content data persisted in the `courses` table) |
| **Reproducibility** | **100 % deterministic** — confirmed on `/courses/11` in an isolated audit (`broken=1`, `unsplashFailures=1`) and in the catalog grid; control course `/courses/4` with a valid URL loads cleanly (`broken=0`, `failures=0`) |
| **Fix owner** | Content/seed data. **Not fixed in P1.** |

### D-P1-02 — Course catalog intermittently renders 0 course cards

| Field | Value |
|---|---|
| **ID** | D-P1-02 |
| **Page** | `/courses` (and any flow that must first populate the catalog, e.g. the course-detail test) |
| **Action** | Navigate to `/courses` and wait for the card grid |
| **Expected** | 107 course cards, counter "Showing 107 courses" |
| **Actual** | Grid stays empty — "Showing 0 courses", skeleton cards never resolve, 0 course links in the DOM after 45 s |
| **Evidence** | `test-results/p1-public-P1-course-catalog/test-failed-1.png`, `trace.zip`; recheck screenshot `p1-recheck-catalog.png` shows the same page with **107 cards** when it succeeds |
| **Reproducibility** | **Intermittent — 2 of 3 P1 runs failed** (both `p1-public` catalog and course-detail tests). The page succeeded in the navigation test, all 3 responsive viewports, the back/forward test, and the isolated recheck. `GET /api/courses` returns HTTP 200 / 155 609 bytes / 107 records when queried directly |
| **Classification** | **APPLICATION_ERROR / ENVIRONMENT_ERROR** — the data is present and served correctly; the SPA's ~155 KB catalog fetch does not reliably complete under the current dev-server/concurrency conditions. Not conclusively attributable to application vs. environment without deeper profiling, which is out of P1 scope |
| **Fix owner** | Performance / infrastructure. **Not fixed in P1.** |

### Observations (not defects)

| ID | Observation | Classification |
|---|---|---|
| O-P1-01 | Homepage course grid needs >10 s to populate; caught mid-load it shows 6 skeletons | NOT_A_FAILURE (latency) |
| O-P1-02 | Homepage renders 0 `<img>`/0 background images — purely text + 2 CSS gradients | NOT_A_FAILURE (design) |
| O-P1-03 | Enquiry-modal inputs lack HTML5 `required`; validation is component-level (still blocked submission, 0 writes) | NOT_A_FAILURE |
| O-P1-04 | Back/forward "failure" on first pass was a harness snapshot-timing error | NOT_A_FAILURE (test bug, corrected) |
| O-P1-05 | Responsive desktop `/courses/4` "failure" on first pass was a footer-wait timeout; re-measured 0 px overflow | NOT_A_FAILURE (test bug, corrected) |

---

## 19. P2 Readiness

# READY_WITH_WARNINGS

**P2 (lead generation / CRM) can begin.** The public website is stable and correct: 13/13 public routes render, navigation is 100 % working, the homepage and all 8 content pages are complete, the catalog and course detail function, all breakpoints are overflow-free, browser history works, and the entire pass produced **zero console errors, zero page errors and zero unexpected HTTP statuses**. The enquiry form and its fields are present and its validation demonstrably blocks an empty submit with **zero network writes** — so P2 has a clean, verified starting point and can measure a single controlled POST.

**Why not plain `READY`:**

1. **D-P1-02 will directly affect P2.** The enquiry modal is reached from the homepage and course pages, and the catalog grid is one of the slowest-loading surfaces (15.5 s cold, and it failed outright in 2 of 3 runs). A P2 test that depends on the catalog being populated will be flaky. P2 must allow a bounded retry on catalog-dependent steps, and this should be triaged before larger suites are built on it.
2. **D-P1-01 is a live content defect on the public site** — 6 published course cards visibly fail to render an image. If any P2 assertion screenshots or asserts on those cards, it will trip over this. Fixing the 6 URLs is a small, high-value pre-P2 task (explicitly out of P1 scope).
3. **P0 blockers still open:** Playwright is undeclared in `package.json` (runner not reproducible from a clean install) and the backend runs from an undocumented manual process. P2 should not deepen either dependency.
4. **Three public routes remain untested** (`/register`, `/forgot-password`, `/events/:id`) because they are not reachable from any rendered nav element. If P2 needs them, they should be added to the route inventory explicitly.

**Recommended pre-P2 actions (not performed in P1):** correct the 6 corrupted image URLs; decide whether the catalog payload should be paginated or cached to remove D-P1-02; declare `@playwright/test` in `frontend/package.json`.
