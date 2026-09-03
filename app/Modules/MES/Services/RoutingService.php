<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\RoutingOp;
use Illuminate\Support\Facades\DB;

/** MES_SPECS.md §3E — discrete routing header + ops CRUD, same "one active version per product" discipline as PP's BomService. */
class RoutingService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Routing
    {
        return DB::transaction(function () use ($data) {
            if ($data['is_active'] ?? true) {
                $this->deactivateOthers($data['product_id']);
            }

            $routing = Routing::query()->create([
                'product_id' => $data['product_id'],
                'version' => $data['version'] ?? $this->nextVersion($data['product_id']),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $this->syncOps($routing, $data['ops'] ?? []);

            return $routing->load('ops');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Routing $routing, array $data): Routing
    {
        return DB::transaction(function () use ($routing, $data) {
            if (($data['is_active'] ?? false) && ! $routing->is_active) {
                $this->deactivateOthers($routing->product_id);
            }

            $routing->update([
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $routing->is_active,
            ]);

            $this->syncOps($routing, $data['ops'] ?? []);

            return $routing->refresh()->load('ops');
        });
    }

    public function delete(Routing $routing): void
    {
        $routing->delete();
    }

    private function nextVersion(int $productId): int
    {
        return (int) Routing::query()->where('product_id', $productId)->max('version') + 1;
    }

    /** Only one `is_active` routing per product (§3E rule) — deactivate before the new one is written, same DB partial-unique-index defense as the migration. */
    private function deactivateOthers(int $productId): void
    {
        Routing::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /** @param  list<array<string, mixed>>  $ops */
    private function syncOps(Routing $routing, array $ops): void
    {
        $routing->ops()->delete();

        foreach ($ops as $seq => $op) {
            if (empty($op['op_code']) || empty($op['op_name']) || empty($op['work_center_id'])) {
                continue;
            }

            RoutingOp::query()->create([
                'routing_id' => $routing->id,
                'seq' => ($seq + 1) * 10,
                'op_code' => $op['op_code'],
                'op_name' => $op['op_name'],
                'work_center_id' => $op['work_center_id'],
                'setup_time_minutes' => $op['setup_time_minutes'] ?? 0,
                'run_time_minutes' => $op['run_time_minutes'] ?? 0,
                'queue_time_minutes' => $op['queue_time_minutes'] ?? 0,
                'standard_output_qty' => $op['standard_output_qty'] ?? null,
                'instructions' => $op['instructions'] ?? null,
                'auto_issue_components' => array_key_exists('auto_issue_components', $op) ? (bool) $op['auto_issue_components'] : true,
                'is_rework_destination' => (bool) ($op['is_rework_destination'] ?? false),
            ]);
        }
    }
}
