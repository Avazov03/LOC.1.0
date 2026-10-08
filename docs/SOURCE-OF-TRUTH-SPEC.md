# UNIVERSITY INTERNSHIP MANAGEMENT SYSTEM

## FINAL PRODUCT & TECHNICAL SPECIFICATION

**Version:** 1.0  
**Status:** SOURCE OF TRUTH  
**Product type:** University Internship Management & Attendance Platform  
**Primary channel:** Web Application + Telegram Bot  
**Initial target:** TDYU  
**Architecture goal:** Universal enough for other universities, without hard-coding TDYU-specific business rules.

This file is the business source of truth supplied for the project. If implementation disagrees with this document, this document wins. Gaps are recorded in `ASSUMPTIONS.md` and are not silent product changes.

## 0. Source-of-truth rule

This document is the product and technical specification. If another document, old code, a guess, or a developer opinion conflicts with it, this specification wins.

Do not invent business rules. Do not change an agreed workflow without a reason. Do not add an unagreed MVP feature. Do not implement one business rule two different ways. Do not change the database model just because a screen looks different.

If a requirement is unclear:

1. Check the rules already in this document.
2. Choose the solution with the least conflict.
3. If a business decision is required, record it before implementation.

## 1. Product idea

Attach university students to an internship place, manage that place, and record arrival and departure with a Telegram location.

The goal is a simple, central, checkable electronic record of whether the student really arrived, where, and when. This is not a plain attendance bot.

Core domain: Internship Assignment, Organization, Geofence, Attendance Events, Attendance Policy, Supervisor Dashboard. Telegram is the student’s convenient interface.

## 2. Problem

Paper attendance, a student marked absent at the university despite being at the internship, unclear places, many organizations, a supervisor tracking hundreds of students by hand, hard paper checks, stale place data, no near-real-time view, and no trustworthy history when attendance is disputed.

## 3. Product principles

### 3.1 The student does not create their production location

A student cannot write an arbitrary location into the system as a production assignment. An organization is created by an admin, or through an approved new-organization workflow.

### 3.2 A group is not an internship place

A course group of 30 students may be split across 5, 10, 20, or 30 organizations. Group is not Organization.

### 3.3 Assignment sits between student and organization

The student is not linked directly to an organization. An Internship Assignment says which student, which organization, which period, which university supervisor, which status, when it took effect, and when it ended. It is the history and the place-change record.

### 3.4 An attendance event is not an attendance status

An event is something that happened, such as `09:03 CHECK_IN`. A status is the result of events plus policy, such as `PRESENT`. Raw events are not deleted.

### 3.5 Do not hard-wire the product to TDYU

The first deployment may be TDYU. Faculty, course, group, supervisor, organization, and attendance policy are configuration. TDYU-specific data is not hard-coded.

## 4. Roles

### 4.1 Admin

Full management: academic structure, supervisors, organizations, invite links, internship groups, students, assignments, attendance policy, attendance correction, reports, audit logs.

### 4.2 University supervisor

The university-appointed internship supervisor. They see their internship groups, their students, attendance, student attendance detail, and internship change requests. If authorized, they start an organization-change workflow. They cannot see another supervisor’s students.

### 4.3 Student

Joins by invite, links Telegram, sees their profile and assignment, checks in, checks out, sees attendance history, and sends an internship change request. They cannot see other students, create an organization, edit another student’s attendance, or confirm their own location as the internship place.

### 4.4 Organization supervisor

Not a required login role in MVP. The organization’s contact may store name, phone, and position. A later portal is possible. In MVP the university system is the source that confirms attendance.

## 5. Academic structure

```text
University
  └── Faculty
        └── Program
              └── Academic Year
                    └── Study Year / Course
                          └── Group
                                └── Student
```

Example shape: a university, a faculty, a program, `2026/2027`, 4th course, group 403, then students. Those names are examples, not constants.

## 6. Academic year

Academic Year is its own entity, for example `2026/2027`, because a group or student changes year by year. History must not be lost.

## 7. Student onboarding

An admin creates a group invitation by choosing academic year, course, group, university supervisor, and internship period, then creates an invite link.

## 8. Invite link

Each link has a unique random token, group, academic year, internship period, supervisor, status, created by, created at, and expires at or closed at. The URL must not be a predictable path such as `/join/403`.

## 9. Invite statuses

