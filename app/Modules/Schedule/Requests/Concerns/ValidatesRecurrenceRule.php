<?php

namespace App\Modules\Schedule\Requests\Concerns;

use Illuminate\Validation\Validator;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;

/**
 * §3F: shared by Store/Update Task/Event Requests. A recurrence_rule must be
 * bounded (COUNT= or UNTIL=) — an infinite series has no "given date range"
 * to safely validate resource availability against at save time (EventService)
 * or expand for the occurrences panel, and it's the RFC 5545 subset the spec
 * itself scopes v1 to.
 */
trait ValidatesRecurrenceRule
{
    protected function assertBoundedRecurrenceRule(Validator $validator, ?string $rrule): void
    {
        if (! $rrule) {
            return;
        }

        if (! preg_match('/\b(COUNT|UNTIL)=/i', $rrule)) {
            $validator->errors()->add('recurrence_rule', 'A recurrence rule must include COUNT= or UNTIL= so it has a bounded end.');

            return;
        }

        if (preg_match('/\bCOUNT=(\d+)/i', $rrule, $m) && (int) $m[1] > 366) {
            $validator->errors()->add('recurrence_rule', 'Recurrence COUNT cannot exceed 366 occurrences.');

            return;
        }

        try {
            new Rule($rrule, now());
        } catch (InvalidRRule $e) {
            $validator->errors()->add('recurrence_rule', 'That recurrence rule is not valid: '.$e->getMessage());
        }
    }
}
