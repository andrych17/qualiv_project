<?php

namespace App\Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\AfterConstraint;

/**
 * §3P — Accounting's own thin wrapper around simshaun/recurr, the same library the Schedule
 * module uses (§3F's RecurrenceService), reused per the spec's own "reusing the Schedule
 * module's recurrence approach and library" wording. Deliberately NOT a dependency on
 * Schedule's own RecurrenceService class or SchedItem model: §3P's Rules/logic section
 * requires Accounting to function standalone with Schedule absent, so only the underlying
 * composer package is shared, never a cross-module service or model reach-through.
 *
 * Also deliberately simpler than Schedule's version: Schedule expands a rule into every
 * occurrence across a calendar range (needs a bounded COUNT=/UNTIL= rule and per-occurrence
 * skip/move exceptions to do that safely). §3P only ever needs the single next occurrence
 * after a given date, one at a time, as the generation sweep consumes them — so an unbounded
 * rule (e.g. a rent bill with no natural end) is fine here and is not rejected at validation
 * time the way Schedule's ValidatesRecurrenceRule trait rejects one.
 */
class RecurrenceService
{
    /** Only the first occurrence after $after is ever read — no need for Recurr's default 732-occurrence virtual limit. */
    private const VIRTUAL_LIMIT = 5;

    /** Null means the rule has no occurrence after $after — either it's exhausted (COUNT=/UNTIL= reached) or was never valid for this date. */
    public function nextOccurrenceAfter(string $rrule, Carbon $anchorDate, Carbon $after): ?Carbon
    {
        $rule = new Rule($rrule, $anchorDate);

        $config = new ArrayTransformerConfig;
        $config->setVirtualLimit(self::VIRTUAL_LIMIT);
        $transformer = new ArrayTransformer($config);

        $recurrences = $transformer->transform($rule, new AfterConstraint($after, false));

        $first = $recurrences->first();

        return $first ? Carbon::instance($first->getStart()) : null;
    }
}
