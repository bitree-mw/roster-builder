<?php

namespace App\Services;

use App\Models\RosterPeriod;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates roster periods (months). Building, publishing and overrides are future milestones.
 */
class RosterPeriodService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create the draft period for a month, or return the existing one (idempotent). A new period stores a
     * snapshot of the current duty rules so later rule changes do not affect it.
     *
     * @param  string  $month  "YYYY-MM"
     */
    public function create(string $month, User $actor): RosterPeriod
    {
        return DB::transaction(function () use ($month, $actor): RosterPeriod {
            $period = RosterPeriod::firstOrCreate(['month' => $month.'-01'], [
                'status' => 'draft',
                'rules_snapshot' => RuleSet::findOrFail(1)->makeHidden(['id', 'created_at', 'updated_at'])->toArray(),
            ]);
            if ($period->wasRecentlyCreated) {
                $this->audit->record($actor, 'created', $period, null, $period->toArray());
            }

            return $period->load('trips.assignments');
        });
    }
}
