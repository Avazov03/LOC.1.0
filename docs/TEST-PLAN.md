# Test plan

A feature is not done because a page renders. The tests below are the acceptance list from the spec and the contract. Geography, exclusion constraints, and webhook tests run against PostgreSQL with PostGIS. SQLite is not a substitute for those tests.

PHPUnit 12. Two configurations run the same suite:

- `php artisan test` uses `phpunit.xml`: sqlite `:memory:`, array cache. Fast. PostgreSQL-only tests skip themselves.
- `composer test:pgsql` uses `phpunit.pgsql.xml`: PostGIS database `internship_test` on port 5434 and Redis DB 2/3 on port 16379. Needs `docker compose up -d postgres redis`. This run is the gate.

Phase 1 foundation tests: `AuthenticationTest`, `RoleAuthorizationTest`, `UniversityScopeTest`, `SupervisorScopeTest`, `MigrationIntegrityTest`, `InfrastructureTest`, `AcademicStructureTest`.

Phase 2 tests: `Phase2AuthorizationTest` (role matrix, university 404, supervisor scope 404, no coordinates on supervisor pages), `AssignmentTest` (D2, D5, bulk mixed results, inactive organization, transitions, scheduler activation, bounded queries), `InviteOnboardingTest` (hash only, closed/expired refusal, duplicate Telegram id, closing keeps students), `ChangeRequestTest` (one pending, no coordinates, atomic approve, forced-failure rollback, admin-only new organization), `OrganizationTest` (audit split, validation, geography type, GiST, `ST_DWithin` 99/100/101), `InternshipManagementTest` (supervisors, replacement history and access, exclusion constraint), `AuditLogTest` (append-only trigger, university scope, UTC instants), `ConcurrencyTest` (two PostgreSQL sessions: open-assignment race, student row lock, trigger after a concurrent deactivation). Shared fixtures live in `tests/Concerns/BuildsInternships.php`.

Phase 3–7 tests:

- `TelegramBotTest` (27): webhook secret 404/403, `update_id` replay, group chats and edited messages ignored, onboarding with `JOIN_CONFIRM`, student-code clash, invalid/closed/expired invites, invite closed mid-dialog, unknown and blocked users, menus, per-user and join and attendance rate limits, check-in/out through the bot, forwarded location and venue, text while a location is expected, change requests with stale list buttons, a failing send does not stop processing. Shared helpers: `tests/Concerns/TalksToBot.php` with `FakeTelegramClient`.
- `AttendanceServiceTest` (21): evidence snapshots, server time over device time, outside radius, low and missing accuracy, invalid coordinates, no assignment, outside the period, inactive organization, blocked student, D3 duplicate, failed check-out keeps the session OPEN, check-out/check-in disabled, location override audited, stale-session closing, event immutability, one open session, multiple sessions refused, group policy replaces the university policy.
- `StudentIdentityTest` (8): contact-only phone, duplicate phone refused at the phone step and at confirm, other universities unaffected, Telegram rebind by supervisor and admin with history kept, single use, expiry, scope and status checks.
- Location anti-spoofing in `AttendanceServiceTest`: map-picked point, live location without accuracy, late message, accuracy hard cap, reused point from another student or another day, same-day repeat flagged. Tests shift each sent point by 1e-7° (`tests/Concerns/FreshCoordinates.php`) because real fixes never repeat exactly.
- `AttendancePostgisTest` (PostgreSQL only): `ST_DWithin` at 99 m, 99.9999 m and 101 m with points projected by `ST_Project`; two concurrent check-ins produce one session (lock timeout `55P03`, unique `23505`); immutability trigger.
- `AttendanceWebTest` (14): the D4 formula on seven scenario students, totals, minimum off and group policy, 62-day cap, admin list and filters, evidence page, supervisor scope 404 and admin-only 403, corrections and close-session with audit and untouched originals, report and scoped CSV with formula-injection guard and owner-only download, export rate limit 429, dashboard tiles.
- `NotificationsAndOpsTest` (10): assignment notification once after commit, none after rollback, change-request decision notification, blocked students skipped, failures recorded, `/health`, forwarded HTTPS trusted only from `TRUSTED_PROXIES`, `admin:ensure`, `telegram:webhook` HTTPS and secret checks, schedule list.

