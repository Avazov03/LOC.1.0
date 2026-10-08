# State machines

Transitions not listed here are refused in the service and, where practical, by a database constraint or trigger. Rows are not deleted to “go back”.

## 1. Invite link (§9, §135)

```text
ACTIVE ──close──► CLOSED
ACTIVE ──past expires_at──► EXPIRED
```

- `CLOSED` and `EXPIRED` reject new onboarding.
- Students who already joined stay (§9).
- No path back to `ACTIVE` in MVP. Admin creates a new invite.
- A row still marked `ACTIVE` whose `expires_at` has passed behaves as `EXPIRED` (A18).

## 2. Student profile (§13)

```text
ACTIVE ──admin──► INACTIVE
ACTIVE ──admin──► BLOCKED
INACTIVE ──admin──► ACTIVE
BLOCKED ──admin──► ACTIVE
```

`BLOCKED` and `INACTIVE` cannot check in or start a second profile with the same Telegram id.

## 3. Staff user

```text
ACTIVE ──admin──► INACTIVE
INACTIVE ──admin──► ACTIVE
```

`INACTIVE` cannot log in and cannot be selected on a new invite (§14).

## 4. Organization (§134)

```text
ACTIVE ──admin──► INACTIVE
INACTIVE ──admin──► ACTIVE
```

`INACTIVE` cannot receive a new assignment. Existing historical assignments stay.

## 5. Supervisor period (§58)

```text
open (ends_on is null) ──replace──► closed (ends_on set)
new row opened for the new supervisor
```

Both writes are one transaction with an audit row. Periods do not overlap.

## 6. Assignment (§23, §132)

```text
PENDING ──activate──► ACTIVE ──end──► ENDED
   │
   └──cancel──► CANCELLED
```

- Direct delete of `ACTIVE` is forbidden.
- `ENDED` and `CANCELLED` are terminal.
- `ACTIVE` → `CANCELLED` is not the normal path. Ending a real placement is `ENDED`. `CANCELLED` is for a placement that should not have been in force.
- At most one `ACTIVE` per student.
- **D2:** at most one `PENDING` or `ACTIVE` per student, and their time ranges cannot overlap.
- Organization change approval: old `ACTIVE` → `ENDED`, new row `PENDING` then `ACTIVE`, request → `APPROVED`, audit, one transaction (§102). If any step fails, none remain written.
- **A25:** if `start_at` is already in the past or present when an admin assigns, activation happens in that same transaction. If `start_at` is in the future, the row stays `PENDING` until `start_at`.

## 7. Change request (§80, §133)

```text
PENDING ─┬─ APPROVED
         ├─ REJECTED
         └─ CANCELLED
```

- `APPROVED` is terminal. A second approval is refused.
- `REJECTED` and `CANCELLED` are terminal.
- `CANCELLED` is the student withdrawing a pending request, or an admin withdrawing it (A24).
- `REJECTED` is a reviewer saying no.
- Existing-organization approval performs the assignment transaction.
- New-organization approval is allowed only after an admin has created an `ACTIVE` organization and the assignment is created in the same transaction. The request cannot jump to `APPROVED` with no assignment.

## 8. Group membership (D1)

```text
ACTIVE ──admin moves student / new year──► ENDED
new ACTIVE membership inserted
```

One `ACTIVE` membership per student.

## 9. Attendance session (§40–§42)

```text
verified CHECK_IN ──► OPEN
OPEN + verified CHECK_OUT ──► COMPLETED  (duration = checkout - checkin, server times)
OPEN + local day ended with no checkout ──► INCOMPLETE
```

- The system never writes a guessed checkout time.
- A rejected checkout leaves the session `OPEN` and stores a failed event.
- A second verified check-in while `OPEN` is refused (§62).
- Check-out with no `OPEN` session is refused (§63).
- **D3:** if policy disallows multiple sessions, a new check-in on a local date that already has a session is refused.
- Partial unique index on `OPEN` plus a student row lock is the concurrency control (§103, §125).

## 10. Attendance event types (§39)

```text
CHECK_IN
CHECK_OUT
FAILED_CHECK_IN
FAILED_CHECK_OUT
MANUAL_CORRECTION
SYSTEM_ADJUSTMENT
```

`LOCATION_REJECTED` in §39 is the verification outcome, not a second event type. It is stored in `verification_status` as `OUTSIDE_RADIUS` (see `ATTENDANCE-RULES.md`). Events are insert-only.

`SYSTEM_ADJUSTMENT` is reserved for the midnight `OPEN` → `INCOMPLETE` marker if we need an event alongside the session status change. The session status change itself is not an invented checkout. Prefer updating the session status and writing an audit/system event that says the day closed without checkout. That event’s type is `SYSTEM_ADJUSTMENT`. It has no fake checkout timestamp in `closed_at`.

## 11. Day status

Day status is not a stored state machine. It is derived. See `ATTENDANCE-RULES.md` and D4. `SUSPICIOUS` is in the enum and has no automatic transition (A31).

## 12. Telegram conversation

Dialog state only. Legal values are listed in `TELEGRAM-FLOW.md`. Clearing or replacing a conversation never deletes attendance events.
