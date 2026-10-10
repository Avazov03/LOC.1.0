# Assumptions

**Status:** Phase 0 locked on 8 October 2026. D1–D6 are final.  
**Rule:** These are recorded so implementation does not invent a second, silent rule. Spec business rules stay in `SOURCE-OF-TRUTH-SPEC.md`. This file only records gaps.

Phase 1 foundation is implemented (session auth, academic tables, Tailwind staff screens). This document does not authorize Phase 2 work until Phase 1 is accepted.

D1–D6 above are final. Items A1 onward are **USE** readings. They are the least-conflict reading of a gap. They are not a second product.

---

## FINAL — D1 through D6

These are no longer open. Later phases follow them. They do not add a rule the source of truth forbids. They close a gap the source of truth left unnamed.

### D1. Academic history vs one group column
**Conflict:** §6 says academic state changes every year and history must survive. §73 stores one academic group on the student profile. §71 does not list an enrollment table.

**Final:** `student_group_memberships` stores student, group, academic year, invite, status, `joined_at`, and `ended_at`. `student_profiles.current_group_id` points at the active membership. One ACTIVE membership per student. Ending a membership does not delete it.

**Rejected:** Only `current_group_id`. That cannot satisfy §6.

### D2. Second assignment while one is still active
**Gap:** §23 allows one ACTIVE assignment. §24 refuses overlapping ACTIVE ranges. A future PENDING that does not overlap was not described.

**Final:** A student may have at most one assignment in `PENDING` or `ACTIVE`. A non-overlapping future assignment is refused until the current one is `ENDED` or `CANCELLED`. Historical `ENDED` and `CANCELLED` rows stay. The database expression is a partial unique index on `student_profile_id` where status is `PENDING` or `ACTIVE`. A range-exclusion constraint is unnecessary once that unique index exists.

### D3. `multiple_sessions_allowed = false`
**Conflict:** §41 says a second interval is not strictly forbidden and, if policy disallows it, status may be affected later. The contract allows multiple sessions when policy allows them and does not name the penalty when policy disallows them.

**Final:** The second check-in on the same university-local date is rejected. No second session is created. The student is told another session is not allowed today. No extra status penalty is invented.

**Rejected:** Accept the second session and mark the day with an undefined penalty.

### D4. Day status and duration (revised 2026-10)
**Gap:** §44 says OFF means the system records real attendance and does not name the day status. §43 says PRESENT means the policy requirement was met. The contract allows `PARTIAL`.

**Final (product decision, replaces the earlier minimum-duration rule):** universities do not want a required number of hours. Supervisors want to see how long the student actually worked.

- At least one COMPLETED session → `PRESENT`, and the summed duration is shown next to it
- Verified check-in whose session never checked out → `INCOMPLETE`
- No verified check-in, and at least one radius rejection that day → `LOCATION_REJECTED`
- Supervisor/admin mark `PRESENT` → `PRESENT`; mark `EXCUSED` (reason required) → `EXCUSED`
- Expected work day with nothing of the above → `ABSENT`
- Not a work day → no status, never `ABSENT`
- `PARTIAL` is not used. `SUSPICIOUS` is never assigned automatically

The `minimum_duration_minutes` policy column is kept for history and ignored. See `ATTENDANCE-RULES.md` §8–8c for work days, marks and supervisor notifications.

**Deferred:** a student "Boshqa joydaman" request from the bot, and a university holiday calendar.

### D5. May a supervisor create an assignment directly?
**Conflict:** §21 says an admin or an authorized supervisor may assign a student to an organization. §4.2 does not list assignment among the supervisor’s everyday powers and never names the flag that makes a supervisor “authorized”.

**Final:** A supervisor may create an assignment only for a student who already participates in an internship they currently supervise, and only to an ACTIVE organization in the same university. They cannot create an organization or set its location. Bulk assign uses the same limit. The narrower reading (admin-only initial assignment) is not used.

