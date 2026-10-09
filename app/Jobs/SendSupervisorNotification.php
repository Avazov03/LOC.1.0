<?php

namespace App\Jobs;

use App\Enums\ActiveStatus;
use App\Models\SupervisorNotification;
use App\Telegram\Keyboard;
use App\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendSupervisorNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $notificationId) {}

    public function handle(TelegramClient $client): void
    {
        $notification = SupervisorNotification::query()->with('supervisor.user')->find($this->notificationId);
        if ($notification === null || in_array($notification->status, ['SENT', 'SKIPPED'], true)) {
            return;
        }

        $supervisor = $notification->supervisor;
        if ($supervisor?->telegram_user_id === null || $supervisor->user?->status !== ActiveStatus::Active) {
            $notification->forceFill(['status' => 'SKIPPED'])->save();

            return;
        }

        $notification->forceFill(['attempts' => $notification->attempts + 1])->save();
        $client->sendMessage((int) $supervisor->telegram_user_id, $notification->text, $this->markup($notification));
        $notification->forceFill(['status' => 'SENT', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        SupervisorNotification::query()->whereKey($this->notificationId)->where('status', '<>', 'SENT')->update([
            'status' => 'FAILED',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 500),
            'updated_at' => now(),
        ]);
    }

    /**
     * Digest buttons carry the notification id and a list position, never a student id.
     *
     * @return array<string, mixed>|null
     */
    private function markup(SupervisorNotification $notification): ?array
    {
        $students = $notification->payload['students'] ?? [];
        if ($students === []) {
            return null;
        }

        $rows = [];
        foreach ($students as $index => [, $name]) {
            $rows[] = [['text' => '✅ '.mb_substr((string) $name, 0, 40), 'callback_data' => "dg:{$notification->id}:{$index}"]];
        }
        if (count($students) > 1) {
            $rows[] = [['text' => '✅ Hammasi keldi', 'callback_data' => "dg:{$notification->id}:all"]];
        }

        return Keyboard::inline($rows);
    }
}
