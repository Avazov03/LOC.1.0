<?php

namespace App\Services\Notifications;

use App\Jobs\SendTelegramNotification;
use App\Models\AttendanceSession;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\TelegramNotification;
use App\Telegram\BotText;
use Illuminate\Support\Facades\DB;

/**
 * A38: queued Telegram messages to a student. The unique key makes a repeated call a no-op, and the job is
 * dispatched only after the surrounding transaction commits, so a rolled-back decision never notifies anyone.
 */
class StudentNotifier
{
    public function assignmentCreated(InternshipAssignment $assignment): void
    {
        $assignment->loadMissing(['organization:id,name,address', 'student.university:id,timezone']);
        $tz = $assignment->student->university->timezone;

        $this->queue("assignment:{$assignment->id}:created", $assignment->student_profile_id, implode("\n", array_filter([
            '📌 Sizga amaliyot joyi biriktirildi.',
            '',
            '🏢 '.$assignment->organization->name,
            $assignment->organization->address ? '📍 '.$assignment->organization->address : null,
            '📅 '.$assignment->start_at->copy()->setTimezone($tz)->format('d.m.Y').' — '.$assignment->end_at->copy()->setTimezone($tz)->format('d.m.Y'),
            $assignment->status->value === 'ACTIVE'
                ? 'Amaliyot boshlandi. Davomat uchun «🟢 Amaliyotni boshlash» tugmasidan foydalaning.'
                : 'Amaliyot boshlanish kunida sizga eslatamiz.',
        ], fn ($line) => $line !== null)));
    }

    public function assignmentStarted(InternshipAssignment $assignment): void
    {
        $assignment->loadMissing('organization:id,name');

        $this->queue("assignment:{$assignment->id}:started", $assignment->student_profile_id,
            "🟢 Amaliyotingiz boshlandi: {$assignment->organization->name}.\nDavomat uchun «🟢 Amaliyotni boshlash» tugmasidan foydalaning.");
    }

    public function assignmentCancelled(InternshipAssignment $assignment): void
    {
        $assignment->loadMissing('organization:id,name');

        $this->queue("assignment:{$assignment->id}:cancelled", $assignment->student_profile_id,
            "ℹ️ {$assignment->organization->name} bo‘yicha amaliyot biriktiruvingiz bekor qilindi. Savollar bo‘lsa, rahbaringizga murojaat qiling.");
    }

    public function changeRequestDecided(InternshipChangeRequest $request): void
    {
        $status = $request->status->value;
        if (! in_array($status, ['APPROVED', 'REJECTED'], true)) {
            return;
        }
        $text = $status === 'APPROVED'
            ? '✅ Amaliyot joyini o‘zgartirish so‘rovingiz tasdiqlandi. Yangi joy ma’lumotlarini «📋 Mening amaliyotim» bo‘limida ko‘ring.'
            : '❌ Amaliyot joyini o‘zgartirish so‘rovingiz rad etildi.'.($request->review_note ? "\nIzoh: {$request->review_note}" : '');

        $this->queue("change_request:{$request->id}:{$status}", $request->student_profile_id, $text);
    }

    /**
     * @return bool false when this session was already reminded
     */
    public function checkoutReminder(AttendanceSession $session): bool
    {
        return $this->queue("checkout_reminder:{$session->id}", $session->student_profile_id, BotText::CHECKOUT_REMINDER);
    }

    private function queue(string $key, int $studentId, string $text): bool
    {
        $inserted = DB::table('telegram_notifications')->insertOrIgnore([
            'key' => $key,
            'student_profile_id' => $studentId,
            'text' => $text,
            'status' => 'PENDING',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted !== 1) {
            return false;
        }

        $id = TelegramNotification::query()->where('key', $key)->value('id');
        SendTelegramNotification::dispatch((int) $id)->afterCommit();

        return true;
    }
}
