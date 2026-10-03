<?php

namespace App\Services;

use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of a roster period (one week, two weeks or a month): create the draft, publish it to crew, and
 * reopen it for changes. Building lives in RosterBuilderService and seat edits in AssignmentService; both
 * rely on assertEditable().
 */
class RosterPeriodService
{
    public function __construct(private AuditService $audit, private ExpiryService $expiry, private RosterConflictService $conflicts) {}

    /**
     * Create a draft period, or return the existing one with the same start and length (idempotent). Periods
     * may not overlap. A new period stores a snapshot of the current duty rules so later rule changes do not
     * affect it.
     *
     * @param  string  $startsOn  first day, "YYYY-MM-DD" (a Monday, or the 1st for a month)
     * @param  string  $length  week | fortnight | month
     *
     * @throws ValidationException when the dates overlap another roster
     */
    public function create(string $startsOn, User $actor, string $length = 'week'): RosterPeriod
    {
        return DB::transaction(function () use ($startsOn, $actor, $length): RosterPeriod {
            $start = CarbonImmutable::parse($startsOn, 'UTC');
            $end = RosterPeriod::endFor($start, $length);
            $existing = RosterPeriod::query()->where('starts_on', $start->format('Y-m-d'))->first();
            if ($existing !== null && $existing->length === $length) {
                return $existing;
            }
            // Periods never overlap, so every trip belongs to exactly one roster.
            $overlap = RosterPeriod::query()->where('starts_on', '<=', $end->format('Y-m-d'))->where('ends_on', '>=', $start->format('Y-m-d'))->orderBy('starts_on')->first();
            if ($overlap !== null) {
                throw ValidationException::withMessages(['starts_on' => 'These dates overlap the roster for '.$overlap->label().'. Choose dates after '.$overlap->ends_on->format('d M Y').', or open that roster.']);
            }
            $period = RosterPeriod::create([
                'starts_on' => $start->format('Y-m-d'),
                'ends_on' => $end->format('Y-m-d'),
                'length' => $length,
                'rules_snapshot' => RuleSet::findOrFail(1)->makeHidden(['id', 'created_at', 'updated_at'])->toArray(),
            ]);
            if ($period->wasRecentlyCreated) {
                $period->refresh();
                $this->audit->record($actor, 'created', $period, null, $period->toArray());
            }

            return $period;
        });
    }

    /**
     * Release a built draft to crew. Open seats are allowed (they show as open time), but every rule
     * conflict must be fixed or accepted with a recorded override first, and weeks that have ended cannot
     * be published.
     *
     * @throws ValidationException when the week is not ready to publish
     */
    public function publish(RosterPeriod $period, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($period, $actor): RosterPeriod {
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertEditable($period);
            if ($period->built_at === null) {
                throw ValidationException::withMessages(['period' => 'Build this roster before publishing it.']);
            }
            $blocking = collect($this->conflicts->forPeriod($period))->filter(fn (array $conflict): bool => $conflict['blocking'])->count();
            if ($blocking > 0) {
                throw ValidationException::withMessages(['period' => 'Resolve or override '.$blocking.' rule '.($blocking === 1 ? 'conflict' : 'conflicts').' before publishing.']);
            }
            $before = $period->toArray();
            $period->status = 'published';
            $period->published_at = now();
            $period->published_by = $actor->id;
            $period->save();
            $this->audit->record($actor, 'published', $period, $before, $period->toArray());

            return $period;
        });
    }

    /**
     * Take a published week back to draft so it can be rebuilt or edited (crew stop seeing it until it is
     * published again). Weeks that have ended stay published as history.
     *
     * @throws ValidationException when the week is not published or has ended
     */
    public function reopen(RosterPeriod $period, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($period, $actor): RosterPeriod {
            $period = RosterPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== 'published') {
                throw ValidationException::withMessages(['period' => 'This roster is already a draft.']);
            }
            $this->assertNotEnded($period);
            $before = $period->toArray();
            $period->status = 'draft';
            $period->published_at = null;
            $period->published_by = null;
            $period->save();
            $this->audit->record($actor, 'reopened', $period, $before, $period->toArray());

            return $period;
        });
    }

    /**
     * A week can be built or edited only while it is a draft and has not ended.
     *
     * @throws ValidationException otherwise
     */
    public function assertEditable(RosterPeriod $period): void
    {
        if ($period->status !== 'draft') {
            throw ValidationException::withMessages(['period' => 'This roster is published. Reopen it as a draft before making changes.']);
        }
        $this->assertNotEnded($period);
    }

    /** Whether the week can still change (draft and not ended), for the API response. */
    public function editable(RosterPeriod $period): bool
    {
        return $period->status === 'draft' && ! $this->ended($period);
    }

    /** Whether the period's last day is before today's base-local date. */
    public function ended(RosterPeriod $period): bool
    {
        return $period->ends_on->lt($this->expiry->today());
    }

    /**
     * @throws ValidationException when the week has ended
     */
    private function assertNotEnded(RosterPeriod $period): void
    {
        if ($this->ended($period)) {
            throw ValidationException::withMessages(['period' => 'This roster has ended. Past rosters are kept as read-only history.']);
        }
    }
}
