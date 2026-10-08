# ERD

Phase 0 schema, locked 8 October 2026. Names below are the ones implementation must use so the same rule is not modeled twice.

Academic tables through `student_group_memberships` already have a migration. Later tables in this file are still planned. Where the migration disagrees with this file, this file wins and a follow-up migration closes the gap. The `users.university_id` gap was closed in Phase 1 (`2026_10_08_110000_require_user_university_and_index_scope`).

Conventions:

- Primary keys are `bigint` identity.
- Instants are `timestamptz`. Calendar dates entered by an admin are `date`.
- Status columns are PostgreSQL enums or checked varchar values matching `STATE-MACHINES.md`.
- Referenced rows use `ON DELETE RESTRICT` and `ON UPDATE CASCADE`.
- No `deleted_at` on operational history. End a row by status.
- `attendance_events` and `audit_logs` have triggers that raise on `UPDATE` and `DELETE`.

Assumptions D1, D2, A9, A15, A19, and A34 change the table list. They are marked inline.

## 1. Entity relationship

```text
University
  ├── Faculty
  │     └── Program
  │           └── StudyYear ──── AcademicYear
  │                 └── StudentGroup
  │                       ├── StudentGroupMembership ── StudentProfile
  │                       └── AttendancePolicy (GROUP)
  ├── AcademicYear
  ├── AttendancePolicy (UNIVERSITY)
  ├── SupervisorProfile ── User
  ├── Organization
  └── Internship ── StudentGroup
        ├── InternshipSupervisorPeriod ── SupervisorProfile
        ├── InternshipInvite
        ├── InternshipParticipant ── StudentProfile
        ├── InternshipAssignment ── Organization
        │     ├── AttendanceSession
        │     └── AttendanceEvent
        └── InternshipChangeRequest

StudentProfile ── User
AuditLog ── User (actor)
TelegramProcessedUpdate
TelegramConversation
```

`Group != Organization`. Students in one `StudentGroup` may hold assignments at different `Organization` rows.

## 2. Identity

### users

| Column | Notes |
| --- | --- |
| id | PK |
| university_id | FK, not null, restrict on delete |
| name | Display name for staff. Students mirror profile name |
| email | Nullable, unique when not null |
| login | Nullable, unique when not null. Required for admin and supervisor |
| password | Nullable. Null for students |
| role | `ADMIN`, `SUPERVISOR`, `STUDENT`. One role (A14) |
| status | `ACTIVE`, `INACTIVE` |
| remember_token | Staff sessions |
| timestamps | |

### student_profiles

| Column | Notes |
| --- | --- |
| id | PK |
| user_id | FK unique |
| university_id | FK |
| current_group_id | FK `student_groups`, nullable until onboarding commits |
| student_code | Nullable. Unique `(university_id, student_code)` where not null |
| first_name, last_name | Required |
| phone | Required, indexed, not unique |
| telegram_user_id | `bigint`, unique, nullable until the bot account is linked. Check-in refuses a null. Username is never stored as the key |
| status | `ACTIVE`, `INACTIVE`, `BLOCKED` |
| timestamps | |

### supervisor_profiles

| Column | Notes |
| --- | --- |
| id | PK |
| user_id | FK unique |
| university_id | FK |
| phone | Required |
| position | Required |
| timestamps | |

Staff active flag is `users.status`. The spec’s supervisor active/inactive is that column, not a second flag.

## 3. Academic structure

### universities

`name`, `slug` unique, `timezone` default `Asia/Tashkent`, timestamps.

### faculties

`university_id` FK, `name`, `status` `ACTIVE|INACTIVE`, unique `(university_id, name)`.

### programs

`faculty_id` FK, `name`, `status` `ACTIVE|INACTIVE`, unique `(faculty_id, name)`.

### academic_years

`university_id` FK, `name` (example `2026/2027`), `starts_on`, `ends_on`, `status` `ACTIVE|CLOSED`, unique `(university_id, name)`, check `ends_on > starts_on`.

### study_years

`program_id` FK, `academic_year_id` FK, `course_number` integer check `> 0`, `name`, unique `(program_id, academic_year_id, course_number)`.

