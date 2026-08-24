<?php

namespace App\Modules\Accounting\Requests\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;

/**
 * §3P: shared by the recurring journal/AR template Store/Update Requests. Unlike Schedule's
 * own ValidatesRecurrenceRule (SCHEDULE §3F), a COUNT=/UNTIL= bound is NOT required here —
 * Schedule needs one to safely expand a rule across a calendar range, but §3P's
 * RecurrenceService only ever reads the single next occurrence after a date, so an unbounded
 * rule (e.g. a rent bill with no natural end) is a legitimate, common case, not a footgun.
 */
trait ValidatesRecurrenceRule
{
    protected function assertValidRecurrenceRule(Validator $validator, ?string $rrule, ?string $anchorDate): void
    {
        if (! $rrule) {
            return;
        }

        try {
            new Rule($rrule, $anchorDate ? Carbon::parse($anchorDate) : now());
        } catch (InvalidRRule $e) {
            $validator->errors()->add('recurrence_rule', 'That recurrence rule is not valid: '.$e->getMessage());
        }
    }
}
