<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'utc_offset_minutes', 'is_base'])]
class Airport extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['utc_offset_minutes' => 'integer', 'is_base' => 'boolean'];
    }
}
