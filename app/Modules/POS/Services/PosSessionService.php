<?php

namespace App\Modules\POS\Services;

use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS_SPECS.md §3C, §3D — POS Session (Cash Shift) & Cash Movements.
 */
class PosSessionService
{
    public function __construct(
        protected PosSupervisorService $supervisorService,
    ) {}

    public function openSession(int $terminalId, int $userId, float $openingCash = 0.0, ?int $employeeId = null): PosSession
    {
        $existingOpen = PosSession::query()
            ->where('terminal_id', $terminalId)
            ->where('status', PosSession::STATUS_OPEN)
            ->first();

        if ($existingOpen) {
            throw ValidationException::withMessages([
                'terminal_id' => ["Terminal already has an active open session (#{$existingOpen->id})."],
            ]);
        }

        $terminal = PosTerminal::query()->findOrFail($terminalId);
        if (! $terminal->is_active) {
            throw ValidationException::withMessages([
                'terminal_id' => ['Cannot open session on an inactive terminal.'],
            ]);
        }

        return PosSession::query()->create([
            'terminal_id' => $terminalId,
            'cashier_user_id' => $userId,
            'cashier_employee_id' => $employeeId,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'status' => PosSession::STATUS_OPEN,
        ]);
    }

    public function addCashMovement(int $sessionId, string $type, float $amount, ?string $reason, int $userId): PosCashMovement
    {
        $session = PosSession::query()->findOrFail($sessionId);

        if ($session->status !== PosSession::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'session_id' => ['Cannot add cash movement to a closed session.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be greater than zero.'],
            ]);
        }

        return PosCashMovement::query()->create([
            'session_id' => $sessionId,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'user_id' => $userId,
            'occurred_at' => now(),
        ]);
    }

    public function closeSession(
        int $sessionId,
        float $actualCash,
        int $closedByUserId,
        ?string $supervisorPin = null,
        ?int $approvedByUserId = null
    ): PosSession {
        return DB::transaction(function () use ($sessionId, $actualCash, $closedByUserId, $supervisorPin, $approvedByUserId) {
            $session = PosSession::query()->findOrFail($sessionId);

            if ($session->status !== PosSession::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'session_id' => ['Session is already closed.'],
                ]);
            }

            // Calculate expected cash
            $openingCash = (float) $session->opening_cash;

            $cashIn = (float) PosCashMovement::query()
                ->where('session_id', $sessionId)
                ->where('type', PosCashMovement::TYPE_CASH_IN)
                ->sum('amount');

            $cashOut = (float) PosCashMovement::query()
                ->where('session_id', $sessionId)
                ->whereIn('type', [PosCashMovement::TYPE_CASH_OUT, PosCashMovement::TYPE_PETTY_CASH])
                ->sum('amount');

            // Cash sales minus cash change given
            $completedTxnIds = PosTxnHdr::query()
                ->where('session_id', $sessionId)
                ->where('status', PosTxnHdr::STATUS_COMPLETED)
                ->pluck('id');

            $cashPayments = (float) PosPayment::query()
                ->whereIn('txn_id', $completedTxnIds)
                ->where('method', PosPayment::METHOD_CASH)
                ->sum(DB::raw('amount - change_given'));

            $expectedCash = $openingCash + $cashIn - $cashOut + $cashPayments;
            $variance = $actualCash - $expectedCash;

            $threshold = (float) ConfigConst::query()
                ->where('const_group', 'POS')
                ->where('group_code', 'POS_CASH_VARIANCE_THRESHOLD')
                ->value('value') ?: 50000.0;

            $approvedBy = $approvedByUserId;

            if (abs($variance) > $threshold) {
                if ($supervisorPin !== null) {
                    $approvedBy = $this->supervisorService->verifyPinAndGetUserId($supervisorPin);
                    if (! $approvedBy) {
                        throw ValidationException::withMessages([
                            'supervisor_pin' => ['Invalid supervisor PIN for variance sign-off.'],
                        ]);
                    }
                } elseif (! $approvedBy) {
                    throw ValidationException::withMessages([
                        'variance' => ["Cash variance of {$variance} exceeds threshold of {$threshold}. Supervisor approval is required."],
                    ]);
                }
            }

            $session->update([
                'status' => PosSession::STATUS_CLOSED,
                'closed_at' => now(),
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'variance' => $variance,
                'closed_by' => $closedByUserId,
                'approved_by' => $approvedBy,
            ]);

            return $session->refresh();
        });
    }

    public function getSessionSummary(int $sessionId): array
    {
        $session = PosSession::query()->with(['terminal', 'cashier'])->findOrFail($sessionId);

        $txns = PosTxnHdr::query()->where('session_id', $sessionId)->get();
        $completedTxns = $txns->where('status', PosTxnHdr::STATUS_COMPLETED);

        $payments = PosPayment::query()
            ->whereIn('txn_id', $completedTxns->pluck('id'))
            ->get();

        $paymentBreakdown = [];
        foreach ($payments as $payment) {
            $netAmount = (float) $payment->amount - (float) $payment->change_given;
            $paymentBreakdown[$payment->method] = ($paymentBreakdown[$payment->method] ?? 0.0) + $netAmount;
        }

        return [
            'session' => $session,
            'total_sales' => (float) $completedTxns->sum('grand_total'),
            'transaction_count' => $completedTxns->count(),
            'void_count' => $txns->where('status', PosTxnHdr::STATUS_VOIDED)->count(),
            'payment_breakdown' => $paymentBreakdown,
            'opening_cash' => (float) $session->opening_cash,
            'expected_cash' => (float) ($session->expected_cash ?? 0),
            'actual_cash' => (float) ($session->actual_cash ?? 0),
            'variance' => (float) ($session->variance ?? 0),
        ];
    }
}
