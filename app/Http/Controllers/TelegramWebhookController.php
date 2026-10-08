<?php

namespace App\Http\Controllers;

use App\Telegram\UpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The only externally reachable endpoint (A40). Requires the secret header (§106) before reading the body.
 */
class TelegramWebhookController extends Controller
{
    /** A39: failed-secret attempts per IP per minute. Authenticated traffic is limited per Telegram user instead (A73). */
    public const BAD_SECRET_PER_MINUTE = 120;

    public function __invoke(Request $request, UpdateProcessor $processor): JsonResponse
    {
        $secret = config('services.telegram.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            abort(404);
        }

        $key = 'tg-webhook-bad:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::BAD_SECRET_PER_MINUTE)) {
            abort(429);
        }

        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (! is_string($given) || ! hash_equals($secret, $given)) {
            RateLimiter::hit($key, 60);
            abort(403);
        }

        $payload = $request->json()->all();
        if (is_array($payload) && $payload !== []) {
            $processor->process($payload);
        }

        // Always 200 once authenticated, so Telegram does not redeliver an update that was handled or ignored.
        return response()->json(['ok' => true]);
    }
}
