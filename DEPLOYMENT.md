# MasterInTech — Deployment Guide

Operational runbook for the MasterInTech LMS platform: the Laravel API
(`backend/`) and the React/Vite SPA (`frontend/`).

> **Scope note.** This document describes how to prepare, configure, run and
> deploy the application. It references environment variables **by name only**
> — actual values live in deployment secrets (`.env` files are gitignored and
> never committed). It also points to `READMEINESS.md` for a repo-level status
> record of verified-behaviours and external blockers.

---

## 1. Prerequisites

| Component | Minimum | Notes |
|---|---|---|
| PHP | `^8.3` | CLI is enough; `php -m` must list `pdo_sqlite` (and `pdo_mysql`/`pdo_pgsql` for those adapters) |
| Composer | 2.x | `composer check-platform-reqs` must pass |
| Node.js | `^20.19` or `>=22.12` | Vite 8 / TypeScript 6 requirement |
| npm | 10/11+ | lockfile-driven installs (`npm ci`) |
| FFmpeg + FFprobe | any recent | required by the video transcode/remux pipeline (see §14) |
| Database | SQLite (dev) / MySQL 8+ or PostgreSQL (prod) | MySQL and PostgreSQL are both supported via driver-aware migrations |
| Redis | optional | NOT required at runtime default; see §11 |
| Web server | Nginx/Apache or `php artisan serve` | TLS recommended in prod; see §23 |

The application operates fully **without Redis** (defaults use the database for
cache, session and queue). Redis only becomes relevant when you opt into
`CACHE_STORE=redis` / `QUEUE_CONNECTION=redis` for scale-out.

---

## 2. Repository layout

```
.
├── backend/            Laravel API (the deployable backend artifact)
├── backend-incomplete/ historical/incomplete work - DO NOT deploy or modify
├── frontend/           React + Vite SPA (the deployable frontend artifact)
├── deploy/             Docker helpers (dev images, nginx, entrypoint)
├── docker-compose.yml  dev environment (MySQL, Redis, Mailpit, backend, frontend)
├── .github/            CI workflows
└── READINESS.md        repo readiness + external blockers
```

---

## 3. Backend installation (local)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate                # or: touch database/database.sqlite first on SQLite
php artisan serve                  # http://127.0.0.1:8001
```

Sanity checks:

```bash
php artisan about
php artisan route:list --path=api/health
curl http://127.0.0.1:8001/api/health
curl http://127.0.0.1:8001/up          # HTTP 200
```

Run the test suite:

```bash
php artisan test                       # ~540 tests
```

---

## 4. Frontend installation (local)

```bash
cd frontend
npm ci                 # exact install from the lockfile
cp .env.example .env   # local dev values only
npm run dev            # Vite dev server, usually http://localhost:5173
```

Production-style local build (validates the deploy artifact):

```bash
npm run lint
# VITE_API_URL must be set for ANY production-mode build (see §21):
# PowerShell: $env:VITE_API_URL="http://127.0.0.1:8001/api"
# bash:       VITE_API_URL=http://127.0.0.1:8001/api
npm run build          # tsc -b && vite build  -> dist/
```

---

## 5. Environment variables

All supported variables are documented (with explanatory comments) in:

- `backend/.env.example` — backend/API settings
- `frontend/.env.example` — frontend settings (only `VITE_*` are client-side)

Both files are the **canonical reference**. The sections below group the same
variables by purpose. **Never commit real values** — `.env` / `.env.production`
are gitignored.

A production backend `.env` will set at least the variables in the groups
below (names only):

- **App/host**: `APP_NAME`, `APP_ENV=production`, `APP_KEY`,
  `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `APP_TIMEZONE`,
  `APP_BUSINESS_TIMEZONE`, `APP_LOCALE`.
- **Logging**: `LOG_CHANNEL`, `LOG_LEVEL`, `LOG_DAILY_DAYS`,
  `LOG_SLACK_WEBHOOK_URL`, `PAPERTRAIL_URL`, `PAPERTRAIL_PORT`.
