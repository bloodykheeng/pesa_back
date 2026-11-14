<?php

namespace Tests\Feature;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Throwable;

/**
 * Guard for the timezone rules (docs/timezones.md), NICE's UtcQueryBindingsGuardTest adapted to MySQL.
 *
 * Calls every GET route under api/ without route parameters, with each date style the new admin, the old admin
 * and a link send, inside DB::pretend(): every query is built and its bindings seen, nothing runs. Fails on:
 *
 *  1. A Carbon that is not UTC, or a "Y-m-d 00:00:00" / "23:59:59" string, against a UTC column (Laravel binds a
 *     Carbon's wall-clock text without converting it: a Kampala value is 3 hours off).
 *  2. A UTC Carbon at exactly 00:00:00 / 23:59:59 (the UTC day: Carbon::parse('2026-07-15'), now()->startOfDay()).
 *     A Kampala day runs 21:00:00 to 20:59:59 UTC.
 *  3. A request value sent to SQL as it came ("2026-06-30T21:00:00.000Z").
 *  4. whereDate / whereYear / whereMonth on a UTC column: MySQL's date(`col`) is the UTC day.
 *
 * Fix with App\Support\DateBounds, never by widening CALENDAR_COLUMNS (only real `date` columns go there).
 * Pretend returns no rows, so a query that only runs per row is not reached.
 */
class UtcQueryBindingsGuardTest extends TestCase
{
    /** Real calendar dates (`date` columns): compared with a Kampala day, as they are. */
    private const CALENDAR_COLUMNS = ['date_of_birth'];

    public function test_no_route_compares_a_utc_column_with_a_utc_day_a_kampala_value_or_a_raw_request_date(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->withoutMiddleware();
        Sanctum::actingAs((new User)->forceFill(['id' => 1, 'name' => 'Guard']));

        $iso = ['from' => '2026-06-30T21:00:00.000Z', 'to' => '2026-07-31T20:59:59.999Z'];
        $day = ['from' => '2026-07-01', 'to' => '2026-07-31'];
        $variants = [
            'none' => [],
            // The new admin: filter[from]/filter[to] on lists, from/to on the dashboard.
            'days' => $day + ['filter' => $day],
            'instants' => $iso + ['filter' => $iso],
            // The old admin and the old API: startDate/endDate, start_date/end_date.
            'old-days' => ['startDate' => '2026-07-01', 'endDate' => '2026-07-31', 'start_date' => '2026-07-01', 'end_date' => '2026-07-31'],
            'old-instants' => ['startDate' => $iso['from'], 'endDate' => $iso['to'], 'start_date' => $iso['from'], 'end_date' => $iso['to']],
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($r) => in_array('GET', $r->methods())
            && str_starts_with($r->uri(), 'api/')
            && ! str_contains($r->uri(), '{'));

        $bad = [];
        $current = '';
        $reached = [];
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$bad, &$current, &$reached) {
            $reached[explode(' ', $current)[0]] = true;
            foreach (array_values($bindings) as $i => $b) {
                $before = $this->sqlBefore($sql, $i);
                if ($before === null) {
                    continue;
                }
                $problem = null;
                $column = null;
                if (($b instanceof DateTimeInterface && $b->getOffset() !== 0)
                    || (is_string($b) && preg_match('/^\d{4}-\d{2}-\d{2} (00:00:00|23:59:59)$/', $b))) {
                    [$column, $problem] = [$this->plainColumn($before), 'Kampala value on a UTC column'];
                } elseif ($b instanceof DateTimeInterface && in_array($b->format('H:i:s'), ['00:00:00', '23:59:59'], true)) {
                    [$column, $problem] = [$this->plainColumn($before), 'UTC day boundary (should be the Kampala day)'];
                } elseif (is_string($b) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $b)) {
                    [$column, $problem] = [$this->dateFunctionColumn($before) ?? $this->plainColumn($before), 'raw request date sent to SQL'];
                } elseif (($column = $this->dateFunctionColumn($before)) !== null) {
                    $problem = 'whereDate/Year/Month on a UTC column';
                }
                if ($problem === null || in_array($column, self::CALENDAR_COLUMNS, true)) {
                    continue;
                }
                $value = $b instanceof DateTimeInterface ? $b->format('Y-m-d H:i:sP') : (string) $b;
                $bad[] = sprintf('%s: %s  column=%s  value=%s', $current, $problem, $column ?? '?', $value);
            }
        });

        DB::pretend(function () use ($routes, $variants, &$current) {
            foreach ($routes as $route) {
                foreach ($variants as $name => $query) {
                    Cache::flush();
                    $current = $route->uri().' ['.$name.']';
                    try {
                        app()->handle(Request::create('/'.$route->uri(), 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']));
                    } catch (Throwable) {
                        // An empty result can make a controller throw; the bindings it sent before that were seen.
                    }
                }
            }
        });

        $this->assertSame([], array_values(array_unique($bad)));
        // Only worth something while it reaches the code: most routes must build at least one query.
        $this->assertGreaterThan(intdiv($routes->count(), 2), count($reached), 'The guard reached too few routes.');
    }

    /** The SQL in front of the $index-th placeholder, or null. */
    private function sqlBefore(string $sql, int $index): ?string
    {
        $pos = -1;
        for ($n = 0; $n <= $index; $n++) {
            $pos = strpos($sql, '?', $pos + 1);
            if ($pos === false) {
                return null;
            }
        }

        // For the second value of "between ? and ?", drop the "? and " so the column is next.
        return preg_replace('/\?\s+and\s+$/i', '', substr($sql, 0, $pos));
    }

    /** The column compared with the placeholder: "`orders`.`created_at` >= ", "created_at between ". */
    private function plainColumn(string $before): ?string
    {
        return preg_match('/[`"]?(\w+)[`"]?\s*(?:not\s+)?(?:between|>=|<=|<>|!=|<|>|=)\s*$/i', $before, $m) ? $m[1] : null;
    }

    /** The column inside MySQL's whereDate/Year/Month/Day/Time: date(`orders`.`created_at`) >= ?. */
    private function dateFunctionColumn(string $before): ?string
    {
        return preg_match('/\b(?:date|year|month|day|time)\(\s*(?:`?\w+`?\.)?`?(\w+)`?\s*\)\s*(?:not\s+)?(?:between|>=|<=|<>|!=|<|>|=)\s*$/i', $before, $m) ? $m[1] : null;
    }
}
