<?php

namespace App\Telegram;

final class Reply
{
    /**
     * @param  array<string, mixed>|null  $markup
     */
    public function __construct(
        public readonly int $chatId,
        public readonly string $text,
        public readonly ?array $markup = null,
    ) {}
}