- **Database**: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`, `DB_URL` (managed providers), `DB_SSLMODE`,
  `DB_CHARSET`, `DB_COLLATION`.
- **Sessions**: `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_DOMAIN`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE`, `SESSION_HTTP_ONLY`.
- **Queue**: `QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`,
  `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE_RETRY_AFTER`.
- **Cache**: `CACHE_STORE`, `CACHE_PREFIX`, `MEMCACHED_HOST`,
  `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`,
  `REDIS_USERNAME`, `REDIS_URL`.
- **Mail**: `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
  `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`,
  `POSTMARK_API_KEY`, `RESEND_API_KEY`.
- **AWS/S3**: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`,
  `AWS_USE_PATH_STYLE_ENDPOINT`, `AWS_ENDPOINT`.
- **Google OAuth**: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
  `GOOGLE_REDIRECT_URI`.
- **OTP/auth** : `OTP_EXPIRY_SECONDS`, `OTP_MAX_ATTEMPTS`,
  `OTP_RESEND_COOLDOWN_SECONDS`, `SANCTUM_TOKEN_EXPIRATION`,
  `AUTH_PASSWORD_RESET_EXPIRE`, `AUTH_PASSWORD_RESET_THROTTLE`.
- **CORS / Sanctum**: `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`.
- **Video**: `VIDEO_SECURITY_DRIVER`, `VIDEO_TOKEN_TTL`,
  `VIDEO_STORAGE_DISK`, `VIDEO_S3_PREFIX`, `FFMPEG_BINARY`,
  `FFPROBE_BINARY`.
- **Security**: `SECURITY_HSTS_FORCE`, `TRUSTED_PROXIES`.
- **LiveKit**: `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`,
  `LIVEKIT_TOKEN_TTL`.
- **AI**: `AI_PROVIDER`, `OPENAI_API_KEY`, `OPENAI_BASE_URL`,
  `OPENAI_MODEL`, `OPENAI_TIMEOUT`, `AI_MAX_TOKENS`,
  `AI_MAX_MESSAGE_LENGTH`, `AI_MAX_HISTORY_MESSAGES`.