`ACTIVE` accepts new students. `CLOSED` and `EXPIRED` do not. Students who already joined keep their profiles.

## 10. Onboarding flow

The student opens the invite. The bot says they are joining that course and group internship, then collects first name, last name, phone, student id when needed, and any further fields the university requires. The Telegram account id is stored.

## 11. Telegram id

The technical identity in the bot is the Telegram user id. Name is not the technical key. By default one Telegram account cannot be tied to more than one active student account.

## 12. Onboarding limit

The invite sets the group. It is not absolute proof of university identity. MVP works with Telegram user id, phone, full name, student id when needed, and admin correction. A future official registry or API may strengthen verification. HEMIS is not mandatory in MVP.

## 13. Student profile

Full name, phone, Telegram user id, student id when present, academic structure, status, created at, updated at. Status: `ACTIVE`, `INACTIVE`, `BLOCKED`.

## 14. Supervisor management

Admin creates a supervisor with full name, phone, email, login, password or another authentication method, position, and active or inactive. A supervisor who is not in the system cannot be selected on an invite.

## 15. Supervisor on the invite

Creating an internship group collects course, group, academic year, internship period, and university supervisor. The picker lists active supervisors. The link is bound to the chosen supervisor.

## 16. Organization management

Name, type, address, phone, optional website, contact person, contact phone, location, radius, active status, created by, timestamps.

## 17. Organization location

Stored as `geography(Point, 4326)`. Latitude and longitude floats are not the primary geospatial source. Checks run in PostGIS.

## 18. Radius

Admin chooses 100–500 meters. Default is 100. Each organization may differ.

## 19. Meaning of radius

Radius is the allowed geofence around the organization center. Compare distance from the student point to the organization point. Distance less than or equal to radius is `LOCATION VERIFIED`. Otherwise `LOCATION REJECTED`. Use geography, `ST_DWithin`, and meters.

## 20. No exact coordinate equality

Do not require `student_lat == organization_lat`. Only geographic distance matters.

## 21. Assigning a place

Admin, or an authorized supervisor, selects a student and an active organization, then creates an assignment with student, organization, supervisor, start, end, and status.

## 22. Bulk assignment

Selecting several students and one organization creates one assignment row per student. Bulk assignment does not cancel individual assignment.

## 23. Assignment statuses

`PENDING`, `ACTIVE`, `ENDED`, `CANCELLED`. The active assignment is the student’s current place. A student has one active assignment at a time.

## 24. Assignment conflict

An active range blocks another active overlapping range for that student. Changing place ends the old assignment, then starts the new one.

## 25. Changing place

The student chooses change internship place in the bot and submits a reason plus an existing organization or information about a new one. The request is created as `PENDING`.

## 26. Place already in the database

The student picks an existing organization. The supervisor approves. Then the old assignment ends, a new assignment is created, the new organization becomes the active assignment, and history remains.

## 27. Place not in the database

The student submits information. That place does not become active immediately. The request is treated as a new-organization request. Admin enters name, address, location, radius, and contact, activates the organization, then assigns the student.

## 28. The student cannot set the location

A student cannot change an organization location by choosing coordinates. This rule is strict.

## 29. Telegram bot

Primary attendance interface. Menu: my internship, start internship, finish internship, my attendance, change internship place, profile, help.

## 30. Check-in flow

The system checks, in order: Telegram account belongs to an active student, an active assignment exists, the internship period is current, an organization is assigned, a location was received, the location is valid, it is inside the radius, policy allows check-in, and there is no duplicate active session. If all pass, a `CHECK_IN` event is created.

## 31. Asking for location

The bot asks for a location. The backend receives latitude, longitude, accuracy when the platform provides it, Telegram user id, and a timestamp. The Telegram location request is the collection mechanism.

## 32. Server time

Attendance does not trust the phone’s local clock. The authoritative time is the server/database timestamp. Changing the phone clock must not change the attendance time.

## 33. Location verification

Student, active assignment, organization, organization location, radius, incoming point, geospatial distance. Example: 47 m against a 100 m radius is verified.

## 34. Location rejection

Example: 1,850 m against 100 m is rejected. A verified check-in event is not created. The student is told they are outside the internship place.

## 35. Location accuracy

When the platform sends accuracy, store it on the event. If accuracy is very poor, the system may ask again. The threshold may be stored as configuration.

## 36. Check-in event