### D6. Web UI stack vs Zonic design DNA
**Conflict:** §85 and the implementation contract require one Laravel app with React, Inertia, TypeScript, and Tailwind. `docs/design/01-DIZAYN-DNK.md` and `03-PROMPTLAR.md` require Sneat Bootstrap 5 and vanilla JS, and they forbid React and Tailwind. The 8 October 2026 stack lock repeats React, TypeScript, Inertia, and Tailwind as the MVP frontend.

**Final:** Staff UI is React, TypeScript, Inertia, and Tailwind inside the Laravel app. The kit is a visual reference, not a second application: primary `#696cff`, Public Sans, a fixed vertical menu, cards, light and dark, Leaflet for the map. Do not ship the demo HTML catalog. Do not build a static admin beside Inertia. Do not switch staff auth to the kit JWT API. Laravel session stays. No public marketing site. UI copy stays Uzbek Latin. Russian and English kit dictionaries are not built.

Phase 1 aligned every staff screen to Tailwind 4. Sneat CSS and JS were removed from `public/`. Public Sans is self-hosted under `resources/fonts` (SIL OFL).

---

## USE — recorded readings

### A1. Repository started empty
The first inspection found an empty folder. Sibling folders under `SCP loyhalar` are not part of this product and their rules are not copied. By the Phase 0 lock the folder is a git repository on `main`, remote `https://github.com/Avazov03/LOC.1.0.git`, with the early foundation described in `ARCHITECTURE.md` section 1.

### A2. Laravel 13 is real and is the framework
Verified against Laravel 13 release notes: released 17 March 2026, PHP 8.3–8.5, security fixes until 17 March 2028. Docker will pin **PHP 8.4**, which sits inside that range.

### A3. One Laravel application, not a second frontend
The app is the `laravel/laravel` skeleton plus Inertia React, not the official starter-kit component library. Stack: Inertia Laravel 3, `@inertiajs/react` 3, React 19, TypeScript, Tailwind 4. No Next.js, no separate frontend repo, no microservices. Tailwind is the staff styling system (D6) and the only UI pipeline.

### A4. UI name “Internship Group” is the `internships` row
§15 and the admin menu say Internship Group. §71’s entity is `Internship`. One row is one cohort: one student group, one academic year, one inclusive period, and a supervisor history. It is not an academic group and not an organization.

### A5. Internship has no extra status enum
Open and close behavior comes from `period_start` / `period_end` and from invite status. Check-in outside the period is refused (§65). No grace period in MVP.

### A6. Period boundaries are inclusive local dates
Admin enters calendar dates. They are interpreted in the university timezone from `00:00:00.000` on `period_start` through `23:59:59.999` on `period_end`. MVP seed and the default university timezone are `Asia/Tashkent` (contract §7). The value lives on the `universities` row, not as a TDYU constant in code.

### A7. Timestamps are `timestamptz` in UTC
Business “today”, session date, and dashboards use the university timezone. Attendance `occurred_at` is database/server time. Phone time is ignored (§32).

### A8. Onboarding does not create an assignment
The invite creates the student, the group membership, and an internship participant row. Organization assignment is a later admin or authorized supervisor action (§140).

### A9. `internship_participants`
Not in the §71 minimum list, but without it a student cannot be tied to a cohort before an organization exists. Unique `(internship_id, student_profile_id)`.

### A10. One Telegram account, one student profile
`telegram_user_id` is globally unique. A second invite for the same Telegram user does not create another student (§11, §113). The bot says the account is already registered. Username is never an identity key. Phone is indexed and is not unique.

### A11. Institutional student number
Column name is `student_code` (nullable). Unique per university when present. It is not the primary key and not the bot identity.

### A12. Names
Onboarding asks for ism and familiya (§10). Columns: `first_name`, `last_name`. Full name shown in the UI is those two fields. No custom-field builder. “Additional university fields” in §10 are not an MVP form engine.

