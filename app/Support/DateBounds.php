<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place a request date becomes query bounds (docs/timezones.md). Adapted from NICE's App\Support\DateBounds.
 *
 * Storage is UTC and queries are UTC. "A day" is the business timezone's day (config app.business_timezone,
 * Africa/Kampala), the same for every viewer, never the server's or the browser's. One rule for every input:
 *
 * - A bare date ("2026-11-04", from "Pick days", a list's filter, the old admin, a link) is the whole Kampala day:
 *   2026-11-03 21:00:00 to 2026-11-04 20:59:59 UTC.
 * - Anything with a time is used exactly: an ISO instant ("...Z", "+03:00") keeps its moment; a naive
 *   "2026-11-04 13:00:00" is Kampala wall-clock. So 1 pm to 3 pm stays 1 pm to 3 pm.
 *
 * Never hand-roll it: Carbon::parse('2026-11-05') is midnight UTC (03:00 in Kampala, and as an end date it drops
 * the whole day), whereDate()/DATE() give the UTC day, and Laravel binds a Kampala Carbon as its wall-clock text
 * without converting it (3 hours off).
 */
final class DateBounds
{
    /** The business timezone: what "a day" means. */
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Africa/Kampala');
    }

    /** A filter's lower bound on a UTC column: a bare date is the first moment of that Kampala day. */
    public static function startInstant(mixed $input, string $field = 'from'): CarbonImmutable
    {
        $moment = self::interpret($input, $field);

        return (self::isBareDate($input) ? $moment->startOfDay() : $moment)->utc();
    }

    /** A filter's upper bound on a UTC column, inclusive (`<=`): a bare date is the last second of that Kampala day. */
    public static function endInstant(mixed $input, string $field = 'to'): CarbonImmutable
    {
        $moment = self::interpret($input, $field);

        return (self::isBareDate($input) ? $moment->endOfDay() : $moment)->utc();
    }

    /** [start, end] (UTC, both inclusive) for an optional pair; a missing end is open (null). */
    public static function range(mixed $from, mixed $to): array
    {
        return [
            self::filled($from) ? self::startInstant($from, 'from') : null,
            self::filled($to) ? self::endInstant($to, 'to') : null,
        ];
    }

    /** The Kampala day ("2026-11-09") a value falls on: a bare date is itself, a moment the day it is in Kampala. */
    public static function businessDayOf(mixed $value): ?string
    {
        if (! self::filled($value)) {
            return null;
        }

        return self::isBareDate($value) ? trim((string) $value) : self::interpret($value, 'date')->toDateString();
    }

    /** Today on the business calendar, "2026-11-14" (never Carbon::today(), the UTC day). */
    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /** A Carbon built on the business clock (startOfMonth and so on), as the UTC instant a query needs. */
    public static function toUtc(CarbonInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->utc();
    }

    /**
     * SQL for a UTC column on the Kampala clock, for grouping by day or month:
     * DATE_FORMAT(DateBounds::businessTimeSql('created_at'), '%Y-%m'). A numeric offset, so MySQL needs no timezone
     * tables; SQLite has no CONVERT_TZ, so it shifts by minutes (Kampala keeps one offset all year).
     */
    public static function businessTimeSql(string $column): string
    {
        $minutes = CarbonImmutable::now(self::timezone())->utcOffset();
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "datetime({$column}, '".($minutes >= 0 ? '+' : '')."{$minutes} minutes')";
        }

        return "CONVERT_TZ({$column}, '+00:00', '".CarbonImmutable::now(self::timezone())->format('P')."')";
    }

    /** SQL for the Kampala month ("2026-11") of a UTC column. */
    public static function businessMonthSql(string $column): string
    {
        $time = self::businessTimeSql($column);

        return DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', {$time})" : "DATE_FORMAT({$time}, '%Y-%m')";
    }

    /** "2026-11-04" with no time: a whole business day. */
    public static function isBareDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && trim((string) ($value instanceof CarbonInterface ? $value->toIso8601String() : $value)) !== '';
    }

    /** One input as a moment on the business clock: an instant keeps its moment, a naive value is Kampala wall-clock. */
    private static function interpret(mixed $value, string $field): CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::timezone());
        }
        if (! self::filled($value) || ! is_scalar($value)) {
            throw ValidationException::withMessages([$field => 'Pick a date.']);
        }

        try {
            return CarbonImmutable::parse(trim((string) $value), self::timezone())->setTimezone(self::timezone());
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => "\"{$value}\" is not a date."]);
        }
    }
}
