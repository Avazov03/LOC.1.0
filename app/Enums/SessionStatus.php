<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Open = 'OPEN';
    case Completed = 'COMPLETED';
    case Incomplete = 'INCOMPLETE';
}
