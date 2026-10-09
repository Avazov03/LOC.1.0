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
JOIN_PHONE       → phone, only from the "📱 Raqamni yuborish" button with contact.user_id = sender (A83).
                   Typed numbers and other people's contacts are refused.
                   Same phone already registered in this university → PHONE_REGISTERED, dialog closed,
                   "ask your supervisor for a Telegram rebind link".
JOIN_STUDENT_CODE → optional. Student may skip.
JOIN_CONFIRM     → summary with confirm / cancel buttons (A76)
    → on confirm, StudentOnboardingService in one transaction:
         user + student profile + group membership + internship participant
    → show the main menu
```

The invite supplies group, academic year, internship, and the supervisor snapshot. It does not prove civil identity and does not create an organization assignment.

Duplicate start for the same person does not create a second student. A new Telegram account of the same person is refused by phone; the student is moved instead:

```text
/start r_<token>   (created on the student page by an admin or the current supervisor, 24 h, one use)
    → token hash matches, not expired, student ACTIVE
    → this Telegram id belongs to another student → refuse
    → else student.telegram_user_id = sender; old and new conversations cleared
    → new account: "✅ Telegram hisobingiz talaba profilingizga ulandi"
    → old account: notice that the profile moved and it can no longer record attendance
```

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
| MAP_LOCATION | A point picked on the map is not accepted; turn GPS on and press «📍 Joylashuvni yuborish». |
| STALE_LOCATION | The location arrived late; press the button again. |
| REUSED_LOCATION | This exact point was sent before (saved or someone else's); send the current location on site. |
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
| Digest button pressed twice | One active PRESENT mark |
| Scheduler tick repeated after the reminder time | One reminder per open session, one digest per supervisor per day |

## 11. Work days

On a day outside the student's work days (group mask or personal override), "Amaliyotni boshlash" replies:

```text
📅 Bugun sizning amaliyot kuningiz emas.
Amaliyot kunlaringiz: Du, Chor, Ju.
Bu kun «Kelmadi» deb hisoblanmaydi.
```

No event is written. "Mening amaliyotim" shows "🗓 Amaliyot kunlari". "Davomatim" shows each day with its icon (✅ ⏳ 📍 📝 ❌) and "(rahbar belgiladi)" for supervisor marks. After the university reminder time (default 18:00) a student whose session from today is still open gets one reminder to check out.

## 12. Supervisor in the bot

1. The supervisor opens "Profil va Telegram" on the web (or an admin opens the supervisor page) and creates a link `https://t.me/<bot>?start=s_<token>`. It is valid 24 hours, single-use, and only its hash is stored. Link attempts share the join rate limit.
2. `/start s_<token>` links that Telegram account to the supervisor. A Telegram account linked to another supervisor is moved; a student account stays a student.
3. A linked supervisor (not a student, not in onboarding) gets a short help text for any message; there is no student menu.
4. Messages the supervisor receives:
   - "🟢 {F.I.Sh.} amaliyotga keldi" / "🔴 … amaliyotdan ketdi" with group, time, organization, duration and distance, for students of internships they currently supervise (can be turned off in the profile);
   - once a day after the reminder time, the list of today's expected students who are ABSENT or LOCATION_REJECTED, with a "✅ name" button per student and "✅ Hammasi keldi".
5. A button press marks that date PRESENT through the same rules as the web (scope, 7-day window, assignment). Callback data is `dg:{digestId}:{index|all}`; a list that is stale or belongs to another supervisor is refused.
6. Unlinking (by the supervisor or an admin) stops all messages immediately.