A successful check-in stores event type `CHECK_IN`, verification `VERIFIED`, student, assignment, organization, latitude, longitude, accuracy, distance in meters, and server `occurred_at`.

## 37. Check-out flow

Check an active attendance session, an active assignment, a valid internship period, a submitted location, and the radius. If location is verified, create `CHECK_OUT`.

## 38. Check-out location rejected

A verified check-in remains. The failed check-out may be stored as an audit or event. The open session is not automatically marked successfully closed. This can later become `INCOMPLETE`.

## 39. Raw attendance events

Events are historical facts. Types include `CHECK_IN`, `CHECK_OUT`, `FAILED_CHECK_IN`, `FAILED_CHECK_OUT`, `LOCATION_REJECTED`, `MANUAL_CORRECTION`, and `SYSTEM_ADJUSTMENT`. Verified events are not deleted from history.

## 40. Attendance session

A check-in and check-out pair is a session. Example: 09:03 to 13:08 is 4 hours 5 minutes.

## 41. Several sessions in one day

Supported. Example: 09:00–11:00 and 12:00–14:00 totals 4 hours. This is not strictly forbidden. If policy disallows it, status may be affected later.

## 42. Open session

Check-in without check-out is `OPEN`. At the end of the day it may become `INCOMPLETE`. The system must not invent a departure time.

## 43. Attendance status

`PRESENT` when policy is satisfied. `INCOMPLETE` when check-in exists and the session is not fully closed. `PARTIAL` when attendance exists but does not meet policy. `ABSENT` when the period has no verified check-in. `LOCATION_REJECTED` when location verification failed. `SUSPICIOUS` when a suspicious pattern is detected.

## 44. Minimum duration

Not hard-coded. Admin sets it OFF or to a duration such as 4 hours. OFF records real attendance. ON uses duration when calculating status.

## 45. Short attendance is not absent

If policy requires 4 hours and the student has 09:00–11:00, the result may be `PARTIAL` or insufficient duration. It is not `ABSENT`, because a verified attendance event exists.

## 46. Attendance policy

Policy may later control check-in enabled, check-out enabled, minimum duration, multiple sessions, late threshold, location verification required, accuracy threshold, manual correction, and notification settings. MVP implements only the necessary parameters. Unnecessary configuration is not shown in the UI.

## 47. Policy scope

Policy may apply as a university default, an internship or program policy, or a group policy. MVP needs university default plus group override. Inheritance must be explicit. A group override wins.

## 48. Admin manual correction

Admin may add attendance, for example a 09:04 check-in when a phone failed. Reason, actor, time, previous state, and new state are written to the audit log.

## 49. Correction history

The original event is not deleted. The correction is an additional fact. Example: no original check-in, plus a correction check-in at 09:04 with the reason.

## 50. Supervisor dashboard

Only their scope. Today shows totals and counts for present, incomplete, absent, and location rejected.

## 51. Supervisor group view

Opening a course and group lists each student with times, duration, and status. A missing check-out is shown as incomplete. No times means absent.

## 52. Supervisor student detail

Name, group, organization, supervisor, internship period, today’s attendance, history, failed location attempts, change requests, and manual corrections.

## 53. Admin dashboard

Whole system: student count, active internships, today’s present, incomplete, absent, and location rejected. Filters: faculty, program, course, group, supervisor, organization, date.

## 54. Notifications

The bot may send change-request status, assignment changed, and important attendance notices. Reminders in MVP depend on configuration. Do not bother students with needless notifications.

## 55. Organization location changes

Admin edits the location. Future attendance uses the new point. Historical events keep the distance, organization location, and radius from that moment. The event stores a snapshot of the location and radius used for verification.

## 56. Radius changes

Events after the change use the new radius. Older events keep the old radius snapshot.

## 57. Assignment history

Replacing ABC Law with XYZ Law keeps both ranges. The old row is not removed.

## 58. Supervisor replacement

Admin may replace a supervisor for a later date range. The current supervisor manages the students. The historical supervisor relationship is not lost.

## 59. Permission security

Student: own data only. Supervisor: own students and groups only. Admin: global management. A hidden frontend button is not security. Every authorization is also checked on the backend.

## 60. IDOR

A supervisor who edits a student URL to another id must not see that student. Every resource is checked by an access policy.

## 61. Rate limiting

Telegram webhook, student action, and change request are rate-limited. A student must not be able to send hundreds of check-ins in a few seconds.

