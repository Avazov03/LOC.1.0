# CURSOR IMPLEMENTATION CONTRACT v1.0

This file is the technical implementation contract supplied for this project. It is used together with `SOURCE-OF-TRUTH-SPEC.md`.

If the two documents conflict:

1. On a business rule, the Source of Truth wins.
2. On technical implementation, this contract wins.
3. Where the reading is unclear, do not invent a business rule.
4. Record the uncertainty before continuing implementation.

Do not silently invent business rules.

## 1. Source of truth

The business source is Source-of-Truth Specification v1.0. This contract defines how that specification is built.

## 2. First task — do not code

After accepting the project, do not start writing code. Inspect the repository first: files, Laravel and PHP versions, `package.json`, `composer.json`, database, migrations, models, controllers, routes, middleware, authentication, frontend, React, Inertia, Tailwind, Telegram, Docker, `.env.example`, tests, config, services, policies, jobs, events, and listeners.

Then create:

```text
docs/ARCHITECTURE.md
docs/ERD.md
docs/PERMISSIONS.md
docs/STATE-MACHINES.md
docs/TELEGRAM-FLOW.md
docs/ATTENDANCE-RULES.md
docs/API-CONTRACT.md
docs/TEST-PLAN.md
docs/IMPLEMENTATION-PLAN.md
docs/ASSUMPTIONS.md
```

Do not write business code in that step. Show the implementation plan first.

## 3. Required architecture

MVP stack: Laravel 13, PHP 8.3+, PostgreSQL, PostGIS, Redis, React, TypeScript, Inertia.js, Tailwind CSS, Telegram Bot API, Docker.

```text
Browser → React + Inertia → Laravel → Domain / Application Services
       → PostgreSQL + PostGIS
       → Redis / Queue

Telegram → Webhook → Telegram Adapter / Handler → Application Service
        → Domain Logic → Database
```

Do not put business logic in a Telegram handler. `TelegramWebhookController` only accepts the update. Logic lives in services such as `StudentOnboardingService`, `AttendanceService`, `AttendanceVerificationService`, `AttendancePolicyService`, `InternshipAssignmentService`, and `InternshipChangeRequestService`.

## 4. Database before business features

Design the schema before feature code. Minimum entities:

User, StudentProfile, SupervisorProfile, University, Faculty, Program, AcademicYear, StudyYear, StudentGroup, Internship, InternshipInvite, Organization, OrganizationContact, InternshipAssignment, InternshipChangeRequest, AttendancePolicy, AttendanceSession, AttendanceEvent, AuditLog.

For each model specify primary key, foreign keys, nullability, uniqueness, indexes, status, timestamps, and delete behavior.

## 5. Database integrity

Do not rely only on application validation. Protect invariants in the database: unique Telegram identity, unique invite token, unique active assignment, foreign keys, valid radius range, spatial index, attendance indexes. Use application validation and database constraints together.

## 6. Organization location

Store location as PostGIS `geography(Point, 4326)`. Radius is 100–500 meters. Default is 100 meters. Distance uses `ST_Distance`. Within-radius uses `ST_DWithin`. Boundary: 99 m pass, 100 m pass, 101 m fail. Cover that boundary with an automated test.

## 7. Never trust client time

Do not use phone or device time for attendance. The authority is the server/database timestamp. Business display uses `Asia/Tashkent`. The database timestamp strategy stays consistent for the whole project.

## 8. Attendance events are immutable

`AttendanceEvent` is a historical fact. Do not update or delete it through ordinary CRUD. A correction is a new correction record plus an audit log. The original fact remains.

## 9. Attendance session

`CHECK_IN` opens a session. `CHECK_OUT` completes it. Without checkout the session stays `OPEN` / `INCOMPLETE`. The system must not invent a checkout time.

## 10. Multiple sessions

When policy allows it, `09:00–11:00` and `12:00–14:00` are two sessions and the total is 4 hours. Do not force one day into a single attendance row.

## 11. Attendance status

Minimum statuses: `PRESENT`, `INCOMPLETE`, `PARTIAL`, `ABSENT`, `LOCATION_REJECTED`. Status comes from business rules. A verified check-in that misses the minimum duration is not automatically `ABSENT`. Example: 4 hours required, 2 hours actual, result may be `PARTIAL`.

## 12. Location verification

Order: Telegram identity, student, active assignment, internship period, organization, GPS, accuracy, distance, policy, attendance event, attendance session.

A rejected location may be stored as a failed attempt for audit. It does not count as verified attendance.

## 13. GPS accuracy

If a policy accuracy threshold exists and accuracy is worse than that threshold, retry or reject. Example: 8 m against a 50 m threshold is valid; 150 m against 50 m is unreliable. If the business document does not set the default number, do not choose one.

## 14. Telegram identity

`telegram_user_id` is the technical identity. Do not use the Telegram username. Do not use the phone number as the sole primary identity.

