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
                ['label' => 'Boshqaruv', 'href' => '/dashboard', 'icon' => 'home'],
                ['header' => 'Amaliyot'],
                ['label' => 'Amaliyot guruhlari', 'href' => '/internships', 'icon' => 'briefcase'],
                ['label' => 'Tashkilotlar', 'href' => '/organizations', 'icon' => 'mapPin'],
                ['label' => 'Biriktirishlar', 'href' => '/assignments', 'icon' => 'link'],
                ['label' => 'O‘zgartirish so‘rovlari', 'href' => '/change-requests', 'icon' => 'swap'],
                ['label' => 'Rahbarlar', 'href' => '/supervisors', 'icon' => 'userCheck'],
                ['header' => 'Davomat'],
                ['label' => 'Davomat', 'href' => '/attendance', 'icon' => 'clock'],
                ['label' => 'Hisobotlar', 'href' => '/reports', 'icon' => 'chart'],
                ['label' => 'Davomat siyosati', 'href' => '/attendance/policies', 'icon' => 'sliders'],
                ['header' => 'Akademik tuzilma'],
                ['label' => 'Fakultetlar', 'href' => '/academic/faculties', 'icon' => 'building'],
                ['label' => 'Yo‘nalishlar', 'href' => '/academic/programs', 'icon' => 'book'],
                ['label' => 'O‘quv yillari', 'href' => '/academic/years', 'icon' => 'calendar'],
                ['label' => 'Kurslar', 'href' => '/academic/study-years', 'icon' => 'layers'],
                ['label' => 'Guruhlar', 'href' => '/academic/groups', 'icon' => 'users'],
                ['label' => 'Talabalar', 'href' => '/academic/students', 'icon' => 'user'],
                ['header' => 'Nazorat'],
                ['label' => 'Audit jurnali', 'href' => '/audit-logs', 'icon' => 'shield'],
                ['label' => 'Sozlamalar', 'href' => '/settings', 'icon' => 'settings'],
                ['label' => 'Profil', 'href' => '/profile', 'icon' => 'user'],
            ];
        }

        if ($user->isSupervisor()) {
            return [
                ['header' => 'Asosiy'],
                ['label' => 'Boshqaruv', 'href' => '/dashboard', 'icon' => 'home'],
                ['label' => 'Mening guruhlarim', 'href' => '/my-groups', 'icon' => 'users'],
                ['label' => 'Talabalar', 'href' => '/my-students', 'icon' => 'user'],
                ['label' => 'Davomat', 'href' => '/attendance', 'icon' => 'clock'],
                ['label' => 'Hisobotlar', 'href' => '/reports', 'icon' => 'chart'],
                ['label' => 'O‘zgartirish so‘rovlari', 'href' => '/change-requests', 'icon' => 'swap'],
                ['header' => 'Hisob'],
                ['label' => 'Profil va Telegram', 'href' => '/profile', 'icon' => 'settings'],
            ];
        }

        return [];
    }
}