### A13. Students have a `users` row and no web panel
Role `STUDENT`, password null, no admin/supervisor routes. Telegram is the only student interface. Staff use session login.

### A14. One role per user
`ADMIN`, `SUPERVISOR`, or `STUDENT`. A person who needs two roles gets two accounts. Not specified either way; this is the smaller model.

### A15. Supervisor history is its own table
`internship_supervisor_periods` keeps §58. The open period (`ends_on` null) is who can see the cohort now. Replacing a supervisor closes the old period and opens a new one in one transaction. Past supervisors lose access. The relationship row remains.

`internship_assignments.supervisor_profile_id` is the supervisor at assignment time. It is not rewritten when the internship supervisor changes. Scope checks use the open supervisor period, so the new supervisor sees current students (§58).

### A16. Invite supervisor is a snapshot
The invite stores the supervisor selected when the link was created (§81). Onboarding still joins the internship. Live authorization uses the open supervisor period, not the frozen invite supervisor.

### A17. Invite token
32 random bytes, URL-safe, shown once. Database stores SHA-256 only (§81). Lookup hashes the presented token. Predictable `/join/403` URLs are not created.

### A18. Expired invites
If `expires_at` is in the past, onboarding is rejected even when the stored status is still `ACTIVE`. A scheduled command may flip the row to `EXPIRED`. `CLOSED` is manual and sets `closed_at`.

### A19. Organization contact stays on `organizations`
§16 and §74 put one contact on the organization. A second `organization_contacts` table would be a second source of truth. It is not created in MVP. `contact_position` is included because §4.4 stores name, phone, and position for the on-site contact. That person cannot log in.

### A20. Organization type is admin-entered text
The spec lists `type` and does not enumerate it. No hardcoded court/firm/ministry list.

### A21. Organizations are not deleted
Status becomes `INACTIVE`. New assignments to an inactive organization are refused (§134). History remains. FK is `ON DELETE RESTRICT`.

### A22. Radius
Integer meters, database `CHECK (radius_meters BETWEEN 100 AND 500)`, default 100. Boundary uses PostGIS `ST_DWithin` on `geography`, which is inclusive: 100 m against a 100 m radius passes (§130). The pass/fail decision uses `ST_DWithin`, not a rounded distance. Stored distance keeps three decimal places so display rounding cannot rewrite the decision.

### A23. Student cannot send coordinates as an organization location
New-organization request JSON may contain name, address text, contact, and reason. Latitude, longitude, and radius keys are rejected. Admin sets the point and radius (§27, §28).

### A24. Change-request shape
`request_type`: `EXISTING_ORGANIZATION` or `NEW_ORGANIZATION`. Status stays the §80 set: `PENDING`, `APPROVED`, `REJECTED`, `CANCELLED`.

- Existing organization: a supervisor of that student’s current internship may approve or reject.
- New organization: admin creates the organization, then the assignment. Approval is admin-only. Supervisor can see the request (§4.2) and cannot set the point.
- Student or the in-scope supervisor may open a request (§4.2, §25). `initiated_by` records who did.
- Student may cancel their own `PENDING` request. Cancel is not the same as reject.
- Approving an existing-organization request, ending the old assignment, creating the new one, and writing audit is one transaction (§102).
- A student with no current assignment who taps “change place” is told they have no place yet. That action does not create a request.
- At most one `PENDING` change request per student.

### A25. Assignment activation
Creating an assignment inserts `PENDING`, then in the same transaction moves it to `ACTIVE` when the period has started, the organization is `ACTIVE`, and D2 is satisfied. If `start_at` is still in the future, it stays `PENDING` and check-in is refused until a scheduler or an admin activates it at `start_at`. Check-in requires `ACTIVE` (§30).

### A26. Failed location attempts are events
§39 and §69 override the softer “may” in §38. Every rejected check-in or check-out attempt is an `attendance_events` row. It does not open or close a verified session.