“4-kurs” is data in `name` and `course_number`. It is not a constant in PHP.

### student_groups

`study_year_id` FK, `name`, `code`, unique `(study_year_id, name)`.

A group belongs to one program, one academic year, and one course, through `study_years`. The same label in a later year is a new row, which is how §6 keeps history.

### student_group_memberships

**D1.** This is the history §6 requires.

| Column | Notes |
| --- | --- |
| id | PK |
| student_profile_id | FK |
| student_group_id | FK |
| academic_year_id | FK |
| internship_invite_id | FK nullable |
| status | `ACTIVE`, `ENDED` |
| joined_at | timestamptz |
| ended_at | Nullable |
| timestamps | |

Partial unique index: one `ACTIVE` membership per `student_profile_id`.

## 4. Internship cohort

### internships

The admin action “Create Internship Group” inserts this row.

| Column | Notes |
| --- | --- |
| id | PK |
| university_id | FK |
| academic_year_id | FK |
| student_group_id | FK |
| period_start, period_end | `date`, check `period_end >= period_start` |
| created_by | FK users |
| timestamps | |

No separate internship status (A5). The period is the gate.

### internship_supervisor_periods

**A15. §58.**

| Column | Notes |
| --- | --- |
| id | PK |
| internship_id | FK |
| supervisor_profile_id | FK |
| starts_on | date |
| ends_on | date nullable. Null means current |
| created_by | FK users |
| timestamps | |

Exclusion constraint: ranges for the same `internship_id` must not overlap. Ranges are half-open `[starts_on, ends_on)` (A50). Null `ends_on` is treated as unbounded. Partial unique index: one open period per internship.

### internship_invites

| Column | Notes |
| --- | --- |
| id | PK |
| internship_id | FK |
| token_hash | `char(64)` unique. SHA-256 of the raw token |
| academic_year_id | FK, copied from the internship at creation |
| student_group_id | FK |
| supervisor_profile_id | FK, snapshot at creation (A16) |
| created_by | FK users |
| status | `ACTIVE`, `CLOSED`, `EXPIRED` |
| expires_at | timestamptz nullable |
| closed_at | timestamptz nullable |
| timestamps | |

Index on `token_hash`. Raw token is not stored.

### internship_participants

**A9.**

`internship_id`, `student_profile_id`, `internship_invite_id`, `joined_at`, timestamps. Unique `(internship_id, student_profile_id)`.

## 5. Organization

### organizations

| Column | Notes |
| --- | --- |
| id | PK |
| university_id | FK |
| name | |
| type | Admin-entered text (A20) |
| address | |
| location | `geography(Point, 4326)` not null |
| radius_meters | int not null default 100, check 100–500 |
| status | `ACTIVE`, `INACTIVE` |
| contact_name | |
| contact_phone | |
| contact_position | Nullable (A19, §4.4) |
| website | Nullable |
| created_by | FK users |
| timestamps | |

GiST index on `location`. No `organization_contacts` table (A19).

Location edits update this column and write `audit_logs`. They do not update past events.

## 6. Assignment and change request

### internship_assignments

| Column | Notes |
| --- | --- |
| id | PK |
| student_profile_id | FK |
| organization_id | FK |
| supervisor_profile_id | FK. Snapshot at assignment time, not rewritten on later supervisor change |
| internship_id | FK |
| start_at, end_at | timestamptz, check `end_at > start_at` |
| status | `PENDING`, `ACTIVE`, `ENDED`, `CANCELLED` |
| created_by | FK users |
| ended_at | Nullable actual close time. Does not replace planned `end_at` |
| cancel_reason | Nullable |
| timestamps | |

Indexes:

- partial unique `(student_profile_id) WHERE status IN ('PENDING', 'ACTIVE')` — D2, including a future row that does not overlap
- `(student_profile_id, start_at)` — one student’s history
- `(internship_id, status)` — a cohort’s current placements. This replaces a low-selectivity index on `status` alone
- `(organization_id)`, `(supervisor_profile_id)`

