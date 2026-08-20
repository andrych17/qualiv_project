<?php

namespace App\Modules\WNE\Exceptions;

use RuntimeException;

/**
 * Thrown for anything the §3C engine cannot safely do on its own — an
 * unpublished/missing definition, a misconfigured graph, or a step type/
 * branch that needs §3D/§3G/§3I before it can execute. Never swallowed:
 * silently no-oping here is exactly the "webhook never fired but the
 * instance says completed" failure mode the engine must avoid.
 */
class WorkflowEngineException extends RuntimeException {}
