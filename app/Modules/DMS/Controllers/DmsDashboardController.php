<?php

namespace App\Modules\DMS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DMS\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A — DMS's own landing page: library-health summary cards + a tabbed recent-activity
 * preview (Recent Uploads | Expiring Soon | On Legal Hold), mirroring WNE's dashboard
 * (WneDashboardController, WNE_SPECS.md §3A). Every tab here is a capped, read-only
 * preview — the full browse/filter/upload experience is DocumentController::index()
 * (DMS/Documents/Index.vue), which this dashboard links out to, not duplicates.
 */
class DmsDashboardController extends Controller
{
    private const CAP = 10;

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $recentUploads = Document::query()
            ->accessibleTo($userId)
            ->whereNotIn('status', [Document::STATUS_PURGED])
            ->with(['folder:id,name', 'docType:id,name'])
            ->orderByDesc('created_at')
            ->limit(self::CAP)
            ->get();

        $expiringSoon = Document::query()
            ->accessibleTo($userId)
            ->filter(['flag' => 'expiring_soon'])
            ->with(['folder:id,name', 'docType:id,name'])
            ->orderBy('expiry_date')
            ->limit(self::CAP)
            ->get();

        $onLegalHold = Document::query()
            ->accessibleTo($userId)
            ->where('legal_hold', true)
            ->with(['folder:id,name', 'docType:id,name'])
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get();

        return Inertia::render('DMS/Dashboard/Index', [
            'summary' => [
                'total_documents' => Document::query()->accessibleTo($userId)->count(),
                'active_documents' => Document::query()->accessibleTo($userId)->where('status', Document::STATUS_ACTIVE)->count(),
                'expiring_soon' => Document::query()->accessibleTo($userId)->filter(['flag' => 'expiring_soon'])->count(),
                'on_legal_hold' => Document::query()->accessibleTo($userId)->where('legal_hold', true)->count(),
            ],
            'recentUploads' => $recentUploads->map(fn (Document $d) => $this->row($d)),
            'expiringSoon' => $expiringSoon->map(fn (Document $d) => $this->row($d)),
            'onLegalHold' => $onLegalHold->map(fn (Document $d) => $this->row($d)),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Document $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'folder_name' => $d->folder?->name,
            'doc_type_name' => $d->docType?->name,
            'status' => $d->status,
            'rail' => $d->rail,
            'expiry_date_formatted' => $d->expiry_date?->format('d M Y'),
            'created_at_formatted' => $d->created_at?->format('d M Y H:i'),
        ];
    }
}
