# Attendance rules

Web and Telegram call the same services. Phone time is never the attendance time. The product records that a verified event happened at a server time. It does not claim the student stayed on site between those events.

D3 and D4 are final. A disallowed second session that day is rejected. Minimum duration OFF plus one completed session is PRESENT. `AttendanceEvent` is the immutable fact, `AttendanceSession` is the pair, and the day status is computed. Those three are not one table.

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

Look up an ACTIVE policy with `scope_type = GROUP` and `scope_id` of the student's current group. If it exists, use that row entirely. Otherwise use the ACTIVE `UNIVERSITY` policy. If the university has none yet, the seeded default from A32 applies: check-in and check-out on, minimum duration off, multiple sessions allowed, location required, accuracy off, manual correction allowed.

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

Computed for a student and a local date. Not stored as the only attendance record.

1. Any OPEN session that date → show INCOMPLETE.
2. No COMPLETED session, and an INCOMPLETE session exists → INCOMPLETE.
3. No verified check-in, and at least one OUTSIDE_RADIUS attempt that date → LOCATION_REJECTED.
4. No verified check-in → ABSENT.
5. Otherwise apply D4 to the summed COMPLETED duration:
   - minimum off, or duration greater than or equal to the minimum → PRESENT
   - duration under the minimum → PARTIAL

Verified attendance is never auto-labeled ABSENT only because it was short.

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
