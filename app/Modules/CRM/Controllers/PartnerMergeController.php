<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Requests\MergePartnersRequest;
use App\Modules\CRM\Services\PartnerMergeService;
use Inertia\Inertia;
use Inertia\Response;

class PartnerMergeController extends Controller
{
    public function __construct(
        protected PartnerMergeService $service,
    ) {}

    public function index(): Response
    {
        $groups = $this->service->duplicateGroups();

        return Inertia::render('CRM/Merge/Index', [
            'groups' => collect($groups)->map(fn ($g) => [
                'reason' => $g['reason'],
                'partners' => $g['partners']->map(fn (Partner $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'trade_name' => $p->trade_name,
                    'registration_tax_id' => $p->registration_tax_id,
                ]),
            ]),
        ]);
    }

    public function store(MergePartnersRequest $request)
    {
        $data = $request->validated();
        $survivor = Partner::query()->findOrFail($data['survivor_partner_id']);
        $loser = Partner::query()->findOrFail($data['loser_partner_id']);

        $this->service->merge($survivor, $loser, auth()->id());

        return redirect()->route('crm.merge.index')->with('success', "Merged \"{$loser->name}\" into \"{$survivor->name}\".");
    }
}
