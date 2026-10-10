# Permissions

Backend enforcement only. A hidden menu item is not a control. Every protected operation checks authentication, role, university, and scope. Out-of-scope ids return **404** for supervisors and students, so existence of another person’s record is not revealed. Admin receives normal 404 only when the row does not exist in that admin’s university.

University ownership is a column, not a tenant product. A user of university A does not receive university B’s faculties, students, organizations, or attendance by changing an id. There is no student web route; student access is the Telegram profile resolved from `telegram_user_id`.

Organization on-site contacts (§4.4) have no login.

## 1. Roles

| Role | Channel | Scope |
| --- | --- | --- |
| ADMIN | Web session | Entire university |
| SUPERVISOR | Web session | Internships with an open `internship_supervisor_periods` row for that supervisor |
| STUDENT | Telegram only | Own profile, own assignments, own attendance, own change requests |

Inactive `users.status` cannot log in. `BLOCKED` or `INACTIVE` student profiles cannot use attendance or a second onboarding (A48).

## 2. Admin

Allowed:

- Academic structure: create and update faculty, program, academic year, study year, group, and correct a student’s identity fields.
- Supervisors: create, update, activate, deactivate. Deactivated supervisors disappear from the invite picker (§14).
- Organizations: create, update, set location, set radius, activate, deactivate.
- Internship groups, invite links (create and close), assignments (create, activate, end, cancel), including bulk assign.
- Change requests: review `NEW_ORGANIZATION`; also review existing-organization requests.
- Attendance policy: university default and group override.
- Manual attendance correction when the resolved policy allows it.
- Dashboards, filters, reports, audit log read.
- Replace the current supervisor of an internship (closes one period, opens the next, one transaction).

Not allowed:

- Update or delete an attendance event in place.
- Delete an ended assignment to hide history.
- See another university’s data once `university_id` is set. MVP seeds one university; the check still exists.

Every item in §70 writes `audit_logs`: admin login, organization create/update, location update, radius update, invite create/close, assignment create/update/cancel, supervisor assignment, manual correction, policy update, change-request decision.

**Work as supervisor ("Rahbar sifatida kirish").** An admin may open the panel as an ACTIVE supervisor of the same university, from the supervisor page, and use every supervisor function with the supervisor's scope. The session keeps the admin id, and every audit row written meanwhile carries `impersonator_user_id` / `impersonator_name` in metadata; history shows "{supervisor} (admin {name} orqali)". The supervisor's own account stays off limits: password change, Telegram link/unlink and notification settings are refused (`ProtectImpersonatedAccount`). Start and end are audited as `auth.impersonate` / `auth.impersonate_end` with the admin as actor; it does not count as the supervisor's login. A sticky banner with "Admin hisobiga qaytish" is shown the whole time.

**Student Telegram rebind.** An admin (own university) or a supervisor (students of internships with an open supervisor period for them) may create a one-time 24 h link for an ACTIVE student on the student page (`POST /students/{id}/telegram-rebind`, 10 per minute). Anyone else gets 404. Opening it moves the student to the new Telegram account and the old account loses access (ASSUMPTIONS A84). A student with a verified phone can also recover alone by sharing their own contact (A85); staff only see the result in the audit log and the supervisor's Telegram message.

**Holidays.** Only an admin adds or removes university days off in Settings (`/settings/holidays`); a supervisor gets 403 (A87).

**Two-factor sign-in.** Every admin and supervisor turns their own second factor on or off in Profile with the current password (A86). An admin may turn it off for a supervisor of the own university (`POST /supervisors/{id}/two-factor-reset`); nobody can read another user's secret. While impersonating, the supervisor's second factor is off limits like the password. A password change by anyone signs out the user's other sessions (A88).

## 3. Supervisor

Allowed, only inside open supervisor periods:

- List own internship groups and the students participating in them.
- Read those students’ profiles, assignments, today attendance, history, failed attempts, change requests, and manual corrections (§52).
- Approve or reject an `EXISTING_ORGANIZATION` change request for a student in scope.
- See `NEW_ORGANIZATION` requests for students in scope. Cannot set coordinates and cannot approve them (A24).
- Start a change request for a student in scope (§4.2). Same state machine as a student-started request.
- Dashboard counts and the group day list (§50, §51).
- CSV export of own scope only.