- **Payments**: `PAYMENT_PROVIDER`, `PAYMENT_CURRENCY`,
  `PAYMENT_IDEMPOTENCY`, `PAYMENT_IDEMPOTENCY_TTL_DAYS`, `RAZORPAY_KEY_ID`,
  `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `RAZORPAY_WEBHOOK_URL`,
  `RAZORPAY_CURRENCY`, `RAZORPAY_THEME_COLOR`.
- **Seed/dev only** (never used at runtime in production): `SEED_DEFAULT_PASSWORD`,
  `SEED_LIVE_CLASS_PASSCODE`, `SEED_ALLOW_PRODUCTION=false`.

A production frontend `.env.production` sets exactly two variables:

- `VITE_API_URL` — required (build fails without it in production mode, §21).
- `VITE_GOOGLE_CLIENT_ID` — public Google OAuth client id.

---

## 6. APP_KEY generation

`APP_KEY` is required by Laravel (session/cookie/encryption). On first setup:

```bash
cd backend
php artisan key:generate --force
```

For **token rotation** on an existing deployment, the previous key(s) may be
kept in the comma-separated `APP_PREVIOUS_KEYS` variable so previously
encrypted cookies/sessions continue to decode during rotation.

If `APP_KEY` is missing, the app still boots but cookie/session features fail;
always confirm `php artisan about` shows no key warning.

---

## 7. Database configuration

Development uses SQLite: `DB_CONNECTION=sqlite` and
`DB_DATABASE=database/database.sqlite` (create the file: `touch
database/database.sqlite`).

Production should use MySQL 8+ or PostgreSQL: `DB_CONNECTION=mysql|pgsql` plus
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Migrations
are **driver-aware** — schema definitions branch on the active connection, so
the same migrations run clean on SQLite, MySQL and PostgreSQL (MySQL
uses `MEDIUMTEXT`; SQLite/PG use standard `TEXT`).

Run the official MariaDB/MySQL health probe `/api/health` to confirm the DB
afterwards (§26).

---

## 8. Migrations & seeders

```bash
cd backend
php artisan migrate              # dev / first deploy
php artisan migrate --force      # CI / non-interactive production deploys
```

Rolling back is deliberately not used in production deploys; forward-only
migrations are the convention (`php artisan migrate`).

**Seeders are production-locked.** `php artisan db:seed` throws unless
`SEED_ALLOW_PRODUCTION` is set to `true` **and** the environment is not
production-level guarded — it is intended for a single, deliberate, one-time
seed only. Never seed a live environment with known passwords.

---

## 9. storage:link & storage permissions

The app serves uploaded/derived media from `storage/`:

```bash
cd backend
php artisan storage:link
```

- The webserver user must be able to **write** to `backend/storage/` and
  `backend/bootstrap/cache/` (framework cache/sessions/logs, uploaded
  materials, HLS segments and encryption keys).
- Sensitive video keys live in `storage/app/private/…` (see §19), so keep the
  storage tree **not** web-exposed (only the `storage/app/public` symlink is
  intended to be public via `public/storage`).

---

## 10. Cache configuration

Default (no external deps): `CACHE_STORE=database` (uses the `cache` table).
Alternatives: `file`, `memcached`, `redis`.

For scale-out (multiple web nodes) use `CACHE_STORE=redis` with
`REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` (predis client is bundled and
works without the phpredis extension). Redis also enables the
single-runner scheduler lock.

The application **never hard-depends** on Redis: the scheduler lock and health
probe degrade gracefully (see `READMEINESS.md` → "operates without Redis").

---

## 11. Queue worker

Queued jobs (e.g. OTP email delivery via `SendOtpEmailJob`) require a worker:

```bash
cd backend
php artisan queue:work --sleep=1 --tries=3        # foreground
# or with process supervision (systemd/supervisor):
#   php artisan queue:work --queue=default --tries=3 --timeout=90
```

- `QUEUE_CONNECTION=database` uses the `jobs` table (matches local defaults).
- Production with Redis: `QUEUE_CONNECTION=redis`.
- Failed jobs land in the `failed_jobs` table; review with
  `php artisan queue:failed`, retry with `php artisan queue:retry all`.
- **Without a running worker, emails/jobs never execute** — include the worker
  in your deployment unit (see also the `worker` service in §27).

---

## 12. Scheduler / cron

Three scheduled maintenance commands exist (see `routes/console.php`):

| Command | Schedule | Purpose |
|---|---|---|
| `mit:prune-stale-sessions` | daily 03:00 | Delete expired video playback sessions and login OTPs |
| `mit:migrate-legacy-materials --apply` | daily 03:15 | Move legacy public class-material files to private storage after byte-identical verification |
| `mit:prune-orphan-video-dirs --apply` | daily 03:45 | Delete orphaned per-asset HLS directories with no `VideoAsset` row |

```bash
# Option A - keep a single supervising process alive:
php artisan schedule:work

# Option B - 1-minute cron (one line per scheduler node):
# * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Exactly one scheduler tick must fire the Laravel schedule (one `schedule:work`
process or one cron line per node; `onOneServer` + `withoutOverlapping`
dedupe multi-node ticks). Each command additionally guards itself with the
shared distributed lock (`RedisHealthService`, graceful single-process
fallback), so only one runner executes the work.

Behavior notes:

- All three commands are **idempotent**: re-running a tick that already
  converged is a no-op summary.
- The two storage commands are **dry-run by default** (`--apply` performs
  deletions, and only after verification: byte-identical SHA-256 copies for
  materials; row-absence + root containment + 24h grace for video dirs).
  Manual runs without `--apply` only report what would change.
- `mit:prune-stale-sessions` runs guard rails (`withoutOverlapping`,
`onOneServer`, distributed cache lock) so a single runner is safe even on
multi-node deployments.

---

## 13. FFmpeg / FFprobe

