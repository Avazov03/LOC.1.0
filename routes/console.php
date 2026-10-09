<?php

use App\Services\Admin\BootstrapAdminService;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\DailyReminderService;
use App\Services\Internships\InviteService;
use App\Telegram\ConversationStore;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramClient;
use App\Telegram\UpdateProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

$allowedUpdates = ['message', 'callback_query'];

Artisan::command('invites:expire', function (InviteService $invites) {
    $this->info($invites->expireDue().' ta havola muddati tugadi.');
})->purpose('Mark ACTIVE invites past expires_at as EXPIRED');

Artisan::command('assignments:activate-due', function (InternshipAssignmentService $assignments) {
    $this->info($assignments->activateDue().' ta biriktirish faollashtirildi.');
})->purpose('Activate PENDING assignments whose start_at has arrived');

Artisan::command('attendance:close-stale', function (AttendanceService $attendance) {
    $this->info($attendance->closeStaleSessions().' ta ochiq sessiya INCOMPLETE qilindi.');
})->purpose('A30: mark OPEN sessions from an earlier local date as INCOMPLETE');

Artisan::command('attendance:daily-reminders', function (DailyReminderService $reminders) {
    $result = $reminders->run();
    $this->info("{$result['reminders']} ta talabaga eslatma, {$result['digests']} ta rahbarga kunlik ro‘yxat.");
})->purpose('After the university reminder time: check-out reminders to students, unmarked-student digests to supervisors');

Artisan::command('telegram:prune', function (ConversationStore $conversations) {
    $updates = DB::table('telegram_processed_updates')->where('processed_at', '<', now()->subDays(14))->delete();
    $dialogs = $conversations->pruneExpired();
    $this->info("{$updates} ta eski update, {$dialogs} ta tugagan dialog o‘chirildi.");
})->purpose('Drop processed update ids older than 14 days and expired dialogs');

Artisan::command('admin:ensure {--login=} {--name=}', function (BootstrapAdminService $admins) {
    try {
        $user = $admins->ensure($this->option('login') ?: null, null, $this->option('name') ?: null);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
    $this->info("Administrator tayyor: {$user->login}");

    return 0;
})->purpose('Create or refresh the first ADMIN from ADMIN_LOGIN / ADMIN_PASSWORD in .env');

Artisan::command('telegram:webhook {url? : Public HTTPS URL of /telegram/webhook} {--delete} {--info}', function (TelegramClient $client) use ($allowedUpdates) {
    try {
        if ($this->option('info')) {
            $me = $client->getMe();
            $info = $client->getWebhookInfo();
            $this->line('Bot: @'.($me['username'] ?? '?'));
            $this->line('Webhook: '.(($info['url'] ?? '') ?: '—').'  pending: '.($info['pending_update_count'] ?? 0));
            if (! empty($info['last_error_message'])) {
                $this->warn('Last error: '.$info['last_error_message']);
            }

            return 0;
        }
        if ($this->option('delete')) {
            $client->deleteWebhook();
            $this->info('Webhook o‘chirildi.');

            return 0;
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if (strlen($secret) < 16) {
            $this->error('TELEGRAM_WEBHOOK_SECRET .env faylida kamida 16 belgi bo‘lishi kerak.');

            return 1;
        }
        $url = (string) ($this->argument('url') ?: rtrim((string) config('app.url'), '/').'/telegram/webhook');
        if (! str_starts_with($url, 'https://')) {
            $this->error('Telegram faqat HTTPS webhook manzilini qabul qiladi.');

            return 1;
        }
        $client->setWebhook($url, $secret, $allowedUpdates);
        $this->info("Webhook o‘rnatildi: {$url}");

        return 0;
    } catch (TelegramApiException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Set, inspect or delete the Telegram webhook');

Artisan::command('telegram:poll {--once : Process one batch and exit}', function (TelegramClient $client, UpdateProcessor $processor) use ($allowedUpdates) {
    // Local development transport (TELEGRAM-FLOW intro). Production uses the webhook.
    try {
        $client->deleteWebhook();
        $me = $client->getMe();
    } catch (TelegramApiException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
    $this->info('Polling @'.($me['username'] ?? '?').' … (Ctrl+C to stop)');

    $offset = 0;
    do {
        try {
            $updates = $client->getUpdates($offset, $this->option('once') ? 0 : 25, $allowedUpdates);
        } catch (TelegramApiException $exception) {
            $this->warn($exception->getMessage());
            sleep(3);

            continue;
        }
        foreach ($updates as $update) {
            $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
            $handled = $processor->process($update);
            $this->line(now()->format('H:i:s').' update '.($update['update_id'] ?? '?').($handled ? '' : ' (skipped)'));
        }
    } while (! $this->option('once'));

    return 0;
})->purpose('Long-poll the Bot API locally and feed updates to the same processor as the webhook');

Schedule::command('invites:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('assignments:activate-due')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('attendance:close-stale')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('attendance:daily-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('telegram:prune')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
