<?php

namespace Database\Seeders;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendancePolicy;
use App\Models\Faculty;
use App\Models\Organization;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\StudyYear;
use App\Models\SupervisorProfile;
use App\Models\University;
use App\Models\User;
use App\Services\Admin\BootstrapAdminService;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendancePolicyService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocationInput;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Internships\InternshipService;
use App\Services\Internships\InviteService;
use App\Services\Onboarding\StudentOnboardingService;
use App\Services\Organizations\OrganizationService;
use App\Services\Supervisors\SupervisorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $university = University::query()->create([
            'name' => 'Demo Universitet',
            'slug' => 'demo-universitet',
            'timezone' => 'Asia/Tashkent',
        ]);

        $admin = User::query()->create([
            'university_id' => $university->id,
            'name' => 'Demo Admin',
            'email' => 'admin@demo.test',
            'login' => 'admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'status' => ActiveStatus::Active,
        ]);

        $rahbar = User::query()->create([
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

        $group403 = StudentGroup::query()->create(['study_year_id' => $lawCourse->id, 'name' => '403-guruh', 'code' => '403']);
        StudentGroup::query()->create(['study_year_id' => $lawCourse->id, 'name' => '404-guruh', 'code' => '404']);
        $group201 = StudentGroup::query()->create(['study_year_id' => $financeCourse->id, 'name' => '201-guruh', 'code' => '201']);

        // Demo students have made-up Telegram ids: record their notifications but never send them.
        Queue::fake();
        $students = $this->seedInternships($university, $admin, $rahbar, $group403, $group201);
        $this->seedAttendance($university, $admin, $group201, $students);
        DB::table('telegram_notifications')->update(['status' => 'SKIPPED']);

        if (config('app.bootstrap_admin.login') && config('app.bootstrap_admin.password')) {
            app(BootstrapAdminService::class)->ensure();
        }
    }

    /**
     * Phase 4 demo: policies plus a week of attendance written through AttendanceService with a moved clock,
     * so every row obeys the same rules as a real check-in. Covers PRESENT, PARTIAL, INCOMPLETE,
     * LOCATION_REJECTED, ABSENT, a failed attempt before success, an open session today and a manual correction.
     *
     * @param  array{law: Collection<int, StudentProfile>, finance: Collection<int, StudentProfile>, organizations: Collection<int, Organization>}  $students
     */
    private function seedAttendance(University $university, User $admin, StudentGroup $group201, array $students): void
    {
        $policies = app(AttendancePolicyService::class);
        $policies->saveUniversity($admin, AttendancePolicy::DEFAULTS);
        // Finance group: 4-hour minimum, so a short day is PARTIAL.
        $policies->saveGroup($admin, $group201->id, [...AttendancePolicy::DEFAULTS, 'minimum_duration_minutes' => 240, 'accuracy_threshold_meters' => 100]);

        $attendance = app(AttendanceService::class);
        $tz = $university->timezone;
        $orgs = $students['organizations'];
        $near = fn (Organization $org) => Organization::query()->withCoordinates()->find($org->id)->coordinates();
        $at = function (string $date, string $time) use ($tz) {
            Carbon::setTestNow(CarbonImmutable::parse("{$date} {$time}", $tz)->utc());
        };
        $inside = function (Organization $org, float $shift = 0.0003) use ($near) {
            $point = $near($org);

            return new LocationInput($point['latitude'] + $shift, $point['longitude'], 15.0);
        };
        $outside = function (Organization $org) use ($near) {
            $point = $near($org);

            return new LocationInput($point['latitude'] + 0.006, $point['longitude'], 12.0);
        };

        [$jasur, $malika, $sardor, $nodira] = [$students['law'][0], $students['law'][1], $students['law'][2], $students['law'][3]];
        $otabek = $students['finance'][0];
        $today = CarbonImmutable::parse($university->today());

        for ($offset = 6; $offset >= 1; $offset--) {
            $date = $today->subDays($offset)->toDateString();

            // Jasur: on time every day.
            $at($date, '09:0'.($offset % 6));
            $attendance->checkIn($jasur, $inside($orgs[0]));
            $at($date, '17:1'.($offset % 6));
            $attendance->checkOut($jasur, $inside($orgs[0]));

            // Malika: a rejected attempt first, then success; one day she never checks out.
            $at($date, '08:55');
            $attendance->checkIn($malika, $outside($orgs[0]));
            $at($date, '09:12');
            $attendance->checkIn($malika, $inside($orgs[0], 0.0005));
            if ($offset !== 3) {
                $at($date, '16:40');
                $attendance->checkOut($malika, $inside($orgs[0]));
            }

            // Sardor: absent on two days, otherwise present.
            if (! in_array($offset, [2, 5], true)) {
                $at($date, '10:00');
                $attendance->checkIn($sardor, $inside($orgs[0]));
                $at($date, '15:30');
                $attendance->checkOut($sardor, $inside($orgs[0]));
            }

            // Nodira: one day only outside the radius (LOCATION_REJECTED).
            $at($date, '09:30');
            if ($offset === 4) {
                $attendance->checkIn($nodira, $outside($orgs[1]));
            } else {
                $attendance->checkIn($nodira, $inside($orgs[1]));
                $at($date, '18:00');
                $attendance->checkOut($nodira, $inside($orgs[1]));
            }

            // Otabek: full days, except short (PARTIAL under the 4-hour group minimum) every other day.
            $at($date, '09:00');
            $attendance->checkIn($otabek, $inside($orgs[3]));
            $at($date, $offset % 2 === 0 ? '11:00' : '14:30');
            $attendance->checkOut($otabek, $inside($orgs[3]));
        }

        // Today: Jasur still on site (OPEN), Nodira rejected once and then verified, Malika checked in and out.
        $now = CarbonImmutable::now($tz);
        $todayAt = fn (int $hoursAgo) => $now->subHours($hoursAgo)->format('H:i');
        if ($now->hour >= 4) {
            $at($today->toDateString(), $todayAt(3));
            $attendance->checkIn($jasur, $inside($orgs[0]));
            $attendance->checkIn($malika, $inside($orgs[0]));
            $attendance->checkIn($nodira, $outside($orgs[1]));
            $at($today->toDateString(), $todayAt(2));
            $attendance->checkIn($nodira, $inside($orgs[1]));
            $at($today->toDateString(), $todayAt(1));
            $attendance->checkOut($malika, $inside($orgs[0]));
        }

        Carbon::setTestNow();
        $attendance->closeStaleSessions();

        // An audited admin correction: Sardor forgot to record day -5; the admin adds it with a reason.
        app(AttendanceCorrectionService::class)->addSession($admin, $sardor->id, $today->subDays(5)->toDateString(), '09:20', '16:10', 'Tashkilot rahbari talaba kelganini yozma tasdiqladi.');
    }

    /**
     * Phase 2 demo: supervisors, organizations, internships, invites, onboarded students, assignments, a pending change request.
     * Everything goes through the services so demo data obeys the same rules as the UI.
     */
    /**
     * @return array{law: Collection<int, StudentProfile>, finance: Collection<int, StudentProfile>, organizations: Collection<int, Organization>}
     */
    private function seedInternships(University $university, User $admin, User $rahbar, StudentGroup $group403, StudentGroup $group201): array
    {
        $admin->setRelation('university', $university);
        $rahbar->setRelation('university', $university);

        $rahbarProfile = SupervisorProfile::query()->create([
            'user_id' => $rahbar->id,
            'university_id' => $university->id,
            'phone' => '+998901112233',
            'position' => 'Dotsent',
        ]);
        $secondProfile = app(SupervisorService::class)->create($admin, [
            'name' => 'Karimova Dilnoza',
            'login' => 'rahbar2',
            'email' => 'rahbar2@demo.test',
            'password' => 'password',
            'phone' => '+998902223344',
            'position' => 'Katta o‘qituvchi',
        ]);

        $organizations = app(OrganizationService::class);
        $orgs = collect([
            ['Toshkent shahar sudi', 'Sud', 'Toshkent, Mirobod tumani, Amir Temur ko‘chasi 1', 41.3111000, 69.2797000, 150],
            ['Yunusobod tuman prokuraturasi', 'Prokuratura', 'Toshkent, Yunusobod tumani, Amir Temur shoh ko‘chasi 108', 41.3645000, 69.2868000, 100],
            ['“Adolat” advokatlik byurosi', 'Advokatlik byurosi', 'Toshkent, Chilonzor tumani, Bunyodkor shoh ko‘chasi 15', 41.2856000, 69.2034000, 200],
            ['“Moliya Invest” MChJ', 'Xususiy kompaniya', 'Toshkent, Shayxontohur tumani, Navoiy ko‘chasi 30', 41.3264000, 69.2401000, 120],
        ])->map(fn (array $row) => $organizations->create($admin, [
            'name' => $row[0],
            'type' => $row[1],
            'address' => $row[2],
            'contact_name' => 'Mas’ul xodim',
            'contact_phone' => '+998712000000',
            'latitude' => $row[3],
            'longitude' => $row[4],
            'radius_meters' => $row[5],
        ]));

        $today = CarbonImmutable::parse($university->today());
        $internships = app(InternshipService::class);
        $lawInternship = $internships->create($admin, $group403->id, $rahbarProfile->id, $today->subDays(7)->toDateString(), $today->addDays(60)->toDateString());
        $financeInternship = $internships->create($admin, $group201->id, $secondProfile->id, $today->subDays(7)->toDateString(), $today->addDays(45)->toDateString());

        $invites = app(InviteService::class);
        $onboarding = app(StudentOnboardingService::class);
        $lawToken = $invites->create($admin, $lawInternship->id, null)['token'];
        $financeToken = $invites->create($admin, $financeInternship->id, null)['token'];

        $lawStudents = collect([
            ['Aliyev', 'Jasur'], ['Bakirova', 'Malika'], ['Valiyev', 'Sardor'],
            ['Ganiyeva', 'Nodira'], ['Davronov', 'Bekzod'], ['Ergasheva', 'Zarina'],
        ])->map(fn (array $name, int $i) => $onboarding->join($lawToken, 900000001 + $i, $name[1], $name[0], '+99893100000'.$i, 'L-'.(1001 + $i)));
        $financeStudents = collect([['Fayziyev', 'Otabek'], ['Hasanova', 'Gulnora'], ['Ismoilov', 'Rustam']])
            ->map(fn (array $name, int $i) => $onboarding->join($financeToken, 900000101 + $i, $name[1], $name[0], '+99894100000'.$i, 'F-'.(2001 + $i)));

        $assignments = app(InternshipAssignmentService::class);
        $start = $university->startOfLocalDay($today->subDays(7)->toDateString());
        $lawEnd = $university->endOfLocalDay($lawInternship->period_end->toDateString());

        // Many students at one organization; the same group spread over several organizations.
        $assignments->assign($admin, $lawStudents->take(3)->pluck('id')->all(), $orgs[0]->id, $lawInternship->id, $start, $lawEnd);
        $assignments->assign($rahbar, [$lawStudents[3]->id], $orgs[1]->id, $lawInternship->id, $start, $lawEnd);
        // Future start stays PENDING; the last law student stays unassigned.
        $assignments->assign($rahbar, [$lawStudents[4]->id], $orgs[2]->id, $lawInternship->id, $university->startOfLocalDay($today->addDays(3)->toDateString()), $lawEnd);
        // One student at one organization.
        $assignments->assign($admin, [$financeStudents[0]->id], $orgs[3]->id, $financeInternship->id, $start, $university->endOfLocalDay($financeInternship->period_end->toDateString()));

        app(InternshipChangeRequestService::class)->openExisting($rahbar, $lawStudents[0]->id, $orgs[1]->id, 'Talaba yashash joyiga yaqinroq tashkilotni so‘radi.');

        return ['law' => $lawStudents, 'finance' => $financeStudents, 'organizations' => $orgs];
    }
}
