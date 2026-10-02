<?php

namespace App\Services;

use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RuleSetService
{
    public function __construct(private AuditService $audit) {}

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
