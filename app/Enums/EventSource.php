<?php

namespace App\Enums;

enum EventSource: string
{
    case Telegram = 'TELEGRAM';
    case Manual = 'MANUAL';
    case System = 'SYSTEM';
}