**D2.** The partial unique index is the constraint. A `btree_gist` range exclusion is not added, because two open rows cannot exist to overlap.

Inactive organization cannot be referenced by a new `ACTIVE` or `PENDING` row. Enforced in the service and with a deferred trigger or an application check plus a test. A plain FK cannot express “organization must be active”.

### internship_change_requests

| Column | Notes |
| --- | --- |
| id | PK |
| student_profile_id | FK |
| current_assignment_id | FK nullable |
| request_type | `EXISTING_ORGANIZATION`, `NEW_ORGANIZATION` |
| requested_organization_id | FK nullable. Required for existing type. Set to the created organization when a new-type request is approved (A56) |
| requested_organization_data | jsonb nullable. Required for new type. No coordinates (A23) |
| reason | text |
| status | `PENDING`, `APPROVED`, `REJECTED`, `CANCELLED` |
| initiated_by | FK users |
| reviewed_by | FK users nullable |
| reviewed_at | Nullable |
| review_note | Nullable |
| timestamps | |

Check constraint ties type to which payload is present. Partial unique: one `PENDING` row per student.

## 7. Attendance

### attendance_policies

| Column | Notes |
| --- | --- |
| id | PK |
| scope_type | `UNIVERSITY`, `GROUP` |
| scope_id | University id or student group id |
| check_in_enabled | bool |
| check_out_enabled | bool |
| minimum_duration_minutes | int nullable. Null means OFF |
| multiple_sessions_allowed | bool, default false. Locked off: `CHECK attendance_policies_single_session (multiple_sessions_allowed = false)` (A69) |
| location_required | bool |
| accuracy_threshold_meters | int nullable. Null means off (A29) |
| manual_correction_allowed | bool |
| status | `ACTIVE`, `INACTIVE` |
| timestamps | |

Partial unique: one `ACTIVE` policy per `(scope_type, scope_id)`. No late-threshold column (A32). Checks on PostgreSQL: minimum and accuracy are null or positive.

### attendance_sessions

| Column | Notes |
| --- | --- |
| id | PK |
| student_profile_id | FK |
| assignment_id | FK |
| local_date | `date` in the university timezone, taken from check-in `occurred_at` |
| check_in_event_id | FK unique |
| check_out_event_id | FK unique nullable |
| duration_seconds | Nullable. Set only when checkout is verified. Never guessed |
| status | `OPEN`, `COMPLETED`, `INCOMPLETE` |
| opened_at | timestamptz |
| closed_at | Nullable. Set only from a verified check-out event |
| timestamps | |

Partial unique: one `OPEN` session per `student_profile_id`. Index `(student_profile_id, local_date)`. Checks: `duration_seconds >= 0`; a `COMPLETED` session has `closed_at` and `duration_seconds`.

There is no `attendance_days` table. Day status is computed (`ATTENDANCE-RULES.md`).

### attendance_events

Immutable fact (§76, §77).

| Column | Notes |
| --- | --- |
| id | PK |
| student_profile_id | FK |
| assignment_id | FK nullable for attempts with no assignment |
| session_id | FK nullable |
| event_type | See state machines |
| verification_status | See `ATTENDANCE-RULES.md` |
| latitude, longitude | `numeric(10,7)` nullable |
| accuracy_meters | `numeric(10,2)` nullable |
| distance_meters | `numeric(12,3)` nullable |
| organization_latitude_snapshot | `numeric(10,7)` nullable |
| organization_longitude_snapshot | `numeric(10,7)` nullable |
| radius_snapshot_meters | int nullable |
| occurred_at | timestamptz not null, database clock |
| source | `TELEGRAM`, `MANUAL`, `SYSTEM` |
| telegram_update_id | bigint nullable, unique |
| metadata | jsonb. Correction reason and actor ids live here and in `audit_logs` |
| created_at | No `updated_at` |

Indexes: `(student_profile_id, occurred_at)`, `(assignment_id)`, `(session_id)`, unique `telegram_update_id`.

Trigger: reject `UPDATE` and `DELETE`.

## 8. Audit and Telegram technical tables

