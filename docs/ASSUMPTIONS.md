# Assumptions

**Status:** Phase 0. Business code has not been written.  
**Rule:** These are recorded so implementation does not invent a second, silent rule. Spec business rules stay in `SOURCE-OF-TRUTH-SPEC.md`. This file only records gaps.

Each item is one of:

- **USE** — least-conflict reading. Implementation will follow it unless you reject it before the phase that needs it.
- **DECISION** — two readings change user-visible behavior. Phase that needs it does not start until you choose.

---

## DECISION — answer these before the listed phase

### D1. Academic history vs one group column
**Needed before:** Phase 1 schema.  
**Conflict:** §6 says academic state changes every year and history must survive. §73 stores one academic group on the student profile. §71 does not list an enrollment table.

**USE unless you reject it:** Add `student_group_memberships` (student, group, academic year, invite, status, joined_at, ended_at). `student_profiles.current_group_id` points at the active membership. One ACTIVE membership per student. Ending a membership does not delete it.

**Rejected alternative:** Only `current_group_id`, which cannot satisfy §6.

### D2. Second assignment while one is still active
**Needed before:** Phase 2.  
**Spec:** §23 one ACTIVE assignment at a time. §24 overlapping ACTIVE ranges are refused. The sequence is end the old one, then start the new one. A future PENDING that does not overlap is not described.

**USE unless you reject it:** A student may have at most one assignment in `PENDING` or `ACTIVE`. A non-overlapping future assignment is also refused until the current one is `ENDED` or `CANCELLED`. Historical `ENDED` rows stay.

### D3. `multiple_sessions_allowed = false`
**Needed before:** Phase 4.  
**Conflict:** §41 says a second interval is not strictly forbidden and, if policy disallows it, status may be affected later. The contract says multiple sessions exist when policy allows them. It does not define the penalty when policy disallows them.

**USE unless you reject it:** The second check-in on the same Asia/Tashkent date is rejected. No second session is created. Message tells the student that another session is not allowed today. No invented status penalty formula.

**Rejected alternative:** Accept the second session and mark the day with an undefined penalty.

### D4. Day status when minimum duration is OFF
**Needed before:** Phase 4.  
**Spec:** §44 says OFF means the system records real attendance. It does not name the day status. §43 says PRESENT means the policy requirement was met.

**USE unless you reject it:**

- OFF, or duration greater than or equal to the configured minimum, and at least one COMPLETED session → `PRESENT`
- Completed time above 0 but under the minimum → `PARTIAL`
- Verified check-in whose session never checked out → `INCOMPLETE`
- No verified check-in, and at least one radius rejection that day → `LOCATION_REJECTED`
- No verified check-in and no rejection → `ABSENT`
- `SUSPICIOUS` is never assigned automatically

A one-minute completed session with minimum duration OFF is `PRESENT`, because no minimum was configured.

### D5. May a supervisor create an assignment directly?
**Needed before:** Phase 2.  
**Conflict:** §21 says an admin or an authorized supervisor may assign a student to an organization. §4.2 does not list assignment among the supervisor’s normal powers. It says the supervisor starts an organization-change workflow only when that authority was given. The spec never names the flag that makes a supervisor “authorized”.

**USE unless you reject it:** A supervisor may create an assignment only for a student who already participates in an internship they currently supervise, and only to an ACTIVE organization. They still cannot create an organization or set its location. Bulk assign uses the same limit.

**Narrower alternative:** Supervisors never create assignments. They only approve existing-organization change requests. Admins do every initial assignment. Say so before Phase 2 if you want this narrower reading.

### D6. Admin UI stack vs Zonic design DNA
**Needed before:** the first admin screen. Laravel, database, and auth in Phase 1 can start either way.  
**Conflict:** `SOURCE-OF-TRUTH-SPEC.md` §85 and the implementation contract require one Laravel app with React, Inertia, TypeScript, and Tailwind. `docs/design/01-DIZAYN-DNK.md` and `03-PROMPTLAR.md` require Sneat Bootstrap 5, vanilla JS modules, Boxicons, and Public Sans, and they forbid adding React, Vue, or Tailwind.