### A27. Duplicate button press vs duplicate webhook
A replayed Telegram `update_id` creates nothing. A new update that is a second check-in while a session is open does not create a verified session (§62) and does not insert another attendance event. It is logged and the student is told they already have an open attendance. Same for check-out with no open session (§63): message only.

### A28. One-shot location, not live tracking
The bot requests a single location for the current action. If Telegram delivers a live-location stream, only the point tied to the pending action is used. Later edits are not stored as a trail (§108).

### A29. Accuracy threshold default is unset
`accuracy_threshold_meters` null means only the hard cap applies: accuracy worse than 300 m (`AttendanceService::MAX_ACCURACY_METERS`) is always refused, and a policy value above 300 cannot loosen it. No 50 m default (contract §13). When set, accuracy worse than the threshold rejects the attempt with `LOW_ACCURACY` and the bot asks the student to send location again. The failed attempt is stored (A26).

### A30. Session versus day status
Session status is `OPEN`, `COMPLETED`, or `INCOMPLETE`. Day status is computed, not stored, and is not forced into one session row (contract §10).

While a session is `OPEN`, the supervisor day cell shows `INCOMPLETE` (§51, §118). A scheduler after local midnight sets yesterday’s still-`OPEN` sessions to `INCOMPLETE` and does not invent `closed_at`.

Day precedence:

1. Any `OPEN` session that local date → display `INCOMPLETE`.
2. No `COMPLETED` session, and an `INCOMPLETE` session exists → `INCOMPLETE`.
3. No verified check-in, and a radius rejection exists that day → `LOCATION_REJECTED`.
4. No verified check-in → `ABSENT`.
5. Otherwise apply D4 to the sum of `COMPLETED` durations.

An incomplete session contributes no duration.

### A31. `SUSPICIOUS` is reserved and unused
The status exists in the enum because §43 names it. MVP does not classify it (§68, §111). No fraud-detection job.

### A32. Policy resolution is whole-record
MVP scopes are `UNIVERSITY` and `GROUP` only (§47, contract §30). Program and internship scopes are not implemented. If an active group policy exists, that row is used as a whole. `minimum_duration_minutes` is no longer used (D4, revised).

Defaults for a newly created university policy, chosen because they match the spec rather than add a new rule:

| Field | Default | Why |
| --- | --- | --- |
| check_in_enabled | true | Attendance is the product |
| check_out_enabled | true | Sessions need checkout |
| minimum_duration_minutes | null | Ignored since D4 was revised; no required hours |
| multiple_sessions_allowed | true | §41 does not forbid them |
| location_required | true | §67; turning it off is an explicit admin act |
| accuracy_threshold_meters | null | A29 |
| manual_correction_allowed | true | §48; admin can later disable per group |

`late_threshold` and notification-settings are named in §46 as later policy knobs. They are not columns in MVP (contract §30).

### A33. Manual correction is admin-only
Supervisors do not correct attendance. If the resolved policy has `manual_correction_allowed = false`, admin correction for that group is refused. Correction inserts a new event with `source = MANUAL`. It does not update or delete the original (§48, §49).

### A34. Immutable events and audit
`attendance_events` and `audit_logs` reject `UPDATE` and `DELETE` with a database trigger. Sessions may be updated from `OPEN` to `COMPLETED` or `INCOMPLETE` because they are derived state, not the raw fact.

### A35. Language
Bot copy and the admin/supervisor UI are Uzbek in Latin script, matching the spec’s user-facing sentences. Code, schema, and docs identifiers are English. No i18n framework in MVP. Where the spec gives the sentence, that sentence is used.

### A36. Reports in MVP
Admin and supervisor menus include Reports. The spec does not define a report catalog. MVP report is the filtered attendance list already required by dashboards, plus a queued CSV export of the caller’s scope. No BI module.

### A37. Settings
No general settings junk drawer. University timezone is visible. Telegram connectivity is an operational health check, not a product setting. Policies live under Attendance → Policies.

