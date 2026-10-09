# Final acceptance matrix

Checked on 8 October 2026 against the locked documents. "Browser" means checked by hand in a browser against the Docker stack (`http://localhost:8080`) as `Avazov` (ADMIN) and `rahbar` (SUPERVISOR), including a 390 px viewport and a console-error recorder. "Webhook e2e" means the full bot conversation driven through `POST /telegram/webhook` in tests with the fake Telegram client; a live Telegram chat needs a human with a phone and was not part of this check.

COMPLETE means every applicable layer below is done. N/A means the layer does not exist for that requirement.

## 1. Foundation, academic structure, staff (Phases 1–2)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Staff login, throttle, inactive refused, student role refused | `AuthenticatedSessionController`, `LoginRequest`, `EnsureActiveUser` | Done | Login page with validation | Guest redirect; roles | `AuthenticationTest` | Login, validation error, logout for both roles | COMPLETE |
| Role menus and role-gated routes | `HandleInertiaRequests`, `EnsureRole`, `AppLayout` | Done | Role navigation; only the most specific item is highlighted | Supervisor gets 403 on admin routes | `RoleAuthorizationTest`, `Phase2AuthorizationTest` | All 17 admin and 6 supervisor pages | COMPLETE |
| University scope (A-to-B ids are 404) | `AccessScope`, `university_id` on root rows | Done | N/A | 404 across universities | `UniversityScopeTest`, `AdminCompletionTest` | N/A (one demo university) | COMPLETE |
| Faculties, programs, years, courses, groups, settings | `AcademicController`, `AcademicStructureService`, `UniversitySettingsService` | Done | CRUD pages | Admin only | `AcademicStructureTest`, `AdminCompletionTest` | Pages open at 390 px, no overflow | COMPLETE |
| Student directory, identity correction, status | `StudentController`, `StudentService` | Done, audited | List with filters, detail, link to attendance | Admin; supervisor sees own scope only | `StudentManagementTest` | Detail and attendance link | COMPLETE |
| Supervisors and replacement history | `SupervisorController`, `SupervisorService`, `InternshipService` | Done, audited | List, detail, create, status | Admin only | `InternshipManagementTest` | Pages open | COMPLETE |
| Organizations with PostGIS point and radius 100–500 | `OrganizationController`, `OrganizationService`, Leaflet picker | Done; GiST index; CHECK on radius | Map picker, detail, status | Admin only; supervisors never get coordinates | `OrganizationTest` (99/100/101 m) | Pages open | COMPLETE |
| Internships, invites (hash only), participants | `InternshipController`, `InviteService` | Done | Invite created once, link shown once | Admin | `InviteOnboardingTest` | Pages open | COMPLETE |
| Assignments (D2), bulk assign in chunks of 100 | `AssignmentController`, `InternshipAssignmentService` | Done; partial unique index | Assign, bulk, end, cancel, edit dates | Admin; supervisor only for own participants | `AssignmentTest`, `ConcurrencyTest` | Pages open | COMPLETE |
| Change requests with atomic approval | `ChangeRequestController`, `InternshipChangeRequestService` | Done; one pending per student | Web review; bot request and cancel | Supervisor approves existing org only (A24) | `ChangeRequestTest`, `TelegramBotTest` | Page open as both roles | COMPLETE |
| Audit log, append-only | `AuditLogger`, `audit_logs` trigger | Done | Audit page | Admin only | `AuditLogTest` | Page open | COMPLETE |

