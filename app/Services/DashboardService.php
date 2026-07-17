<?php

namespace App\Services;

use App\Modules\Config\Services\ConfigService;
use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Legal\Models\LegalCase;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped dashboard stats (no fake Marketing numbers).
 */
class DashboardService
{
    public function __construct(
        protected TenantFeatureService $features,
        protected ConfigService $config,
    ) {}

    /** @return array{cards: list<array<string, mixed>>, activities: list<array<string, mixed>>, firm: string, shortcuts: list<array<string, string>>} */
    public function payload(): array
    {
        $inventoryEnabled = $this->features->enabled('INVENTORY');
        $legalEnabled = $this->features->enabled('LEGAL');

        $itemCount = $inventoryEnabled ? InventoryItem::query()->count() : 0;
        $lowStock = $inventoryEnabled
            ? InventoryItem::query()->whereColumn('stock', '<=', 'minimum_stock')->count()
            : 0;
        $openCases = $legalEnabled
            ? LegalCase::query()->whereIn('status', ['open', 'pending'])->count()
            : 0;
        $modules = count($this->features->enabledModules());

        $firm = $this->config->constValue('APP', 'NAME')?->str1
            ?? (tenant()?->name ?? 'Tenant');

        $cards = [
            [
                'title' => 'Total Inventory Items',
                'value' => number_format($itemCount),
                'description' => $inventoryEnabled ? 'SKUs in this firm' : 'Module off',
                'icon' => 'Boxes',
                'href' => $inventoryEnabled ? '/inventory/items' : null,
            ],
            [
                'title' => 'Low Stock Items',
                'value' => number_format($lowStock),
                'description' => $lowStock > 0 ? 'Need attention' : 'All above minimum',
                'icon' => 'TriangleAlert',
                'href' => $inventoryEnabled ? '/inventory/items' : null,
            ],
            [
                'title' => 'Active Modules',
                'value' => (string) $modules,
                'description' => 'Plan: '.(tenant()?->plan ?? 'starter'),
                'icon' => 'LayoutGrid',
                'href' => null,
            ],
            [
                'title' => 'Open Legal Cases',
                'value' => number_format($openCases),
                'description' => $legalEnabled ? 'Open + pending' : 'Module off',
                'icon' => 'Scale',
                'href' => $legalEnabled ? '/legal/cases' : null,
            ],
        ];

        return [
            'firm' => $firm,
            'cards' => $cards,
            'activities' => $this->recentActivities($inventoryEnabled, $legalEnabled),
            'shortcuts' => array_values(array_filter([
                $inventoryEnabled ? ['label' => 'Manage Inventory', 'href' => '/inventory/items', 'icon' => 'Boxes'] : null,
                $legalEnabled ? ['label' => 'Legal Cases', 'href' => '/legal/cases', 'icon' => 'Scale'] : null,
                ['label' => 'Users', 'href' => '/config/users', 'icon' => 'UserRoundCog'],
            ])),
        ];
    }

    /**
     * @return list<array{id: string, module: string, action: string, user: string, time: string}>
     */
    private function recentActivities(bool $inventoryEnabled, bool $legalEnabled): array
    {
        $rows = collect();

        if ($inventoryEnabled) {
            InventoryItem::query()
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(['id', 'code', 'name', 'updated_at', 'created_at'])
                ->each(function (InventoryItem $item) use ($rows) {
                    $created = $item->created_at?->eq($item->updated_at);
                    $rows->push([
                        'id' => 'inv-'.$item->id,
                        'module' => 'Inventory',
                        'action' => ($created ? 'Created' : 'Updated').' item '.$item->code,
                        'user' => '—',
                        'at' => $item->updated_at,
                    ]);
                });
        }

        if ($legalEnabled) {
            LegalCase::query()
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(['id', 'code', 'title', 'status', 'updated_at', 'created_at'])
                ->each(function (LegalCase $case) use ($rows) {
                    $created = $case->created_at?->eq($case->updated_at);
                    $rows->push([
                        'id' => 'case-'.$case->id,
                        'module' => 'Legal',
                        'action' => ($created ? 'Opened' : 'Updated').' '.$case->code.' ('.$case->status.')',
                        'user' => '—',
                        'at' => $case->updated_at,
                    ]);
                });
        }

        return $rows
            ->sortByDesc(fn ($r) => $r['at']?->timestamp ?? 0)
            ->take(10)
            ->values()
            ->map(fn ($r) => [
                'id' => $r['id'],
                'module' => $r['module'],
                'action' => $r['action'],
                'user' => $r['user'],
                'time' => $r['at'] instanceof Carbon
                    ? $r['at']->diffForHumans()
                    : '',
            ])
            ->all();
    }
}
