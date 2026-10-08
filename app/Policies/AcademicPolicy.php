<?php

namespace App\Policies;

use App\Enums\ActiveStatus;
use App\Models\User;

class AcademicPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() && $user->status === ActiveStatus::Active;
    }
}
