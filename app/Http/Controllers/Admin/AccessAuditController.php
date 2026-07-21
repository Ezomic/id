<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessAuditController extends Controller
{
    public function index(Request $request): Response
    {
        $userFilter = $request->integer('user') ?: null;
        $applicationFilter = $request->integer('application') ?: null;

        $audits = AccessAudit::query()
            ->with(['actor:id,name', 'subjectUser:id,name', 'application:id,name', 'group:id,name'])
            ->when($userFilter, fn ($query, $id) => $query->where('subject_user_id', $id))
            ->when($applicationFilter, fn ($query, $id) => $query->where('application_id', $id))
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (AccessAudit $audit): array => [
                'id' => $audit->id,
                'actor' => optional($audit->actor)->name ?? 'System',
                'action' => $audit->action,
                'subject' => $audit->subjectUser?->name,
                'application' => $audit->application?->name,
                'group' => $audit->group?->name,
                'at_diff' => $audit->created_at?->diffForHumans(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/AccessAudit', [
            'audits' => $audits,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'applications' => Application::orderBy('name')->get(['id', 'name']),
            'filters' => ['user' => $userFilter, 'application' => $applicationFilter],
        ]);
    }
}
