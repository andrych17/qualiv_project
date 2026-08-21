<?php

namespace App\Modules\WNE\Controllers;

use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §3G inbound (pause-and-resume): the one endpoint an external system calls to resume a
 * `wait_for_callback` step. No auth beyond the token itself — the token IS the credential,
 * single-use and only honored while its step is still `waiting_external` (see
 * WorkflowService::resumeFromCallback()'s docblock for why there's no separate expiry).
 */
class CallbackController
{
    public function __construct(private readonly WorkflowService $workflow) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        try {
            $instanceStep = $this->workflow->resumeFromCallback($token, $request->all());
        } catch (WorkflowEngineException $e) {
            return response()->json(['error' => $e->getMessage()], 410);
        }

        return response()->json(['status' => $instanceStep->status, 'instance_step_id' => $instanceStep->id]);
    }
}