## 2. Telegram (Phase 3)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Webhook secret, replay protection | `TelegramWebhookController`, `telegram_processed_updates` | Done | N/A | 404 with no secret configured, 403 on a wrong one, 120/min/IP | `TelegramBotTest` | N/A | COMPLETE |
| Onboarding through an invite with confirmation | `BotHandler` (JOIN_* states), `StudentOnboardingService` | Done | Name, surname, phone, optional code, confirm | Invalid, closed, expired invite refused; one profile per Telegram id | `TelegramBotTest`, `ConcurrencyTest` | Webhook e2e | COMPLETE |
| Identity by Telegram user id, unknown and blocked users denied | `StudentContextService` | Done | "Access denied" copy | Same answer for unknown, inactive, blocked | `StudentContextServiceTest`, `TelegramBotTest` | Webhook e2e | COMPLETE |
| Menu: profile, placement, today, history, help | `BotHandler`, `BotText`, `Keyboard` | Done | Seven-button menu | Own data only | `TelegramBotTest` | Webhook e2e | COMPLETE |
| Check-in and check-out by shared location | `BotHandler` → `AttendanceService` | Done | Location request keyboard; forwarded or venue refused | Own assignment only; 6/min | `TelegramBotTest` | Webhook e2e | COMPLETE |
| Change request from the bot | `BotHandler` → `InternshipChangeRequestService` | Done | Existing org by list index, new org text without coordinates | Stale buttons refused | `TelegramBotTest` | Webhook e2e | COMPLETE |
| Rate limits (A73), group chats and edits ignored, send failure tolerated | `UpdateProcessor` | Done | N/A | 20/min per user, 5 joins/min | `TelegramBotTest` | N/A | COMPLETE |
| Webhook setup, local polling, pruning | `telegram:webhook`, `telegram:poll`, `telegram:prune` | Done | N/A | HTTPS and 16-character secret enforced | `NotificationsAndOpsTest` | N/A | COMPLETE |

## 3. Attendance (Phase 4)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Geofence decided by PostGIS `ST_DWithin`, distance by `ST_Distance` | `LocationVerifier` | Done | Distance and radius shown as evidence | N/A | `AttendancePostgisTest` (99, 99.9999, 101 m) | Evidence 33 m / 100 m, rejected 666 m | COMPLETE |
| Server time only; client timestamps ignored | `AttendanceService` | Done | N/A | N/A | `AttendanceServiceTest` | N/A | COMPLETE |
| Immutable events with snapshots of point, radius, distance | `attendance_events` + trigger | Done | Evidence list | No update or delete path | `AttendanceServiceTest`, `AttendancePostgisTest` | Evidence list | COMPLETE |
| Failed attempts recorded (outside radius, low accuracy, invalid, forwarded) | `AttendanceService` | Done | Bot answers with the reason | N/A | `AttendanceServiceTest`, `TelegramBotTest` | Rejected attempts listed | COMPLETE |
| One open session per student; concurrent check-in gives one session | Partial unique index, student row lock | Done | N/A | N/A | `AttendancePostgisTest` (two connections) | N/A | COMPLETE |
| Multiple sessions per day locked off (D3) | Policy service, request rule, PostgreSQL CHECK | Done | Disabled locked switch on the policy page | Admin only | `AttendanceServiceTest`, `AttendanceWebTest` | Policy page shows the locked switch | COMPLETE |
| Day status by one formula (D4) | `AttendanceDayQuery::STATUS_SQL` | Done | Same status in web, CSV and bot | Scoped student set | `AttendanceWebTest` (seven scenario students) | Dashboard, list, student page | COMPLETE |
| Policies: university default, group override | `AttendancePolicyService`, `AttendancePolicyController` | Done, audited | Policy page | Admin only; supervisor 403 | `AttendanceServiceTest`, `AttendanceWebTest` | Page at 390 px; supervisor 403 | COMPLETE |
| Stale sessions closed after the day | `attendance:close-stale` (every 15 min) | Done | INCOMPLETE shown | N/A | `AttendanceServiceTest` | INCOMPLETE tile and rows | COMPLETE |

## 4. Supervisor operations (Phase 5)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Dashboard with today's attendance for own scope | `DashboardService` | Done | Tiles link to filtered list | Own scope | `AttendanceWebTest` | Counts match own group only | COMPLETE |
| Day list, student days, sessions, evidence | `AttendanceController` | Done | `Attendance/Index`, `Attendance/Student` | Out-of-scope student 404 | `AttendanceWebTest` | 3 own students 200, 6 others 404 | COMPLETE |
| Groups, students, change requests | `SupervisorHomeController`, `StudentController` | Done | Pages | Own open periods only | `SupervisorScopeTest`, `Phase2AuthorizationTest` | Pages open, others 404 | COMPLETE |
| No corrections, policies or admin pages | Routes in the admin group | Done | Controls hidden | 403 on corrections, policies, supervisors, academic, audit, settings | `AttendanceWebTest`, `RoleAuthorizationTest` | Probed by URL: 403 | COMPLETE |