### A38. Notifications in MVP
Queued Telegram messages for: change-request decided, assignment changed. No reminder campaign (§54). No notification preference center.

### A39. Rate limits are operational, not business rules
Redis limits, adjustable without a product change:

- webhook: 120 requests / minute / IP
- any student bot action: 10 / minute / telegram user
- check-in and check-out: 6 / minute / student

§61 requires limiting. It does not set the numbers.

### A40. Web surface is Inertia, not a public REST API
Admin and supervisor use Inertia routes. The only external HTTP endpoint is the Telegram webhook. Web and bot call the same application services. A second attendance implementation is not allowed (contract §43).

### A41. Seed data is fictional
Development seed uses a demo university and demo people. It includes the contract §49 scenarios (one student one organization, many students one organization, one group many organizations). Real TDYU names, structures, and tokens are not committed.

### A42. Staff login
Staff sign in with `login` and password. Email is contact data, unique when present, and is not the credential. Laravel session auth, hashed passwords. `users.status` must be `ACTIVE`. No SSO and no HEMIS login. Students have no web password and no web home (A13).

### A43. Map
Leaflet with OpenStreetMap-compatible tiles. Admin sets marker and sees the radius circle. The browser never decides verification.

### A44. Backup
Phase 7 documents a daily `pg_dump` of PostgreSQL, 30-day retention, and a tested restore. Location and attendance are in that backup. Phase 0 only records the requirement (contract §58).

### A45. Scale targets, not a measured claim
Three targets, one modular monolith. None of them is a load-test result.

| Target | Meaning |
| --- | --- |
| Initial operation | 1,000+ students in one university (§129) |
| Architecture | 10,000+ students without a second service or a second database |
| Future | More universities and larger populations, using `university_id` scope already on the domain |

Phase 7 measures the 1,000-student path. Phase 0 does not claim that measurement has been run. The means are indexes, pagination, bulk chunks, Redis queues, and spatial indexes (`ARCHITECTURE.md` sections 3.9 and 3.10). Microservices, Kafka, and Kubernetes stay out.

### A46. Tests
PHPUnit 12, which is what this app ships. Feature tests hit PostgreSQL + PostGIS, not SQLite, whenever geography or a partial unique that sqlite cannot express matters. `phpunit.xml` runs on sqlite memory for speed; `phpunit.pgsql.xml` (`composer test:pgsql`) runs the same suite on PostGIS and Redis and is the gate.

### A47. Git
The repository is initialized. Branch `main` tracks `origin` at `https://github.com/Avazov03/LOC.1.0.git`. Commits and pushes happen only when asked. `.env` stays untracked.

### A48. Blocked and inactive students
`BLOCKED` and `INACTIVE` students cannot check in, check out, or complete a new onboarding. The existing profile remains. Admin can correct identity fields; that correction is audited (§12, §22).

### A49. Turning location off is an audited admin override
`location_required` exists because the contract lists it. Default is true. If an admin sets it false, check-in skips the geofence and still stores an event. The student path does not have a way to skip location by itself (§67).

## USE — Phase 2 readings

### A50. Supervisor periods are half-open and change "today"
`internship_supervisor_periods` covers `[starts_on, ends_on)`. Replacing a supervisor sets the open period's `ends_on` to the university's today and opens the next period from the same date, so the two never overlap (PostgreSQL `EXCLUDE` on `daterange(..., '[)')`). A same-day replacement leaves an empty range in history. Scope uses only the open period (`ends_on IS NULL`): the replaced supervisor loses access at once. Assignments keep their own `supervisor_profile_id` snapshot.

### A51. Stored instants are UTC
`config('app.timezone')` is `UTC` and the PostgreSQL session timezone is pinned to `UTC`. Eloquent writes timestamps without an offset, so any other app timezone shifts every stored instant on `timestamptz`. University "today", day edges, and every displayed time use `universities.timezone` (A-series day rules unchanged). Assignment form dates are local: start is 00:00:00 and end is 23:59:59 of the chosen dates in the university timezone.

