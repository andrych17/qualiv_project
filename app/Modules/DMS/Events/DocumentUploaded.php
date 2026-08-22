<?php

namespace App\Modules\DMS\Events;

use App\Modules\DMS\Models\Document;
use App\Modules\DMS\Models\DocumentVersion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * §5 internal event bus — fired after every upload (new document or new version), whether
 * the caller went through DocumentService::upload() or ::uploadNewVersion(). No listener
 * exists yet: OCR text extraction and auto-tagging (§3G) are Future Version no-ops that will
 * subscribe here later, without DocumentService itself ever needing to know they exist.
 */
class DocumentUploaded
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public bool $isNewDocument,
    ) {}
}