## 5. Admin attendance, reports, notifications (Phase 6)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| University-wide list with filters and search | `AttendanceController`, `AttendanceFilters` | Done; paginated by 25 | Filters, status tiles | Admin university only | `AttendanceWebTest` | Filters and search | COMPLETE |
| Manual correction and closing an open session | `AttendanceCorrectionService` | Done; new event, originals untouched, audited | Forms with required reason | Admin only, when policy allows | `AttendanceWebTest` | Closed a session with a reason; success flash; day became PRESENT | COMPLETE |
| Report summary for up to 366 days | `ReportController`, `AttendanceDayQuery::summary` | Done | Report page with totals | Own scope | `AttendanceWebTest` | Totals shown | COMPLETE |
| Queued CSV (summary and daily), owner-only download, formula guard | `AttendanceReportService`, `GenerateAttendanceReport` | Done; streamed in chunks of 200 | Export list with status and download | Owner only; 5/min | `AttendanceWebTest` | Daily CSV built by the Docker queue (35 rows) and downloaded | COMPLETE |
| Notifications for assignment changes and decisions | `StudentNotifier`, `SendTelegramNotification` | Done; after commit, idempotent key | Bot message | Blocked students skipped | `NotificationsAndOpsTest` | N/A | COMPLETE |

## 6. Production (Phase 7)

| Requirement | Implementation | Backend | Frontend / Telegram | Authorization | Tests | Browser | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Docker: FPM, nginx, PostGIS, Redis, queue, scheduler, health checks | `docker-compose.yml`, `docker/` | Built and running; all services up, health checks green | N/A | N/A | `/health` test | QA run against this stack | COMPLETE |
| HTTPS notes, trusted proxies, webhook, workers, backups | `docs/DEPLOYMENT.md`, `TRUSTED_PROXIES` | Done | N/A | Forwarded headers trusted only from configured proxies | `NotificationsAndOpsTest` | N/A | COMPLETE |
| First admin from the environment | `admin:ensure`, `BootstrapAdminService` | Done | N/A | Refuses empty login and short password | `NotificationsAndOpsTest` | Logged in as `Avazov` | COMPLETE |
| Secrets only in `.env` | `.env.example` keeps `TELEGRAM_*` and `ADMIN_*` empty; tests force them empty | Done | N/A | Token scrubbed from Telegram errors | Gap scan | N/A | COMPLETE |
| Migrations up, down, up; demo seed | 14 migrations, `DatabaseSeeder` | Verified | N/A | N/A | `MigrationIntegrityTest` | Demo data visible | COMPLETE |
| Backup and restore drill | `pg_dump -Fc` / `pg_restore` | Restored copy identical | N/A | N/A | Manual drill | N/A | COMPLETE |

## 7. Security acceptance

| Check | Evidence |
| --- | --- |
| IDOR and cross-supervisor access | `Phase2AuthorizationTest`, `SupervisorScopeTest`, `AttendanceWebTest`; browser probes: other supervisor's students 404 |
| Cross-university access | `UniversityScopeTest`, `AdminCompletionTest` |
| Inactive users, blocked students | `AuthenticationTest`, `StudentContextServiceTest`, `AttendanceServiceTest`, `NotificationsAndOpsTest` |
| Duplicate onboarding | `InviteOnboardingTest`, `ConcurrencyTest` (two connections), `TelegramBotTest` |
| Duplicate attendance, D3 | `AttendanceServiceTest`, `TelegramBotTest` (replayed `update_id` and location) |
| Concurrent assignment, concurrent check-in | `ConcurrencyTest`, `AttendancePostgisTest` |
| Webhook authentication | `TelegramBotTest` |
| Rate limits | Login, bot, join, attendance, webhook, change requests, exports: `AuthenticationTest`, `TelegramBotTest`, `ChangeRequestTest`, `AttendanceWebTest` |
| Audit integrity, immutable events | `AuditLogTest`, `AttendancePostgisTest` (triggers reject update and delete) |
| Client timestamp manipulation | `AttendanceServiceTest`: device time ignored, server time stored |
| Client coordinate manipulation | Coordinates in change-request payloads rejected (`ChangeRequestTest`); forwarded locations and venues refused (`TelegramBotTest`). A spoofed live location from a rooted phone cannot be detected and is not claimed (out of scope: fraud detection) |

