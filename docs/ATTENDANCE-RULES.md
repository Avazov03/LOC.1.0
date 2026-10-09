# Attendance rules

Web and Telegram call the same services. Phone time is never the attendance time. The product records that a verified event happened at a server time. It does not claim the student stayed on site between those events.

D3 and D4 are final. A disallowed second session that day is rejected. Any completed session is PRESENT; there is no minimum duration. `AttendanceEvent` is the immutable fact, `AttendanceSession` is the pair, and the day status is computed. Those three are not one table.

## 1. Check-in order

The order is fixed:

1. Identify the Telegram user.
2. Load the ACTIVE student. Otherwise stop: access denied.
3. Load the single ACTIVE assignment. Otherwise stop: no active placement.
4. Reject when server "now" is outside the internship period and outside the assignment range. No grace period.
5. Load the organization. It must be the assigned one. Inactive organization: stop.
6. Load `geography(Point, 4326)` and `radius_meters`.
7. Require a student location for this action. If missing: "Location yuborish kerak."
8. Reject latitude or longitude outside world bounds: `INVALID_LOCATION`.
9. If policy `accuracy_threshold_meters` is set and reported accuracy is worse: `LOW_ACCURACY`, store a failed event, ask for a new location. If the threshold is null, skip this step.
10. `ST_Distance` for the stored meter value. `ST_DWithin(student, organization, radius_meters)` for the decision.
11. Distance greater than radius: `OUTSIDE_RADIUS`.
12. Resolve policy (section 4). If check-in is disabled, stop.
13. If an OPEN session exists: `DUPLICATE_ACTION`. Do not open another.
14. If D3 applies and this local date already has a session while multiple sessions are disallowed: stop.
15. Insert immutable `CHECK_IN` / `VERIFIED` with snapshots.
16. Insert `attendance_sessions` `OPEN`.
17. Recompute the day status for the response.
18. Reply with the success sentence.

Steps 13–16 run in one transaction after locking the student row. The open-session partial unique index is the backstop.

## 2. Check-out order

1. Same identity, assignment, and period checks.
2. No OPEN session: "Sizda yopiladigan faol attendance mavjud emas."
3. If check-out is disabled by policy, stop.
4. Same location checks as check-in.
5. Verified: insert `CHECK_OUT` / `VERIFIED`, set session `COMPLETED`, `closed_at` from that event, `duration_seconds` = difference of the two server timestamps.
6. Outside radius: insert `FAILED_CHECK_OUT` / `OUTSIDE_RADIUS`. Leave the session `OPEN`. Do not invent a close time.

## 3. Event mapping

| Situation | event_type | verification_status | Session |
| --- | --- | --- | --- |
| Check-in inside radius | CHECK_IN | VERIFIED | OPEN created |
| Check-out inside radius | CHECK_OUT | VERIFIED | OPEN becomes COMPLETED |
| Check-in outside radius | FAILED_CHECK_IN | OUTSIDE_RADIUS | unchanged |
| Check-out outside radius | FAILED_CHECK_OUT | OUTSIDE_RADIUS | stays OPEN |
| Bad coordinates | FAILED_CHECK_IN or FAILED_CHECK_OUT | INVALID_LOCATION | unchanged |
| Accuracy over threshold | FAILED_* | LOW_ACCURACY | unchanged |
| No assignment | FAILED_CHECK_IN | NO_ASSIGNMENT | unchanged |
| Outside period | FAILED_CHECK_IN | OUTSIDE_INTERNSHIP_PERIOD | unchanged |
| Admin correction | MANUAL_CORRECTION | VERIFIED | session adjusted by new events, originals kept |
| Day rolled without checkout | SYSTEM_ADJUSTMENT | VERIFIED is not used | OPEN becomes INCOMPLETE |

`LOCATION_REJECTED` is a day status, not an event type. The event stores `OUTSIDE_RADIUS`.

Duplicate webhook and duplicate button press do not insert a second verified event. A genuine failed location attempt does (A26, A27).

## 4. Policy

Look up an ACTIVE policy with `scope_type = GROUP` and `scope_id` of the student's current group. If it exists, use that row entirely. Otherwise use the ACTIVE `UNIVERSITY` policy. If the university has none yet, the seeded default from A32 applies: check-in and check-out on, multiple sessions allowed, location required, accuracy off, manual correction allowed.

`location_required = false` is an explicit admin override. MVP still ships the flag because the contract lists it. Turning it off is audited. The student flow then skips the geofence steps and records `verification_status = VERIFIED` with null distance. This is allowed only when an admin set the flag. The default remains true.

## 5. Boundary

| Distance | Radius | ST_DWithin | Result |
| --- | --- | --- | --- |
| 99 m | 100 m | true | VERIFIED |
| 100 m | 100 m | true | VERIFIED |
| 101 m | 100 m | false | OUTSIDE_RADIUS |

The decision function is `ST_DWithin`, not a comparison of a rounded `distance_meters`. Tests run on PostGIS.

Radius stored on the organization is an integer from 100 to 500. The event copies it into `radius_snapshot_meters`. Later radius edits do not change that column.

## 6. Snapshots

