<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Supervisor = 'SUPERVISOR';
    case Student = 'STUDENT';
}
