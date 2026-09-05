<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Services\AchievementService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3I Achievements Engine — badge/rule library CRUD, manual award, and the streak-on-track auto-award rule. */
class AchievementTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_crud_a_badge_definition_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->get('/performance/badge-definitions')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/BadgeDefinitions/Index'));

        $this->post('/performance/badge-definitions', [
            'name' => 'Notary Champion',
            'trigger_type' => BadgeDefinition::TRIGGER_TARGET_HIT,
            'icon' => 'Award',
        ])->assertRedirect(route('performance.badgeDefinitions.index'));

        $badgeId = null;
        $tenant->run(function () use (&$badgeId) {
            $badgeId = BadgeDefinition::query()->where('name', 'Notary Champion')->value('id');
        });

        $this->put("/performance/badge-definitions/{$badgeId}", [
            'name' => 'Notary Champion (renamed)',
            'trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK,
            'trigger_params' => ['streak_length' => 3],
        ])->assertRedirect(route('performance.badgeDefinitions.index'));

        $tenant->run(function () use ($badgeId) {
            $badge = BadgeDefinition::query()->find($badgeId);
            $this->assertSame('Notary Champion (renamed)', $badge->name);
            $this->assertSame(3, $badge->trigger_params['streak_length']);
        });

        $this->delete("/performance/badge-definitions/{$badgeId}")->assertRedirect(route('performance.badgeDefinitions.index'));
        $tenant->run(function () use ($badgeId) {
            $this->assertNull(BadgeDefinition::query()->find($badgeId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeBadgeDefinition('Bulk A')->id;
            $ids[] = $this->makeBadgeDefinition('Bulk B')->id;
        });
        $this->delete('/performance/badge-definitions/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, BadgeDefinition::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_badge_store_requires_streak_length_only_for_streak_trigger(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/badge-definitions', [
            'name' => 'Missing Streak Length',
            'trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK,
        ])->assertSessionHasErrors(['trigger_params.streak_length']);

        $this->post('/performance/badge-definitions', [
            'name' => 'No Streak Needed',
            'trigger_type' => BadgeDefinition::TRIGGER_OKR_COMPLETED,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_admin_can_manually_award_an_achievement(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $badgeId = null;
        $tenant->run(function () use (&$badgeId) {
            $badgeId = $this->makeBadgeDefinition()->id;
        });

        $this->get('/performance/achievements')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Achievements/Index'));

        $this->post('/performance/achievements', [
            'badge_id' => $badgeId,
            'subject_type' => Achievement::SUBJECT_COMPANY,
        ])->assertRedirect(route('performance.achievements.index'));

        $tenant->run(function () use ($badgeId) {
            $achievement = Achievement::query()->where('badge_id', $badgeId)->first();
            $this->assertNotNull($achievement);
            $this->assertNotNull($achievement->awarded_by);
        });
    }

    public function test_manual_award_rejects_invalid_or_inactive_badge(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->post('/performance/achievements', ['badge_id' => 999999, 'subject_type' => Achievement::SUBJECT_COMPANY])
            ->assertSessionHasErrors(['badge_id']);

        $inactiveBadgeId = null;
        $tenant->run(function () use (&$inactiveBadgeId) {
            $inactiveBadgeId = $this->makeBadgeDefinition('Inactive Badge', ['is_active' => false])->id;
        });

        $tenant->run(function () use ($inactiveBadgeId) {
            $this->expectException(ValidationException::class);
            app(AchievementService::class)->award(['badge_id' => $inactiveBadgeId, 'subject_type' => Achievement::SUBJECT_COMPANY]);
        });
    }

    public function test_manual_award_requires_subject_id_for_non_company_subjects(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $badgeId = null;
        $tenant->run(function () use (&$badgeId) {
            $badgeId = $this->makeBadgeDefinition()->id;
        });

        $this->post('/performance/achievements', ['badge_id' => $badgeId, 'subject_type' => Achievement::SUBJECT_EMPLOYEE])
            ->assertSessionHasErrors(['subject_id']);
    }

    public function test_achievement_index_filters_by_badge_and_subject_type(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $badgeId = null;
        $tenant->run(function () use (&$badgeId) {
            $badge = $this->makeBadgeDefinition();
            $badgeId = $badge->id;
            $this->makeAchievement($badge);
        });

        $this->get("/performance/achievements?badge_id={$badgeId}")->assertOk()->assertInertia(fn ($page) => $page->has('achievements.data', 1));
        $this->get('/performance/achievements?subject_type='.Achievement::SUBJECT_COMPANY)->assertOk()->assertInertia(fn ($page) => $page->has('achievements.data', 1));
        $this->get('/performance/achievements?sort=earned_at&direction=asc')->assertOk();
    }

    // --- AchievementService streak-on-track direct coverage ---

    public function test_streak_on_track_awards_after_reaching_the_configured_length(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(AchievementService::class);
            $kpi = $this->makeKpiDefinition('Streaky KPI', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeBadgeDefinition('3-Peat', ['trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK, 'trigger_params' => ['streak_length' => 3]]);

            $periods = [
                $this->makePeriod('P1', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31']),
                $this->makePeriod('P2', ['start_date' => '2026-02-01', 'end_date' => '2026-02-28']),
                $this->makePeriod('P3', ['start_date' => '2026-03-01', 'end_date' => '2026-03-31']),
            ];

            foreach ($periods as $period) {
                $this->makeTarget($kpi, $period, ['target_value' => 100]);
                $this->makeKpiValue($kpi, $period, ['actual_value' => 100]); // on_track every period
            }

            // Checking after only the first on-track period: streak of 1, not yet 3.
            $service->checkStreakOnTrack('company', null, $kpi->id, $periods[0]->id);
            $this->assertSame(0, Achievement::query()->where('kpi_id', $kpi->id)->count());

            // Checking after the third on-track period: streak of 3 reaches the badge's threshold.
            $service->checkStreakOnTrack('company', null, $kpi->id, $periods[2]->id);
            $this->assertSame(1, Achievement::query()->where('kpi_id', $kpi->id)->count());

            // Checking again (e.g. a later re-save) must not duplicate the badge.
            $service->checkStreakOnTrack('company', null, $kpi->id, $periods[2]->id);
            $this->assertSame(1, Achievement::query()->where('kpi_id', $kpi->id)->count());
        });
    }

    public function test_streak_on_track_ignores_badges_with_no_or_zero_streak_length(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(AchievementService::class);
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->makeKpiValue($kpi, $period, ['actual_value' => 100]);

            $this->makeBadgeDefinition('No Params', ['trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK, 'trigger_params' => null]);
            $this->makeBadgeDefinition('Zero Length', ['trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK, 'trigger_params' => ['streak_length' => 0]]);

            $service->checkStreakOnTrack('company', null, $kpi->id, $period->id);

            $this->assertSame(0, Achievement::query()->count());
        });
    }

    public function test_streak_on_track_breaks_on_a_non_on_track_period_and_returns_early_for_unknown_period(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(AchievementService::class);
            $kpi = $this->makeKpiDefinition('Broken Streak KPI', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeBadgeDefinition('3-Peat', ['trigger_type' => BadgeDefinition::TRIGGER_STREAK_ON_TRACK, 'trigger_params' => ['streak_length' => 2]]);

            $p1 = $this->makePeriod('Break P1', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
            $p2 = $this->makePeriod('Break P2', ['start_date' => '2026-02-01', 'end_date' => '2026-02-28']);

            $this->makeTarget($kpi, $p1, ['target_value' => 100]);
            $this->makeKpiValue($kpi, $p1, ['actual_value' => 10]); // way off track

            $this->makeTarget($kpi, $p2, ['target_value' => 100]);
            $this->makeKpiValue($kpi, $p2, ['actual_value' => 100]); // on track

            // Streak walk starts at p2 and looks backward; p1 breaks it, so count stays at 1 (< 2).
            $service->checkStreakOnTrack('company', null, $kpi->id, $p2->id);
            $this->assertSame(0, Achievement::query()->count());

            // Non-existent period id returns early without error.
            $service->checkStreakOnTrack('company', null, $kpi->id, 999999);
            $this->assertSame(0, Achievement::query()->count());
        });
    }
}
