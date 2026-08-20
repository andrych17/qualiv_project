<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigSnumService
{
    public function __construct(
        protected ConfigAuditLogger $audit,
    ) {}

    public function create(array $data): ConfigSnum
    {
        $row = ConfigSnum::query()->create($this->attrs($data));
        $this->audit->log('config_snums', $row->id, 'created', null, $this->snapshot($row));

        return $row;
    }

    public function update(ConfigSnum $snum, array $data): ConfigSnum
    {
        $before = $this->snapshot($snum);
        $snum->update($this->attrs($data));
        $snum->refresh();
        $action = (int) $before['last_cnt'] !== (int) $snum->last_cnt ? 'serial_corrected' : 'updated';
        $this->audit->log('config_snums', $snum->id, $action, $before, $this->snapshot($snum));

        return $snum;
    }

    public function delete(ConfigSnum $snum): void
    {
        $before = $this->snapshot($snum);
        $snum->update(['status_code' => 'I']);
        $this->audit->log('config_snums', $snum->id, 'deactivated', $before, $this->snapshot($snum->refresh()));
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

            $this->applyResetRule($row);

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

    /** @param  array<string, mixed>  $data */
    private function attrs(array $data): array
    {
        return [
            'code' => strtoupper($data['code']),
            'last_cnt' => (int) ($data['last_cnt'] ?? 0),
            'wrap_low' => (int) ($data['wrap_low'] ?? 1),
            'wrap_high' => (int) ($data['wrap_high'] ?? 999999),
            'step_cnt' => max(1, (int) ($data['step_cnt'] ?? 1)),
            'descr' => $data['descr'] ?? null,
            'status_code' => $data['status_code'] ?? 'A',
            'padding_length' => isset($data['padding_length']) && $data['padding_length'] !== '' ? (int) $data['padding_length'] : null,
            'reset_rule' => $data['reset_rule'] ?? 'never',
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(ConfigSnum $snum): array
    {
        return $snum->only(['code', 'last_cnt', 'wrap_low', 'wrap_high', 'step_cnt', 'descr', 'status_code', 'padding_length', 'reset_rule']);
    }

    private function applyResetRule(ConfigSnum $row): void
    {
        $rule = $row->reset_rule ?? 'never';
        if ($rule === 'never') {
            return;
        }

        $period = $rule === 'yearly' ? now()->format('Y') : now()->format('Y-m');
        $last = $row->last_reset_at;
        if ($last === null) {
            // ponytail: first enable stamps the period; do not wipe last_cnt (§3D = period boundary only)
            $row->last_reset_at = now();
            $row->save();

            return;
        }
        $lastPeriod = $rule === 'yearly' ? $last->format('Y') : $last->format('Y-m');
        if ($lastPeriod === $period) {
            return;
        }

        $row->last_cnt = max(0, (int) $row->wrap_low - max(1, (int) $row->step_cnt));
        $row->last_reset_at = now();
        $row->save();
    }
}
