<?php

namespace App\Telegram;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * TELEGRAM-FLOW §1: record update_id and run the handler in one transaction, then reply after commit.
 * A replayed update_id does nothing (§104, A27). Webhook and local polling share this class.
 */
class UpdateProcessor
{
    /** A39 reading: bot actions per Telegram user per minute (A73). */
    public const USER_ACTIONS_PER_MINUTE = 20;

    public function __construct(
        private readonly BotHandler $handler,
        private readonly TelegramClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return bool false when the update was a replay or carried no update_id
     */
    public function process(array $raw): bool
    {
        $updateId = $raw['update_id'] ?? null;
        if (! is_int($updateId)) {
            return false;
        }
        $update = Update::parse($raw);

        try {
            $replies = DB::transaction(function () use ($updateId, $update) {
                if (! $this->claim($updateId, $update)) {
                    return null;
                }
                if ($update === null) {
                    return [];
                }

                $key = 'tg-user:'.$update->userId;
                if (RateLimiter::tooManyAttempts($key, self::USER_ACTIONS_PER_MINUTE)) {
                    // Tell the student once per window, then stay silent.
                    return RateLimiter::hit($key, 60) === self::USER_ACTIONS_PER_MINUTE + 1
                        ? [new Reply($update->chatId, BotText::RATE_LIMITED)]
                        : [];
                }
                RateLimiter::hit($key, 60);

                return $this->handler->handle($update);
            });
        } catch (Throwable $exception) {
            // Technical detail goes to the log; the student gets the generic sentence, never SQL or a stack trace.
            report($exception);
            $this->claim($updateId, $update);
            $replies = $update !== null ? [new Reply($update->chatId, BotText::GENERIC_FAILURE)] : [];
        }

        if ($replies === null) {
            return false;
        }

        if ($update?->callbackId) {
            $this->safely(fn () => $this->client->answerCallbackQuery($update->callbackId));
        }
        foreach ($replies as $reply) {
            $this->safely(fn () => $this->client->sendMessage($reply->chatId, $reply->text, $reply->markup));
        }

        return true;
    }

    private function claim(int $updateId, ?Update $update): bool
    {
        $kind = match (true) {
            $update === null => 'ignored',
            $update->callbackId !== null => 'callback',
            $update->location !== null => 'location',
            default => 'message',
        };

        return DB::table('telegram_processed_updates')->insertOrIgnore([
            'update_id' => $updateId,
            'handler' => $kind,
            'processed_at' => now(),
        ]) === 1;
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (TelegramApiException $exception) {
            Log::warning('telegram.send_failed', ['message' => $exception->getMessage()]);
        }
    }
}
