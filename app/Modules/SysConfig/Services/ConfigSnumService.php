<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigSnumService
{
    public function create(array $data): ConfigSnum
    {
        return ConfigSnum::query()->create([
            'code' => strtoupper($data['code']),
            'last_cnt' => (int) ($data['last_cnt'] ?? 0),
            'wrap_low' => (int) ($data['wrap_low'] ?? 1),
            'wrap_high' => (int) ($data['wrap_high'] ?? 999999),
            'step_cnt' => (int) ($data['step_cnt'] ?? 1),
            'descr' => $data['descr'] ?? null,
            'status_code' => $data['status_code'] ?? 'A',
        ]);
    }

    public function update(ConfigSnum $snum, array $data): ConfigSnum
    {
        $snum->update([
            'code' => strtoupper($data['code']),
            'last_cnt' => (int) $data['last_cnt'],
            'wrap_low' => (int) $data['wrap_low'],
            'wrap_high' => (int) $data['wrap_high'],
            'step_cnt' => max(1, (int) $data['step_cnt']),
            'descr' => $data['descr'] ?? null,
            'status_code' => $data['status_code'] ?? 'A',
        ]);

        return $snum->refresh();
    }

    public function delete(ConfigSnum $snum): void
    {
        $snum->delete();
    }

    /**
     * Atomically allocate next serial for code (netapp1-style wrap).
     * Must run inside tenant context.
     */
    public function next(string $code): int
    {
        return (int) DB::transaction(function () use ($code) {
            /** @var ConfigSnum|null $row */
            $row = ConfigSnum::query()
                ->where('code', strtoupper($code))
                ->where('status_code', 'A')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new RuntimeException("Serial counter [{$code}] not found or inactive.");
            }

            $next = $row->last_cnt + max(1, $row->step_cnt);

            if ($next > $row->wrap_high) {
                $next = $row->wrap_low;
            }
            if ($next < $row->wrap_low) {
                $next = $row->wrap_low;
            }

            $row->update(['last_cnt' => $next]);

            return $next;
        });
    }
}
