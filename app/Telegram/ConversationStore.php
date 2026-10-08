<?php

namespace App\Telegram;

use App\Models\TelegramConversation;

/**
 * TELEGRAM-FLOW §9. An expired dialog behaves as IDLE; an abandoned join never leaves a partial student.
 */
class ConversationStore
{
    public function get(int $telegramUserId): ?TelegramConversation
    {
        $conversation = TelegramConversation::query()->where('telegram_user_id', $telegramUserId)->first();
        if ($conversation !== null && $conversation->expires_at !== null && $conversation->expires_at->isPast()) {
            $conversation->delete();

            return null;
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function put(int $telegramUserId, string $state, array $context = []): void
    {
        TelegramConversation::query()->updateOrCreate(
            ['telegram_user_id' => $telegramUserId],
            ['state' => $state, 'context' => $context, 'expires_at' => now()->addMinutes(max(1, (int) config('services.telegram.conversation_ttl', 30)))],
        );
    }

    public function clear(int $telegramUserId): void
    {
        TelegramConversation::query()->where('telegram_user_id', $telegramUserId)->delete();
    }

    public function pruneExpired(): int
    {
        return TelegramConversation::query()->where('expires_at', '<', now())->delete();
    }
}
