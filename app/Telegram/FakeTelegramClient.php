<?php

namespace App\Telegram;

/**
 * Records outgoing calls instead of sending them. Bound in tests and whenever TELEGRAM_FAKE=true.
 */
class FakeTelegramClient implements TelegramClient
{
    /** @var list<array{chat_id: int, text: string, reply_markup: array<string, mixed>|null}> */
    public array $sent = [];

    /** @var list<array{id: string, text: ?string}> */
    public array $answered = [];

    public bool $failSends = false;

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        if ($this->failSends) {
            throw new TelegramApiException('fake send failure');
        }
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $replyMarkup];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->answered[] = ['id' => $callbackQueryId, 'text' => $text];
    }

    public function setWebhook(string $url, string $secret, array $allowedUpdates): array
    {
        return ['ok' => true];
    }

    public function deleteWebhook(): array
    {
        return ['ok' => true];
    }

    public function getWebhookInfo(): array
    {
        return ['url' => ''];
    }

    public function getMe(): array
    {
        return ['id' => 1, 'is_bot' => true, 'username' => 'fake_bot'];
    }

    public function getUpdates(int $offset, int $timeout, array $allowedUpdates): array
    {
        return [];
    }

    public function lastText(): ?string
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)]['text'];
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(fn (array $message) => $message['text'], $this->sent);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->answered = [];
    }
}
