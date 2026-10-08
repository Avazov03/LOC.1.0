# Architecture

Phase 0. No application code yet. Business rules live in `SOURCE-OF-TRUTH-SPEC.md`. Technical rules live in `CURSOR-IMPLEMENTATION-CONTRACT.md`. Visual rules live in `docs/design/`. Gaps live in `ASSUMPTIONS.md`. The stack conflict between Inertia/React and the Sneat HTML kit is D6.

## 1. Repository analysis

Inspected `d:\SCP loyhalar\Loc.1.0` on 8 October 2026.

| Check | Result |
| --- | --- |
| Files | Directory is empty |
| Git | Not a repository |
| PHP / Composer | None |
| Laravel version | None. Target is Laravel 13 (released 17 March 2026, PHP 8.3–8.5, security support through 17 March 2028) |
| `package.json` | None |
| Database, migrations, models | None |
| Routes, middleware, policies, jobs | None |
| React / Inertia / Tailwind | None |
| Telegram | None |
| Docker / `.env.example` | None |
| Tests | None |

There is no existing architecture to conflict with. Sibling projects next to this folder are out of scope and are not a source of business rules.

## 2. Current architecture

None. This is a greenfield build.

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
| UI | Official Laravel React starter kit: Inertia 3, React 19, TypeScript, Tailwind 4 |
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

Concurrency for check-in uses a transaction, a row lock on the student, and the partial unique index on open sessions. A PHP `if` alone is not the control.

### 3.7 Authorization

Every web and service entry checks authenticated user, role, and scope. Hiding a button is not authorization. Supervisor queries are constrained to internships whose open supervisor period is that supervisor. Missing and out-of-scope records answer 404, so a supervisor cannot learn that another supervisor’s student exists.

### 3.8 What this architecture will not contain

QR, HEMIS, biometrics, face recognition, continuous GPS, native mobile apps, an organization portal, fraud-detection services, a second frontend, microservices, Kafka, Kubernetes.

## 4. Conflicts found

No conflict with existing code, because there is no code.

Conflicts inside the two authoritative documents are recorded in `ASSUMPTIONS.md`. The ones that change behavior are D1–D5. Technical mappings that do not add a product rule:

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
| D1–D4 left open and then implemented two different ways | Do not start the phase that needs a decision until it is accepted |
| PostGIS tests accidentally run on SQLite | Feature tests that touch geography use the PostGIS service |
| Webhook retry plus a second button press treated as the same case | `update_id` uniqueness and the open-session unique index are separate controls |
| Live location becomes a trail | Store only the point for the pending action |
| Starter kit ships a demo profile and SQLite defaults | Replace the database config and remove demo product pages in Phase 1 |
| Path contains a space (`SCP loyhalar`) | Quote Docker and shell paths |
| Claiming 1,000 students works without a measurement | No performance claim until Phase 7 measures it |
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
| `ASSUMPTIONS.md` | Gaps that are not silent inventions |
| `design/01-DIZAYN-DNK.md` | Visual tokens, layout, components, page patterns |
| `design/02-QOLLANMA.md` | How the Zonic kit is meant to be copied |
| `design/03-PROMPTLAR.md` | Kit prompts. They forbid React and Tailwind; D6 records the resolution |