The secure-video pipeline (`VIDEO_SECURITY_DRIVER=local_hls`, AES-128 HLS)
calls FFmpeg/FFprobe to remux/transcode and derive keys/segments.

- Leave `FFMPEG_BINARY`/`FFPROBE_BINARY` **blank** to use the system `PATH`
  binaries (works on both Linux servers and local Windows).
- On servers where ffmpeg is not on `PATH`, set absolute paths, e.g.
  `FFMPEG_BINARY=/usr/bin/ffmpeg`, `FFPROBE_BINARY=/usr/bin/ffprobe`.
- Verify playability through `/api/video-stream/{assetId}/master.m3u8` with a
  valid playback auth token, plus the `/api/health` “redis/database” payload.

---

## 14. LiveKit configuration

LiveKit powers real-time classrooms; tokens are signed server-side with a
JWT (`HS256`).

Set on the backend:

- `LIVEKIT_URL` — the **WebSocket** URL of your LiveKit project (e.g.
  `wss://your-project.livekit.cloud`).
- `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` — project credentials.
- `LIVEKIT_TOKEN_TTL` — token lifetime in seconds (default `7200`).

**Fail-loud behaviour (implemented):** if these are missing/blank, token
generation throws `LiveKitConfigurationException` and the endpoints return
`503 { "code": "LIVEKIT_NOT_CONFIGURED" }` instead of minting an invalid token
against a placeholder. There is deliberately **no default `LIVEKIT_URL`** in
the codebase.

**Webhooks are fail-closed:** `POST /api/livekit/webhook` verifies the signed
Bearer JWT (`kid` = API key, `HS256` with the API secret) and rejects
unsigned/bad requests with `401` — attach this endpoint to your LiveKit
project as the webhook URL so attendance events land (`participant_joined`,
`participant_left`, `room_started`, `room_finished`).

---

## 15. Razorpay configuration

- `PAYMENT_PROVIDER=stub` — no network, no credentials (dev/test default).
- `PAYMENT_PROVIDER=razorpay` — real gateway. Requires `RAZORPAY_KEY_ID`,
  `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`.

**Checkout flow (course purchases).** `POST /api/payments/order` requires a
`course_id`; the amount charged is ALWAYS the course price from the database
and never a client-supplied value. Each (student, course) pair gets one
server-derived idempotency key, so re-clicking "Pay" reuses the existing order
and never double-charges. The student's browser opens Razorpay Checkout.js
with the `key_id`, `order_id`, `amount` and `currency` returned by the server,
then sends `order_id`, `razorpay_payment_id` and `razorpay_signature` to
`POST /api/payments/confirm`. The server verifies the payment signature
(HMAC-SHA256 of `order_id|payment_id` with `RAZORPAY_KEY_SECRET`), re-fetches
the payment, and checks order/amount/currency before marking the transaction
`paid` and activating the student's `active` course enrollment exactly once.
The browser can never declare a payment successful on its own.

Provide `RAZORPAY_WEBHOOK_URL` to the gateway dashboard so
`POST /api/payments/razorpay/webhook` receives events. The webhook is
**fail-closed**: HMAC signature verification rejects invalid payloads, and a
`paid` transition happens only after order/amount/currency checks. Both the
confirm endpoint and the webhook converge on the same paid-transition logic
(stored `payment_id` association + enrollment activation), so exactly one
`course_enrollments` row is created regardless of which event arrives first.
`PAYMENT_IDEMPOTENCY=true` gives at-most-once order creation.

**Outbound refunds (admin-initiated).** `POST /api/admin/payments/{payment}/refund`
(admin-only) issues full refunds (omit `amount`) or partial refunds (amount in
currency subunits) through the same provider abstraction. Behavior implemented
in code: the remaining refundable amount is calculated under a row lock from
previously recorded refunds; repeated requests with the same idempotency key
return the original refund instead of refunding twice; before issuing, the
service reconciles authoritative provider refund state so an ambiguous
prior outcome (gateway accepted, response lost) is recorded rather than
refunded a second time; provider failures persist no successful refund and
never fabricate a provider refund id; error responses carry only a reason
code (no credentials, payloads, or traces); successful refunds are audited
and never revoke enrollment automatically. Inbound `refund.*` webhooks
reconcile against the same ledger instead of duplicating rows.

