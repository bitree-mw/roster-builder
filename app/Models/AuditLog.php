<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'action', 'entity', 'entity_id', 'before', 'after'])]
class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
