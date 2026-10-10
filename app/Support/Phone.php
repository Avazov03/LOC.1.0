<?php

namespace App\Support;

/**
 * Phone numbers as Telegram and staff write them. Compared by digits; a 9-digit local number gets the 998 prefix.
 */
final class Phone
{
    public static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) === 9 ? '998'.$digits : $digits;
    }

    /**
     * "+<digits>", or null when the number is too short or too long to be a phone number.
     */
    public static function normalize(string $phone): ?string
    {
        $digits = self::digits($phone);

        return strlen($digits) < 10 || strlen($digits) > 15 ? null : '+'.$digits;
    }
}