## 62. Duplicate check-in

A second check-in while one is active tells the student they already have active attendance. A second verified session is not created.

## 63. Duplicate check-out

Without an active session, tell the student there is no active attendance to close.

## 64. Wrong user or assignment

A Telegram user who is not an active student gets access denied. Without an active assignment, tell them no internship is currently assigned.

## 65. Outside the internship period

A check-in outside the assignment period is refused by default. A later policy may add grace. In MVP the internship period is a hard boundary.

## 66. No internet

If the bot cannot reach the server, the system does not invent an attendance time. The student retries when the network returns. Offline attendance is not guaranteed in MVP.

## 67. GPS off

Without a location, verification does not happen. The bot says a location is required.

## 68. Fake GPS

The system does not claim perfect fake-GPS prevention. MVP verifies with location, accuracy, distance, server time, Telegram identity, and assignment. Mock-location detection, suspicious movement, and anomaly detection may come later.

## 69. Failed attempts

Failed location attempts are kept, for example a rejected check-in 3.2 km away. They can support audit or a dispute. The UI may hide them when they are not needed.

## 70. Audit log

Audit admin login, supervisor login, organization create and update, location update, radius update, invite create and close, assignment create, update, and cancel, supervisor assignment, manual attendance correction, policy update, and change-request approval or rejection.

Fields: actor, action, entity, entity id, before, after, timestamp, metadata.

## 71. Core models

User, StudentProfile, SupervisorProfile, University, Faculty, Program, AcademicYear, StudyYear, StudentGroup, Internship, InternshipInvite, Organization, OrganizationContact, InternshipAssignment, InternshipChangeRequest, AttendancePolicy, AttendanceSession, AttendanceEvent, AuditLog.

## 72. User

Authentication. Roles: `ADMIN`, `SUPERVISOR`, `STUDENT`. Telegram identity for a student is linked from the profile.

## 73. Student profile

One-to-one with user. Stores student id, university, academic group, phone, Telegram user id, and status.

## 74. Organization

id, name, type, address, `location geography(Point,4326)`, `radius_meters`, status, contact name, contact phone, website, created by, timestamps.

## 75. Internship assignment

id, student id, organization id, supervisor id, internship id, start at, end at, status, created by, timestamps. A future organization-supervisor relation is separate.

## 76. Attendance event

id, student id, assignment id, nullable session id, event type, verification status, latitude, longitude, nullable accuracy meters, nullable distance meters, organization latitude snapshot, organization longitude snapshot, radius snapshot meters, occurred at, source, metadata, created at.

This is the attendance audit fact.

## 77. Why snapshots exist

The organization location and radius can change later. Recomputing an old event against the new values must not change the historical result. The event stores the verification context from that moment.

## 78. Attendance session

id, student id, assignment id, date, check-in event id, nullable check-out event id, nullable duration seconds, status, timestamps.

## 79. Attendance policy

id, scope type, scope id, check-in enabled, check-out enabled, nullable minimum duration minutes, multiple sessions allowed, location required, nullable accuracy threshold meters, manual correction allowed, status, timestamps.

## 80. Change request

id, student id, current assignment id, nullable requested organization id, nullable requested organization JSON, reason, status, reviewed by, reviewed at, timestamps. Status: `PENDING`, `APPROVED`, `REJECTED`, `CANCELLED`.

## 81. Invite model

id, token hash, academic year id, group id, internship id, supervisor id, created by, status, expires at, closed at, timestamps. The raw token does not have to be stored in clear text.

## 82. Indexes

Telegram user id, student id, phone, group id, supervisor id, organization id, assignment status, assignment student and date, attendance event student and date, attendance session date, invite token hash, audit entity. A PostGIS spatial index on organization location.

## 83. Geo strategy

PostgreSQL plus PostGIS `geography(Point,4326)`. Radius check: `ST_DWithin`. Distance: `ST_Distance`. Geography returns meters, and `ST_DWithin` can use the spatial index.

## 84. Backend

Laravel 13 on PHP 8.3+. Laravel 13 was released on 17 March 2026, supports PHP 8.3–8.5, and security support runs through 17 March 2028.

## 85. Frontend

Admin and supervisor panel: React plus Inertia, in the same Laravel project. TypeScript, Tailwind, accessible UI components. A separate Next.js frontend is not required for MVP.

## 86. Database

