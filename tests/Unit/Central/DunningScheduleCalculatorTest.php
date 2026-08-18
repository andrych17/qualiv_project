<?php

namespace Tests\Unit\Central;

use App\Modules\Central\Support\DunningScheduleCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DunningScheduleCalculatorTest extends TestCase
{
    public function test_negative_offset_matches_days_before_due(): void
    {
        $dueDate = Carbon::parse('2026-08-25');
        $today = Carbon::parse('2026-08-18'); // 7 days before due

        $this->assertTrue(DunningScheduleCalculator::offsetDueToday($dueDate, -7, $today));
    }

    public function test_positive_offset_matches_days_past_due(): void
    {
        $dueDate = Carbon::parse('2026-08-01');
        $today = Carbon::parse('2026-08-08'); // 7 days past due

        $this->assertTrue(DunningScheduleCalculator::offsetDueToday($dueDate, 7, $today));
    }

    public function test_offset_zero_matches_due_date_itself(): void
    {
        $dueDate = Carbon::parse('2026-08-18');
        $today = Carbon::parse('2026-08-18');

        $this->assertTrue(DunningScheduleCalculator::offsetDueToday($dueDate, 0, $today));
    }

    public function test_non_matching_day_returns_false(): void
    {
        $dueDate = Carbon::parse('2026-08-25');
        $today = Carbon::parse('2026-08-18');

        $this->assertFalse(DunningScheduleCalculator::offsetDueToday($dueDate, -3, $today));
    }

    public function test_boundary_the_day_after_a_configured_offset_does_not_match(): void
    {
        $dueDate = Carbon::parse('2026-08-01');
        $today = Carbon::parse('2026-08-15'); // cutoff_days_after_due = 14 would land on the 15th

        $this->assertTrue(DunningScheduleCalculator::offsetDueToday($dueDate, 14, $today));
        $this->assertFalse(DunningScheduleCalculator::offsetDueToday($dueDate, 14, $today->copy()->addDay()));
    }

    public function test_does_not_mutate_the_passed_due_date(): void
    {
        $dueDate = Carbon::parse('2026-08-25');
        $original = $dueDate->copy();

        DunningScheduleCalculator::offsetDueToday($dueDate, -7, Carbon::parse('2026-08-18'));

        $this->assertTrue($dueDate->eq($original));
    }
}