**USE unless you reject it:** Keep Laravel + Inertia + React as the application. The look comes from the kit: copy `assets/vendor`, `zon-admin.css`, Public Sans, Boxicons, and Leaflet without editing `core.css`. React pages output those existing class names (`card`, `table`, `layout-menu`, `zon-*`). Do not copy the 150 demo HTML pages into the product. Do not build a second static admin. Do not switch admin auth to the kit’s JWT `/Admin/Auth/Login` API. Laravel session stays the staff login. A public marketing site from `front-pages/` is not part of MVP. MVP UI language stays Uzbek; Russian and English dictionaries from the kit are not built now.

**Literal-kit alternative:** Static HTML admin plus a Laravel JSON API, exactly as `02-QOLLANMA.md` describes. That drops Inertia and React. Say so before the first screen if you want that path.

---

## USE — recorded readings

### A1. Repository is greenfield
`Loc.1.0` has no files, git, Laravel app, migrations, or tests. There is no existing architecture to preserve. Sibling folders under `SCP loyhalar` are not part of this product and their rules will not be copied.

### A2. Laravel 13 is real and is the framework
Verified against Laravel 13 release notes: released 17 March 2026, PHP 8.3–8.5, security fixes until 17 March 2028. Docker will pin **PHP 8.4**, which sits inside that range.

### A3. Official React starter kit, one application
Phase 1 starts from the official Laravel React starter kit (Inertia 3, React 19, TypeScript, Tailwind 4, accessible UI components). No Next.js, no separate frontend repo, no microservices.

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
`accuracy_threshold_meters` null means the check is off. No 50 m default (contract §13). When set, accuracy worse than the threshold rejects the attempt with `LOW_ACCURACY` and the bot asks the student to send location again. The failed attempt is stored (A26).

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
MVP scopes are `UNIVERSITY` and `GROUP` only (§47, contract §30). Program and internship scopes are not implemented. If an active group policy exists, that row is used as a whole. Null `minimum_duration_minutes` on that row means OFF, not “inherit the university value”.

Defaults for a newly created university policy, chosen because they match the spec rather than add a new rule:

| Field | Default | Why |
| --- | --- | --- |
| check_in_enabled | true | Attendance is the product |
| check_out_enabled | true | Sessions need checkout |
| minimum_duration_minutes | null | §44 OFF until an admin sets hours |
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
Email plus password, Laravel session auth, hashed passwords. Inactive staff cannot log in. No SSO and no HEMIS login.

### A43. Map
Leaflet with OpenStreetMap-compatible tiles. Admin sets marker and sees the radius circle. The browser never decides verification.

### A44. Backup
Phase 7 documents a daily `pg_dump` of PostgreSQL, 30-day retention, and a tested restore. Location and attendance are in that backup. Phase 0 only records the requirement (contract §58).

### A45. Performance claim
The schema is shaped for 1,000+ students. Phase 0 does not claim that load is proven (contract §59). A measured check belongs to Phase 7.

### A46. Tests
PHPUnit 12 as shipped with Laravel 13, unless the starter kit already uses Pest. Feature tests hit PostgreSQL + PostGIS, not SQLite, whenever geography or exclusion constraints matter.

### A47. Git
The folder is not a git repository. Phase 1 may run `git init`. Commits happen only when you ask.

### A48. Blocked and inactive students
`BLOCKED` and `INACTIVE` students cannot check in, check out, or complete a new onboarding. The existing profile remains. Admin can correct identity fields; that correction is audited (§12, §22).

### A49. Turning location off is an audited admin override
`location_required` exists because the contract lists it. Default is true. If an admin sets it false, check-in skips the geofence and still stores an event. The student path does not have a way to skip location by itself (§67).
