<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hands out every human-facing number made of a fixed stem and a running counter:
 * order numbers (`ORD1120265`), package numbers (`PKG1120262`), payment receipts (`PAY11202614`).
 * docs/reference-numbers.md. Copied from Alpha (alpha-fund-laravel, same file), the house pattern; Elevate's
 * older records keep the random 10-character numbers they were given.
 *
 * The last counter of each series is kept in `serial_counters` and raised under a row lock:
 * - two saves at the same moment wait for each other, so they never get the same number;
 * - the counter never goes back, so a number whose record was deleted is never handed out
 *   again. "Highest number still in the table + 1" (this helper until 12 Nov 2026) gave the
 *   newest record's number to the next one once that record was deleted, while the old
 *   number still sat on an emailed contract note, an approval email and the audit trail;
 * - never id order (a ULID is creation order, and account numbers are issued at approval)
 *   and never a row count (it drops on every delete).
 *
 * Inside a caller's transaction (an approval, a posting) the lock is held until that
 * transaction commits, and a rollback gives the number back. Outside one, the counter
 * commits on its own: a save that then fails leaves a gap, never a duplicate. The unique
 * index on every numbered column stays the last guard.
 */
final class SerialCounter
{
    /**
     * The next number under `$stem` for `$column` of `$query`'s table, e.g.
     * take(Order::query(), 'order_number', 'ORD112026') gives "ORD11202642".
     * `$pad` zero-pads the counter (0: none).
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function take(Builder $query, string $column, string $stem, int $pad = 0): string
    {
        $table = $query->getModel()->getTable();

        return DB::transaction(function () use ($query, $table, $column, $stem, $pad): string {
            $row = self::lockedRow($table, $column, $stem);

            if (! $row) {
                // First number of this series: start from what the table already holds
                // (numbers given before the counter existed).
                DB::table('serial_counters')->insertOrIgnore([
                    'table_name' => $table,
                    'column_name' => $column,
                    'stem' => $stem,
                    'last_value' => self::highestUsed(clone $query, $column, $stem),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = self::lockedRow($table, $column, $stem);
            }

            $counter = (int) $row->last_value;
            do {
                // Steps past a number already on a record (one given before the counter
                // existed, or typed in by hand).
                $counter++;
                $number = $stem.str_pad((string) $counter, $pad, '0', STR_PAD_LEFT);
            } while ((clone $query)->where($column, $number)->exists());

            DB::table('serial_counters')
                ->where(['table_name' => $table, 'column_name' => $column, 'stem' => $stem])
                ->update(['last_value' => $counter, 'updated_at' => now()]);

            return $number;
        }, 3);
    }

    /**
     * The highest counter already used under `$stem`. Within one series the stem has a fixed
     * width, so a longer value holds a larger counter and equal lengths compare as strings:
     * right for unpadded (`…9` < `…10`) and zero-padded (`…9999` < `…10000`) counters alike.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function highestUsed(Builder $query, string $column, string $stem): int
    {
        // The stem can carry a product code, which is free text: escape LIKE's wildcards
        // so a code with `_` or `%` matches only itself. `!` as the escape character is
        // read the same way by MySQL, SQLite and Postgres.
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $stem).'%';

        $last = $query
            ->whereRaw("{$column} LIKE ? ESCAPE '!'", [$prefix])
            ->orderByRaw("LENGTH({$column}) DESC")
            ->orderByDesc($column)
            ->value($column);

        return $last ? (int) substr((string) $last, strlen($stem)) : 0;
    }

    /**
     * The month part of a `PREFIX + MMYYYY` stem: the business calendar's month, never the
     * server's UTC one. On UTC, anything made between 00:00 and 02:59 Kampala time on the
     * 1st took the previous month's stem while every report counted it in the new month.
     */
    public static function monthStem(): string
    {
        return CarbonImmutable::now(DateBounds::timezone())->format('mY');
    }

    private static function lockedRow(string $table, string $column, string $stem): ?object
    {
        return DB::table('serial_counters')
            ->where(['table_name' => $table, 'column_name' => $column, 'stem' => $stem])
            ->lockForUpdate()
            ->first();
    }
}