PostgreSQL and PostGIS. MySQL is not the MVP database, because geospatial attendance is a core function.

## 87. Cache and queue

Redis for cache, queue, rate limiting, background jobs, and notifications.

## 88. Telegram

Telegram Bot API. Production uses a webhook into Laravel. Polling may be used in development. Webhook is the production path.

## 89. Map

Leaflet, or a compatible OpenStreetMap provider, so admin can set a marker, see a radius circle, and see the address.

## 90. Containers

Docker for development and production consistency. Services: app, nginx, postgres, redis, queue-worker, scheduler. The Telegram webhook is exposed over HTTPS.

## 91. Production

Nginx, Laravel, PHP-FPM, PostgreSQL plus PostGIS, Redis, queue worker, scheduler, HTTPS, Telegram webhook. PHP 8.3 or newer.

## 92. Design

Simple, professional, desktop-first admin, mobile-friendly, no decorative dashboard cards, aimed at the real workflow. An admin should not cross many pages to finish one action.

## 93. Admin menu

Dashboard. Academic structure: faculties, programs, courses, groups, students. Internship: internship groups, organizations, assignments, change requests. Supervisors. Attendance: today, history, policies. Reports. Audit logs. Settings.

## 94. Supervisor menu

Dashboard, my groups, students, attendance, change requests, reports. Admin menus are not shown.

## 95. Student bot menu

My internship, start, finish, my attendance, change place, profile, help.

## 96. Service principle

Attendance logic does not live in a controller.

```text
AttendanceController
  → AttendanceService
  → LocationVerificationService
  → AttendancePolicyService
  → AttendanceEvent repository / model
```

The Telegram handler also does not own the business logic. Telegram is an input adapter. The domain service is the business logic.

## 97. Telegram handler principle

```text
Telegram Update
  → command / action handler
  → authenticated student
  → domain service
  → database
  → Telegram response
```

A later web or mobile client must not require the attendance rules to be rewritten.

## 98. Verification algorithm

1. Identify the Telegram user.
2. Find the active student.
3. Find the active internship assignment.
4. Validate the internship period.
5. Load the organization.
6. Load the organization location.
7. Load the radius.
8. Receive the student location.
9. Validate latitude and longitude.
10. Validate accuracy when it is present.
11. Calculate distance.
12. Compare distance with radius.
13. Evaluate attendance policy.
14. Create an immutable attendance event.
15. Create or update the attendance session.
16. Recalculate attendance status.
17. Return a plain Telegram response.

## 99. Verification results

`VERIFIED`, `OUTSIDE_RADIUS`, `INVALID_LOCATION`, `LOW_ACCURACY`, `NO_ASSIGNMENT`, `OUTSIDE_INTERNSHIP_PERIOD`, `DUPLICATE_ACTION`.

## 100. User-facing errors

Do not show technical errors such as a SQL state to the student. Say the action could not be completed and to retry in a few seconds. Keep the technical detail in logs.

## 101. Identity and permission

Every backend action checks the authenticated user, the role, and ownership or scope. A student request for another student id is limited to their own profile. A supervisor cannot leave their group and student scope.

## 102. Transactions

Assignment change: close the old assignment, create the new one, and approve the request in one transaction. Manual correction: original state, correction, and audit log in one transaction. Organization creation plus assignment is atomic when the workflow requires it.

## 103. Concurrency

Two parallel check-ins for one student must not create two active sessions. Protect this with a database constraint and a transaction or lock.

## 104. Idempotency

Telegram may redeliver an update. Webhook processing is idempotent on `update_id`. Processing one update twice must not create a duplicate attendance event.

## 105. Security

HTTPS, secure authentication, password hashing, CSRF protection, authorization policies, rate limiting, webhook secret or token validation, input validation, SQL injection prevention, XSS protection, audit logging, and sensitive-data minimization.

## 106. Webhook security

Secret path or header validation, `update_id` idempotency, rate protection, and logging.

## 107. Privacy

Location is sensitive operational data. Collect only the location that is needed, store it with the attendance context, do not show one student’s location to another student, and show a supervisor only what their authority covers.

## 108. No continuous tracking

MVP does not track the student continuously and does not do background live tracking. Location is taken only at the attendance action. That keeps the product simpler, lowers privacy risk, and avoids constant battery use.

## 109. Product decision

The system does not claim the student was at the place all day. It records that check-in was location-verified at one server time and check-out was location-verified at another. If a minimum-duration policy is on, the system evaluates the session between those events.

