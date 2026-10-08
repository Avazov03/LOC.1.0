<?php

namespace Tests\Feature;

use App\Telegram\HttpTelegramClient;
use App\Telegram\TelegramApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpTelegramClientTest extends TestCase
{
    private const TOKEN = '123456:test-token-not-real';

    private function client(): HttpTelegramClient
    {
        return new HttpTelegramClient(self::TOKEN, 'https://api.telegram.org', 5);
    }

    public function test_methods_match_the_bot_api_result_shapes(): void
    {
        Http::fake([
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'username' => 'TestBot']]),
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
            '*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 7]]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 3]]),
        ]);
        $client = $this->client();

        $this->assertSame(['ok' => true], $client->setWebhook('https://loc.example.uz/telegram/webhook', 'secret-secret-secret', ['message']));
        $this->assertSame(['ok' => true], $client->deleteWebhook());
        $this->assertSame('TestBot', $client->getMe()['username']);
        $this->assertSame(0, $client->getWebhookInfo()['pending_update_count']);
        $this->assertSame(7, $client->getUpdates(0, 0, ['message'])[0]['update_id']);
        $client->sendMessage(42, 'Salom');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === 'secret-secret-secret'
            && $request['url'] === 'https://loc.example.uz/telegram/webhook');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === 42);
    }

    public function test_api_errors_never_contain_the_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized for bot'.self::TOKEN], 401)]);

        try {
            $this->client()->getMe();
            $this->fail('Expected an exception');
        } catch (TelegramApiException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('401', $exception->getMessage());
        }
    }
}