Each event copies the organization latitude, longitude, and radius used for that check. Verification is not replayed against the live organization row. Assignment changes do not rewrite old events.

## 7. Sessions and duration

One OPEN session per student. Several COMPLETED sessions on one local date are allowed when policy says so. Total duration is the sum of `duration_seconds` of COMPLETED sessions that date. Incomplete sessions add zero.

Example that policy allows:

```text
09:00–11:00 and 12:00–14:00 → 4 hours
```

## 8. Day status

Computed for a student and a local date by one SQL expression (`AttendanceDayQuery::STATUS_SQL`) shared by the web, reports, CSV, dashboard and bot. Not stored as the only attendance record. First matching rule wins:

1. Active supervisor/admin mark `PRESENT` → PRESENT.
2. Any OPEN session that date → INCOMPLETE.
3. At least one COMPLETED session → PRESENT, whatever its length.
4. Active mark `EXCUSED` → EXCUSED ("Sababli").
5. An INCOMPLETE session exists → INCOMPLETE.
6. At least one OUTSIDE_RADIUS attempt that date → LOCATION_REJECTED.
7. The date is an expected work day, or a failed attempt exists → ABSENT.
8. Otherwise no status (not a work day, outside the assignment).

There is no minimum duration and no PARTIAL status (decision D4, revised). The summed COMPLETED duration is shown next to "Keldi" and in reports as "Ishlagan vaqt" / "Jami vaqt". The old `minimum_duration_minutes` column stays for history but is ignored.

Verified attendance is never auto-labeled ABSENT.

## 8a. Work days

`internships.work_days` is an ISO weekday bitmask (bit 0 = Monday … bit 6 = Sunday; 127 every day, 63 Mon–Sat, 31 Mon–Fri, 21 odd days Mon/Wed/Fri, 42 even days Tue/Thu/Sat, or any custom set). `internship_participants.work_days` overrides it for one student; null means "use the group". The effective mask is `COALESCE(participant, internship)`.

- A day is *expected* only when an ACTIVE or ENDED assignment covers it and its weekday is in the effective mask. Non-work days are never ABSENT and are excluded from "Kutilgan".
- Check-in on a non-work day is refused by the bot (`NOT_WORK_DAY`) before any event is written. Check-out of an already open session is always allowed.
- Only admins change the group mask; admins and the current supervisor change a student's override. Both are audited. Changing a mask re-evaluates past days too, because status is computed.
- Day ranges go up to 366 days (`MAX_RANGE_DAYS`), so internships of three months or a full year are reported in one range. PostgreSQL builds the range with `generate_series`.

## 8b. Supervisor and admin day marks

`attendance_day_marks` holds human decisions; attendance events are never edited.

- `PRESENT` ("✅ Keldi"): one click, optional note — for a student who worked elsewhere that day or could not send location.
- `EXCUSED` ("Sababli"): note required. The day is not ABSENT.
- One active mark per student and date (partial unique index). A new mark revokes the previous one; revoking sets `revoked_at`/`revoked_by`. Every mark and revoke is audited with the note.
- Limits: no future dates; an ACTIVE or ENDED assignment must cover the date; a supervisor only for students in scope and only the last 7 days; an admin any past day.
- Marks are made on the web (attendance list, student page, dashboard "Bugun belgilanmaganlar") or from the supervisor's Telegram digest.

## 8c. Supervisor Telegram and daily reminders

- A supervisor links Telegram from "Profil va Telegram" (or an admin creates the link). The deep link `/start s_<token>` is valid 24 hours and single-use; only its SHA-256 hash is stored.
- After each verified check-in or check-out, the current supervisor of that internship gets a message (name, group, time, organization, duration on check-out, distance), unless they turned it off. Messages are queued after commit with a unique key per event.
- `attendance:daily-reminders` runs every 5 minutes. Once the university's local time passes `universities.reminder_time` (default 18:00, set in Sozlamalar):
  - each student whose session from today is still OPEN gets one reminder to check out;
  - each linked supervisor gets one digest of today's expected students with ABSENT or LOCATION_REJECTED status, with a "✅ name" button per student (first 30) and "✅ Hammasi keldi". Buttons carry the digest id and list position, never a student id, and are re-checked through the same mark rules. An empty digest is recorded as skipped and not sent.

`SUSPICIOUS` is never returned by this calculator.

After local midnight, a scheduler sets yesterday's OPEN sessions to INCOMPLETE, writes `SYSTEM_ADJUSTMENT`, and leaves `closed_at` null.

## 9. Manual correction

Admin only, and only when the resolved policy allows it.

The transaction:

1. Read the current events and session. That is the "before" audit payload.
2. Insert a `MANUAL_CORRECTION` event. Do not update old events.
3. Create or attach a session if the correction is a check-in or check-out the admin is adding.
4. Write `audit_logs` with actor, reason, before, after, timestamp, and IP.

A correction that adds a 09:04 check-in where none existed leaves "there was no original check-in" visible in audit.

## 10. What is stored on a rejected attempt

Student, assignment if any, event type, verification status, raw coordinates, accuracy, distance, organization snapshot, radius snapshot, server `occurred_at`, source `TELEGRAM`, telegram update id.

Supervisors in scope can see these on the student detail. The student bot's default history may hide them.
