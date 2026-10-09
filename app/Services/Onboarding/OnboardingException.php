<?php

namespace App\Services\Onboarding;

use App\Exceptions\BusinessRuleException;

class OnboardingException extends BusinessRuleException
{
    public const INVALID_INVITE = 'INVALID_INVITE';

    public const INVITE_CLOSED = 'INVITE_CLOSED';

    public const INVITE_EXPIRED = 'INVITE_EXPIRED';

    public const ALREADY_REGISTERED = 'ALREADY_REGISTERED';

    public const STUDENT_CODE_TAKEN = 'STUDENT_CODE_TAKEN';

    public const PHONE_REGISTERED = 'PHONE_REGISTERED';

    private const MESSAGES = [
        self::INVALID_INVITE => 'Taklif havolasi topilmadi.',
        self::INVITE_CLOSED => 'Taklif havolasi yopilgan.',
        self::INVITE_EXPIRED => 'Taklif havolasining muddati tugagan.',
        self::ALREADY_REGISTERED => 'Bu Telegram hisobi allaqachon ro‘yxatdan o‘tgan.',
        self::STUDENT_CODE_TAKEN => 'Bu talaba ID raqami allaqachon band.',
        self::PHONE_REGISTERED => 'Bu telefon raqami bilan talaba allaqachon ro‘yxatdan o‘tgan. Yangi Telegram hisobidan foydalanmoqchi bo‘lsangiz, rahbaringizdan «Telegram’ni qayta bog‘lash» havolasini so‘rang.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }
}
