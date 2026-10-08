<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Access\AccessScope;
use App\Support\Present;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, AccessScope $scope): Response
    {
        $user = $request->user();
        $scope->requireAdmin($user);
        $timezone = $user->university->timezone;
        $entity = $request->string('entity')->trim()->toString() ?: null;

        $logs = AuditLog::query()
            ->where('university_id', $user->university_id)
            ->when($entity, fn ($query, $value) => $query->where('entity_type', $value))
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log) => Present::auditLog($log, $timezone));

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filters' => ['entity' => $entity],
            'entities' => ['university', 'student_profile', 'supervisor_profile', 'organization', 'internship', 'internship_invite', 'internship_assignment', 'internship_change_request', 'user'],
        ]);
    }
}