Latest run: `composer test:pgsql` 285 tests, 1957 assertions, OK. `php artisan test` 267 passed, 18 skipped (PostgreSQL-only), 1886 assertions; the same with `--parallel`. The 1,000-student measurement is recorded in `FINAL-ACCEPTANCE-MATRIX.md` §9.

## 1. Unit

| Case | Expect |
| --- | --- |
| Distance 99 m, radius 100 m | pass |
| Distance 100 m, radius 100 m | pass |
| Distance 101 m, radius 100 m | fail |
| Radius 99 and 501 | rejected by validation and by the check constraint |
| Minimum duration off | day status PRESENT when a completed session exists (D4) |
| Any completed session, even 2 h (old 4 h minimum set) | PRESENT, duration shown |
| Non-work day (odd/even/custom mask, student override) | Check-in refused, day not ABSENT |
| Supervisor "Keldi" / "Sababli" mark, revoke | PRESENT / EXCUSED, audited; 7-day window, no future |
| Telegram digest button after reminder time | Day marked PRESENT once; stale or foreign list refused |
| No verified check-in | ABSENT |
| No verified check-in, radius rejection exists | LOCATION_REJECTED |
| Open session | day display INCOMPLETE |
| 09:00–11:00 and 12:00–14:00 | 4 h total |
| Policy group row present | group row wins as a whole |
| Policy group row absent | university row is used |
| Accuracy null threshold | accuracy up to 300 m is stored and not rejected |
| Policy threshold above 300 m | 301 m still LOW_ACCURACY (hard cap) |
| No accuracy, not live (map pick) | MAP_LOCATION, failed event, no session |
| No accuracy, live location | accepted |
| Message `date` older than 180 s | STALE_LOCATION |
| Exact point of another student / own point from another day | REUSED_LOCATION with `reused_event_id` |
| Exact point again the same day | accepted, flagged `repeated_coordinates_event_id` |
| Accuracy worse than a set threshold | LOW_ACCURACY |
| Assignment overlap | refused |
| Change request second approve | refused |
| SUSPICIOUS calculator | never returns SUSPICIOUS |

Boundary tests must build real geography points and call PostGIS, not a PHP haversine copy. A PHP distance helper may exist only as a non-authoritative display aid and must not be what the test treats as the decision.

## 2. Feature

| Flow | Expect |
| --- | --- |
| Admin creates faculty → program → year → course → group | rows linked as in the ERD |
| Supervisor login | admin menu routes return 403 |
| Admin creates supervisor, internship, invite | token hash stored, raw token not in the database |
| Closed invite onboarding | no new student |
| Expired invite onboarding | no new student |
| Same Telegram user joins twice | one student |
| Assign one student | bot-facing read model shows that organization |
| Bulk assign four students | four assignment rows |
| Assign to inactive organization | refused |
| Second active assignment | refused, first remains |
| Approve existing-organization change | old ENDED, new ACTIVE, request APPROVED, one transaction. Forced failure leaves the old assignment ACTIVE |
| New organization request | no organization row until admin submits the real location |
| Student payload with latitude | rejected |
| Check-in 50 m vs 100 m radius | CHECK_IN VERIFIED and an OPEN session |
| Check-in 1 km | FAILED event, no session |
| Check-in then check-out | duration matches server timestamps |
| Check-out outside radius | check-in remains, session stays OPEN |
| Check-out with no session | no event that completes a session |
| Missing location | no verified event |
| Organization move after an event | old snapshot unchanged, new event uses the new point |
| Radius 100 then 300 | old event snapshot 100, new event snapshot 300 |
| Admin correction | new event, reason, actor, original state still readable, audit row |
| Supervisor A opens B’s student | 404 and no student fields in the body |
| Student scope | no route returns another student’s attendance |
| Inactive supervisor | cannot log in and is absent from the invite picker |
| Admin student directory | search (name, phone, code), group, internship, placement, status filters; detail with academic path, internship, supervisor, assignments (`StudentManagementTest`) |
| Student identity correction / status | audited `student.update` / `student.status_change`; Telegram id unchanged; duplicate code refused; reason required; assignment untouched |
| Supervisor `/my-students` | only open-period participants; filters cannot widen; replaced supervisor sees nothing |
| University settings, course/group edit | audited `university.update`; foreign rows 404; parents fixed (`AdminCompletionTest`) |
| Supervisor create/update/status, detail | `supervisor.create/update/status_change`; periods, open students, history |
| Organization detail and status | `organization.status_change`; existing assignments kept; foreign 404 |
| Internship dates, assignment dates | `internship.update`, `assignment.update`; PENDING start+end, ACTIVE end only, history frozen |
| Dashboards | admin counts own university only; supervisor counts scoped students only |
| Student read contract | `StudentContextServiceTest`: denial for unknown/INACTIVE/BLOCKED, PENDING is not active, §64 message, no coordinates |
| Change request rate limit | 11th request in a minute is 429 |