### A52. Assignment needs internship participation, for admin too
An assignment row names an internship. The student must be a participant of that internship (A9), whoever creates it. D5 narrows supervisors further to internships with their open period.

### A53. Change request needs a current ACTIVE assignment
Opening a request requires an ACTIVE assignment, stored as `current_assignment_id`. Approval ends it now and creates the replacement from now to the old planned `end_at`. If that `end_at` has already passed, approval is refused; the admin creates a fresh assignment instead.

### A54. Cancelling an ACTIVE assignment is admin-only and needs a reason
`ACTIVE → CANCELLED` exists for a placement that should never have been in force. It is admin-only, requires a reason, and is audited. Normal closure is `ACTIVE → ENDED`.

### A55. Audit rows carry `university_id`
`audit_logs.university_id` is not in the original ERD. It lets the audit page and later reports scope by university without joining every entity type. Scheduler-written rows have a null actor and take the university from the entity.

### A56. Approved new-organization request points at the created row
On admin approval of a `NEW_ORGANIZATION` request, `requested_organization_id` is set to the organization created in the same transaction. `requested_organization_data` stays as the student's original text.

### A57. Scheduler commands exist before Telegram
`invites:expire` and `assignments:activate-due` run every five minutes without overlap. Onboarding treats an expired-but-ACTIVE invite as expired (A18), so the command only tidies state.

### A58. Onboarding service is channel-agnostic
`StudentOnboardingService` (context, join) exists in Phase 2 with tests. Phase 3 calls it from the Telegram adapter. It never creates an assignment (A8).

### A59. Public bot username for invite links
`TELEGRAM_BOT_USERNAME` (public, not a secret) builds `https://t.me/<bot>?start=<token>`. Empty means the admin sees the raw token once. Token and webhook secret stay empty in `.env.example`.

### A60. Date corrections do not cascade
Editing internship dates changes only `internships.period_start/period_end`; existing assignment dates are not shifted. An assignment is corrected on its own (A62). Group and academic year of an internship never change.

### A61. Change requests are rate-limited per signed-in user
§61 asks for a limit without a number. Opening change requests is limited to 10 per minute per user (`throttle:change-requests`). The one-pending-request rule (partial unique index) stays the real guard.

### A62. Assignment date correction
Admin only. PENDING: start and end editable; if the new start is today or earlier and the organization is ACTIVE, the row becomes ACTIVE. ACTIVE: only the planned end, and it must stay in the future (closing now is "Yakunlash"). ENDED and CANCELLED rows are history and are refused. Organization and student never change through this path. Audited as `assignment.update`.

### A63. Student status does not touch assignments
Admin may set ACTIVE, INACTIVE or BLOCKED with a required reason (`student.status_change`). Assignments and memberships are left as they are; A48 blocks a non-ACTIVE student at the student channel. The Telegram user id is never editable from the web; identity corrections (name, phone, student code) are audited as `student.update`.

### A64. Course and group parents are fixed
A course keeps its program and academic year; a group keeps its course. Only number/name (course) and name/code (group) are editable, because memberships and internships point at these rows.

### A65. University settings
Admin may change the university name and timezone (`university.update`). Stored instants are UTC (A51), so nothing is rewritten; only display and the reading of new local dates follow the new timezone.

### A66. Supervisor last sign-in comes from the audit log
There is no `last_login_at` column. The supervisor detail page reads the latest `auth.login` audit row.

### A67. Student read contract for Phase 3
`StudentContextService` answers by Telegram user id only: unknown, INACTIVE, BLOCKED students and students whose user account is not ACTIVE all get the same "access denied". `activeAssignment()` counts only ACTIVE (not PENDING) and otherwise raises "Sizda hozir faol amaliyot biriktirilmagan." (§64). No organization coordinates or radius leave it.

## Phases 3–7 readings

