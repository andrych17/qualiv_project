<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\QcResult;
use App\Modules\MES\Models\QcSample;
use App\Modules\SysConfig\Services\ConfigSnumService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3L — recording a sample is its own composable action (order-scoped for
 * assembly, batch-phase-scoped for process), decoupled from Complete/Complete-Phase — same
 * "independent panel on the order" posture §3J's Material Consumption/Output already use,
 * rather than threading QC into `OperationExecutionService::complete()`/
 * `BatchExecutionService::completePhase()`'s own transactions. A sample optionally names which
 * `mes_production_outputs` row it's inspecting (the "finished-goods checkpoint") — a `fail`
 * against one auto-creates a hold on that output's lot/serial (§3L Rules/Logic), same as if the
 * sample were forced into the completion flow, without the extra coupling.
 */
class QcInspectionService
{
    public function __construct(
        protected ConfigSnumService $serials,
        protected MesAuditLogger $audit,
    ) {}

    /**
     * @param  array{order_id?: int, batch_phase_id?: int, output_id?: int, results: list<array{characteristic_id: int, actual_value?: float|null, result: string}>}  $data
     */
    public function recordSample(array $data, int $userId): QcSample
    {
        return DB::transaction(function () use ($data, $userId) {
            $sample = QcSample::query()->create([
                'order_id' => $data['order_id'] ?? null,
                'batch_phase_id' => $data['batch_phase_id'] ?? null,
                'sample_number' => $this->nextSampleNumber(),
                'taken_by' => $userId,
                'taken_at' => now(),
            ]);

            $anyFail = false;
            foreach ($data['results'] as $result) {
                QcResult::query()->create([
                    'sample_id' => $sample->id,
                    'characteristic_id' => $result['characteristic_id'],
                    'actual_value' => $result['actual_value'] ?? null,
                    'result' => $result['result'],
                ]);

                if ($result['result'] === QcResult::RESULT_FAIL) {
                    $anyFail = true;
                }
            }

            if ($anyFail && ! empty($data['output_id'])) {
                $this->holdOutput((int) $data['output_id'], $sample);
            }

            return $sample->load('results');
        });
    }

    /** §3L Rules/Logic: holds "the output lot/serial" when the product is tracked, else the output row itself. */
    private function holdOutput(int $outputId, QcSample $sample): void
    {
        $output = ProductionOutput::query()->find($outputId);
        if (! $output) {
            return;
        }

        [$subjectType, $subjectId] = match (true) {
            $output->lot_id !== null => ['inventory.stock_batches', $output->lot_id],
            $output->serial_id !== null => ['inventory.stock_serials', $output->serial_id],
            default => ['mes.mes_production_outputs', $output->id],
        };

        QcHold::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => "Finished-goods QC fail on sample {$sample->sample_number}.",
            'status' => QcHold::STATUS_OPEN,
            'created_at' => now(),
        ]);
    }

    public function releaseHold(QcHold $hold, ?string $note, int $userId): QcHold
    {
        if ($hold->status !== QcHold::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => 'Only an open hold can be released.']);
        }

        $before = ['status' => $hold->status];

        $hold->update([
            'status' => QcHold::STATUS_RELEASED,
            'released_by' => $userId,
            'released_at' => now(),
            'reason' => $hold->reason.($note ? " | Released: {$note}" : ''),
        ]);

        $this->audit->log('mes.mes_qc_holds', $hold->id, 'released', $before, ['status' => $hold->status, 'note' => $note], $userId);

        return $hold->refresh();
    }

    private function nextSampleNumber(): string
    {
        $n = $this->serials->next('MES_QC_SAMPLE_LASTID');

        return sprintf('QC-%06d', $n);
    }
}
