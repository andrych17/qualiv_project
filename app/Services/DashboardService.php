<?php

namespace App\Services;

use App\Modules\Inventory\Models\InventoryItem;
use App\Modules\Legal\Models\Matter;
use App\Modules\Projects\Models\Issue;
use App\Modules\SysConfig\Services\ConfigService;
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
        $projectsEnabled = $this->features->enabled('PROJECTS');

        $itemCount = $inventoryEnabled ? InventoryItem::query()->count() : 0;
        $lowStock = $inventoryEnabled
            ? InventoryItem::query()->whereColumn('stock', '<=', 'minimum_stock')->count()
            : 0;
        $openMatters = $legalEnabled
            ? Matter::query()->whereIn('status', ['open', 'in_progress', 'on_hold'])->count()
            : 0;
        $openIssues = $projectsEnabled
            ? Issue::query()->where('status', '!=', 'done')->count()
            : 0;
        $modules = count($this->features->enabledModules());

        $firm = $this->config->constValue('APP', 'NAME')?->str1
            ?? (tenant()?->name ?? 'Tenant');

        // ponytail: module-off cards (Inventory 0 / Module off) are noise — only show
        // cards for modules the plan actually enables, plus the Active Modules card.
        $cards = array_values(array_filter([
            $inventoryEnabled ? [
                'title' => 'Total Inventory Items',
                'value' => number_format($itemCount),
                'description' => 'SKUs in this firm',
                'icon' => 'Boxes',
                'href' => '/inventory/items',
            ] : null,
            $inventoryEnabled ? [
                'title' => 'Low Stock Items',
                'value' => number_format($lowStock),
                'description' => $lowStock > 0 ? 'Need attention' : 'All above minimum',
                'icon' => 'TriangleAlert',
                'href' => '/inventory/items',
            ] : null,
            $projectsEnabled ? [
                'title' => 'Open Issues',
                'value' => number_format($openIssues),
                'description' => 'Not done across all projects',
                'icon' => 'CircleDot',
                'href' => '/projects',
            ] : null,
            [
                'title' => 'Active Modules',
                'value' => (string) $modules,
                'description' => 'Plan: '.(tenant()?->plan ?? 'starter'),
                'icon' => 'LayoutGrid',
                'href' => null,
            ],
            $legalEnabled ? [
                'title' => 'Open Legal Matters',
                'value' => number_format($openMatters),
                'description' => 'Open, in progress, or on hold',
                'icon' => 'Scale',
                'href' => '/legal/matters',
            ] : null,
        ]));

        return [
            'firm' => $firm,
            'cards' => $cards,
            'activities' => $this->recentActivities($inventoryEnabled, $legalEnabled, $projectsEnabled),
            'shortcuts' => array_values(array_filter([
                $inventoryEnabled ? ['label' => 'Manage Inventory', 'href' => '/inventory/items', 'icon' => 'Boxes'] : null,
                $legalEnabled ? ['label' => 'Legal Matters', 'href' => '/legal/matters', 'icon' => 'Scale'] : null,
                $projectsEnabled ? ['label' => 'Projects', 'href' => '/projects', 'icon' => 'Kanban'] : null,
                // Users is admin/config chrome — hidden on the internal plan (Jira board only).
                (tenant()?->plan ?? 'starter') !== 'internal' ? ['label' => 'Users', 'href' => '/config/users', 'icon' => 'UserRoundCog'] : null,
            ])),
        ];
    }

    /**
     * @return list<array{id: string, module: string, action: string, user: string, time: string}>
     */
    private function recentActivities(bool $inventoryEnabled, bool $legalEnabled, bool $projectsEnabled): array
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
            Matter::query()
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(['id', 'code', 'title', 'status', 'updated_at', 'created_at'])
                ->each(function (Matter $matter) use ($rows) {
                    $created = $matter->created_at?->eq($matter->updated_at);
                    $rows->push([
                        'id' => 'matter-'.$matter->id,
                        'module' => 'Legal',
                        'action' => ($created ? 'Opened' : 'Updated').' '.$matter->code.' ('.$matter->status.')',
                        'user' => '—',
                        'at' => $matter->updated_at,
                    ]);
                });
        }

        if ($projectsEnabled) {
            Issue::query()
                ->with('project:id,code')
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(['id', 'code', 'title', 'status', 'project_id', 'updated_at', 'created_at'])
                ->each(function (Issue $issue) use ($rows) {
                    $created = $issue->created_at?->eq($issue->updated_at);
                    $rows->push([
                        'id' => 'issue-'.$issue->id,
                        'module' => 'Projects',
                        'action' => ($created ? 'Opened' : 'Updated').' '.$issue->code.' ('.$issue->status.')',
                        'user' => '—',
                        'at' => $issue->updated_at,
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
