<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Shared pieces of every admin list (/api/admin/*): paging, search, Kampala-day date filters.
 *
 * Request shape (Spatie QueryBuilder): ?page=2&per_page=5&sort=-created_at&filter[search]=jane
 * &filter[status]=pending&filter[from]=2025-01-01&filter[to]=2025-01-31
 */
class AdminList
{
    public const DEFAULT_PER_PAGE = 5;

    public const MAX_PER_PAGE = 100;

    public static function paginate($query, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * filter[search]: a case-insensitive "contains" across columns. A dotted column searches a
     * relation (createdBy.name).
     */
    public static function search(array $columns): AllowedFilter
    {
        return AllowedFilter::callback('search', function (Builder $query, $value) use ($columns) {
            $term = '%' . trim((string) $value) . '%';
            $query->where(function (Builder $q) use ($columns, $term) {
                foreach ($columns as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $q->orWhereHas($relation, fn (Builder $r) => $r->where($field, 'like', $term));
                    } else {
                        $q->orWhere($q->qualifyColumn($column), 'like', $term);
                    }
                }
            });
        });
    }

    /** filter[from] and filter[to]: whole Kampala calendar days, inclusive. */
    public static function dateRange(string $column = 'created_at'): array
    {
        return [
            AllowedFilter::callback('from', fn (Builder $q, $value) => $q->where($q->qualifyColumn($column), '>=', self::dayStartUtc($value))),
            AllowedFilter::callback('to', fn (Builder $q, $value) => $q->where($q->qualifyColumn($column), '<', self::dayStartUtc($value)->addDay())),
        ];
    }

    /** Midnight of a Kampala day, in UTC (how timestamps are stored). */
    public static function dayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, config('app.business_timezone'))->startOfDay()->utc();
    }

    /** The UTC range [start, end) for an optional from/to pair of Kampala days. Null ends are open. */
    public static function utcRange(?string $from, ?string $to): array
    {
        return [
            $from ? self::dayStartUtc($from) : null,
            $to ? self::dayStartUtc($to)->addDay() : null,
        ];
    }
}
