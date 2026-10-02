<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(User $actor, string $action, Model $model, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'entity' => $model->getTable(), 'entity_id' => $model->getKey(), 'before' => $before, 'after' => $after]);
    }
}
