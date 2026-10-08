# Architecture

Phase 0 locked on 8 October 2026. Business rules live in `SOURCE-OF-TRUTH-SPEC.md`. Technical rules live in `CURSOR-IMPLEMENTATION-CONTRACT.md`. Visual reference lives in `docs/design/`. The staff UI decision is D6 in `ASSUMPTIONS.md`: React, TypeScript, Inertia, and Tailwind. The design kit does not replace that stack.

Words used below mean different things:

- **Selected** — the MVP standard.
- **Configured** — present in Docker, Composer, npm, or `.env.example`, and not yet doing product work.
- **Implemented** — running in application code or a migration that has been applied.
- **Planned** — required by the ERD or a later phase, and not in the schema or routes yet.

## 1. Repository audit

Inspected `d:\SCP loyhalar\Loc.1.0` on 8 October 2026. The first inspection found an empty folder. An early foundation was added before this lock. The table records what is actually in the tree now.

| Piece | Selected | Configured | Implemented | Planned |
| --- | --- | --- | --- | --- |
| PHP | 8.3+ | Docker image `php:8.4-fpm-bookworm` with pdo_pgsql, redis, opcache, intl, pcntl. Local machine has PHP 8.3 | Both run the app and both test suites | — |
| Laravel | 13 | `laravel/framework` ^13.0 | Full product: academic tree, internships, Telegram, attendance, reports, notifications | — |
| React | 19 | `react` ^19.3, `@inertiajs/react` ^3.8 | All admin and supervisor screens | — |
| TypeScript | yes | `typescript` ^7, `tsconfig.json` | Every page; `npm run typecheck` is a gate | — |
| Inertia | 3 | `inertiajs/inertia-laravel` 3.0 | Shared props, role navigation, flash | — |
| Tailwind | 4 | `@tailwindcss/vite` in `vite.config.js`, theme tokens in `resources/css/app.css` | The only staff styling pipeline. Kit tokens (`#696cff`, Public Sans, light/dark) are Tailwind theme values | — |
| PostgreSQL | 17 | Docker `postgis/postgis:17-3.5`, host port 5434 | 14 migrations, constraints, triggers, partial unique indexes | — |
| PostGIS | 3.5 | Migration `enable_postgis` | `organizations.location geography(Point,4326)` with GiST; `ST_DWithin` decides, `ST_Distance` records | — |
| Redis | 7 | Docker `redis:7-alpine` (host port 16379, AOF on). Docker uses phpredis; local PHP uses `predis/predis` | Cache, queue (notifications, CSV), rate limits. Never attendance storage; bot dialog state is in PostgreSQL | — |
| Docker | compose | `app` (FPM), `nginx` :8080, `postgres` :5434, `redis` :16379, `queue`, `scheduler`, health checks | Built and running; `/health` checks DB and cache | TLS terminates in front of nginx (`DEPLOYMENT.md`) |
| Auth | Laravel session | Login + password, staff roles only, `users.status = ACTIVE` | Login throttle (5 tries), `active` middleware signs out deactivated staff, student role refused | — |
| Migrations | ERD | 14 migration files | Up, down and re-up verified on PostGIS | — |
| Routes | Inertia + one webhook | `routes/web.php`, `routes/console.php` | 76 application routes including `POST /telegram/webhook` and `GET /health` | — |
| Tests | PHPUnit 12 | `phpunit.xml` sqlite `:memory:`; `phpunit.pgsql.xml` PostGIS + Redis (`composer test:pgsql`) | 285 tests, 1957 assertions on PostGIS | — |
| Frontend files | `resources/js` | Pages, layouts, `Components/ui.tsx`, `Icon.tsx`, `Modal.tsx`, Leaflet map | Tailwind only | — |
| Telegram | Bot API webhook | `TELEGRAM_*` read from the environment; `.env.example` keeps them empty | `app/Telegram`: webhook, `UpdateProcessor`, `BotHandler`, `ConversationStore`, HTTP and fake clients; `telegram:webhook`, `telegram:poll` (local only), `telegram:prune` | — |

Git: branch `main`, remote `https://github.com/Avazov03/LOC.1.0.git`. `.env` is gitignored.

