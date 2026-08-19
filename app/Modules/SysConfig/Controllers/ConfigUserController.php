<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Requests\StoreConfigUserRequest;
use App\Modules\SysConfig\Requests\UpdateConfigUserRequest;
use App\Modules\SysConfig\Services\ConfigUserService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigUserController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'email', 'created_at'];

    public function __construct(
        protected ConfigUserService $service,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString() ?: null;
        $direction = $request->string('direction')->toString() ?: null;
        $perPage = $request->filled('per_page') ? (int) $request->input('per_page') : null;

        $users = User::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->tap(fn ($query) => TableQuery::applySort($query, $sort, $direction, self::SORTABLE, 'name'))
            ->paginate(TableQuery::perPage($perPage, 20))
            ->withQueryString()
            ->through(function (User $u) {
                $groups = ConfigGroupUser::query()
                    ->where('user_id', $u->id)
                    ->pluck('group_code')
                    ->all();

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'groups' => $groups,
                    'is_active' => $u->is_active,
                    'created_at_formatted' => $u->created_at?->format('d M Y'),
                ];
            });

        return Inertia::render('Config/Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction, 'per_page' => $perPage],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Config/Users/Create', [
            'groups' => $this->groupOptions(),
        ]);
    }

    public function store(StoreConfigUserRequest $request)
    {
        $result = $this->service->create($request->validated());

        return redirect()->route('config.users.index')
            ->with('success', 'User created.')
            ->with('generated_credentials', [
                'email' => $result['user']->email,
                'password' => $result['password'],
            ]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Config/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'groups' => $this->groupOptions(),
            'group_ids' => ConfigGroupUser::query()
                ->where('user_id', $user->id)
                ->pluck('group_id')
                ->all(),
        ]);
    }

    public function update(UpdateConfigUserRequest $request, User $user)
    {
        $this->service->update($user, $request->validated());

        return redirect()->route('config.users.index')
            ->with('success', 'User updated.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Cannot deactivate your own account.');
        }

        $this->service->deactivate($user);

        return redirect()->route('config.users.index')
            ->with('success', 'User deactivated.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))->map(fn ($id) => (int) $id);

        if ($ids->contains(auth()->id())) {
            return back()->with('error', 'Cannot deactivate your own account.');
        }

        $request->merge(['ids' => $ids->all()]);

        return $this->bulkDestroyUsing($request, User::class, fn (User $user) => $this->service->deactivate($user));
    }

    public function activate(User $user)
    {
        $this->service->activate($user);

        return redirect()->route('config.users.index')
            ->with('success', 'User activated.');
    }

    public function resetPassword(User $user)
    {
        $password = $this->service->resetPassword($user);

        return redirect()->route('config.users.index')
            ->with('success', 'Password reset.')
            ->with('generated_credentials', [
                'email' => $user->email,
                'password' => $password,
            ]);
    }

    /** @return list<array{label: string, value: int}> */
    private function groupOptions(): array
    {
        return ConfigGroup::query()
            ->where('status_code', 'A')
            ->orderBy('code')
            ->get(['id', 'code', 'descr'])
            ->map(fn (ConfigGroup $g) => [
                'label' => $g->code.($g->descr ? ' — '.$g->descr : ''),
                'value' => $g->id,
            ])
            ->values()
            ->all();
    }
}
