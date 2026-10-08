<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bot API over HTTPS. The token lives in the request URL, so every error message is scrubbed before it can reach a log.
 */
class HttpTelegramClient implements TelegramClient
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $apiBase,
        private readonly int $timeout,
    ) {}

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'link_preview_options' => ['is_disabled' => true],
        ], fn ($value) => $value !== null));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $text], fn ($value) => $value !== null));
    }

    public function setWebhook(string $url, string $secret, array $allowedUpdates): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => false,
            'max_connections' => 40,
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    public function getUpdates(int $offset, int $timeout, array $allowedUpdates): array
    {
        return $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => $allowedUpdates], $timeout + 10);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload, ?int $timeout = null): mixed
    {
        if ($this->token === null || $this->token === '') {
            throw new TelegramApiException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            $response = Http::timeout($timeout ?? $this->timeout)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->apiBase, '/')."/bot{$this->token}/{$method}", $payload);
        } catch (Throwable $exception) {
            throw new TelegramApiException($this->scrub("{$method} failed: ".$exception->getMessage()));
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
            $description = is_array($body) ? (string) ($body['description'] ?? '') : '';
            throw new TelegramApiException($this->scrub("{$method} failed: HTTP {$response->status()} {$description}"), $response->status());
        }

        return $body['result'] ?? [];
    }

    private function scrub(string $message): string
    {
        return $this->token ? str_replace($this->token, '***', $message) : $message;
    }
}
