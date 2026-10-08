<?php

use App\Http\Controllers\Academic\AcademicController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendancePolicyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InternshipController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SupervisorController;
use App\Http\Controllers\SupervisorHomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::middleware(['auth', 'active', 'role:ADMIN,SUPERVISOR'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:ADMIN')->group(function () {
        Route::prefix('academic')->group(function () {
            Route::get('/faculties', [AcademicController::class, 'faculties'])->name('faculties.index');
            Route::post('/faculties', [AcademicController::class, 'storeFaculty'])->name('faculties.store');
            Route::put('/faculties/{faculty}', [AcademicController::class, 'updateFaculty'])->name('faculties.update');

            Route::get('/programs', [AcademicController::class, 'programs'])->name('programs.index');
            Route::post('/programs', [AcademicController::class, 'storeProgram'])->name('programs.store');
            Route::put('/programs/{program}', [AcademicController::class, 'updateProgram'])->name('programs.update');

            Route::get('/years', [AcademicController::class, 'years'])->name('years.index');
            Route::post('/years', [AcademicController::class, 'storeYear'])->name('years.store');
            Route::put('/years/{year}', [AcademicController::class, 'updateYear'])->name('years.update');

            Route::get('/study-years', [AcademicController::class, 'studyYears'])->name('study-years.index');
            Route::post('/study-years', [AcademicController::class, 'storeStudyYear'])->name('study-years.store');
            Route::put('/study-years/{studyYear}', [AcademicController::class, 'updateStudyYear'])->whereNumber('studyYear')->name('study-years.update');

            Route::get('/groups', [AcademicController::class, 'groups'])->name('groups.index');
            Route::post('/groups', [AcademicController::class, 'storeGroup'])->name('groups.store');
            Route::put('/groups/{group}', [AcademicController::class, 'updateGroup'])->whereNumber('group')->name('groups.update');

            Route::get('/students', [StudentController::class, 'index'])->name('students.index');
            Route::get('/students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('students.show');
            Route::put('/students/{student}', [StudentController::class, 'update'])->whereNumber('student')->name('students.update');
            Route::patch('/students/{student}/status', [StudentController::class, 'status'])->whereNumber('student')->name('students.status');
        });

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/supervisors', [SupervisorController::class, 'index'])->name('supervisors.index');
        Route::post('/supervisors', [SupervisorController::class, 'store'])->name('supervisors.store');
        Route::get('/supervisors/{supervisor}', [SupervisorController::class, 'show'])->whereNumber('supervisor')->name('supervisors.show');
        Route::put('/supervisors/{supervisor}', [SupervisorController::class, 'update'])->whereNumber('supervisor')->name('supervisors.update');
        Route::patch('/supervisors/{supervisor}/status', [SupervisorController::class, 'status'])->whereNumber('supervisor')->name('supervisors.status');

        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->whereNumber('organization')->name('organizations.show');
        Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->whereNumber('organization')->name('organizations.edit');
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->whereNumber('organization')->name('organizations.update');
        Route::patch('/organizations/{organization}/status', [OrganizationController::class, 'status'])->whereNumber('organization')->name('organizations.status');

        Route::get('/internships', [InternshipController::class, 'index'])->name('internships.index');
        Route::post('/internships', [InternshipController::class, 'store'])->name('internships.store');
        Route::get('/internships/{internship}', [InternshipController::class, 'show'])->whereNumber('internship')->name('internships.show');
        Route::put('/internships/{internship}', [InternshipController::class, 'update'])->whereNumber('internship')->name('internships.update');
        Route::post('/internships/{internship}/supervisor', [InternshipController::class, 'replaceSupervisor'])->whereNumber('internship')->name('internships.supervisor');
        Route::post('/internships/{internship}/invites', [InternshipController::class, 'storeInvite'])->whereNumber('internship')->name('invites.store');
        Route::post('/invites/{invite}/close', [InternshipController::class, 'closeInvite'])->whereNumber('invite')->name('invites.close');

        Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
        Route::put('/assignments/{assignment}', [AssignmentController::class, 'update'])->whereNumber('assignment')->name('assignments.update');
        Route::post('/assignments/{assignment}/activate', [AssignmentController::class, 'activate'])->whereNumber('assignment')->name('assignments.activate');
        Route::post('/assignments/{assignment}/end', [AssignmentController::class, 'end'])->whereNumber('assignment')->name('assignments.end');
        Route::post('/assignments/{assignment}/cancel', [AssignmentController::class, 'cancel'])->whereNumber('assignment')->name('assignments.cancel');

        Route::get('/change-requests/{changeRequest}/approve-new', [ChangeRequestController::class, 'approveNewForm'])->whereNumber('changeRequest')->name('change-requests.approve-new.form');
        Route::post('/change-requests/{changeRequest}/approve-new', [ChangeRequestController::class, 'approveNew'])->whereNumber('changeRequest')->name('change-requests.approve-new');
        Route::post('/change-requests/{changeRequest}/cancel', [ChangeRequestController::class, 'cancel'])->whereNumber('changeRequest')->name('change-requests.cancel');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/attendance/policies', [AttendancePolicyController::class, 'index'])->name('attendance.policies');
        Route::put('/attendance/policies/university', [AttendancePolicyController::class, 'updateUniversity'])->name('attendance.policies.university');
        Route::post('/attendance/policies/groups', [AttendancePolicyController::class, 'storeGroup'])->name('attendance.policies.groups');
        Route::post('/attendance/policies/{policy}/deactivate', [AttendancePolicyController::class, 'deactivate'])->whereNumber('policy')->name('attendance.policies.deactivate');
        Route::post('/attendance/students/{student}/corrections', [AttendanceController::class, 'storeCorrection'])->whereNumber('student')->name('attendance.corrections.store');
        Route::post('/attendance/sessions/{session}/close', [AttendanceController::class, 'closeSession'])->whereNumber('session')->name('attendance.sessions.close');
    });

    Route::middleware('role:SUPERVISOR')->group(function () {
        Route::get('/my-groups', [SupervisorHomeController::class, 'groups'])->name('supervisor.groups');
        Route::get('/my-groups/{internship}', [SupervisorHomeController::class, 'group'])->whereNumber('internship')->name('supervisor.groups.show');
        Route::get('/my-students', [StudentController::class, 'supervisorIndex'])->name('supervisor.students');
        Route::get('/students/{student}', [SupervisorHomeController::class, 'student'])->whereNumber('student')->name('supervisor.students.show');
        Route::post('/students/{student}/change-requests', [ChangeRequestController::class, 'store'])->whereNumber('student')->middleware('throttle:change-requests')->name('change-requests.store');
    });

    // Shared by admin and supervisor; the services apply university and supervisor scope.
    Route::post('/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/change-requests', [ChangeRequestController::class, 'index'])->name('change-requests.index');
    Route::post('/change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])->whereNumber('changeRequest')->name('change-requests.approve');
    Route::post('/change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])->whereNumber('changeRequest')->name('change-requests.reject');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/students/{student}', [AttendanceController::class, 'student'])->whereNumber('student')->name('attendance.student');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('/reports/export', [ReportController::class, 'export'])->middleware('throttle:exports')->name('reports.export');
    Route::get('/reports/exports/{export}/download', [ReportController::class, 'download'])->whereNumber('export')->name('reports.download');
});
