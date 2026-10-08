<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Ended = 'ENDED';
}
