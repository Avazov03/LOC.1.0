<?php

namespace App\Telegram;

/**
 * Transport to the Bot API. Handlers never call HTTP directly, so tests and local runs swap in FakeTelegramClient.
 */
interface TelegramClient
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void;

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void;

    /**
     * @param  list<string>  $allowedUpdates
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secret, array $allowedUpdates): array;

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(): array;

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array;

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array;

    /**
     * @param  list<string>  $allowedUpdates
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout, array $allowedUpdates): array;
}
