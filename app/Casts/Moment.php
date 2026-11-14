<?php

namespace App\Casts;

use App\Support\DateBounds;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A moment column, always stored in UTC whatever is written (NICE's and Letshego's cast; docs/timezones.md):
 *
 *   an instant ("2026-11-09T06:30:00Z", "...+03:00", a Carbon)   stored exactly, in UTC
 *   a naive time ("2026-11-09 09:30:00")                          already UTC (the database's own text, sent back)
 *   a bare day ("2026-11-09")                                     the FIRST MOMENT of that Kampala day: 2026-11-08 21:00:00
 *
 * Laravel's own `datetime` cast stores "...+03:00" as its wall-clock text, 3 hours off, and a bare day as UTC
 * midnight (03:00 in Kampala, the 8th for anything reading the UTC date). Read a day back with
 * DateBounds::businessDayOf(), never ->toDateString(). Goes out as ISO with its Z.
 *
 * @implements CastsAttributes<Carbon, string|DateTimeInterface>
 */
class Moment implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
        }

        return DateBounds::isBareDate($value)
            ? DateBounds::startInstant($value, $key)->format('Y-m-d H:i:s')
            : Carbon::parse((string) $value, 'UTC')->utc()->format('Y-m-d H:i:s');
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Laravel hands over the stored UTC text here, not the Carbon from get().
        if ($value === null) {
            return null;
        }

        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC'))->utc()->toJSON();
    }
}
