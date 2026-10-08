<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SupervisorHomeController extends Controller
{
    public function groups(): Response
    {
        return Inertia::render('Supervisor/Groups');
    }
}
