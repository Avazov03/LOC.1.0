<?php

namespace App\Services\Students;

use App\Exceptions\BusinessRuleException;

class StudentAccessException extends BusinessRuleException
{
    public const ACCESS_DENIED = 'ACCESS_DENIED';

    public const NO_ACTIVE_ASSIGNMENT = 'NO_ACTIVE_ASSIGNMENT';

    private const MESSAGES = [
        self::ACCESS_DENIED => 'Kirish taqiqlangan.',
        self::NO_ACTIVE_ASSIGNMENT => 'Sizda hozir faol amaliyot biriktirilmagan.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }
}