## 110. QR

No QR in MVP. A later version may add it. MVP verification is Telegram, location, radius, and server time.

## 111. Not in MVP

HEMIS, automatic university registry sync, QR attendance, biometrics, face recognition, continuous GPS, native Android or iOS, an organization supervisor portal, AI fraud detection, automatic fake-GPS prevention, microservices, Kafka, Kubernetes, and complex external integrations.

## 112. Acceptance — onboarding

An invite for a course, group, and supervisor A causes the student to onboard in that group context.

## 113. Acceptance — duplicate onboarding

Opening the link twice does not create a second student account.

## 114. Acceptance — assignment

After admin assigns a student to an organization, the bot’s “my internship” shows that organization.

## 115. Acceptance — check-in verified

Radius 100 m and distance 50 m produces `CHECK_IN VERIFIED`.

## 116. Acceptance — check-in rejected

Radius 100 m and distance 1 km produces a rejected check-in and no verified session.

## 117. Acceptance — check-out

Verified check-in at 09:00 and verified check-out at 13:00 produce a 4 hour duration.

## 118. Acceptance — incomplete

Check-in at 09:00 with no check-out is `OPEN` / `INCOMPLETE`.

## 119. Acceptance — multiple sessions

09:00–11:00 and 12:00–14:00 total 4 hours when policy allows multiple sessions.

## 120. Acceptance — place change

Moving a student from one organization to another leaves old attendance events unchanged and attaches the new assignment to the new organization.

## 121. Acceptance — radius change

An event taken at radius 100 keeps snapshot 100. An event after the radius becomes 300 stores snapshot 300.

## 122. Acceptance — supervisor scope

Supervisor A of group 403 cannot open a student of supervisor B’s group 404. The result is forbidden or not found, with no data leak.

## 123. Acceptance — admin correction

The correction, reason, and actor are stored, the original state remains, and an audit log is created.

## 124. Acceptance — duplicate Telegram update

The same update delivered twice does not create a duplicate event.

## 125. Acceptance — concurrent check-in

Two simultaneous check-ins create one active session.

## 126. Acceptance — no internet

If the student cannot reach the bot, the server does not invent attendance.

## 127. Acceptance — GPS off

If the student cannot send a location, check-in verification fails.

## 128. Acceptance — fake GPS

A fake location may exist. The system does not guarantee full anti-fraud. It does store location, accuracy, distance, timestamp, and assignment for a later anomaly check.

## 129. Acceptance — 1,000+ students

Dashboard, attendance, assignment, and organization lookup keep working for at least 1,000 students. Webhook processing does not block for a long time. Heavy notification and report work goes through the queue.

## 130. Testing strategy

Unit: distance, radius boundaries, policy calculation, session duration, multiple sessions, assignment conflict, change-request transitions, permissions.

Feature: admin, supervisor, student, organization, invite, assignment, and attendance workflows.

Telegram: webhook, update idempotency, commands, location message, invalid user, duplicate action.

Geospatial, mandatory:

```text
99 m against 100 m → pass
100 m against 100 m → pass
101 m against 100 m → fail
```

The boundary behavior must be exact.

## 131. Database integrity

Required constraints: no duplicate Telegram identity, one active assignment per student at a time, valid foreign keys, valid organization radius, unique invite token, valid status transitions.

## 132. Assignment transitions

`PENDING` to `ACTIVE` to `ENDED`, or `PENDING` to `CANCELLED`. An active assignment is finished through history and status, not by direct deletion.

## 133. Change-request transitions

`PENDING` may become `APPROVED`, `REJECTED`, or `CANCELLED`. An approved request cannot be approved again.

## 134. Organization status

`ACTIVE` or `INACTIVE`. No new assignment to an inactive organization. Old history remains.

## 135. Invite status

`ACTIVE`, `CLOSED`, `EXPIRED`. Closed and expired links do not accept new onboarding.

## 136. Phases

1. Foundation: Laravel, React/Inertia, PostgreSQL, PostGIS, Redis, auth, roles, academic structure.
2. Internship management: organizations, supervisors, internship, assignments, invite links.
3. Telegram: bot, webhook, onboarding, Telegram identity.
4. Attendance: location, geofence, check-in, check-out, sessions, policies.
5. Supervisor dashboard: groups, students, attendance, history, change requests.
6. Admin dashboard: global statistics, filters, management, audit.
7. Hardening: tests, security, concurrency, idempotency, performance, production deployment.

