<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $stats = [];

        if ($user?->isAdmin() && $user->university_id !== null) {
            $universityId = $user->university_id;
            $stats = [
                ['label' => 'Fakultetlar', 'value' => Faculty::query()->where('university_id', $universityId)->count(), 'icon' => 'bx-building', 'tone' => 'primary'],
                ['label' => 'Yo‘nalishlar', 'value' => Program::query()->whereHas('faculty', fn ($q) => $q->where('university_id', $universityId))->count(), 'icon' => 'bx-book-open', 'tone' => 'info'],
                ['label' => 'Guruhlar', 'value' => StudentGroup::query()->whereHas('studyYear.program.faculty', fn ($q) => $q->where('university_id', $universityId))->count(), 'icon' => 'bx-group', 'tone' => 'success'],
                ['label' => 'Talabalar', 'value' => StudentProfile::query()->where('university_id', $universityId)->count(), 'icon' => 'bx-user', 'tone' => 'warning'],
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'yearCount' => $user?->university_id
                ? AcademicYear::query()->where('university_id', $user->university_id)->count()
                : 0,
        ]);
    }
}
