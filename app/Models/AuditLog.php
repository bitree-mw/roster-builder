<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of an operational change (who, what, before/after). Written inside the same
 * transaction as the change by AuditService; never stores passwords or tokens.
 */
#[Fillable(['user_id', 'action', 'entity', 'entity_id', 'before', 'after'])]
class AuditLog extends Model
{
    use HasFactory;

    /** Only created_at exists (set by the database); audit rows are never updated. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * Who made the change (null once that account has been deleted).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