### A68. System adjustments are not location checks
Events written by the system or by a manual correction (`SYSTEM_ADJUSTMENT`, `MANUAL_*`) carry verification `NOT_APPLICABLE`. They never claim a location was verified.

### A69. Multiple sessions per day are locked off
`multiple_sessions_allowed` defaults to false and cannot be turned on: the policy request rule is `declined`, `AttendancePolicyService::normalize()` refuses a true value, PostgreSQL has `CHECK attendance_policies_single_session (multiple_sessions_allowed = false)`, and the policy screen shows the switch disabled. A second check-in on a day that already has a session is refused (D3).

### A70. Expected days
A day is expected when an ACTIVE or ENDED assignment covers it and its weekday is in the effective work-day mask (student override, else the internship's mask; default every day). Only an expected day with nothing recorded is ABSENT. There is no holiday calendar yet (deferred).

### A71. Check-out disabled
When the resolved policy turns check-out off, a verified check-in creates a COMPLETED session of 0 seconds, and the day is PRESENT.

### A72. Missing device accuracy (revised 2026-10)
A location sent with the phone's GPS always carries `horizontal_accuracy`; a point picked on the map in Telegram's attachment screen does not. A location without accuracy that is not a live location is refused as `MAP_LOCATION` and stored as a failed event; the bot asks for the "📍 Joylashuvni yuborish" button with GPS on. A live location without accuracy is accepted.

### A73. Rate limits
Bot actions 20 per minute per Telegram user; attendance actions 6 per minute per student; invite joins 5 per minute per Telegram user; wrong webhook secret 120 per minute per IP; report exports 5 per minute per user; change requests 10 per minute per user (A61). The limits only stop floods; the database constraints stay the real guards.

### A74. Forwarded locations and venues
A forwarded location or a venue is not a live share from the student. It is stored as an `INVALID_LOCATION` failed event with metadata `forwarded` and never opens a session.

### A75. Manual correction times
For a manual correction, `occurred_at` is the corrected attendance time chosen by the admin; `created_at` is when the row was written. Originals stay untouched and the correction is audited.

### A76. Join confirmation and list buttons
Onboarding asks for confirmation in a `JOIN_CONFIRM` state before writing the student. Organization buttons in the change-request dialog carry the list index, not the database id; a stale or foreign index is answered with "list changed" and nothing is written.

### A77. Stale open sessions
`attendance:close-stale` (every 15 minutes) closes sessions left OPEN after the local day ends; the day stays INCOMPLETE. A check-in on the next day closes yesterday's open session the same way first.

### A78. Report range and filters
Day reports and exports cover at most 366 days (`AttendanceDayQuery::MAX_RANGE_DAYS`), so a full-year internship fits one range. Export filters are read from the POST body. Day status has one SQL formula (`AttendanceDayQuery::STATUS_SQL`) used by dashboards, reports, CSV and the bot.

### A79. Notifications
Student notifications (assignment changes, change-request decisions) are queued after commit and are idempotent by a unique key, so a rolled-back transaction never notifies and a retry never sends twice. Blocked students are skipped; failures are recorded.

### A80. Operations
`GET /health` checks the database and the cache and is used by the nginx health check. PostgreSQL JIT is turned off per application connection (`SET jit = off`): measured at 1,000 students it added about 0.5 s of compile time to each report query and saved nothing. Forwarded headers are trusted only from `TRUSTED_PROXIES` (empty trusts none). The redis queue `retry_after` is 660 seconds, above the worker `--timeout=620`. The PostGIS migration's `down()` keeps the extension when other PostGIS extensions depend on it. The 100 m boundary test uses 99.9999 m because projecting a point exactly 100 m away round-trips to a hair above 100 m in floating point; the `ST_DWithin` decision itself is unchanged (ATTENDANCE-RULES §5). The database is dumped daily by `scripts/backup.sh` with 14-day retention (DEPLOYMENT §7).

### A81. Late location messages
Telegram stamps each message with its own server time (`date`). A location whose message is more than 180 s older than server "now" (`AttendanceService::MAX_MESSAGE_AGE_SECONDS`) is refused as `STALE_LOCATION`; the student presses the button again. This also covers a location delivered late after an outage.

### A82. Reused coordinates
Two real GPS fixes practically never match to 7 decimals (about 1 cm). The same point already stored for another student, or for this student on another local date, is a saved or shared location: `REUSED_LOCATION`, failed event with `reused_event_id`. The same point again on the same day for the same student (check-out copied from check-in, a cached fix) is accepted, flagged with `repeated_coordinates_event_id`, shown on the student's attendance page and added as a warning to the supervisor's Telegram notification. This does not stop a student who is physically elsewhere with a GPS spoofing app; that needs live-location checks (deferred).

### A83. Student phone and duplicate accounts
Onboarding accepts the phone only from Telegram's "share my number" button whose `contact.user_id` equals the sender; a typed number or someone else's contact is refused. If a student of any status with the same phone (compared by digits) already exists in the same university, onboarding is refused (`PHONE_REGISTERED`) at the phone step and again inside the join transaction, and the student is told to ask the supervisor for a Telegram rebind link.

### A84. Student Telegram rebind
An admin or the student's current supervisor creates a one-time link `/start r_<token>` (24 h, only the SHA-256 hash is stored, shown once). Opening it from a new Telegram account moves the existing profile to that account: history, group, participation and assignment stay; the old account loses access and receives a notice. A Telegram account that already belongs to another student cannot take the link; a non-ACTIVE student cannot get or use one. Audited as `student.telegram_rebind_link` (staff) and `student.telegram_rebind` (old and new ids).

### A85. Self-recovery by a verified phone
`student_profiles.phone_verified_at` is set when the number came from the "share my number" button (onboarding, recovery, confirmation in «👤 Profilim» or right after a rebind) and cleared when staff edit the phone. Telegram allows one account per number, so sharing one's own contact proves the number. A Telegram account that is not linked to any student and shares its own contact (after `/start` without an invite, or at the phone step of a new invite) takes over the profile when exactly one ACTIVE student of any university has that verified number: no new profile, the old account gets a notice, the current supervisor gets a Telegram message, audited as `student.telegram_recover` (`method: verified_phone`). Unverified (legacy) numbers, blocked students and someone else's contact never recover; those students use the rebind link (A84). A linked student who shares a new own number updates the phone (`student.phone_change`) unless another student of the university already has it. There is no separate "change phone" feature.

### A86. Staff two-factor sign-in
Optional TOTP (RFC 6238, 6 digits, 30 s, ±1 step) for admins and supervisors, turned on in Profile with the current password and a confirming code. The secret and the bcrypt hashes of 8 one-time recovery codes are stored encrypted on `users`. After a correct password the user is held in the session for 300 s until the code is given (5 wrong codes end the attempt); a code is refused a second time within its step. An admin can turn it off for a supervisor (`user.two_factor_reset`); a locked-out admin runs `php artisan user:two-factor-reset <login>` on the server. An impersonating admin cannot change the supervisor's second factor.

### A87. University holidays
Admins list days off in Settings. On such a date the bot refuses check-in (`HOLIDAY`, not a failed attempt), the day is not expected, so nobody is ABSENT and the evening digest is skipped. Anything recorded before the holiday was added keeps its status (PRESENT etc.). Audited as `holiday.create` / `holiday.delete`.

### A88. Sessions after a password change
`AuthenticateSession` is on for the web group: a password change by the user, by an admin or by the server command signs out every other session of that user on its next request. The session that made the change stays signed in.

### A89. Browser security headers
The web panel sends a Content-Security-Policy (scripts only from the origin or with the per-request nonce; map tiles from `*.tile.openstreetmap.org`), Permissions-Policy, Cross-Origin-Opener-Policy and, over HTTPS, HSTS for one year. The CSP is skipped while the Vite dev server runs.
