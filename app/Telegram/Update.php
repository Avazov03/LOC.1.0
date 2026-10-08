<?php

namespace App\Telegram;

/**
 * The parts of a Bot API update the student bot uses. Identity is from.id only; username is ignored (A10).
 */
final class Update
{
    /**
     * @param  array{latitude: float, longitude: float, accuracy: ?float, live: bool}|null  $location
     * @param  array{phone: string, user_id: ?int}|null  $contact
     */
    public function __construct(
        public readonly int $updateId,
        public readonly int $userId,
        public readonly int $chatId,
        public readonly ?string $text = null,
        public readonly ?array $location = null,
        public readonly bool $forwarded = false,
        public readonly ?array $contact = null,
        public readonly ?string $callbackId = null,
        public readonly ?string $callbackData = null,
        public readonly ?int $messageDate = null,
    ) {}

    /**
     * Null for anything the bot does not handle: channel posts, edited messages (live-location follow-ups), groups, bots.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function parse(array $raw): ?self
    {
        $updateId = $raw['update_id'] ?? null;
        if (! is_int($updateId)) {
            return null;
        }

        if (isset($raw['callback_query']) && is_array($raw['callback_query'])) {
            $callback = $raw['callback_query'];
            $from = $callback['from'] ?? [];
            $chat = $callback['message']['chat'] ?? [];
            if (! is_int($from['id'] ?? null) || ($from['is_bot'] ?? false) || ($chat['type'] ?? 'private') !== 'private') {
                return null;
            }

            return new self(
                updateId: $updateId,
                userId: $from['id'],
                chatId: is_int($chat['id'] ?? null) ? $chat['id'] : $from['id'],
                callbackId: (string) ($callback['id'] ?? ''),
                callbackData: is_string($callback['data'] ?? null) ? mb_substr($callback['data'], 0, 64) : '',
            );
        }

        $message = $raw['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }
        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        if (! is_int($from['id'] ?? null) || ($from['is_bot'] ?? false) || ($chat['type'] ?? null) !== 'private' || ! is_int($chat['id'] ?? null)) {
            return null;
        }

        $location = null;
        if (isset($message['location']) && is_array($message['location'])) {
            $point = $message['location'];
            if (is_numeric($point['latitude'] ?? null) && is_numeric($point['longitude'] ?? null)) {
                $location = [
                    'latitude' => (float) $point['latitude'],
                    'longitude' => (float) $point['longitude'],
                    'accuracy' => is_numeric($point['horizontal_accuracy'] ?? null) ? (float) $point['horizontal_accuracy'] : null,
                    'live' => isset($point['live_period']),
                ];
            }
        }

        $contact = null;
        if (isset($message['contact']['phone_number'])) {
            $contact = [
                'phone' => (string) $message['contact']['phone_number'],
                'user_id' => is_int($message['contact']['user_id'] ?? null) ? $message['contact']['user_id'] : null,
            ];
        }

        // A picked venue or a forwarded message is not the student's own position at this moment.
        $forwarded = isset($message['forward_origin']) || isset($message['forward_date']) || isset($message['forward_from']) || isset($message['venue']);

        return new self(
            updateId: $updateId,
            userId: $from['id'],
            chatId: $chat['id'],
            text: is_string($message['text'] ?? null) ? mb_substr($message['text'], 0, 2000) : null,
            location: $location,
            forwarded: $forwarded,
            contact: $contact,
            messageDate: is_int($message['date'] ?? null) ? $message['date'] : null,
        );
    }
}
