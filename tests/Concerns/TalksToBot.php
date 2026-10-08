<?php

namespace Tests\Concerns;

use App\Telegram\FakeTelegramClient;
use App\Telegram\TelegramClient;
use Illuminate\Testing\TestResponse;

/**
 * Drives the student bot through the real webhook endpoint with the fake Bot API client.
 */
trait TalksToBot
{
    protected string $webhookSecret = 'test-webhook-secret-0123456789abcdef';

    private int $nextUpdateId = 500000;

    protected function bot(): FakeTelegramClient
    {
        $client = app(TelegramClient::class);
        $this->assertInstanceOf(FakeTelegramClient::class, $client);

        return $client;
    }

    protected function enableWebhook(): void
    {
        config(['services.telegram.webhook_secret' => $this->webhookSecret]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    protected function deliver(array $update, ?string $secret = null): TestResponse
    {
        return $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? $this->webhookSecret]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function messageUpdate(int $userId, array $extra, ?int $updateId = null): array
    {
        return [
            'update_id' => $updateId ?? ++$this->nextUpdateId,
            'message' => [
                'message_id' => $this->nextUpdateId,
                'date' => now()->getTimestamp(),
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Test', 'username' => 'user'.$userId],
                'chat' => ['id' => $userId, 'type' => 'private'],
                ...$extra,
            ],
        ];
    }

    protected function say(int $userId, string $text): ?string
    {
        $this->deliver($this->messageUpdate($userId, ['text' => $text]))->assertOk();

        return $this->bot()->lastText();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function sendLocation(int $userId, float $lat, float $lng, ?float $accuracy = 10.0, array $extra = []): ?string
    {
        $location = ['latitude' => $lat, 'longitude' => $lng];
        if ($accuracy !== null) {
            $location['horizontal_accuracy'] = $accuracy;
        }
        $this->deliver($this->messageUpdate($userId, ['location' => $location, ...$extra]))->assertOk();

        return $this->bot()->lastText();
    }

    protected function sendContact(int $userId, string $phone, ?int $ownerId): ?string
    {
        $this->deliver($this->messageUpdate($userId, ['contact' => ['phone_number' => $phone, 'first_name' => 'Test', 'user_id' => $ownerId]]))->assertOk();

        return $this->bot()->lastText();
    }

    protected function press(int $userId, string $data): ?string
    {
        $this->deliver([
            'update_id' => ++$this->nextUpdateId,
            'callback_query' => [
                'id' => 'cb'.$this->nextUpdateId,
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => ['message_id' => 1, 'chat' => ['id' => $userId, 'type' => 'private']],
                'data' => $data,
            ],
        ])->assertOk();

        return $this->bot()->lastText();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lastMarkup(): ?array
    {
        $sent = $this->bot()->sent;

        return $sent === [] ? null : $sent[array_key_last($sent)]['reply_markup'];
    }
}