## 15. Telegram webhook

Production uses a webhook, not polling. The webhook requires HTTPS and request verification. `update_id` is the idempotency key.

## 16. Telegram update idempotency

If the same `update_id` arrives twice, process it once and ignore the second delivery. A duplicate update must not create a duplicate attendance event. Use a unique constraint or another persistent processed-update strategy.

## 17. Concurrent check-in

Two parallel check-in requests for one student must create only one session. A PHP `if` is not enough. Use a transaction, a row lock, and a unique constraint together. Write a concurrency test.

## 18. Internship assignment

A student has at most one ACTIVE overlapping internship assignment at a time. Statuses: `PENDING`, `ACTIVE`, `ENDED`, `CANCELLED`. Keep history. Do not delete an old assignment.

## 19. Assignment change is atomic

Approving an organization change ends the old assignment, creates the new one, approves the request, and writes audit in one transaction. A failure must not leave a half-updated state.

## 20. Invite links

The token is cryptographically secure and random. Do not use a predictable URL such as `/join/403`. Statuses: `ACTIVE`, `CLOSED`, `EXPIRED`. A closed link rejects new onboarding. Students who already joined stay active.

## 21. Student onboarding

Invite, Telegram, group context, first name, surname, phone, optional student id, student profile, group membership. Duplicate onboarding must not create another student.

## 22. Identity limitation

The invite gives group context. It does not prove the person is that university student. MVP needs an admin correction/verification path. HEMIS is not MVP.

## 23. Role security

Roles: `ADMIN`, `SUPERVISOR`, `STUDENT`. Organization supervisor is not a login role in MVP. Authorization is enforced on the backend. Hiding a button is not authorization.

## 24. Supervisor scope

A supervisor sees only assigned groups, students, assignments, and attendance. Changing `/students/123` to another id must not reveal another supervisor’s student. Every resource checks authentication, role, scope, and ownership.

## 25. Student security

A student sees only their profile, assignment, attendance, and change requests. Sending another student id must not return that student’s attendance.

## 26. Admin security

Admin has global scope. Dangerous operations are audited: attendance correction, organization location change, radius change, assignment change, supervisor change.

## 27. Audit log

At least: actor, action, entity type, entity id, before, after, reason, IP when appropriate, timestamp. Do not needlessly duplicate sensitive data.

## 28. Change request

Student submits current organization, new organization, and reason, status `PENDING`. An existing organization follows supervisor approval. A new organization follows the admin organization workflow.

## 29. Organization security

A student does not create a production organization. A student may only request a new organization. Organization status is `ACTIVE` or `INACTIVE`. Do not assign anyone to an inactive organization.

## 30. Attendance policy

Do not hard-code policy. Supported configuration: check-in enabled, check-out enabled, minimum duration minutes, multiple sessions allowed, location required, accuracy threshold, manual correction allowed.

Scopes: university default and group override. A group override wins.

## 31. No continuous tracking

Do not collect background location. Location is taken only at an attendance action.

## 32. No QR

QR is out of scope for MVP. Do not implement it.

## 33. No HEMIS

HEMIS integration is out of scope for MVP.

## 34. No mobile app

No native Android or iOS app. Students use Telegram. Admin and supervisor use the web.

## 35. No microservices

One Laravel application. Do not add microservices, Kafka, Kubernetes, FastAPI, or a separate Next.js frontend.

## 36. Queues

Notifications, email, large reports, heavy exports, and non-critical processing may be queued. Critical attendance verification is not queued as “we will check later”. The check-in result is decided inside the request when possible.

## 37. Error handling

Do not show SQL errors, stack traces, Laravel exceptions, or database errors to users. Example sentence: the action could not be completed, try again in a few seconds. Technical detail goes to logs.

## 38. Validation

Three layers: UI, request validation, and domain validation. Frontend validation does not replace backend validation.

## 39. Testing requirement

Each critical business rule has an automated test. Minimum coverage includes unit tests for distance, radius, duration, policy, status, assignment conflict, and state transition; feature tests for onboarding, assignment, change request, attendance, dashboard, and authorization; integration tests for the webhook, duplicate updates, transactions, queue, and location; security tests for IDOR, scope bypass, role bypass, and unauthorized correction; concurrency tests for double check-in, duplicate webhook, and simultaneous assignment update.

## 40. Required test cases

99 m pass, 100 m pass, 101 m fail. Valid check-in succeeds. Wrong location fails. No location fails. Poor accuracy fails or asks for a retry. Duplicate check-in does not duplicate. Valid checkout succeeds. Checkout outside the radius fails. Checkout without a session fails. Open session is incomplete. Complete session has a duration. Multiple sessions sum. Minimum duration off keeps actual duration. Minimum duration on and insufficient time is partial. No verified attendance is absent. Closed invite rejects. Expired invite rejects. Duplicate onboarding does not duplicate. Supervisor A cannot see supervisor B. Student A cannot see student B. A duplicate Telegram update is processed once. Simultaneous check-in yields one active session.

