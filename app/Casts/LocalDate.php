<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Store calendar dates without a time component on every database driver. */
class LocalDate implements CastsAttributes
{
    /**
     * Read a stored date as an immutable UTC midnight so comparisons never pick up a time or server timezone.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }

    /**
     * Store only the calendar part (Y-m-d), whatever date-like value was given.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->format('Y-m-d');
    }
}