---

## 16. Mail configuration

- Dev default: `MAIL_MAILER=smtp` — with Mailpit (Docker, §27),
  `MAIL_HOST=mailpit`, `MAIL_PORT=1025`.
- Provider defaults in `config/mail.php` support `smtp` / `log` / SES / Mailgun
  etc.; the skeleton also documents `POSTMARK_API_KEY`, `RESEND_API_KEY`,
  `SLACK_BOT_USER_OAUTH_TOKEN`.
- Set `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` for senders, plus the provider's
  host/credentials.

Without a reachable mailer, OTP/notification emails fail (and queued
`SendOtpEmailJob` jobs end up in `failed_jobs`).

---

## 17. AI configuration

Supported `AI_PROVIDER` values — **exactly three**:

- `stub` — offline placeholder (default, no credentials).
- `openai` — requires `OPENAI_API_KEY` (`OPENAI_BASE_URL` and `OPENAI_MODEL`
  select endpoint/model; `OPENAI_TIMEOUT` tunes client timeouts).
- `ollama` — self-hosted local daemon, no API key (`OLLAMA_BASE_URL`
  default `http://127.0.0.1:11434`, `OLLAMA_MODEL` default `llama3.1`,
  `OLLAMA_TIMEOUT` default 120s). Optional: nothing in the application
  requires Ollama to be installed; an unreachable daemon fails gracefully
  with a 503-style provider error, never a crash.

Any other value **fails loudly** at service resolution — it never silently
falls back to another provider, so a typo cannot silently re-route traffic.

---

## 18. AWS / S3 video configuration

Two security drivers exist (`config/video.php`):

- `local_hls` (default) — AES-128 HLS generated files & key served from the
  local disk under `storage/app/private/videos` via
  `storage/app/private` (`serve=true`).
- `s3` — HLS key/segments stored in S3-compatible object storage.

For `s3`: set `VIDEO_SECURITY_DRIVER=s3`, `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, and (for S3-
compatible providers such as MinIO/Spaces) `AWS_ENDPOINT`. `VIDEO_S3_PREFIX`
namespaces the objects.

Provide the S3 bucket credentials via IAM (no long-term keys) when possible.

---

## 19. CORS & Sanctum

- `CORS_ALLOWED_ORIGINS` — comma-separated allow-list of browser origins
  (e.g. `https://app.yourdomain.com`). Falls back to `FRONTEND_URL`. Keep it
  tight — CORS config is the browser-side cross-origin gate.
- `SANCTUM_STATEFUL_DOMAINS` — comma-separated host list used for the SPA
  cookie stateful auth; include both the frontend and API hosts.
- `SANCTUM_TOKEN_EXPIRATION` — minutes a bearer token lives (default `720`).

The API primarily authenticates via bearer tokens (`Authorization: Bearer`).
Stateful cookie auth is optional; if you rely on it, set
`SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` and pay attention to §23.

---

## 20. VITE_API_URL (frontend, mandatory)

`VITE_API_URL` is embedded into the client bundle **at build time** and is a
**public** value. Consequences of the enforcement (Batch 4):

- `npm run build` **in production mode fails** when `VITE_API_URL` is missing
  (error surfaced from `frontend/vite.config.ts`).
- The dev fallback URL (`http://127.0.0.1:8001/api`) is only used by
  `vite dev` — it is **folded out of production bundles** (verified: the
  shipped `dist/` never contains `127.0.0.1`).
- There is also a runtime guard (`frontend/src/services/api.ts`) that throws at
  boot if a production bundle somehow ships without the variable.

For real deployments, create `frontend/.env.production` (gitignored):

```bash
# frontend/.env.production
VITE_API_URL=https://api.yourdomain.com/api
VITE_GOOGLE_CLIENT_ID=your-public-google-client-id
```

Generic origins are **not** hard-coded anywhere in the codebase.

