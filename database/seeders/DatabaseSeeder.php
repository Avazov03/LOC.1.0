<?php

namespace Database\Seeders;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudyYear;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $university = University::query()->create([
            'name' => 'Demo Universitet',
            'slug' => 'demo-universitet',
            'timezone' => 'Asia/Tashkent',
        ]);

        User::query()->create([
            'university_id' => $university->id,
            'name' => 'Demo Admin',
            'email' => 'admin@demo.test',
            'login' => 'admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'status' => ActiveStatus::Active,
        ]);

        User::query()->create([
            'university_id' => $university->id,
            'name' => 'Demo Rahbar',
            'email' => 'rahbar@demo.test',
            'login' => 'rahbar',
            'password' => Hash::make('password'),
            'role' => UserRole::Supervisor,
            'status' => ActiveStatus::Active,
        ]);

        $law = Faculty::query()->create([
            'university_id' => $university->id,
            'name' => 'Yuridik fakultet',
            'status' => ActiveStatus::Active,
        ]);
        $econ = Faculty::query()->create([
            'university_id' => $university->id,
            'name' => 'Iqtisodiyot fakulteti',
            'status' => ActiveStatus::Active,
        ]);

        $jurisprudence = Program::query()->create([
            'faculty_id' => $law->id,
            'name' => 'Yurisprudensiya',
            'status' => ActiveStatus::Active,
        ]);
        $finance = Program::query()->create([
            'faculty_id' => $econ->id,
            'name' => 'Moliya',
            'status' => ActiveStatus::Active,
        ]);

        $year = AcademicYear::query()->create([
            'university_id' => $university->id,
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'status' => 'ACTIVE',
        ]);

        $lawCourse = StudyYear::query()->create([
            'program_id' => $jurisprudence->id,
            'academic_year_id' => $year->id,
            'course_number' => 4,
            'name' => '4-kurs',
        ]);
        $financeCourse = StudyYear::query()->create([
            'program_id' => $finance->id,
            'academic_year_id' => $year->id,
            'course_number' => 2,
            'name' => '2-kurs',
        ]);

        StudentGroup::query()->create(['study_year_id' => $lawCourse->id, 'name' => '403-guruh', 'code' => '403']);
        StudentGroup::query()->create(['study_year_id' => $lawCourse->id, 'name' => '404-guruh', 'code' => '404']);
        StudentGroup::query()->create(['study_year_id' => $financeCourse->id, 'name' => '201-guruh', 'code' => '201']);
    }
}