There is no TDYU table, no Next.js app, no MongoDB, no microservice, no Kafka, no Kubernetes.

## 2. Current architecture

One Laravel 13 modular monolith. Staff use Inertia. Students have a `STUDENT` role and no web routes; they use the Telegram bot. Writes go through services, never through controller rules:

- `Attendance/AttendanceService` (check-in, check-out, stale sessions), `LocationVerifier` (PostGIS decision and evidence snapshot), `AttendancePolicyService` (university default, group override, single-session lock), `AttendanceCorrectionService` (append-only corrections), `AttendanceDayQuery` (the one D4 day-status SQL used by dashboards, lists, reports, CSV and the bot).
- `Reports/AttendanceReportService` streams the caller's scope to CSV from a queued job. `Notifications/StudentNotifier` queues idempotent Telegram notices after commit.
- `app/Telegram` parses updates and renders replies; it calls `StudentOnboardingService`, `StudentContextService`, `AttendanceService` and `InternshipChangeRequestService` and calculates nothing itself.
- `Admin/BootstrapAdminService` (`php artisan admin:ensure`) creates or refreshes the first admin from `ADMIN_*` environment values.

The staff UI is Tailwind only (D6). The kit is used for colors, font, and layout shape. No kit CSS or JS is loaded.

## 3. Proposed architecture

One Laravel 13 application. React and Inertia live in the same repository. PostgreSQL with PostGIS is the only database. Redis is cache, queue, and rate limiting. Telegram is an input adapter.

```text
Admin / Supervisor browser
        │
        ▼
React + Inertia + TypeScript + Tailwind
        │
        ▼
Laravel HTTP (controllers, form requests, policies)
        │
        ▼
Application services
        │
        ├── PostgreSQL 17 + PostGIS
        └── Redis (queue, cache, rate limit)

Student Telegram
        │ webhook HTTPS
        ▼
TelegramWebhookController  (authenticate, idempotency insert, dispatch)
        │
        ▼
Telegram handlers          (parse update, call a service, render a reply)
        │
        ▼
Same application services as the web panel
```

Controllers orchestrate. They do not decide geofence, policy, or assignment conflicts. Telegram handlers do not contain attendance rules.

### 3.1 Stack

| Layer | Choice |
| --- | --- |
| PHP | 8.4 in Docker (allowed range 8.3–8.5) |
| Framework | Laravel 13 |
| UI | Inertia 3, React 19, TypeScript, Tailwind 4. Visual tokens from the design kit (D6) |
| Database | PostgreSQL 17, PostGIS 3 |
| Queue / cache | Redis 7 |
| Bot | Telegram Bot API, webhook in production, polling allowed only in local development |
| Map | Leaflet + OpenStreetMap-compatible tiles |
| Containers | `app`, `nginx`, `postgres`, `redis`, `queue-worker`, `scheduler` |

MySQL is not a candidate. SQLite is not used for geospatial or constraint tests.

### 3.2 Code layout

```text
app/
  Enums/
  Http/Controllers/Web/Admin/
  Http/Controllers/Web/Supervisor/
  Http/Controllers/Telegram/TelegramWebhookController.php
  Http/Requests/
  Models/
  Policies/
  Services/
    Academic/
    Attendance/
    Audit/AuditLogger.php
    Identity/
    Internship/
    Organization/
    Students/StudentOnboardingService.php
  Telegram/
    DTOs/
    Handlers/
    Keyboards/
    TelegramResponder.php
```

Domain services that both channels share:

- `StudentOnboardingService`
- `AttendanceService`
- `AttendanceVerificationService`
- `AttendancePolicyService`
- `AttendanceStatusCalculator`
- `InternshipAssignmentService`
- `InternshipChangeRequestService`
- `AuditLogger`

`app/Telegram` may format messages and keyboards. It may not calculate distance, open a session, or approve a change request.