## 137. Implementation rules

1. Inspect the existing repository first.
2. Treat this specification as the source of truth.
3. Find conflicts with the current architecture before coding.
4. Do not put business logic in controllers.
5. Do not write attendance logic in a Telegram handler.
6. Check authorization on the backend, not only in the frontend.
7. Do not delete a raw attendance event.
8. A student cannot change an organization location.
9. Protect active-assignment conflicts in the database and the application.
10. A duplicate Telegram update must not create duplicate attendance.
11. Do not hard-code TDYU data.
12. Do not add unagreed QR, HEMIS, AI, or face recognition to MVP.
13. Each large migration has a rollback or recovery strategy.
14. Each important business rule has an automated test.
15. Acceptance is correct business behavior, not merely “no error”.

## 138. Definition of done

A feature is done when the business rule is implemented, authorization is checked, validation exists, database constraints exist, a unit test exists, a feature or integration test exists, the error state works, audit is written when required, the UI shows success and error, a Telegram flow has a duplicate-webhook test, a geolocation feature has a boundary test, documentation is updated, and existing features still work.

## 139. MVP success

The university can create a group, attach a supervisor, create an invite, collect students through Telegram, enter organizations, assign students, check a real location, record check-in and check-out, let the supervisor see attendance, let a student request a place change, and let an admin run that process.

## 140. Main flow

Admin builds academic structure, creates a supervisor and an organization, creates an internship group, selects the supervisor, and creates an invite. Students onboard in Telegram. Admin or supervisor assigns each student to an organization. The student checks in with a Telegram location. PostGIS returns verified or rejected. An attendance event is stored. Check-out creates a session. Policy produces a status. The supervisor sees it on the dashboard.

## 141. Final description

A platform that manages student internships, assigns internship places, and records internship attendance from geolocation.

The core chain is student, internship assignment, organization, geofence, attendance event, attendance session, attendance policy, supervisor dashboard.

Telegram is the student interaction layer. The web is administration and supervision. PostgreSQL and PostGIS are data and geospatial verification. Redis is the async and queue layer. Laravel is business logic. React and Inertia are the web UI.

## 142. Final architecture

```text
Student Telegram Bot
  → Telegram webhook
  → Laravel 13 application core
       → Attendance service
       → Internship service
       → Authorization / RBAC
  → PostgreSQL + PostGIS
  → Redis queue / cache

Admin / Supervisor
  → React + Inertia
  → Laravel core
```

## 143. Non-negotiable rules

1. A student does not create their location as an organization.
2. A student does not independently attach a production organization to themselves.
3. Organization is an admin-controlled entity.
4. Assignment is the main link between student and organization.
5. Students in one group may be at different organizations.
6. A supervisor is entered by an admin.
7. An invite link is for group onboarding.
8. Telegram user id is the bot identity.
9. Attendance location comes from Telegram.
10. Location is checked by distance and radius.
11. Radius is 100–500 m.
12. Default radius is 100 m.
13. Server time is the authoritative attendance time.
14. No QR in MVP.
15. No continuous GPS tracking.
16. Raw attendance events are not deleted.
17. Manual correction is audited.
18. Minimum duration is not hard-coded.
19. Missing the minimum duration is not automatically absent.
20. Multiple sessions in one day are supported.
21. Duplicate check-in is forbidden.
22. Parallel check-in is protected against races.
23. The Telegram webhook is idempotent.
24. A supervisor cannot see another supervisor’s scope.
25. Admin has global management.
26. A student sees only their own data.
27. An organization location change does not rewrite historical attendance.
28. A radius change does not recalculate historical attendance.
29. Old assignment history is kept.
30. Unagreed features are not added to MVP.
31. Each important business rule is protected by a test.
32. TDYU-specific data is not hard-coded.
33. The product goal is a simple, reliable, manageable internship system.
34. The system does not track the student continuously. It takes location only at the attendance action.
35. GPS is one verification signal, not absolute truth.
36. The system records that a verified attendance event was sent from a place at a time. It does not claim the student was there all day.
37. Product logic and technical implementation stay separate.
38. A new feature must not break attendance, assignment, permission, or audit rules.
39. A large change is compared with this specification first.
40. This document exists so developers do not have to guess.