Not allowed:

- Another supervisor’s groups or students, including by editing the URL (§60, §122).
- Create organizations, set location or radius, create invites, edit academic structure, edit policies, read the global audit log, or correct attendance.
- Assign an organization unless the action is the approval transaction of an existing-organization change request, or an admin has given assignment rights.

**Assignment rights need a precise reading.** §21 says “Admin yoki vakolatli supervisor” may assign. The spec does not name the permission flag. **USE:** in MVP a supervisor may create an assignment only for a student who is already a participant in an internship they currently supervise, and only to an `ACTIVE` organization. They may not create organizations. This is the §21 phrase, limited by §4.2 scope. If you want supervisors to be view-only except for change-request approval, say so before Phase 2; that would narrow §21.

Bulk assign uses the same per-student rules and creates one assignment row each (§22).

## 4. Student

Allowed through Telegram, resolved from `telegram_user_id`:

- Complete onboarding through a valid invite.
- Read own profile, own current assignment, own attendance history.
- Check in and check out.
- Open or cancel own pending change request.
- Submit a new-organization description without coordinates.

Not allowed:

- Read or edit any other student.
- Create or edit an organization or its location.
- Approve their own request.
- Call a web URL with another student’s id. There is no student web API in MVP (A40). If a route is added later, it must ignore any student id in the body and use the authenticated profile.

## 5. Scope query

Supervisor student visibility:

```text
student_profiles
  join internship_participants
  join internship_supervisor_periods
    on open period (ends_on is null)
    and supervisor_profile_id = current supervisor
```

Attendance, assignments, and change requests use the same student set. A historical supervisor period does not grant access (A15).

Admin queries filter by `university_id` and then by the optional dashboard filters: faculty, program, course, group, supervisor, organization, date (§53).

## 6. IDOR matrix

| Caller | Attempt | Result |
| --- | --- | --- |
| Supervisor A | Open student of supervisor B | 404, no row in the payload |
| Supervisor A | Approve B’s change request | 404 |
| Student via any future HTTP id | Another student’s attendance | 403/404, own id only |
| Supervisor | Admin organization-create route | 403 |
| Student Telegram user with no profile | Check-in | “Access denied.” (§64) |
| Active student, no active assignment | Check-in | “Sizda hozir faol amaliyot biriktirilmagan.” (§64) |
| Inactive staff | Login | Rejected |

| Admin of university A | Any id from university B (internship, organization, supervisor, assignment, invite) | 404 |
| Supervisor | Assign a student who is not a participant of their open-period internship | Row refused, nothing written |
| Supervisor | Approve or reject a new-organization request | Approve refused, reject 403 |
| Replaced supervisor | Former group or student page | 404 from the replacement date (A50) |
| Supervisor | `/academic/students*`, `/settings`, `/supervisors/{id}`, `/organizations/{id}`, `PUT /internships/{id}`, `PUT /assignments/{id}` | 403 |
| Admin of university A | Student, course, group, organization status, internship dates, assignment dates of university B | 404, nothing written |
| Supervisor | `/my-students` | Only participants of internships with an open period; filters cannot widen it |
| Admin | `/my-students` | 403 (supervisor page) |
| Any user | More than 10 change requests per minute | 429 (A61) |
| Supervisor | `/attendance`, `/reports`, `/attendance/students/{id}` in scope | 200, own scope only |
| Supervisor | `/attendance/students/{id}` of another supervisor's student | 404 |
| Supervisor | `/attendance/policies`, corrections, close-session | 403 |
| Any user | Another user's export download | 404 |
| Any user | More than 5 exports per minute | 429 (A73) |
| Telegram | Webhook without or with a wrong secret | 404 when no secret is configured, otherwise 403; nothing written |

Policies are Laravel policy classes plus query scopes. Controllers do not reimplement ownership with a different condition. From Phase 2 the scope lives in `App\Services\Access\AccessScope`; every service starts from its `internships()` or `students()` builder. Supervisor pages never receive organization coordinates or radius.
