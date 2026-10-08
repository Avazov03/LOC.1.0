<?php

namespace App\Support;

use App\Models\User;

class Navigation
{
    /**
     * @return list<array{header?: string, label?: string, href?: string, icon?: string}>
     */
    public static function for(User $user): array
    {
        if ($user->isAdmin()) {
            return [
                ['header' => 'Asosiy'],
                ['label' => 'Boshqaruv', 'href' => '/dashboard', 'icon' => 'bx-home-smile'],
                ['header' => 'Akademik tuzilma'],
                ['label' => 'Fakultetlar', 'href' => '/academic/faculties', 'icon' => 'bx-building'],
                ['label' => 'Yo‘nalishlar', 'href' => '/academic/programs', 'icon' => 'bx-book-open'],
                ['label' => 'O‘quv yillari', 'href' => '/academic/years', 'icon' => 'bx-calendar'],
                ['label' => 'Kurslar', 'href' => '/academic/study-years', 'icon' => 'bx-layer'],
                ['label' => 'Guruhlar', 'href' => '/academic/groups', 'icon' => 'bx-group'],
                ['label' => 'Talabalar', 'href' => '/academic/students', 'icon' => 'bx-user'],
            ];
        }

        if ($user->isSupervisor()) {
            return [
                ['header' => 'Asosiy'],
                ['label' => 'Boshqaruv', 'href' => '/dashboard', 'icon' => 'bx-home-smile'],
                ['label' => 'Mening guruhlarim', 'href' => '/my-groups', 'icon' => 'bx-group'],
            ];
        }

        return [];
    }
}
