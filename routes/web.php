<?php

use App\Http\Controllers\Academic\AcademicController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SupervisorHomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:ADMIN')->prefix('academic')->group(function () {
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

        Route::get('/groups', [AcademicController::class, 'groups'])->name('groups.index');
        Route::post('/groups', [AcademicController::class, 'storeGroup'])->name('groups.store');

        Route::get('/students', [AcademicController::class, 'students'])->name('students.index');
    });

    Route::middleware('role:SUPERVISOR')->group(function () {
        Route::get('/my-groups', [SupervisorHomeController::class, 'groups'])->name('supervisor.groups');
    });
});