---

## 21. Frontend SPA rewrite requirements

The frontend is a client-side rendered SPA (react-router). Any static host
must **rewrite unknown paths to `/index.html`** so deep links
(`/courses/…`, `/admin/…`, `/reset-password/…`) resolve.

Examples:

- Nginx (this repo ships a config in `deploy/frontend/nginx.conf`):
  `try_files $uri $uri/ /index.html;`
- S3 + CloudFront: use a custom error-page “index.html” / Lambda@Edge rewrite.

Without this, refreshing a deep link returns 404.

---

## 22. TLS / reverse proxy requirements

- Terminate TLS at the edge (Nginx/Caddy/ALB/CloudFront) and forward regular
  HTTP to the backend worker.
- Use `https://` origins in `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` and the
  frontend `VITE_API_URL`.
- Set `SESSION_SECURE_COOKIE=true` and `SECURITY_HSTS_FORCE=true` (forces HSTS
  even when the app itself only ever sees proxy-forwarded HTTP).
- The environment has no TLS material — certificates are provisioned outside
  this repo.

---

## 23. Trusted proxies

The backend runs behind a TLS-terminating proxy/LB. **Laravel ignores proxy
headers unless it trusts the proxy IP**, so configure the hop(s):

- `TRUSTED_PROXIES` — comma-separated proxy IPs/CIDRs, e.g.
  `TRUSTED_PROXIES=10.0.0.10` or `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12`.
  Use `REMOTE_ADDR` to trust exactly the immediate peer.
- **Default is to trust nothing** (safe). Forwarded headers are then ignored,
  meaning `request()->secure()` reflects the app-facing scheme — if that
  breaks session/HSTS behaviour, set the env variable described above.
- Avoid blanket `*` trust; it lets arbitrary callers spoof
  `X-Forwarded-*` (incl. client IP used by rate-limiters).

Implemented via `Illuminate\Http\Middleware\TrustProxies` (registered
globally) reading `config/trustedproxy.php`.

---

## 24. Config / route / cache commands (deploy-time)

Run these after deploying code or env changes (CI does the same):

```bash
cd backend
php artisan config:cache
php artisan route:cache        # optional; both cache routes+config
php artisan view:cache
php artisan event:cache        # optional
```

To invalidate (after config/env edits on a host, since `.env` changes aren’t
picked up by cached config): `php artisan optimize:clear`.

Never run `config:cache` with an invalid `.env` reference; CI runs
`php artisan key:generate` first then migrates.

---

## 25. Health checks

- `GET /api/health` — JSON liveness/diagnostics:
  `status` (always `ok` for liveness), `service`,
  `database.status` (`ok`/`error`) + `database.driver`,
  `redis.available`/`latency_ms`/`cache_store`/`queue_connection`
  (Redis failures are **non-fatal**).
  It **never leaks exception details or credentials**.
- `GET /up` — framework-level liveness (200).

Wire your load balancer/uptime monitor to `GET /api/health` and expect HTTP
200 with `database.status: ok`.

---

## 26. Docker development environment

A dev-only environment is provided via `docker-compose.yml` (MySQL, Redis,
Mailpit, backend API, queue worker, frontend SPA). No real credentials live in
the Compose files.

**Start**

```bash
docker compose up -d          # build (first time) + start all services
docker compose ps
```

Dev addresses (override ports with `MYSQL_PORT`, `REDIS_PORT`,
`BACKEND_PORT`, `FRONTEND_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`):

| Service | URL |
|---|---|
| Frontend SPA | http://localhost:5173 (nginx; `/api` proxied to backend) |
| Backend API | http://localhost:8001/api |
| MySQL | localhost:3306 (user/password `masterintech`, db `masterintech`) |
| Redis | localhost:6379 |
| Mailpit UI | http://localhost:8025 (SMTP localhost:1025) |

**Common commands**

```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan tinker
docker compose logs -f backend frontend
docker compose down           # stop (keeps volumes)
docker compose down -v        # stop + wipe local dev data volumes
docker compose build backend frontend   # rebuild after Dockerfile changes
```

