<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigAuditLog;

class ConfigAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $tableName, int $recordId, string $action, ?array $before, ?array $after): void
    {
        ConfigAuditLog::query()->create([
            'table_name' => $tableName,
            'record_id' => $recordId,
            'action' => $action,
            'actor_id' => auth()->id(),
            'before_value' => $before,
            'after_value' => $after,
            'created_at' => now(),
        ]);
    }
}
