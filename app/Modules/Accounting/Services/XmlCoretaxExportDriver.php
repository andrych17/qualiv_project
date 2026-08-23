<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Contracts\CoretaxExportDriverInterface;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Support\Collection;

/**
 * §3M Coretax XML export — the element/attribute names below are a reasonable-shape
 * placeholder, NOT verified against DJP's published Coretax import schema (no access to
 * it from this environment). Treat this as the driver *seam* being real and correct, not
 * the XML being filing-ready — a tenant's tax preparer must validate the structure
 * against Coretax's actual import spec before this is used to file anything.
 */
class XmlCoretaxExportDriver implements CoretaxExportDriverInterface
{
    public function export(Company $company, TaxPeriod $period, string $batchType, Collection $records): string
    {
        $xml = new \SimpleXMLElement('<CoretaxBatch/>');
        $xml->addAttribute('batchType', $batchType);
        $xml->addAttribute('masaPajak', $period->masa_pajak);
        $xml->addAttribute('generatedAt', now()->toAtomString());

        $companyEl = $xml->addChild('Company');
        $companyEl->addChild('LegalName', htmlspecialchars($company->legal_name));
        $companyEl->addChild('NPWP', htmlspecialchars((string) $company->npwp));

        $recordsEl = $xml->addChild('Records');

        foreach ($records as $record) {
            if ($record instanceof TaxFakturPajak) {
                $this->appendFaktur($recordsEl, $record);
            } elseif ($record instanceof TaxBuktiPotong) {
                $this->appendBuktiPotong($recordsEl, $record);
            }
        }

        return $xml->asXML() ?: '';
    }

    private function appendFaktur(\SimpleXMLElement $parent, TaxFakturPajak $faktur): void
    {
        $el = $parent->addChild('FakturPajak');
        $el->addAttribute('nomorSeriFaktur', $faktur->nomor_seri_faktur);
        $el->addAttribute('direction', $faktur->direction);
        $el->addChild('BuyerNpwpNik', htmlspecialchars((string) $faktur->buyer_npwp_nik));
        $el->addChild('TaxBase', (string) $faktur->tax_base);
        $el->addChild('PpnAmount', (string) $faktur->ppn_amount);
        $el->addChild('IssuedAt', $faktur->issued_at->toAtomString());
    }

    private function appendBuktiPotong(\SimpleXMLElement $parent, TaxBuktiPotong $bp): void
    {
        $el = $parent->addChild('BuktiPotong');
        $el->addAttribute('bpNumber', $bp->bp_number);
        $el->addAttribute('bpType', $bp->bp_type);
        $el->addChild('GrossAmount', (string) $bp->gross_amount);
        $el->addChild('WithheldAmount', (string) $bp->withheld_amount);
        $el->addChild('IssuedAt', $bp->issued_at->toAtomString());
    }
}
