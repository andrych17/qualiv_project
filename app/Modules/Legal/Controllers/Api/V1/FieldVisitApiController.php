<?php

namespace App\Modules\Legal\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\FieldVisit;
use App\Modules\Legal\Requests\CheckInFieldVisitRequest;
use App\Modules\Legal\Requests\CompleteFieldVisitRequest;
use App\Modules\Legal\Services\FieldVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LEGAL_SPECS.md §3M mobile surface — same lifecycle as the web `FieldVisitController`
 * (schedule/check-in/complete), reusing the exact same `FieldVisitService` and Form Requests so
 * the rules never diverge between the desktop and mobile paths. JSON in/out, `auth:sanctum` +
 * `InitializeTenancyByHeader` (see routes/api.php) instead of session/Inertia.
 */
class FieldVisitApiController extends Controller
{
    public function __construct(
        protected FieldVisitService $service,
    ) {}

    /** "My visits" — the mobile purpose is an operator checking their own assigned work. */
    public function index(Request $request): JsonResponse
    {
        $visits = FieldVisit::query()
            ->with(['visitType:id,name,default_checklist', 'matter:id,code,title'])
            ->where('assigned_to', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (FieldVisit $v) => $this->toArray($v));

        return response()->json(['data' => $visits]);
    }

    public function show(FieldVisit $fieldVisit): JsonResponse
    {
        $fieldVisit->loadMissing(['visitType:id,name,default_checklist', 'matter:id,code,title']);

        return response()->json(['data' => $this->toArray($fieldVisit)]);
    }

    public function checkIn(CheckInFieldVisitRequest $request, FieldVisit $fieldVisit): JsonResponse
    {
        $data = $request->validated();
        $visit = $this->service->checkIn($fieldVisit, (float) $data['gps_lat'], (float) $data['gps_lng']);

        return response()->json(['data' => $this->toArray($visit)]);
    }

    public function complete(CompleteFieldVisitRequest $request, FieldVisit $fieldVisit): JsonResponse
    {
        $data = $request->validated();
        $visit = $this->service->complete($fieldVisit, $data['checklist_result'] ?? [], $data['notes'] ?? null);

        return response()->json(['data' => $this->toArray($visit)]);
    }

    /** @return array<string, mixed> */
    private function toArray(FieldVisit $v): array
    {
        return [
            'id' => $v->id,
            'matter_id' => $v->matter_id,
            'matter_code' => $v->matter?->code,
            'land_object_id' => $v->land_object_id,
            'deed_id' => $v->deed_id,
            'visit_type_id' => $v->visit_type_id,
            'visit_type_name' => $v->visitType?->name,
            'status' => $v->status,
            'checked_in_at' => $v->checked_in_at?->toIso8601String(),
            'gps_lat' => $v->gps_lat,
            'gps_lng' => $v->gps_lng,
            'checklist_result' => $v->checklist_result ?? ($v->visitType ? $this->service->blankChecklist($v->visitType) : []),
            'notes' => $v->notes,
        ];
    }
}
