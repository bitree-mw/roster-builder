<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit rows for operational changes. Callers invoke it inside their own DB transaction so the
 * change and its audit entry commit (or roll back) together.
 */
class AuditService
{
    /**
     * Record who did what to which row, with snapshots before and after the change.
     *
     * @param  string  $action  e.g. created, updated, deleted, status_changed, enabled, disabled
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(User $actor, string $action, Model $model, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'entity' => $model->getTable(), 'entity_id' => $model->getKey(), 'before' => $before, 'after' => $after]);
    }

    /**
     * Record a change that is not about one row, such as an import or a full restore.
     *
     * @param  string  $entity  e.g. "imports" or "backups"
     * @param  array<string, mixed>|null  $after  summary of what happened
     */
    public function event(User $actor, string $action, string $entity, ?array $after = null): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => $action, 'entity' => $entity, 'entity_id' => 0, 'before' => null, 'after' => $after]);
    }
}
