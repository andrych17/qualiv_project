<?php

namespace App\Modules\Legal\Events;

use App\Modules\Legal\Models\Deed;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §3C — fired when a deed reaches `signed`. No listener yet (same as DMS's
 * DocumentUploaded); WNE integration is added by wiring a listener here, never by
 * Legal calling WNE directly (CLAUDE.md §2 decoupling).
 */
class LegalDeedSigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Deed $deed) {}
}