**Note.** The backend container writes to the gitignored host directory
`backend/storage` (bind mount) so uploads/HLS keys survive restarts. `APP_KEY`
is generated on first container start and stored in the container’s
`/var/www/.env` — reset with
`docker compose exec backend php artisan key:generate --force` if you need a
fresh one.

---

## 27. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| `/api/health` shows `database.status: error` | DB down / wrong `DB_HOST`/creds; check the DB service, then `php artisan migrate` |
| `npm run build` fails: “VITE_API_URL is required” | No `VITE_API_URL` for production-mode build — §20 |
| Production bundle calls `http://127.0.0.1:8001` | Stale `dist/` built pre-enforcement — rebuild with `VITE_API_URL` |
| LiveKit room won’t start, `503 LIVEKIT_NOT_CONFIGURED` | `LIVEKIT_URL/KP_KEY/SECRET` unset — §14 |
| New session appears “stale” every night | Scheduler not running — §12 (`schedule:run` cron or `schedule:work`) |
| OTP email never arrives | Queue worker down / mailer misconfigured — §11, §16 |
| Deep-linked route 404s | SPA rewrite missing — §21 |
| Rates blocked after moving behind LB | `TRUSTED_PROXIES` mis-set / 0.0.0.0 spoof — §23 |
| HSTS/secure-cookie warnings behind proxy | Set `SECURITY_HSTS_FORCE=true` and TLS terminator + `TRUSTED_PROXIES` |
| `config:cache` and env changes seem ignored | Re-run `php artisan optimize:clear` after editing `.env` — §24 |
| test count differs from an older note | Suite grows with checkpoints; latest verified baseline is backend 909 tests / 5186 assertions — see `READINESS.md` |

---

## 28. Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (MUST — never enable debug
  outside local development: with debug on, API errors disclose exception
  classes, absolute filesystem paths, and stack traces to unauthenticated
  callers. `backend/bootstrap/app.php` additionally sanitizes API error
  responses regardless of this flag, but the flag must still be `false`.)
- [ ] `APP_KEY` set (and `APP_PREVIOUS_KEYS` during rotation)
- [ ] `APP_URL` + `FRONTEND_URL` = real HTTPS origins
- [ ] `DB_*` point at the managed database; `php artisan migrate --force`
  applied
- [ ] Pre-deployment backup taken (database + `storage/app/private` +
  secrets) — see §30
- [ ] `php artisan storage:link` run; `storage/` + `bootstrap/cache` writable
- [ ] Queue worker running/mastered (systemd/supervisor); failed-job
  monitoring in place
- [ ] Scheduler running (`schedule:work` or cron line from §12) covering all
  three maintenance commands
- [ ] `ffmpeg`/`ffprobe` on `PATH` (or `FFMPEG_BINARY`/`FFPROBE_BINARY`);
  private video storage writable; disk capacity planned; upload limit reviewed
- [ ] `LIVEKIT_URL` + `LIVEKIT_API_KEY`/`API_SECRET` set; webhook configured
  and tested
- [ ] `PAYMENT_PROVIDER=razorpay` and Razorpay webhook secret configured;
  webhook delivery tested
- [ ] Mailer credentials set (`MAIL_*`); test delivery works through the
  queue worker
- [ ] `AI_PROVIDER` is `stub`, `openai` with `OPENAI_API_KEY`, or `ollama`
  with a reachable daemon (`OLLAMA_BASE_URL`)
- [ ] `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS` = real hosts
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` set for the deployment
- [ ] `TRUSTED_PROXIES` = the TLS-hop IPs (and `SECURITY_HSTS_FORCE=true`)
- [ ] Web-server upload/request size + timeout limits reviewed
- [ ] Frontend built with `VITE_API_URL=https://api.yourdomain.com/api`
  (production-mode build rejects missing value)
- [ ] SPA rewrite in place on the static host
- [ ] Monitoring: Laravel logs aggregated, `failed_jobs` watched,
  `GET /api/health` → 200 + `database.status: ok` from the LB's domain,
  scheduler and transcoding failures visible
