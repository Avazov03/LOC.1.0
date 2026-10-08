<?php

namespace App\Enums;

enum InviteStatus: string
{
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';
    case Expired = 'EXPIRED';
}
