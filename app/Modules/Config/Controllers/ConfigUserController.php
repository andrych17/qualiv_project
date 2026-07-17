<?php

namespace App\Modules\Config\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Config\Models\ConfigGroup;
use App\Modules\Config\Models\ConfigGroupUser;
use App\Modules\Config\Requests\StoreConfigUserRequest;
use App\Modules\Config\Requests\UpdateConfigUserRequest;
use App\Modules\Config\Services\ConfigUserService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigUserController extends Controller
{
    public function __construct(
        protected ConfigUserService $service,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $users = User::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(20)
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
                    'created_at_formatted' => $u->created_at?->format('d M Y'),
                ];
            });

        return Inertia::render('Config/Users/Index', [
            'users' => $users,
            'filters' => ['search' => $search],
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
        $this->service->create($request->validated());

        return redirect()->route('config.users.index')
            ->with('success', 'User created.');
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
            return back()->with('error', 'Cannot delete your own account.');
        }

        $this->service->delete($user);

        return redirect()->route('config.users.index')
            ->with('success', 'User deleted.');
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