### audit_logs

`university_id` FK (A55), `actor_user_id` nullable, `action`, `entity_type`, `entity_id`, `before` jsonb, `after` jsonb, `reason`, `ip` inet nullable, `metadata` jsonb, `created_at`. Indexes: `(entity_type, entity_id)`, `(actor_user_id)`, `(university_id, created_at)`.

Indexes: `(entity_type, entity_id)`, `(actor_user_id)`, `(created_at)`. Trigger rejects update and delete. Do not copy location trails into `before`/`after` when the entity change is not itself a location change.

### telegram_processed_updates

`update_id bigint` primary key, `processed_at`, `handler`. Inserted in the same transaction as the business effect. Primary key collision means the update was already handled.

### telegram_conversations

One row per `telegram_user_id` (unique): `state`, `context` jsonb, `expires_at`, timestamps. This is dialog state, not attendance state. Context must not be treated as a verified location or as an organization point.

### telegram_notifications

Outbound student notices (A79): `key` unique (idempotency), `student_profile_id` FK, `text`, `status` (`PENDING`, `SENT`, `FAILED`, `SKIPPED`), `attempts`, `error`, `sent_at`, timestamps; index `(student_profile_id, created_at)`. Written after commit and sent by the queue. `telegram:prune` removes old processed updates and expired conversations.

### report_exports

Queued CSV exports (A78): `university_id` FK, `user_id` FK (only that user may download), `filters` jsonb including `type` (`summary`, `daily`), `status` (`PENDING`, `RUNNING`, `DONE`, `FAILED`), `path` on the local disk, `rows`, `error`, `finished_at`, timestamps.

## 9. Indexes and the query each one serves

An index is listed only when a real read uses that leading column. Low-cardinality flags alone are not indexed.

| Query | Index |
| --- | --- |
| Bot identity | unique `student_profiles.telegram_user_id` |
| Student number inside one university | unique `(university_id, student_code)`. PostgreSQL allows many nulls |
| Phone search | `student_profiles.phone`. Not unique |
| Student directory, page by name | `(university_id, last_name, first_name)` |
| Students in one group | `student_profiles.current_group_id` and `student_group_memberships.student_group_id` |
| Staff list | `(users.university_id, role, status)` |
| Active organizations for a picker | `(organizations.university_id, status)` |
| Cohorts in a year | `(internships.university_id, academic_year_id)` |
| Who supervises this cohort now | partial unique one open `internship_supervisor_periods` row per internship, plus `(supervisor_profile_id)` where `ends_on` is null |
| Invite lookup | unique `token_hash` |
| One open placement | partial unique assignment `(student_profile_id)` where status is `PENDING` or `ACTIVE` |
| Student assignment history | `(internship_assignments.student_profile_id, start_at)` |
| Placements at one organization | `internship_assignments.organization_id` |
| One pending change request | partial unique change request per student where `PENDING` |
| One student’s attendance | `(attendance_events.student_profile_id, occurred_at)` |
| One student’s day | `(attendance_sessions.student_profile_id, local_date)` |
| Admin count of sessions on a date | `(attendance_sessions.local_date)`. Supervisor “today” stays on the student-leading index because the scope is a small id list |
| Webhook replay | primary key `telegram_processed_updates.update_id` |
| Audit screen for one row | `(audit_logs.entity_type, entity_id)` |
| Geofence | GiST on `organizations.location` `geography(Point, 4326)`. `ST_DWithin` uses it. `ST_Distance` is the stored meter value, not the pass/fail |

`ST_DWithin` is inclusive. For a 100 m radius, 99 m passes, 100 m passes, 101 m fails. Those three cases are automated tests on PostGIS, not on sqlite.

## 10. Delete and rollback

Migrations are additive in Phases 1–4. Every migration implements `down()`.

Rows that already have attendance, assignments, or audit references are not hard-deleted. Admin operations deactivate or end them. `down()` of a schema migration drops only what that migration created; it is not a data-fix script.

Production data fixes, if ever required, are new forward migrations. Manual SQL against production is out of bounds (contract §48).
