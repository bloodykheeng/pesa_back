<?php

namespace App\Traits;

use App\Support\SerialCounter;

/**
 * Numbers a record when it is created, if it has none yet: the model's SERIAL_PREFIX, the Kampala month (MMYYYY) and a
 * running counter that restarts each month, e.g. ORD1120265. From App\Support\SerialCounter, so a number is never
 * handed out twice, not even after its record is deleted (docs/reference-numbers.md).
 *
 * The model declares `SERIAL_COLUMN` and `SERIAL_PREFIX`. Wherever a record is made (the app, the admin, a seeder,
 * a test) it gets its number here; a number given explicitly is kept.
 */
trait HasSerialNumber
{
    public static function bootHasSerialNumber(): void
    {
        static::creating(function ($model) {
            $column = static::SERIAL_COLUMN;
            if (blank($model->{$column})) {
                $model->{$column} = SerialCounter::take(static::query(), $column, static::SERIAL_PREFIX.SerialCounter::monthStem());
            }
        });
    }
}
