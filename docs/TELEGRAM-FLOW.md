# Telegram flow

The bot is the student interface. Handlers parse updates and call application services. They do not contain attendance or assignment rules.

Production transport is an HTTPS webhook. Local development may poll. Token and webhook secret come from the environment, never from source.

## 1. Security of the endpoint

1. Require `X-Telegram-Bot-Api-Secret-Token` to match `TELEGRAM_WEBHOOK_SECRET`.
2. Rate-limit (A39).
3. Inside one database transaction, insert `telegram_processed_updates.update_id`.
4. If the insert conflicts, commit nothing else and return HTTP 200.
5. Run the handler. The business write shares that transaction.
6. After commit, send the reply.
7. Unexpected exceptions return a generic student sentence and log the technical detail. They do not return SQL text.

Generic failure sentence: "Bu amalni hozir bajarib bo‘lmadi. Bir necha soniyadan keyin qayta urinib ko‘ring."

## 2. Main menu

```text
Mening amaliyotim
Amaliyotni boshlash
Amaliyotni tugatish
Davomatim
Amaliyot joyini o‘zgartirish
Profilim
Yordam
```

The menu is shown after onboarding and after a flow finishes or is cancelled. Button labels in the product UI keep the emoji from the spec. The labels above are the actions.

## 3. Identity gate

Every action except `/start` with an invite token:

| Condition | Reply |
| --- | --- |
| No student for this telegram user id | Access denied. |
| Profile BLOCKED or INACTIVE | Access denied. |
| Action needs an active assignment and there is none | Sizda hozir faol amaliyot biriktirilmagan. |

## 4. Onboarding

```text
/start <token>
    → hash token
    → invite missing, CLOSED, or EXPIRED
         → reject. Already onboarded students are unchanged.
    → telegram user id already has a student profile
         → do not create another profile.
         → tell them the account is already registered.
    → else open conversation JOIN_NAME
         → "Siz {course} {group} amaliyotiga qo‘shilmoqdasiz."
JOIN_NAME        → first name
JOIN_SURNAME     → last name
JOIN_PHONE       → phone. Store the number. It is not the identity key.
JOIN_STUDENT_CODE → optional. Student may skip.
JOIN_CONFIRM     → summary with confirm / cancel buttons (A76)
    → on confirm, StudentOnboardingService in one transaction:
         user + student profile + group membership + internship participant
    → show the main menu
```

The invite supplies group, academic year, internship, and the supervisor snapshot. It does not prove civil identity and does not create an organization assignment.

Duplicate start for the same person does not create a second student.

Conversation context expires. An abandoned join does not leave a partial student. The profile is written only on the final confirm.

## 5. Check-in

```text
Amaliyotni boshlash
    → AttendanceService preconditions
         (active student, active assignment, period, policy, no open session)
    → if a precondition fails, reply and stop
    → state AWAIT_CHECKIN_LOCATION
    → "Davomatni tasdiqlash uchun hozirgi joylashuvingizni yuboring."
    → location message
         → AttendanceVerificationService
         → VERIFIED: event CHECK_IN, session OPEN, reply success
         → otherwise: event FAILED_CHECK_IN, no session, reply failure
```

Success reply:

```text
✅ Amaliyot boshlanishi qayd etildi.

Vaqt: 09:03
Joy: ABC Law Firm
Masofa: 42 m
```

Time is `occurred_at` rendered in the university timezone. Coordinates are not shown.

Outside radius:

```text
❌ Amaliyot joyidan tashqaridasiz.

Belgilangan joydan taxminan 180 metr uzoqdasiz.
Amaliyot joyiga yaqinlashib, qayta urinib ko‘ring.
```

Distance in that sentence is the stored meter value, rounded only for display.

Other failures:

| Result | Student-facing meaning |
| --- | --- |
| No location attached | Location yuborish kerak. |
| LOW_ACCURACY | Location is too imprecise. Ask them to send it again. |
| OUTSIDE_INTERNSHIP_PERIOD | Attendance is not accepted outside the internship period. |
| Duplicate open session | Sizda allaqachon faol attendance mavjud. |
| Policy check-in disabled | This action is not available. |
| Second session blocked by D3 | Another session is not allowed today. |
| Unexpected error | Generic failure sentence from section 1 |

A live-location follow-up update is not a new check-in and is not stored as a trail.

## 6. Check-out

```text
Amaliyotni tugatish
    → no OPEN session
         → "Sizda yopiladigan faol attendance mavjud emas."
    → policy or period failure → stop
    → state AWAIT_CHECKOUT_LOCATION
    → same location request sentence
    → VERIFIED: CHECK_OUT event, session COMPLETED
         duration is checkout minus check-in, both server timestamps
    → OUTSIDE_RADIUS: FAILED_CHECK_OUT event, session stays OPEN
```

The failure reply uses the same outside-radius pattern and does not say the day was closed.

## 7. Read-only actions

**Mening amaliyotim.** Current ACTIVE assignment: organization name, address, period, supervisor name. If none: no active placement yet. No other students. The map point is not sent to the student as a settable coordinate.

**Davomatim.** Own sessions: local date, check-in time, check-out time or an em dash, duration, day status. Failed attempts stay in the database for supervisors and can be omitted from this default list.

**Profilim.** Own name, phone, group, student code, status.

**Yordam.** What the seven buttons do, and that attendance needs a location at the moment of the action. The help text does not claim the student was on site all day, and it does not claim fake GPS is fully prevented.

## 8. Change internship place

```text
Amaliyot joyini o‘zgartirish
    → no current assignment
         → tell them there is no place to change. Stop.
    → existing PENDING request
         → show its status. Do not open a second one.
    → state CHANGE_REASON
    → state CHANGE_KIND: existing organization or new organization
EXISTING:
    → bot lists ACTIVE organization names; each button carries the list index, not the database id (A76)
    → a stale or out-of-range index answers "list changed" and writes nothing
    → service creates PENDING EXISTING_ORGANIZATION
NEW:
    → ask name, address text, contact name, contact phone
    → reject any coordinate or radius included in the message
    → PENDING NEW_ORGANIZATION
    → tell the student the place is not active until the university confirms it
```

Queued notifications: request approved or rejected, and assignment changed. The decision is made in the web transaction.

The student may cancel a PENDING request from this menu. That sets `CANCELLED`.

## 9. Conversation states

```text
IDLE
JOIN_NAME
JOIN_SURNAME
JOIN_PHONE
JOIN_STUDENT_CODE
JOIN_CONFIRM
AWAIT_CHECKIN_LOCATION
AWAIT_CHECKOUT_LOCATION
CHANGE_REASON
CHANGE_KIND
CHANGE_EXISTING_ORG
CHANGE_NEW_NAME
CHANGE_NEW_ADDRESS
CHANGE_NEW_CONTACT
```

A menu command from a non-idle state cancels the pending dialog and does not write attendance. A location that arrives with no waiting state is logged and ignored. A forwarded location or a venue is stored as a failed `INVALID_LOCATION` attempt (A74). Group chats and edited messages are ignored. Conversation state is a `telegram_conversations` row that expires after `TELEGRAM_CONVERSATION_TTL` minutes (default 30); IDLE has no row.

Rate limits (A73): 20 actions per minute per Telegram user, 6 attendance actions per minute, 5 invite joins per minute. A send failure is logged and never undoes the business write.

## 10. Idempotency

| Case | Result |
| --- | --- |
| Same update id twice | One processing, one attendance effect at most |
| Two different updates, parallel check-in | One OPEN session |
| Onboarding message replayed | One student |
