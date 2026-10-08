<?php

namespace App\Enums;

enum ChangeRequestType: string
{
    case ExistingOrganization = 'EXISTING_ORGANIZATION';
    case NewOrganization = 'NEW_ORGANIZATION';
}
