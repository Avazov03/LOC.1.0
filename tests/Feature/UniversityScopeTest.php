<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\StudentGroup;
use App\Models\StudyYear;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAcademicTree;
use Tests\TestCase;

class UniversityScopeTest extends TestCase
{
    use BuildsAcademicTree;
    use RefreshDatabase;

    private User $admin;

    /** @var array{faculty: Faculty, program: Program, year: AcademicYear, studyYear: StudyYear, group: StudentGroup} */
    private array $own;

    /** @var array{faculty: Faculty, program: Program, year: AcademicYear, studyYear: StudyYear, group: StudentGroup} */
    private array $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $home = University::factory()->create(['name' => 'Uy universiteti']);
        $other = University::factory()->create(['name' => 'Boshqa universitet']);
        $this->admin = User::factory()->create(['university_id' => $home->id]);
        $this->own = $this->academicTree($home, 'A');
        $this->foreign = $this->academicTree($other, 'B');
        $this->student($home, $this->own['group'], 5001, 'Aliyev');
        $this->student($other, $this->foreign['group'], 5002, 'Begimov');
    }

    public function test_lists_contain_only_the_admins_university(): void
    {
        $this->actingAs($this->admin)->get('/academic/faculties')
            ->assertInertia(fn ($page) => $page->has('faculties', 1)->where('faculties.0.name', 'Fakultet A'));

        $this->actingAs($this->admin)->get('/academic/programs')
            ->assertInertia(fn ($page) => $page->has('programs', 1)->has('faculties', 1));

        $this->actingAs($this->admin)->get('/academic/years')
            ->assertInertia(fn ($page) => $page->has('years', 1));

        $this->actingAs($this->admin)->get('/academic/study-years')
            ->assertInertia(fn ($page) => $page->has('studyYears', 1));

        $this->actingAs($this->admin)->get('/academic/groups')
            ->assertInertia(fn ($page) => $page->has('groups', 1)->where('groups.0.name', 'Guruh A'));

        $this->actingAs($this->admin)->get('/academic/students')
            ->assertInertia(fn ($page) => $page->has('students.data', 1)->where('students.data.0.name', 'Aliyev Talaba')->where('students.total', 1));
    }

    public function test_dashboard_counts_only_the_admins_university(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertInertia(function ($page) {
                $stats = collect($page->toArray()['props']['stats'])->pluck('value', 'label')->all();
                $this->assertSame(1, $stats['Talabalar']);
                $this->assertSame(1, $stats['Fakultetlar']);
                $this->assertSame(1, $stats['Guruhlar']);
                $this->assertSame(1, $stats['O‘quv yillari']);
                $this->assertSame(0, $stats['Faol amaliyotlar']);
                $this->assertSame(0, $stats['Faol tashkilotlar']);
            });
    }

    public function test_foreign_faculty_cannot_be_updated_by_id(): void
    {
        $this->actingAs($this->admin)
            ->put("/academic/faculties/{$this->foreign['faculty']->id}", ['name' => 'O‘g‘irlangan', 'status' => 'ACTIVE'])
            ->assertNotFound();

        $this->assertSame('Fakultet B', $this->foreign['faculty']->fresh()->name);
    }

    public function test_foreign_parents_cannot_be_used_for_new_rows(): void
    {
        $this->actingAs($this->admin)
            ->post('/academic/programs', ['faculty_id' => $this->foreign['faculty']->id, 'name' => 'Yangi'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post('/academic/study-years', [
                'program_id' => $this->own['program']->id,
                'academic_year_id' => $this->foreign['year']->id,
                'course_number' => 2,
                'name' => '2-kurs',
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post('/academic/groups', ['study_year_id' => $this->foreign['studyYear']->id, 'name' => 'Yangi guruh', 'code' => 'X'])
            ->assertNotFound();

        $this->assertSame(2, StudentGroup::query()->count());
    }

    public function test_foreign_year_cannot_be_updated_by_id(): void
    {
        $this->actingAs($this->admin)
            ->put("/academic/years/{$this->foreign['year']->id}", [
                'name' => '2030/2031',
                'starts_on' => '2030-09-01',
                'ends_on' => '2031-06-30',
                'status' => 'ACTIVE',
            ])
            ->assertNotFound();
    }
}
