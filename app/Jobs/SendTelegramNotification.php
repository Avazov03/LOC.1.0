<?php

namespace App\Jobs;

use App\Enums\StudentStatus;
use App\Models\TelegramNotification;
use App\Telegram\Keyboard;
use App\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $notificationId) {}

    public function handle(TelegramClient $client): void
    {
        $notification = TelegramNotification::query()->with('student')->find($this->notificationId);
        if ($notification === null || $notification->status === 'SENT' || $notification->status === 'SKIPPED') {
            return;
        }

        $student = $notification->student;
        if ($student === null || $student->status !== StudentStatus::Active || $student->telegram_user_id === null) {
            $notification->forceFill(['status' => 'SKIPPED'])->save();

            return;
        }

        $notification->forceFill(['attempts' => $notification->attempts + 1])->save();
        $client->sendMessage((int) $student->telegram_user_id, $notification->text, Keyboard::menu());
        $notification->forceFill(['status' => 'SENT', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        TelegramNotification::query()->whereKey($this->notificationId)->where('status', '<>', 'SENT')->update([
            'status' => 'FAILED',
            'error' => mb_substr((string) $exception?->getMessage(), 0, 500),
            'updated_at' => now(),
        ]);
    }
}