## 41. Logging

Use structured logging. Critical events: login, authorization failure, Telegram webhook, attendance verification, location rejection, assignment change, manual correction, organization location change, supervisor change, system error.

## 42. Sensitive location data

Student A does not see student B’s location. A supervisor sees attendance evidence only inside scope. Do not send more location data to the frontend than the screen needs.

## 43. One business rule

Controllers orchestrate. `AttendanceService`, `AttendanceVerificationService`, and `AttendancePolicyService` hold the rules. Web and Telegram both call those services. Do not write a second attendance implementation for Telegram.

## 44. Frontend

React and TypeScript. The frontend does not decide verification. `distance <= radius` is a backend decision.

## 45. UI principles

Admin: few clicks, clear filters, bulk operations, today first. Supervisor: assigned students only, today first. Student: simple Telegram buttons, clear errors, a clear next action.

## 46. Mobile responsiveness

Admin and supervisor screens are desktop-first and responsive. There is no native student app.

## 47. No overengineering

Prefer a simple solution when it is enough. Do not add structure only because a future version might want it.

## 48. Migrations

Every schema change is a migration. No manual production SQL hacks. Migrations are reproducible, reviewable, and rollback-aware.

## 49. Seed data

Development seed: 1 university, 2 faculties, 2 programs, an academic year, multiple study years, multiple groups, multiple supervisors, multiple organizations, multiple students, assignments, and attendance examples. Include one student at one organization, many students at the same organization, and one group at different organizations.

## 50. Environment

Secrets live in `.env`. Do not commit a real bot token, database password, production secret, or API secret. Provide `.env.example`.

## 51. Telegram token

Do not write the bot token in source. Use `TELEGRAM_BOT_TOKEN`. In development the user supplies it in `.env`.

## 52. Telegram bot structure

Keep the adapter separate, for example `app/Telegram` with commands, handlers, keyboards, services, and DTOs. The exact folders may follow the repository. The Telegram layer must not mix with the domain.

## 53. Telegram menu

My internship, start, finish, my attendance, change place, profile, help. Minimal friction.

## 54. Location request

On check-in the bot asks the student to share location, in plain language. Example: send your current location so attendance can be confirmed. Do not show raw coordinates to the student.

## 55. Check-in response

Success states that the start was recorded, with time, place name, and distance in meters. Do not add needless technical detail.

## 56. Location failure response

Tell the student they are outside the internship place, give the approximate distance, and tell them to move closer and try again.

## 57. Production deployment

Nginx, PHP-FPM, Laravel, PostgreSQL + PostGIS, Redis, queue worker, scheduler, HTTPS, Telegram webhook. Docker provides a reproducible environment.

## 58. Backup

Document a production database backup strategy. Location and attendance data matter.

## 59. Performance

The initial target is 1,000+ students. Do not claim that capacity without a test. Measure it.

## 60. Implementation order

Phase 0 repository analysis, architecture, ERD, permissions, state machines. Phase 1 foundation, auth, roles, academic structure. Phase 2 organizations, supervisors, internships, invites, assignments, change requests. Phase 3 Telegram, webhook, identity, onboarding, menus. Phase 4 attendance, GPS, PostGIS, check-in, check-out, sessions, policy. Phase 5 supervisor dashboard. Phase 6 admin dashboard, reports, audit. Phase 7 security, concurrency, performance, testing, production.

## 61. Phase gate

Do not automatically start the next phase. Check tests, migration, authorization, business rules, and edge cases. Then write the phase completion report.

## 62. Completion report

```text
Implemented:
Tests:
Database:
Security:
Known Issues:
Out of Scope:
Next Phase:
```

## 63. Never claim done early

Without tests, authorization, database integrity, edge cases, and error handling, a feature is not done.

## 64. No silent changes

If a business rule seems to need a change, stop and show:

```text
Conflict:
Reason:
Recommended change:
Impact:
```

## 65. No unapproved features

Do not add QR, HEMIS, AI fraud detection, face recognition, biometrics, continuous GPS, a native mobile app, an organization portal, microservices, Kafka, or Kubernetes. A future idea may be written as a future recommendation. It does not go into the code.

## 66. Final rule

Work as a senior Laravel architect and product engineer. Do not act as product owner by inventing business rules.

Implementation order: Source of Truth, architecture, database, business logic, authorization, tests, UI.

## 67. Final acceptance

MVP is complete only when all of the following work: academic structure, supervisors, organizations, geolocation, invite links, Telegram onboarding, assignments, change requests, check-in, check-out, geofence, attendance sessions, attendance policy, supervisor dashboard, admin dashboard, audit, authorization, IDOR protection, Telegram idempotency, concurrency protection, and automated tests.
