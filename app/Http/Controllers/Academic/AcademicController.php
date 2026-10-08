<?php

namespace App\Http\Controllers\Academic;

use App\Enums\AcademicYearStatus;
use App\Enums\ActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\AcademicRequest;
use App\Models\StudentProfile;
use App\Services\Academic\AcademicStructureService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicController extends Controller
{
    public function __construct(private readonly AcademicStructureService $academic) {}

    public function faculties(): Response
    {
        $this->authorize('academic.manage');

        return Inertia::render('Academic/Faculties', [
            'faculties' => $this->academic->faculties(request()->user())->map(fn ($faculty) => [
                'id' => $faculty->id,
                'name' => $faculty->name,
                'status' => $faculty->status->value,
                'programs_count' => $faculty->programs_count,
            ]),
        ]);
    }

    public function storeFaculty(AcademicRequest $request): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->createFaculty($request->user(), $request->string('name')->toString());

        return back()->with('success', 'Fakultet qo‘shildi.');
    }

    public function updateFaculty(AcademicRequest $request, int $faculty): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->updateFaculty(
            $request->user(),
            $faculty,
            $request->string('name')->toString(),
            ActiveStatus::from($request->string('status')->toString()),
        );

        return back()->with('success', 'Fakultet yangilandi.');
    }

    public function programs(): Response
    {
        $this->authorize('academic.manage');
        $user = request()->user();

        return Inertia::render('Academic/Programs', [
            'programs' => $this->academic->programs($user)->map(fn ($program) => [
                'id' => $program->id,
                'name' => $program->name,
                'status' => $program->status->value,
                'faculty_id' => $program->faculty_id,
                'faculty_name' => $program->faculty->name,
            ]),
            'faculties' => $this->academic->faculties($user)->map(fn ($faculty) => [
                'id' => $faculty->id,
                'name' => $faculty->name,
            ]),
        ]);
    }

    public function storeProgram(AcademicRequest $request): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->createProgram($request->user(), $request->integer('faculty_id'), $request->string('name')->toString());

        return back()->with('success', 'Yo‘nalish qo‘shildi.');
    }

    public function updateProgram(AcademicRequest $request, int $program): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->updateProgram(
            $request->user(),
            $program,
            $request->integer('faculty_id'),
            $request->string('name')->toString(),
            ActiveStatus::from($request->string('status')->toString()),
        );

        return back()->with('success', 'Yo‘nalish yangilandi.');
    }

    public function years(): Response
    {
        $this->authorize('academic.manage');

        return Inertia::render('Academic/Years', [
            'years' => $this->academic->academicYears(request()->user())->map(fn ($year) => [
                'id' => $year->id,
                'name' => $year->name,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status->value,
            ]),
        ]);
    }

    public function storeYear(AcademicRequest $request): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->createAcademicYear(
            $request->user(),
            $request->string('name')->toString(),
            $request->string('starts_on')->toString(),
            $request->string('ends_on')->toString(),
        );

        return back()->with('success', 'O‘quv yili qo‘shildi.');
    }

    public function updateYear(AcademicRequest $request, int $year): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->updateAcademicYear(
            $request->user(),
            $year,
            $request->string('name')->toString(),
            $request->string('starts_on')->toString(),
            $request->string('ends_on')->toString(),
            AcademicYearStatus::from($request->string('status')->toString()),
        );

        return back()->with('success', 'O‘quv yili yangilandi.');
    }

    public function studyYears(): Response
    {
        $this->authorize('academic.manage');
        $user = request()->user();

        return Inertia::render('Academic/StudyYears', [
            'studyYears' => $this->academic->studyYears($user)->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'course_number' => $row->course_number,
                'program_name' => $row->program->name,
                'faculty_name' => $row->program->faculty->name,
                'year_name' => $row->academicYear->name,
            ]),
            'programs' => $this->academic->programs($user)->map(fn ($program) => [
                'id' => $program->id,
                'name' => $program->faculty->name.' / '.$program->name,
            ]),
            'years' => $this->academic->academicYears($user)->map(fn ($year) => [
                'id' => $year->id,
                'name' => $year->name,
            ]),
        ]);
    }

    public function storeStudyYear(AcademicRequest $request): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->createStudyYear(
            $request->user(),
            $request->integer('program_id'),
            $request->integer('academic_year_id'),
            $request->integer('course_number'),
            $request->string('name')->toString(),
        );

        return back()->with('success', 'Kurs qo‘shildi.');
    }

    public function groups(): Response
    {
        $this->authorize('academic.manage');
        $user = request()->user();

        return Inertia::render('Academic/Groups', [
            'groups' => $this->academic->groups($user)->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'course' => $group->studyYear->name,
                'program' => $group->studyYear->program->name,
                'year' => $group->studyYear->academicYear->name,
            ]),
            'studyYears' => $this->academic->studyYears($user)->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->academicYear->name.' / '.$row->program->name.' / '.$row->name,
            ]),
        ]);
    }

    public function storeGroup(AcademicRequest $request): RedirectResponse
    {
        $this->authorize('academic.manage');
        $this->academic->createGroup(
            $request->user(),
            $request->integer('study_year_id'),
            $request->string('name')->toString(),
            $request->string('code')->toString(),
        );

        return back()->with('success', 'Guruh qo‘shildi.');
    }

    public function students(): Response
    {
        $this->authorize('academic.manage');
        $universityId = request()->user()->university_id;

        $students = StudentProfile::query()
            ->where('university_id', $universityId)
            ->with('currentGroup')
            ->orderBy('last_name')
            ->get()
            ->map(fn (StudentProfile $student) => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'phone' => $student->phone,
                'student_code' => $student->student_code,
                'group' => $student->currentGroup?->name,
                'status' => $student->status->value,
            ]);

        return Inertia::render('Academic/Students', [
            'students' => $students,
        ]);
    }
}
