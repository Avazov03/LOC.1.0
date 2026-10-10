<?php

namespace App\Telegram;

/**
 * Button labels (TELEGRAM-FLOW §2) and the reply keyboards built from them.
 */
final class Keyboard
{
    public const INTERNSHIP = '📋 Mening amaliyotim';

    public const START = '🟢 Amaliyotni boshlash';

    public const FINISH = '🔴 Amaliyotni tugatish';

    public const ATTENDANCE = '📅 Davomatim';

    public const CHANGE = '🔄 Amaliyot joyini o‘zgartirish';

    public const PROFILE = '👤 Profilim';

    public const HELP = '❓ Yordam';

    public const CANCEL = '✖️ Bekor qilish';

    public const SKIP = '⏭ O‘tkazib yuborish';

    public const CONFIRM = '✅ Tasdiqlash';

    public const RESTART = '✏️ Qaytadan';

    public const SEND_LOCATION = '📍 Joylashuvni yuborish';

    public const SEND_CONTACT = '📱 Raqamni yuborish';

    public const KIND_EXISTING = '🏢 Mavjud tashkilot';

    public const KIND_NEW = '➕ Yangi tashkilot';

    private const ACTIONS = [
        'internship' => [self::INTERNSHIP, 'mening amaliyotim', '/internship'],
        'start' => [self::START, 'amaliyotni boshlash', '/checkin'],
        'finish' => [self::FINISH, 'amaliyotni tugatish', '/checkout'],
        'attendance' => [self::ATTENDANCE, 'davomatim', '/attendance'],
        'change' => [self::CHANGE, 'amaliyot joyini o‘zgartirish', '/change'],
        'profile' => [self::PROFILE, 'profilim', '/profile'],
        'help' => [self::HELP, 'yordam', '/help'],
        'menu' => ['/menu', 'menyu'],
        'cancel' => [self::CANCEL, 'bekor qilish', '/cancel'],
        'skip' => [self::SKIP, 'o‘tkazib yuborish'],
        'confirm' => [self::CONFIRM, 'tasdiqlash'],
        'restart' => [self::RESTART, 'qaytadan'],
        'kind_existing' => [self::KIND_EXISTING, 'mavjud tashkilot'],
        'kind_new' => [self::KIND_NEW, 'yangi tashkilot'],
    ];

    public const MENU_ACTIONS = ['internship', 'start', 'finish', 'attendance', 'change', 'profile', 'help', 'menu'];

    public static function action(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $normalized = self::normalize($text);
        foreach (self::ACTIONS as $action => $labels) {
            foreach ($labels as $label) {
                if ($text === $label || $normalized === self::normalize($label)) {
                    return $action;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function menu(): array
    {
        return self::reply([
            [self::START, self::FINISH],
            [self::INTERNSHIP, self::ATTENDANCE],
            [self::CHANGE],
            [self::PROFILE, self::HELP],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function location(): array
    {
        return [
            'keyboard' => [[['text' => self::SEND_LOCATION, 'request_location' => true]], [['text' => self::CANCEL]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contact(): array
    {
        return [
            'keyboard' => [[['text' => self::SEND_CONTACT, 'request_contact' => true]], [['text' => self::CANCEL]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * Optional phone confirmation: share the number or skip.
     *
     * @return array<string, mixed>
     */
    public static function confirmPhone(): array
    {
        return [
            'keyboard' => [[['text' => self::SEND_CONTACT, 'request_contact' => true]], [['text' => self::SKIP]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    public static function reply(array $rows): array
    {
        return [
            'keyboard' => array_map(fn (array $row) => array_map(fn (string $label) => ['text' => $label], $row), $rows),
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $rows
     * @return array<string, mixed>
     */
    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remove(): array
    {
        return ['remove_keyboard' => true];
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['\'', '’', 'ʻ', 'ʼ', '`', 'ʹ'], '‘', $text);
        $text = preg_replace('/[^\p{L}\p{N}‘\/ ]+/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