- [ ] Seeders untouched in prod (`SEED_ALLOW_PRODUCTION` unset/false)

---

## 29. Video DRM posture & limitations

What is implemented today:

- Private source storage, short-lived HMAC playback authorization
  (`VIDEO_TOKEN_TTL`), enrollment-gated manifests/segments/keys, AES-128 HLS
  (`local_hls`/`s3` drivers), per-IP throttling on `video-stream/*`, playback
  sessions with audit trail, and user-bound watermark overlay data.
- A driver abstraction (`VideoDriverInterface` + `VideoSecurityManager`) so a
  licensed DRM/license provider (e.g. Mux DRM, Axinom, BuyDRM, PallyCon) can be
  added as a new driver without redesigning LMS authorization. The `mux`
  driver entry point exists for this purpose.

What is explicitly NOT claimed:

- **Widevine, PlayReady and FairPlay cannot be implemented as completely
  free/open-source DRM.** They require licensed certificate/commercial
  license-server infrastructure. Any future "DRM" milestone must budget for a
  commercial provider or per-platform licensing.
- **Browser DRM cannot guarantee prevention of screen recording or camera
  capture.** EME/CENC raise the bar (no trivial URL/segment download), but an
  determined viewer can always re-record rendered output. Treat DRM as
  deterrence + traceability (watermarks, session binding, concurrency
  detection), never as absolute copy prevention.
- Open/self-hosted stepping stones toward DRM-readiness: FFmpeg + Shaka
  Packager (CMAF/CENC packaging), MPEG-DASH via Shaka Player with EME
  Clear Key **for development/testing only** — Clear Key is not production
  protection since the key travels to the browser in the clear.
- [ ] No `.env*`/keys committed (`git status` clean of secrets)

---

## 30. Backup, restore & rollback

No backup tooling ships in this repository; the procedures below are
operator responsibilities using the host/provider's standard facilities.
Retention periods are **operator-defined** (no retention policy exists in
code). Backups must live off the application host.

### Database

- Take regular dumps of the production database (frequency and retention
  are operator-defined; keep several generations).
- Always take a **pre-deployment backup** immediately before running
  `php artisan migrate --force` on production.
- Restore by loading a dump into an empty database, then run
  `php artisan migrate --force` to confirm schema state; verify with
  `GET /api/health` (`database.status: ok`) and row-count spot checks.
- Migrations are forward-only by convention — production rollback means
  restoring the pre-deployment dump, never `migrate:rollback`.

### Private storage

Back up the whole `storage/app/private` tree: uploaded materials,
lesson video sources, HLS output/segments, AES key material, call
recordings, and any other private disk content. HLS output and sources
follow the production storage strategy for the configured disk
(`local_hls` on host volumes, or the S3-compatible bucket when
`VIDEO_SECURITY_DRIVER=s3`): back up whichever backend is authoritative,
including the bucket when S3 is used. Restore by putting the tree back
before starting the web/worker processes, then verify a private download
and an HLS manifest load.

### Environment / secrets

- Keep versioned, access-controlled backups of the production backend
  `.env` and `frontend/.env.production` outside the repository.
- Never commit secrets (see §5 scope note).
- During rollback, restore the previous secrets configuration alongside
  the previous code: a new `APP_KEY` without `APP_PREVIOUS_KEYS`
  invalidates existing sessions/cookies.

### Rollback

- **Application:** redeploy the previous release commit for backend and
  frontend (rebuild frontend `dist/` from the pinned commit with the same
  `VITE_API_URL`).
- **Database:** restore the pre-deployment dump (forward-only migrations).
- **Storage:** restore `storage/app/private` from backup when the release
  touched stored content or migrations affecting file references.
- **Queue:** stop/drain workers before deploy; failed jobs persist in the
  `failed_jobs` table across releases — review and retry after rollback.
- **Environment:** restore the previous secrets set; run
  `php artisan optimize:clear` after any `.env` change on host deploys.