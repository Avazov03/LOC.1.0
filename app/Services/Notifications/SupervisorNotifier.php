<?php

namespace App\Services\Notifications;

use App\Jobs\SendSupervisorNotification;
use App\Models\InternshipAssignment;
use App\Models\InternshipSupervisorPeriod;
use App\Models\StudentProfile;
use App\Models\SupervisorNotification;
use App\Models\SupervisorProfile;
use App\Services\Attendance\AttendanceOutcome;
use App\Telegram\BotText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Queued Telegram messages to supervisors who linked the bot. Same guarantees as StudentNotifier: a unique key makes
 * a repeat a no-op, and the job runs only after the surrounding transaction commits.
 */
class SupervisorNotifier
{
    /** Students listed with a button in one digest; Telegram keyboards stay usable below this. */
    public const DIGEST_BUTTONS = 30;

    /**
     * Check-in or check-out, sent to the supervisor currently running the student's internship.
     */
    public function attendance(AttendanceOutcome $outcome, StudentProfile $student, InternshipAssignment $assignment): void
    {
        if ($outcome->event === null || ! in_array($outcome->code, [AttendanceOutcome::CHECKED_IN, AttendanceOutcome::CHECKED_OUT], true)) {
            return;
        }
        $supervisor = $this->currentSupervisor($assignment->internship_id);
        if ($supervisor === null || ! $supervisor->notify_check_events) {
            return;
        }

        $data = $outcome->data;
        $in = $outcome->code === AttendanceOutcome::CHECKED_IN;
        $student->loadMissing('currentGroup:id,name');
        $text = BotText::lines([
            ($in ? '🟢 ' : '🔴 ').$student->fullName().($in ? ' amaliyotga keldi' : ' amaliyotdan ketdi'),
            $student->currentGroup ? '👥 Guruh: '.$student->currentGroup->name : null,
            '🕘 '.$data['time'].' · '.$data['organization'],
            ! $in ? '⏱ '.BotText::duration((int) $data['duration_seconds']) : null,
            $data['distance'] !== null ? '📏 '.$data['distance'].' m' : null,
        ]);

        $this->queue("event:{$outcome->event->id}", $supervisor->id, $text);
    }

    /**
     * The daily list of students with nothing recorded today, with a "Keldi" button each.
     *
     * @param  list<array{id: int, name: string}>  $students
     */
    public function digest(SupervisorProfile $supervisor, string $date, array $students, ?string $url): void
    {
        $key = "digest:{$supervisor->id}:{$date}";
        if ($students === []) {
            // Recorded as SKIPPED so the scheduler does not recompute this supervisor again today.
            $this->queue($key, $supervisor->id, '', null, 'SKIPPED');

            return;
        }

        $lines = ['📋 '.CarbonImmutable::parse($date)->format('d.m.Y').' — davomati belgilanmagan talabalar: '.count($students), ''];
        foreach ($students as $index => $student) {
            $lines[] = ($index + 1).'. '.$student['name'];
        }
        $lines[] = '';
        $lines[] = count($students) > self::DIGEST_BUTTONS
            ? 'Birinchi '.self::DIGEST_BUTTONS.' ta talaba uchun tugma chiqdi; qolganlarini saytda belgilang.'
            : 'Talaba kelgan bo‘lsa, uning nomi yozilgan «✅» tugmasini bosing.';
        if ($url) {
            $lines[] = 'Sayt: '.$url;
        }

        $this->queue($key, $supervisor->id, implode("\n", $lines), [
            'date' => $date,
            'students' => array_map(fn (array $student) => [$student['id'], $student['name']], array_slice($students, 0, self::DIGEST_BUTTONS)),
        ]);
    }

    public function currentSupervisor(int $internshipId): ?SupervisorProfile
    {
        $id = InternshipSupervisorPeriod::query()
            ->where('internship_id', $internshipId)
            ->whereNull('ends_on')
            ->value('supervisor_profile_id');

        return $id === null ? null : SupervisorProfile::query()->whereKey($id)->whereNotNull('telegram_user_id')->first();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function queue(string $key, int $supervisorId, string $text, ?array $payload = null, string $status = 'PENDING'): void
    {
        $inserted = DB::table('supervisor_notifications')->insertOrIgnore([
            'key' => $key,
            'supervisor_profile_id' => $supervisorId,
            'text' => $text,
            'payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted !== 1 || $status !== 'PENDING') {
            return;
        }

        $id = SupervisorNotification::query()->where('key', $key)->value('id');
        SendSupervisorNotification::dispatch((int) $id)->afterCommit();
    }
}
