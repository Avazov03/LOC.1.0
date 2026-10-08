# API and service contract

MVP has no public REST API for students. Admin and supervisor use Inertia. Telegram uses one webhook. Both channels call the services in section 3. A second attendance implementation is a defect.

## 1. Web routes

Middleware: `auth`, `verified` is not required for MVP, `role`, university scope.

### Admin (`role:ADMIN`)

| Action | Route intent |
| --- | --- |
| Dashboard | `GET /dashboard` |
| Faculties, programs, academic years, study years, groups | CRUD under `/academic/...` |
| Students list and identity correction | `/academic/students` |
| Supervisors | `/supervisors` |
| Organizations, including location and radius | `/organizations` |
| Internship groups and invites | `/internships` |
| Assignments, including bulk | `/assignments` |
| Change requests | `/change-requests` |
| Today, history | `/attendance/today`, `/attendance/history` |
| Policies | `/attendance/policies` |
| Manual correction | `POST /attendance/corrections` |
| Reports export | `POST /reports/attendance-export` queues a job |
| Audit | `GET /audit-logs` |

### Supervisor (`role:SUPERVISOR`)

| Action | Route intent |
| --- | --- |
| Dashboard | `GET /dashboard` |
| My groups | `/groups` |
| Students in scope | `/students`, `/students/{student}` |
| Attendance | `/attendance` |
| Change requests in scope | `/change-requests` |
| Own-scope export | `POST /reports/attendance-export` |

`/students/{id}` runs the scope query first. Out of scope is 404.

Admin and supervisor share `/dashboard` and render different menus. Supervisor responses do not include admin navigation.

### Telegram

`POST /telegram/webhook`

Header `X-Telegram-Bot-Api-Secret-Token` required. Body is a Telegram Update. Response is 200 with an empty body after processing or after an idempotent duplicate. Application errors are not returned as 500 with a stack trace to the caller when the secret was valid; they are logged, and the student receives the generic sentence when a chat id is known.

## 2. Inertia payloads

Pages receive already authorized collections. Location coordinates for an organization are sent to the admin organization form only. Supervisor student detail receives distance, time, verification status, and organization name. It does not receive other students' points. The student never receives a payload on the web.

Filters on the admin dashboard: faculty, program, course, group, supervisor, organization, date. Supervisor filters cannot widen scope past their open periods.

## 3. Services

Controllers and Telegram handlers may only call these entry points.

### StudentOnboardingService

`join(token, telegramUserId, firstName, lastName, phone, studentCode|null): StudentProfile`

Rejects closed, expired, and unknown tokens. Rejects a telegram id that already has a profile. Writes user, profile, membership, and participant in one transaction.

### InternshipAssignmentService

`assign(actor, studentIds, organizationId, internshipId, startAt, endAt): Assignment[]`

One row per student. Refuses inactive organizations, students outside the actor's scope, and D2 conflicts. Bulk failure of one student rolls that student back without leaving a partial assignment for that student. Other students in the bulk set are separate transactions so one conflict does not hide the others' results; the response lists per-student success or the conflict reason.

`end(actor, assignmentId, endedAt): Assignment`

`cancel(actor, assignmentId, reason): Assignment`

`activateDue(): void` for the scheduler on future PENDING rows whose `start_at` has arrived.

### InternshipChangeRequestService

`openExisting(actor, student, organizationId, reason)`

`openNew(actor, student, organizationData, reason)` rejects coordinate keys.

`approveExisting(actor, requestId)` transaction: end old, create new, approve, audit.

`approveNew(actor, requestId, organizationAttributes)` transaction: create organization, assignment, approve, audit. Admin only.

`reject(actor, requestId, note)`

`cancel(actor, requestId)`

### AttendanceVerificationService

`verify(student, assignment, latitude, longitude, accuracy|null): VerificationResult`

Pure with respect to Telegram. Returns one of:

`VERIFIED`, `OUTSIDE_RADIUS`, `INVALID_LOCATION`, `LOW_ACCURACY`, `NO_ASSIGNMENT`, `OUTSIDE_INTERNSHIP_PERIOD`.

Does not write.

### AttendanceService

`checkIn(student, location, telegramUpdateId|null): CheckInResult`

`checkOut(student, location, telegramUpdateId|null): CheckOutResult`

Writes events and sessions. Uses `AttendanceVerificationService` and `AttendancePolicyService`.

### AttendancePolicyService

`resolve(student): AttendancePolicy`

### AttendanceStatusCalculator

`forDate(student, localDate): DayStatus`

Does not write.

### ManualCorrectionService

`add(actor, student, occurredAt, kind, reason): AttendanceEvent`

Admin only. Insert plus audit. `occurred_at` for a correction is the time the admin attests, stored as that timestamp, while audit records when the admin acted. The attested time is not the student's phone clock; it is an admin-entered fact. This is the one path where the timestamp is not "now", because §48 is an explicit backfill. The original absence stays in the audit `before` payload.

### AuditLogger

`log(actor, action, entity, before, after, reason, request|null): void`

Called inside the same transaction as the change.

## 4. Validation layers

1. UI: required fields, radius slider 100–500, date order.
2. Form request: types, ranges, authorization ids exist.
3. Service: state machine, scope, overlap, geofence, policy.

A valid form request is not itself attendance verification.

## 5. Errors

| Audience | Body |
| --- | --- |
| Inertia validation | Field messages |
| Inertia business refusal | Plain sentence, no SQL and no stack |
| Telegram | Sentences in `TELEGRAM-FLOW.md` |
| Logs | Exception, update id, student id, no bot token |

## 6. Queues

| Job | Why |
| --- | --- |
| Notify student about request result | Telegram send, not the decision |
| Notify student about a new assignment | Same |
| Build CSV export | Heavy read |

Not queued: check-in decision, assignment transaction, webhook idempotency insert.

## 7. Scheduler

| Command | Effect |
| --- | --- |
| Expire invites | `ACTIVE` with `expires_at` in the past → `EXPIRED` |
| Activate due assignments | `PENDING` whose `start_at` has arrived → `ACTIVE`, if D2 still holds |
| Close open sessions | Yesterday's `OPEN` → `INCOMPLETE`, `SYSTEM_ADJUSTMENT`, `closed_at` stays null |

Scheduler uses the university timezone to decide "yesterday".
