<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\FakturPajakNumberBlock;
use Illuminate\Validation\ValidationException;

/** §3M — tenant-entered DJP number-allocation blocks that FakturPajakService::issueOutput() draws from. */
class FakturPajakNumberBlockService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): FakturPajakNumberBlock
    {
        if ((int) $data['range_start'] > (int) $data['range_end']) {
            throw ValidationException::withMessages(['range_end' => 'Range end must be on or after range start.']);
        }

        return FakturPajakNumberBlock::query()->create($data);
    }

    public function deactivate(FakturPajakNumberBlock $block): FakturPajakNumberBlock
    {
        $block->update(['is_active' => false]);

        return $block->refresh();
    }
}
