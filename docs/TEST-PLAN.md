# Test plan

A feature is not done because a page renders. The tests below are the acceptance list from the spec and the contract. Geography, exclusion constraints, and webhook tests run against PostgreSQL with PostGIS. SQLite is not a substitute for those tests.

PHPUnit 12, or Pest if the Laravel 13 starter kit already installs it. Either way the cases below exist.

## 1. Unit

| Case | Expect |
| --- | --- |
| Distance 99 m, radius 100 m | pass |
| Distance 100 m, radius 100 m | pass |
| Distance 101 m, radius 100 m | fail |
| Radius 99 and 501 | rejected by validation and by the check constraint |
| Minimum duration off | day status PRESENT when a completed session exists (D4) |
| Minimum 4 h, actual 2 h completed | PARTIAL, not ABSENT |
| No verified check-in | ABSENT |
| No verified check-in, radius rejection exists | LOCATION_REJECTED |
| Open session | day display INCOMPLETE |
| 09:00–11:00 and 12:00–14:00 | 4 h total |
| Policy group row present | group row wins as a whole |
| Policy group row absent | university row is used |
| Accuracy null threshold | accuracy is stored and not rejected |
| Accuracy worse than a set threshold | LOW_ACCURACY |
| Assignment overlap | refused |
| Change request second approve | refused |
| SUSPICIOUS calculator | never returns SUSPICIOUS |

Boundary tests must build real geography points and call PostGIS, not a PHP haversine copy. A PHP distance helper may exist only as a non-authoritative display aid and must not be what the test treats as the decision.

## 2. Feature

| Flow | Expect |
| --- | --- |
| Admin creates faculty → program → year → course → group | rows linked as in the ERD |
| Supervisor login | admin menu routes return 403 |
| Admin creates supervisor, internship, invite | token hash stored, raw token not in the database |
| Closed invite onboarding | no new student |
| Expired invite onboarding | no new student |
| Same Telegram user joins twice | one student |
| Assign one student | bot-facing read model shows that organization |
| Bulk assign four students | four assignment rows |
| Assign to inactive organization | refused |
| Second active assignment | refused, first remains |
| Approve existing-organization change | old ENDED, new ACTIVE, request APPROVED, one transaction. Forced failure leaves the old assignment ACTIVE |
| New organization request | no organization row until admin submits the real location |
| Student payload with latitude | rejected |
| Check-in 50 m vs 100 m radius | CHECK_IN VERIFIED and an OPEN session |
| Check-in 1 km | FAILED event, no session |
| Check-in then check-out | duration matches server timestamps |
| Check-out outside radius | check-in remains, session stays OPEN |
| Check-out with no session | no event that completes a session |
| Missing location | no verified event |
| Organization move after an event | old snapshot unchanged, new event uses the new point |
| Radius 100 then 300 | old event snapshot 100, new event snapshot 300 |
| Admin correction | new event, reason, actor, original state still readable, audit row |
| Supervisor A opens B’s student | 404 and no student fields in the body |
| Student scope | no route returns another student’s attendance |
| Inactive supervisor | cannot log in and is absent from the invite picker |

## 3. Telegram integration

| Case | Expect |
| --- | --- |
| Wrong secret header | rejected, no write |
| Valid update | one student-facing result |
| Same update id delivered twice | one event |
| Unknown telegram user check-in | access denied, no event |
| Location message | reaches AttendanceService |
| Non-location message while a location is required | no verified event |
| Menu command during onboarding | no partial student row |

## 4. Concurrency

Two check-in transactions for one student at the same time: exactly one OPEN session. The test should use overlapping transactions or a lock, not two sequential calls.

Two assignment activations for one student: one ACTIVE.

## 5. Security

IDOR cases in section 2. Role bypass on each admin route. Manual correction as supervisor: forbidden. Webhook without secret: forbidden. Audit row contains actor and not the bot token.

## 6. Seed scenarios used by tests and local demo

- One university, two faculties, two programs, one academic year, more than one course, more than one group.
- More than one supervisor and more than one organization.
- One student at one organization.
- Several students at the same organization.
- One group whose students are at different organizations.
- At least one verified session and one rejected attempt.

Seed names are fictional.

## 7. Not claimed in MVP tests

- Load test of 1,000 students until Phase 7. Phase 4 and 6 only check that the queries are indexed and paginated.
- Fake-GPS defeat. Tests assert the signals are stored, not that spoofing is detected.
- Offline replay. No attendance row appears without a request.
- HEMIS, QR, continuous tracking: no tests because the features are absent. A test may assert the routes do not exist.
