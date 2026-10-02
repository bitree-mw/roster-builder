<?php

namespace App\Services;

use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates the standard duty rule set (id 1). Scheduler-only; enforced by RuleSetRequest.
 */
class RuleSetService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Replace the rule values under a row lock and audit the change. Existing roster periods keep their snapshot.
     *
     * @param  array<string, mixed>  $data  validated RuleSetRequest payload
     */
    public function update(array $data, User $actor): RuleSet
    {
        return DB::transaction(function () use ($data, $actor): RuleSet {
            $rules = RuleSet::query()->lockForUpdate()->findOrFail(1);
            $before = $rules->toArray();
            $rules->update($data);
            $this->audit->record($actor, 'updated', $rules, $before, $rules->toArray());

            return $rules;
        });
    }
}