## 3. Telegram integration

| Case | Expect |
| --- | --- |
| Wrong secret header | rejected, no write |
| Valid update | one student-facing result |
| Same update id delivered twice | one event |
| Unknown telegram user check-in | access denied, no event |
| Location message | reaches AttendanceService |
| Non-location message while a location is required | no verified event |
| Menu command during onboarding | no partial student row |
| Typed phone or someone else's contact | refused; only own contact button accepted |
| Phone already registered in the university (any format) | PHONE_REGISTERED at the phone step and at confirm; other universities not affected |
| Rebind link from supervisor/admin | new account takes the same profile, history and open session; old account loses access and is told; link works once, expires in 24 h; another student's account and a blocked student refused; foreign scope 404 |
| Self-recovery by verified phone (`StudentIdentityTest`) | own contact from a new account moves the profile, old account told, supervisor message, audit; unverified number, blocked student or someone else's contact refused; JOIN_PHONE recovers; staff phone edit clears verification; profile confirm/update and "taken" refusal |
| Evening digest with several groups | sections "👥 group (n)", numbering and buttons in the same order, button marks the right student |
| Holiday | check-in refused as HOLIDAY without an event, day not expected, digest skipped; removing it restores ABSENT; supervisor 403 |

## 4. Concurrency

Two check-in transactions for one student at the same time: exactly one OPEN session. The test should use overlapping transactions or a lock, not two sequential calls.

Two assignment activations for one student: one ACTIVE.

Implemented on PostgreSQL with two sessions (`ConcurrencyTest`): simultaneous open assignments, student row lock, organization closed mid-flight, duplicate onboarding of one Telegram user, simultaneous approval of one change request, concurrent supervisor replacement (one open period).

## 5. Security

IDOR cases in section 2. Role bypass on each admin route. Manual correction as supervisor: forbidden. Webhook without secret: forbidden. Audit row contains actor and not the bot token.

`TwoFactorTest`: RFC 6238 vector; enabling needs the password and a valid code, secret encrypted at rest; login stops at the challenge, wrong code refused, the same code cannot be used twice, recovery code works once, the pending login expires after 300 s and after 5 wrong codes; disable needs the password; admin and `user:two-factor-reset` turn it off; an impersonating admin cannot. `AuthenticationTest`: a password changed elsewhere signs out other sessions while the changing session survives; CSP with a nonce on the inline script, Permissions-Policy, HSTS only over HTTPS.

## 6. Seed scenarios used by tests and local demo

- One university, two faculties, two programs, one academic year, more than one course, more than one group.
- More than one supervisor and more than one organization.
- One student at one organization.
- Several students at the same organization.
- One group whose students are at different organizations.
- At least one verified session and one rejected attempt.

Seed names are fictional.

## 7. Not claimed in MVP tests

- Load test of 1,000 students until Phase 7. Phase 4 and 6 only check that the queries are indexed and paginated.
- Fake-GPS defeat. Tests assert the signals are stored, not that spoofing is detected.
- Offline replay. No attendance row appears without a request.
- HEMIS, QR, continuous tracking: no tests because the features are absent. A test may assert the routes do not exist.
