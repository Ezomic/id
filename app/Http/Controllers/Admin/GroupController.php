<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessAudit;
use App\Models\Application;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(): Response
    {
        $groups = Group::query()
            ->with(['users:id', 'applications:id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'user_ids' => $group->users->pluck('id'),
                'application_ids' => $group->applications->pluck('id'),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Groups', [
            'groups' => $groups,
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
            'applications' => Application::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Group::create(['name' => $data['name']]);

        return back()->with('status', 'Group created.');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'users' => ['array'],
            'users.*' => [Rule::exists('users', 'id')],
            'applications' => ['array'],
            'applications.*' => [Rule::exists('applications', 'id')],
        ]);

        if (isset($data['name'])) {
            $group->update(['name' => $data['name']]);
        }

        $beforeUsers = $group->users()->pluck('users.id')->all();
        $beforeApps = $group->applications()->pluck('applications.id')->all();
        $afterUsers = array_map('intval', $data['users'] ?? []);
        $afterApps = array_map('intval', $data['applications'] ?? []);

        $group->users()->sync($afterUsers);
        $group->applications()->sync($afterApps);

        foreach (array_diff($afterUsers, $beforeUsers) as $userId) {
            AccessAudit::log('group_member_add', ['group_id' => $group->id, 'subject_user_id' => $userId]);
        }
        foreach (array_diff($beforeUsers, $afterUsers) as $userId) {
            AccessAudit::log('group_member_remove', ['group_id' => $group->id, 'subject_user_id' => $userId]);
        }
        foreach (array_diff($afterApps, $beforeApps) as $applicationId) {
            AccessAudit::log('group_app_grant', ['group_id' => $group->id, 'application_id' => $applicationId]);
        }
        foreach (array_diff($beforeApps, $afterApps) as $applicationId) {
            AccessAudit::log('group_app_revoke', ['group_id' => $group->id, 'application_id' => $applicationId]);
        }

        return back()->with('status', 'Group updated.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $group->delete();

        return back()->with('status', 'Group deleted.');
    }
}