## 8. Performance acceptance

See section 9 for the measured run. Day status, reports and CSV use one set-based query (`AttendanceDayQuery`). Lists paginate by 25 (admin, supervisor, student lists, attendance, reports, audit). Dashboard counts are aggregate SQL. Bulk assign runs in chunks of 100 per transaction. CSV exports run on the queue and stream 200 students per chunk. Notifications are queued. Spatial checks use the GiST index on `organizations.location`. Bounded-query tests guard N+1 regressions (`AssignmentTest`).

## 9. Measured run at 1,000 students

A scratch PostGIS database (dropped afterwards) with the normal demo seed plus 1,000 students onboarded through `StudentOnboardingService`, assigned through `InternshipAssignmentService` in chunks of 100, and 20 days of check-ins and check-outs written through `AttendanceService` (about 8 % absent, 5 % rejected first, 5 % never checked out, 12 % short days). Totals: 1,009 students, 37,868 events, 18,431 sessions. Timings are medians of 5 runs on the development machine (Windows host, PostgreSQL in Docker), measured as real Inertia requests through the HTTP kernel as ADMIN.

| Operation | Before | After |
| --- | --- | --- |
| Writing one check-in or check-out through the service | about 20 ms | unchanged |
| Today's status totals, whole university | 77 ms | 4.6 ms |
| `GET /dashboard` | 104 ms | 14 ms |
| `GET /attendance` (one day, 25 rows) | 121 ms | 30 ms |
| `GET /attendance/students/{id}` | 21 ms | 21 ms |
| `GET /academic/students` | 15 ms | 16 ms |
| `GET /reports` for 30 days | 6,258 ms | 262 ms |
| CSV summary, 30 days (1,005 rows) | 10,773 ms | 242 ms |
| CSV daily, 30 days (21,040 rows, flat memory) | 7,367 ms | 575 ms |

The first measurement found the 30-day report too slow. Two fixes, both covered by the existing formula tests: `AttendanceDayQuery` now aggregates sessions and events once per (student, day) and joins them instead of running ten correlated subqueries per row, and PostgreSQL JIT is turned off per connection because its compile time was about 0.5 s per report query (A80). Page queries stay at 11–19 per request. The 10,000-student target remains architectural (`ARCHITECTURE.md` §3.9); it was not measured.

## 10. Gates

| Command | Result |
| --- | --- |
| `php artisan migrate:fresh --seed` | OK; demo: 68 events, 31 sessions; staff `admin`, `rahbar`, `rahbar2`, `Avazov` |
| Rollback all, migrate, rollback, migrate, seed | OK |
| `composer test:pgsql` | OK, 287 tests, 1966 assertions |
| `php artisan test` | 269 passed, 18 skipped (PostgreSQL-only), 1895 assertions |
| `php artisan test --parallel` | OK |
| Live Bot API | `telegram:webhook --info` and `telegram:poll` connect to `@LOCInternshipBot`; `HttpTelegramClientTest` pins the real result shapes (`setWebhook`/`deleteWebhook` return `true`) |
| `npm run typecheck`, `npm run build` | OK |
| `vendor/bin/pint --test` | Passed |
| Gap scan (TODO, FIXME, `console.log`, `dd(`, `dump(`) | No findings in `app`, `resources/js`, `routes`, `config`, `database` |
| `php artisan route:list`, `config:clear`, `cache:clear` | OK, 76 routes |

## 11. Not covered by this check

- A live Telegram conversation on a real phone. The bot token is configured locally; run `php artisan telegram:poll` (local) or set the HTTPS webhook (production) and walk through the flow once by hand.
- Detection of spoofed GPS (out of scope by the spec).
