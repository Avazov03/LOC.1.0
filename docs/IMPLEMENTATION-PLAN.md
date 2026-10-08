# Implementation plan

Phase 0 is this document set. D1–D6 are final in `ASSUMPTIONS.md`. Later phases follow those decisions. They are not reopened in code.

Do not skip a gate. Do not mark a phase done because the UI opens.

Status: Phases 0–7 are implemented. Phase 1 is accepted. Phase 2 added internship management (A50–A67). Phases 3–7 added the Telegram bot, attendance with PostGIS, supervisor and admin attendance pages, reports and queued CSV, notifications, and the Docker production stack (A68–A80). Acceptance evidence is in `FINAL-ACCEPTANCE-MATRIX.md`; production steps are in `DEPLOYMENT.md`.

## Phase 0 — locked

**Done when:** the audit in `ARCHITECTURE.md` matches the tree, D1–D6 are final, the ERD indexes name their queries, university scope is on the domain, and scale targets are written without a false performance claim.

**Not done here:** new migrations, new screens, Telegram, attendance.

**Exit report:** in the Phase 0 reply. Next phase does not start automatically.

## Phase 1 — Foundation

**Decisions:** D1 is final. D6 requires Tailwind before more screens are added.

**Build:**

- Git already exists. No commit unless asked.
- Laravel 13 with Inertia, React, TypeScript, and Tailwind. The staff shell is Tailwind only; Sneat vendor assets are removed.
- Docker: app (PHP 8.4), nginx, `postgis/postgis` PostgreSQL 17, Redis 7, queue worker, scheduler.
- `.env.example` with empty `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET`. No real secrets.
- Session auth for admin and supervisor. Student role exists and has no web home.
- Role middleware and a policy smoke test.
- Migrations for university, faculty, program, academic year, study year, group, users, student profile, supervisor profile, group membership.
- Admin CRUD for that academic tree.
- Seeder: one demo university, two faculties, two programs, one academic year, several courses and groups, one admin. No TDYU constants in PHP.
- Tests: guest redirected, inactive and student logins refused, login throttle, deactivated staff signed out, role matrix, cross-university 404, supervisor sees no university-wide data, migrations up/down, `users.university_id` not null, PostGIS extension, Redis cache and queue.

**Gate:** migrations `up` and `down` on PostGIS, auth tests green, no Telegram and no attendance code yet.

## Phase 2 — Internship management

**Decisions:** D2 and D5 are final.

**Build:**

- Organizations with geography, radius check, Leaflet picker.
- Supervisors CRUD.
- Internships, supervisor periods, invites (hash only, show raw token once).
- Participants are still empty until Phase 3, but the table exists here if assignments need it. Admin-created students for assignment tests are allowed as fixtures.
- Assignments, bulk assign, end, cancel, overlap constraint.
- Change requests and the atomic approve path.
- Audit on the §70 actions that exist in this phase.
- Tests from the test plan’s assignment, invite, organization, and transaction sections. Geospatial boundary tests start here for radius storage and `ST_DWithin`.

**Gate:** inactive organization refusal, overlap refusal, approve rollback, supervisor scope on assignments. No check-in yet.

## Phase 3 — Telegram

**Build:**

- `app/Telegram` adapter, webhook secret, update id table, conversation states.
- Onboarding flow and duplicate-join protection.
- Menu and read-only screens that call Phase 2 services.
- Change-request open and cancel from the bot.
- Tests: secret, duplicate update, closed invite, duplicate onboarding, unknown user.

**Gate:** webhook tests green. Handlers contain no distance calculation. Token is not in the repo.

## Phase 4 — Attendance

**Decisions:** D3 and D4 are final. Day status stays computed. Events stay immutable.

**Build:**

- Policies, university default, group override.
- Verification service, check-in, check-out, sessions, snapshots, failed events.
- Status calculator, midnight incomplete command, due-assignment command, invite expiry command.
- Concurrency control and its test.
- Manual correction is deferred to Phase 6 unless a test needs the insert path. The immutable event trigger is created in this phase so later correction cannot update events.

**Gate:** 99/100/101 tests, rejected check-in creates no session, checkout failure leaves the session open, duplicate update creates one event, parallel check-in creates one session, radius change does not rewrite snapshots.

## Phase 5 — Supervisor dashboard

**Build:**

- Today counts, group list, student detail, history, failed attempts, change requests.
- Scope on every query.
- IDOR tests.

**Gate:** supervisor A cannot read group B. Counts match the calculator, not a second formula in React.

## Phase 6 — Admin dashboard, reports, audit

**Build:**

- Global today counts and the §53 filters.
- Manual correction UI and service.
- Audit log screen.
- Queued CSV of the caller’s scope.
- Assignment-changed and request-decided notifications.

**Gate:** correction audit test, filter tests, export does not include out-of-scope rows.

## Phase 7 — Hardening

**Build:**

- Rate limits, security pass on IDOR and webhook, structured logs.
- Index review against §82.
- A documented backup and restore drill.
- A measured check toward 1,000 students, reported as a measurement. The 10,000-student and multi-university targets stay architectural until a later measurement is asked for. They do not add services.
- Production notes: nginx, PHP-FPM, HTTPS, webhook URL, queue, scheduler.

**Gate:** test plan sections 1–5 green. Out-of-scope features still absent.

## Completion report shape

After each phase:

```text
Implemented:
Tests:
Database:
Security:
Known issues:
Out of scope:
Next phase:
```

Known issues list any USE assumption that was implemented so you can still reject it.

## Order inside a phase

Schema and constraints, then services, then authorization, then tests, then UI. UI that calls a missing rule is not a shortcut.

## Out of scope in every phase

QR, HEMIS, biometrics, face recognition, continuous GPS, native apps, organization login, fraud detection, microservices, Kafka, Kubernetes, a separate Next.js app.
