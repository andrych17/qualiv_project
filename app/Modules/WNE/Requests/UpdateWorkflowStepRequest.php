<?php

namespace App\Modules\WNE\Requests;

/** Same shape as add — a step edit is a full field replace, not a partial patch. */
class UpdateWorkflowStepRequest extends StoreWorkflowStepRequest {}
