<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Data\VarianceResult;
use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Validation\ValidationException;

/**
 * §3I — manual awarding plus the three auto-award checks, one per BadgeDefinition::TRIGGER_*.
 *
 * De-dup key differs by trigger type, not just "all five columns": `target_hit`/`okr_completed`
 * are naturally per-period/per-objective, so the full tuple is the right key. `streak_on_track`
 * is deliberately keyed WITHOUT `period_id` — it's "earned once per KPI per subject", not once
 * per period. Keying it by period would let a corrected/backfilled actual re-trigger the same
 * badge every time the N-in-a-row count happens to land on the threshold again; see the class's
 * own checkStreakOnTrack() docblock.
 */
class AchievementService
{
    public function __construct(protected VarianceService $variance) {}

    /** Manual award, e.g. a manager recognizing an employee from the Achievements log UI. */
    public function award(array $data): Achievement
    {
        $badge = BadgeDefinition::query()->find($data['badge_id']);

        if ($badge === null || ! $badge->is_active) {
            throw ValidationException::withMessages(['badge_id' => 'This badge does not exist or is inactive.']);
        }

        $subjectType = $data['subject_type'];
        $subjectId = $subjectType === Achievement::SUBJECT_COMPANY ? null : ($data['subject_id'] ?? null);

        return Achievement::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'badge_id' => $badge->id,
            'kpi_id' => $data['kpi_id'] ?? null,
            'okr_id' => $data['okr_id'] ?? null,
            'period_id' => $data['period_id'] ?? null,
            'earned_at' => now(),
            'awarded_by' => auth()->id(),
        ]);
    }

    /** Every active target_hit badge fires when a KPI/subject/period lands on_track. */
    public function checkTargetHit(string $subjectType, ?int $subjectId, int $kpiId, int $periodId): void
    {
        $result = $this->variance->evaluateKpi($subjectType, $subjectId, $kpiId, $periodId);

        if ($result === null || $result->status !== VarianceResult::STATUS_ON_TRACK) {
            return;
        }

        $badges = BadgeDefinition::query()
            ->where('trigger_type', BadgeDefinition::TRIGGER_TARGET_HIT)
            ->where('is_active', true)
            ->get();

        foreach ($badges as $badge) {
            if ($this->alreadyAwarded($badge->id, $subjectType, $subjectId, kpiId: $kpiId, periodId: $periodId)) {
                continue;
            }

            $this->createAchievement($badge, $subjectType, $subjectId, kpiId: $kpiId, periodId: $periodId);
        }
    }

    /**
     * Walks periods of the SAME period_type as $periodId, ordered by start_date desc, counting
     * consecutive on_track results starting from $periodId itself, and awards the badge the
     * first time (ever) that count reaches exactly trigger_params.streak_length. Deduped without
     * period_id (see class docblock), so once awarded it never fires again for this badge/KPI/
     * subject — a later break-and-recover of the streak, or an out-of-order backfill, can't
     * mint a second copy of the same "N-peat" badge.
     */
    public function checkStreakOnTrack(string $subjectType, ?int $subjectId, int $kpiId, int $periodId): void
    {
        $badges = BadgeDefinition::query()
            ->where('trigger_type', BadgeDefinition::TRIGGER_STREAK_ON_TRACK)
            ->where('is_active', true)
            ->get();

        if ($badges->isEmpty()) {
            return;
        }

        $currentPeriod = Period::query()->find($periodId);

        if ($currentPeriod === null) {
            return;
        }

        foreach ($badges as $badge) {
            $streakLength = (int) ($badge->trigger_params['streak_length'] ?? 0);

            if ($streakLength <= 0) {
                continue;
            }

            if ($this->alreadyAwarded($badge->id, $subjectType, $subjectId, kpiId: $kpiId, periodId: null)) {
                continue;
            }

            $count = $this->consecutiveOnTrackCount($subjectType, $subjectId, $kpiId, $currentPeriod, $streakLength);

            if ($count === $streakLength) {
                // period_id deliberately omitted (not just left to default) — the dedup key
                // above checks periodId: null, so the stored row must match it exactly or a
                // later period reaching the same streakLength would slip past the dedup check.
                $this->createAchievement($badge, $subjectType, $subjectId, kpiId: $kpiId);
            }
        }
    }

    /** Fires when an OKR Objective transitions into 'completed' (see OkrObjectiveCompleted). */
    public function checkOkrCompleted(int $objectiveId): void
    {
        $objective = OkrObjective::query()->find($objectiveId);

        if ($objective === null || $objective->status !== OkrObjective::STATUS_COMPLETED) {
            return;
        }

        $badges = BadgeDefinition::query()
            ->where('trigger_type', BadgeDefinition::TRIGGER_OKR_COMPLETED)
            ->where('is_active', true)
            ->get();

        foreach ($badges as $badge) {
            if ($this->alreadyAwarded($badge->id, $objective->subject_type, $objective->subject_id, okrId: $objective->id)) {
                continue;
            }

            $this->createAchievement($badge, $objective->subject_type, $objective->subject_id, okrId: $objective->id);
        }
    }

    private function consecutiveOnTrackCount(string $subjectType, ?int $subjectId, int $kpiId, Period $currentPeriod, int $limit): int
    {
        $periods = Period::query()
            ->where('period_type', $currentPeriod->period_type)
            ->where('start_date', '<=', $currentPeriod->start_date)
            ->orderByDesc('start_date')
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($periods as $period) {
            $result = $this->variance->evaluateKpi($subjectType, $subjectId, $kpiId, $period->id);

            if ($result !== null && $result->status === VarianceResult::STATUS_ON_TRACK) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    private function alreadyAwarded(
        int $badgeId,
        string $subjectType,
        ?int $subjectId,
        ?int $kpiId = null,
        ?int $okrId = null,
        ?int $periodId = null,
    ): bool {
        return Achievement::query()
            ->where('badge_id', $badgeId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kpi_id', $kpiId)
            ->where('okr_id', $okrId)
            ->where('period_id', $periodId)
            ->exists();
    }

    private function createAchievement(
        BadgeDefinition $badge,
        string $subjectType,
        ?int $subjectId,
        ?int $kpiId = null,
        ?int $okrId = null,
        ?int $periodId = null,
    ): Achievement {
        $achievement = Achievement::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'badge_id' => $badge->id,
            'kpi_id' => $kpiId,
            'okr_id' => $okrId,
            'period_id' => $periodId,
            'earned_at' => now(),
            'awarded_by' => null,
        ]);

        // MVP simplification, same as EvaluateKpiValueVariance: broadcast to ADMIN role rather
        // than resolving the actual subject's manager/owner (no such mapping exists yet).
        NotificationRequested::dispatch(
            category: 'performance.achievement_earned',
            recipient: ['type' => 'role', 'role' => 'ADMIN'],
            payload: [
                'achievement_id' => $achievement->id,
                'badge_id' => $badge->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ],
            subjectType: 'performance.achievements',
            subjectId: $achievement->id,
            subject: "Achievement earned: {$badge->name}",
            body: "{$badge->name} was just earned.",
        );

        return $achievement;
    }
}
