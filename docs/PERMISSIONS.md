# Permissions

Backend enforcement only. A hidden menu item is not a control. Out-of-scope ids return **404** for supervisors and students, so existence of another person’s record is not revealed. Admin receives normal 404 only when the row does not exist.

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

Policies are Laravel policy classes plus query scopes. Controllers do not reimplement ownership with a different condition.
