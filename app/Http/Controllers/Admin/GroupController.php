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
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Group::create(['name' => $request->string('name')->toString()]);

        return back()->with('status', 'Group created.');
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'users' => ['array'],
            'users.*' => [Rule::exists('users', 'id')],
            'applications' => ['array'],
            'applications.*' => [Rule::exists('applications', 'id')],
        ]);

        if ($request->has('name')) {
            $group->update(['name' => $request->string('name')->toString()]);
        }

        $beforeUsers = $this->intList($group->users()->pluck('users.id')->all());
        $beforeApps = $this->intList($group->applications()->pluck('applications.id')->all());
        $afterUsers = $this->intList($request->collect('users')->all());
        $afterApps = $this->intList($request->collect('applications')->all());

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

    /**
     * @param  array<mixed>  $values
     * @return array<int, int>
     */
    private function intList(array $values): array
    {
        return array_values(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $values));
    }
}
