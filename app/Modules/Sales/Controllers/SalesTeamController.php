<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\SalesTeam;
use App\Modules\Sales\Models\Territory;
use App\Modules\Sales\Requests\StoreSalesTeamRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesTeamController extends Controller
{
    public function index(): Response
    {
        $teams = SalesTeam::with(['territory', 'members.user'])->get();
        $territories = Territory::where('is_active', true)->get();
        $users = User::query()->select(['id', 'name'])->get();

        return Inertia::render('Sales/Master/Teams/Index', [
            'teams' => $teams,
            'territories' => $territories,
            'users' => $users,
        ]);
    }

    public function store(StoreSalesTeamRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $team = SalesTeam::create([
                'name' => $data['name'],
                'territory_id' => $data['territory_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['member_user_ids'])) {
                foreach ($data['member_user_ids'] as $userId) {
                    $team->members()->create([
                        'user_id' => $userId,
                        'role' => 'member',
                    ]);
                }
            }
        });

        return back()->with('success', 'Sales Team created.');
    }

    public function update(StoreSalesTeamRequest $request, SalesTeam $team): RedirectResponse
    {
        DB::transaction(function () use ($request, $team) {
            $data = $request->validated();
            $team->update([
                'name' => $data['name'],
                'territory_id' => $data['territory_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (isset($data['member_user_ids'])) {
                $team->members()->delete();
                foreach ($data['member_user_ids'] as $userId) {
                    $team->members()->create([
                        'user_id' => $userId,
                        'role' => 'member',
                    ]);
                }
            }
        });

        return back()->with('success', 'Sales Team updated.');
    }

    public function destroy(SalesTeam $team): RedirectResponse
    {
        $team->delete();

        return back()->with('success', 'Sales Team deleted.');
    }
}
