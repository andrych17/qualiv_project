<?php

namespace App\Modules\MES\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\MES\Jobs\ProcessIotIngestionJob;
use App\Modules\MES\Requests\IngestIotDataRequest;
use App\Modules\MES\Services\IotAdapters\RestWebhookAdapter;
use Illuminate\Http\JsonResponse;

/**
 * MES_SPECS.md §3S — the one HTTP entry point a PLC/SCADA gateway calls. Bearer-token auth
 * reuses the platform's existing login flow (`POST /api/v1/auth/login`, `routes/api.php`) exactly
 * as Legal's mobile client does — a gateway is provisioned as an ordinary tenant user (an
 * "integration" account, operationally, not a distinct auth mechanism) and mints a token the
 * same way. `InitializeTenancyByHeader` + `auth:sanctum` + `module:MES` (see this module's own
 * `Routes/api.php`) gate the route; no ability-scoping beyond that, same posture Legal's own API
 * routes use today.
 *
 * Accepts, validates, and queues — never writes inline (§3S's own hard rule). 202 Accepted,
 * not 200/201: there is no synchronously-created resource to point at, only a job now queued.
 */
class IotIngestionApiController extends Controller
{
    public function store(IngestIotDataRequest $request): JsonResponse
    {
        $data = $request->validated();

        ProcessIotIngestionJob::dispatch($data, RestWebhookAdapter::class, $request->user()->id);

        return response()->json(['message' => 'Accepted for processing.'], 202);
    }
}