As built through Phase 2: controllers are flat under `app/Http/Controllers` and stay thin. Services are `Access/AccessScope` (the single admin/supervisor scope definition; out-of-scope ids are 404), `Audit/AuditLogger`, `Supervisors`, `Organizations`, `Internships` (internships, supervisor periods, `InviteService`), `Onboarding/StudentOnboardingService`, `Assignments/InternshipAssignmentService`, and `ChangeRequests/InternshipChangeRequestService`. `App\Support\Geo` hides the PostGIS/sqlite difference for the organization point. `BusinessRuleException` becomes a flash error for Inertia requests and a 422 for JSON.

### 3.3 Request paths

**Web.** Session auth → role middleware → policy (role + scope) → form request → service → Inertia response. The React page displays the result. It does not decide `distance <= radius`.

**Telegram.** Secret token check → rate limit → insert `update_id` in the same transaction as the business write → handler → service → reply. A duplicate `update_id` returns 200 and does no work.

Attendance verification finishes inside the webhook request. It is not queued behind “we will check later” (contract §36). Notifications and CSV exports are queued.

### 3.4 Time

- Stored instants: `timestamptz`, UTC.
- Day boundary, “today”, and internship period edges: university timezone, default `Asia/Tashkent`.
- `occurred_at` is set by the database clock. Client timestamps are not accepted as attendance time.

### 3.5 Geospatial

Organization location is `geography(Point, 4326)` with a GiST index. Radius check is `ST_DWithin`. Distance audit value is `ST_Distance`. Both return meters for geography. Latitude and longitude floats are not the live geofence source. Events store numeric snapshots of the coordinates, radius, and distance used at that moment so a later edit of the organization cannot rewrite history.

### 3.6 Integrity

Application validation and database constraints are both required. Invariants that the database enforces:

- unique Telegram user id
- unique invite token hash
- at most one `ACTIVE` assignment per student
- at most one `OPEN` attendance session per student
- radius 100–500
- foreign keys `ON DELETE RESTRICT`
- spatial index
- triggers that reject update and delete on `attendance_events` and `audit_logs`

Concurrency for check-in uses a transaction, a row lock on the student, and the partial unique index on open sessions. A PHP `if` alone is not the control. D2 uses a partial unique index on `PENDING` or `ACTIVE` assignments, so two open placements cannot be inserted together.

### 3.7 Authorization

Every web and service entry checks authenticated user, role, university, and scope. Hiding a button is not authorization.

- Admin queries include `university_id` of the signed-in user. Another university’s id is 404.
- Supervisor queries are constrained to internships whose open supervisor period is that supervisor, and to the same university. Changing a student id in the URL does not widen that set. Missing and out-of-scope records answer 404.
- A student has no web API. Telegram resolves `telegram_user_id` to one profile and ignores any other student id in the update. Student A cannot read student B.
- Organization contacts have no login.

This is university ownership on each root row. It is not a billing tenant framework.

### 3.8 What this architecture will not contain

QR, HEMIS, biometrics, face recognition, continuous GPS, native mobile apps, an organization portal, fraud-detection services, a second frontend, microservices, Kafka, Kubernetes. No `tdyu_students`, `tdyu_groups`, or `tdyu_faculties` tables. The first seed university is fictional data on the generic `universities` row.

### 3.9 Scale

| Target | What the monolith does |
| --- | --- |
| 1,000+ students | Indexed lookups, paginated lists, queue for CSV and Telegram notices, PostGIS index for the geofence |
| 10,000+ students | Same process. Chunked bulk assign, no full-table attendance render, Redis for cache and rate limit, aggregates for dashboard counts |
| More universities | `university_id` on users, faculties, years, organizations, internships, policies. A second university is another row, not a rewrite |

Lists use pages of 25, capped at 100. Dashboards return counts, not every student row. Bulk assignment calls one service. Each chunk of 100 students is one transaction with the same D2 and D5 checks. A request above 500 students is queued so HTTP stays short. Check-in itself is never queued.

### 3.10 Performance rules

