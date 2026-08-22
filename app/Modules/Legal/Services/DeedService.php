<?php

namespace App\Modules\Legal\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Legal\Events\LegalDeedSigned;
use App\Modules\Legal\Models\BpnSubmission;
use App\Modules\Legal\Models\Deed;
use App\Modules\Legal\Models\DeedTax;
use App\Modules\Legal\Models\DeedType;
use App\Modules\Legal\Models\DueDiligenceCheck;
use App\Modules\Legal\Models\ProtocolBook;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeedService
{
    // ponytail: one flat entity_type for now. Spec wants fields per deed_type — split into
    // "legal_deed_{deed_type.code}" once a real deed type needs its own fields; today every
    // notary deed type shares the same generic form.
    public const ENTITY = 'legal_deed';

    public function __construct(
        protected CustomFieldService $customFields,
        protected ProtocolBookService $protocol,
        protected BpnSubmissionService $bpn,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Deed
    {
        $custom = $this->customFields->validateAndNormalize(
            self::ENTITY,
            $data['custom_fields'] ?? [],
        );
        unset($data['custom_fields']);

        $deedType = DeedType::query()->findOrFail($data['deed_type_id']);
        $data['category'] = $deedType->category;
        $data['status'] = Deed::STATUS_DRAFT;

        return DB::transaction(function () use ($data, $custom) {
            $deed = Deed::query()->create($data);
            $this->customFields->sync(self::ENTITY, $deed->id, $custom);

            return $deed;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Deed $deed, array $data): Deed
    {
        if ($deed->isLocked()) {
            throw new RuntimeException('Signed deeds are immutable — create an amending deed instead of editing this one.');
        }

        $custom = $this->customFields->validateAndNormalize(
            self::ENTITY,
            $data['custom_fields'] ?? [],
        );
        unset($data['custom_fields']);

        if (isset($data['deed_type_id'])) {
            $data['category'] = DeedType::query()->findOrFail($data['deed_type_id'])->category;
        }

        return DB::transaction(function () use ($deed, $data, $custom) {
            $deed->update($data);
            $this->customFields->sync(self::ENTITY, $deed->id, $custom);

            return $deed->refresh();
        });
    }

    /** Forward-only lifecycle move (§3C draft → ready_for_signing → signed → archived). */
    public function transition(Deed $deed, string $toStatus): Deed
    {
        $allowed = Deed::TRANSITIONS[$deed->status] ?? [];
        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException("Cannot move deed from '{$deed->status}' to '{$toStatus}'.");
        }

        if ($toStatus === Deed::STATUS_SIGNED && empty($deed->signing_date)) {
            throw new RuntimeException('Signing date is required before a deed can be signed.');
        }

        // §3G hard gate — mirrors the real host-to-host DJP↔BPN check as an app-level
        // workflow gate, not an actual integration (§5 scope note).
        if ($toStatus === Deed::STATUS_SIGNED && $deed->category === DeedType::CATEGORY_PPAT) {
            if (! $deed->land_object_id) {
                throw new RuntimeException('A PPAT deed must reference a land object before it can be signed.');
            }

            // (a) due diligence
            $blocking = DueDiligenceCheck::query()->where('land_object_id', $deed->land_object_id)->get()
                ->contains(fn (DueDiligenceCheck $c) => $c->isBlocking());

            if ($blocking) {
                throw new RuntimeException('This land object has an unresolved flagged due-diligence check — resolve or override it before signing.');
            }

            // (b) tax obligations — only for deed types the tenant has flagged as requiring it
            // (deed_types.requires_tax), since not every PPAT act (e.g. APHT) is a sale.
            if ($deed->deedType->requires_tax) {
                $taxes = DeedTax::query()->where('deed_id', $deed->id)->get();
                foreach (DeedTax::TYPES as $type) {
                    $row = $taxes->firstWhere('tax_type', $type);
                    if (! $row || $row->status !== DeedTax::STATUS_VALIDATED) {
                        throw new RuntimeException("Tax obligation '{$type}' is not yet validated — signing is blocked until both PPh Final and BPHTB are paid_and_validated.");
                    }
                }
            }
        }

        return DB::transaction(function () use ($deed, $toStatus) {
            if ($toStatus === Deed::STATUS_SIGNED) {
                // §3F: "protocol ledger numbering for all" deed types — repertorium is the
                // general-purpose register, used whenever a deed type has no more specific book.
                $bookType = $deed->deedType->default_protocol_book_type ?? ProtocolBook::TYPE_REPERTORIUM;
                $year = (int) $deed->signing_date->format('Y');
                $book = $this->protocol->findActiveBook($bookType, $year);

                if (! $book) {
                    throw new RuntimeException("No active '{$bookType}' protocol book found for {$year} — open one before signing.");
                }

                $entry = $this->protocol->recordEntry($book, $deed, $deed->signing_date->toDateString());
                $deed->update([
                    'status' => $toStatus,
                    'deed_number' => "{$entry->sequence_number}/{$book->abbreviation()}/{$year}",
                ]);

                // §3G: every PPAT deed type requiring BPN follow-up gets a pending
                // bpn_submissions row the moment it's signed (§3L).
                if ($deed->category === DeedType::CATEGORY_PPAT && $deed->deedType->requires_bpn_registration) {
                    $this->bpn->createPending($deed, BpnSubmission::TYPE_BALIK_NAMA);
                }

                LegalDeedSigned::dispatch($deed);
            } else {
                $deed->update(['status' => $toStatus]);
            }

            return $deed->refresh();
        });
    }

    public function delete(Deed $deed): void
    {
        if ($deed->isLocked()) {
            throw new RuntimeException('Signed deeds cannot be deleted.');
        }

        DB::transaction(function () use ($deed) {
            $this->customFields->deleteFor(self::ENTITY, $deed->id);
            $deed->delete();
        });
    }
}