- Pagination on every staff list. Filters are columns that have an index in `ERD.md` section 9.
- Eager-load the relations a page renders. A list that touches organization, student, and group in a loop is an N+1 and is not accepted.
- Redis in Docker and production: cache for read-mostly policy rows, queues for notifications and CSV, rate limits from A39. Redis is not the attendance record.
- `.env.example` sets cache and queue to redis. Local PHP without the extension uses `REDIS_CLIENT=predis`; Docker uses phpredis.
- `phpunit.xml` uses sqlite memory and the array cache for speed. `phpunit.pgsql.xml` runs the same suite on PostGIS and Redis. Any test that touches `geography`, GiST, or a PostgreSQL-only constraint runs there. Sqlite must not be cited as proof of the geofence.
- Notifications that are not the check-in answer go to the queue (A38). The webhook still answers verified or rejected inside the request.
- Reports are the filtered attendance list plus a queued CSV of the caller’s scope (A36).
- Spatial checks stay in PostgreSQL: `ST_DWithin` decides, `ST_Distance` records meters. The browser map does not verify.
- Concurrency: student row lock, partial unique open session, partial unique open assignment, unique `update_id`.

## 4. Conflicts found

The three pre-lock conflicts (Tailwind not compiled, Sneat classes in pages, nullable `users.university_id`) were closed in Phase 1. Academic behavior follows D1. D2–D5 are not in code yet, which is correct for their phases.

Document gaps that were closed without a new product rule:

| Topic | Reading used |
| --- | --- |
| Menu “Internship Groups” vs entity `Internship` | One `internships` table |
| §71 `OrganizationContact` vs §74 contact columns | Columns on `organizations` only |
| §43 `SUSPICIOUS` vs §111 no fraud detection | Enum value reserved, never auto-set |
| §46 late threshold vs contract §30 | Column not created |
| §47 program/internship policy vs MVP university + group | Only `UNIVERSITY` and `GROUP` |
| §99 result codes vs §39 event types | Two columns, mapping in `ATTENDANCE-RULES.md` |
| §38 “may record” vs §69 keep failed attempts | Failed attempts are always events |

## 5. Risks

| Risk | Mitigation |
| --- | --- |
| Redis outage stops cache, queue, and login throttle | Attendance lives in PostgreSQL only, so no attendance data is lost. Redis AOF is on in Docker |
| PostGIS tests accidentally run on SQLite | Feature tests that touch geography use the PostGIS service |
| Webhook retry plus a second button press treated as the same case | `update_id` uniqueness and the open-session unique index are separate controls |
| Live location becomes a trail | Store only the point for the pending action |
| Path contains a space (`SCP loyhalar`) | Quote Docker and shell paths |
| Claiming 1,000 or 10,000 students works without a measurement | Measured at 1,000 students in Phase 7 (`FINAL-ACCEPTANCE-MATRIX.md` §9: every page under 300 ms). 10,000 is not measured |
| Scope leak through Inertia props | Policies and query scopes on the server; props contain only the caller’s rows |

## 6. Documentation map

| File | Contents |
| --- | --- |
| `SOURCE-OF-TRUTH-SPEC.md` | Product rules |
| `CURSOR-IMPLEMENTATION-CONTRACT.md` | How those rules are built |
| `ERD.md` | Tables, keys, indexes, delete behavior |
| `PERMISSIONS.md` | Who can do what |
| `STATE-MACHINES.md` | Legal status transitions |
| `TELEGRAM-FLOW.md` | Bot states and copy |
| `ATTENDANCE-RULES.md` | Verification and day status |
| `API-CONTRACT.md` | Routes and service boundaries |
| `TEST-PLAN.md` | Required automated tests |
| `IMPLEMENTATION-PLAN.md` | Phases and exit gates |
| `DEPLOYMENT.md` | Production setup: TLS, webhook, workers, scheduler, backups, secrets |
| `FINAL-ACCEPTANCE-MATRIX.md` | Requirement-by-requirement evidence and gate results |
| `ASSUMPTIONS.md` | Gaps that are not silent inventions |
| `design/01-DIZAYN-DNK.md` | Visual tokens, layout, components, page patterns |
| `design/02-QOLLANMA.md` | How the Zonic kit is meant to be copied |
| `design/03-PROMPTLAR.md` | Kit prompts. They forbid React and Tailwind. D6 keeps those files as a visual reference and keeps the contract stack |
