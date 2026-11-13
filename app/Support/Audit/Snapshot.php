<?php

namespace App\Support\Audit;

use App\Contracts\HasSnapshotSchema;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a record into what a person reads in the audit trail, through its snapshotSchema().
 *
 * The rule: resolve at write time and freeze the result. A relation is stored as {id, label}, an
 * enum as {value, label}, so the entry still says what was true then after things are renamed.
 * Dates are frozen as YYYY-MM-DD and instants as ISO 8601 UTC; the admin shows them in Kampala time.
 *
 * Entry (snapshot):  ['field' => 'status', 'label' => 'Status', 'type' => 'enum', 'value' => [...]]
 * Change (update):   ['field' => 'status', 'label' => 'Status', 'type' => 'enum', 'old' => ..., 'new' => ...]
 */
final class Snapshot
{
    /** Closed list: the admin renders each one. */
    public const TYPES = ['string', 'text', 'enum', 'relation', 'date', 'datetime', 'boolean', 'number', 'money', 'file', 'secret', 'list'];

    /**
     * Columns no entry shows: bookkeeping (the entry's own causer and time say who and when),
     * tokens, a copy kept for another storage, and sign-in times (sign-ins are entries of their own).
     */
    public const SKIP = [
        'id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at',
        'remember_token', 'device_token', 'lastlogin', 'slug', 'email_verified_at',
        'cloudinary_photo_url', 'cloudinary_photo_public_id',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function schemaFor(Model $model): array
    {
        return $model instanceof HasSnapshotSchema ? $model::snapshotSchema() : [];
    }

    /** Everything a person needs to know about the record, for a create or a delete. */
    public static function of(Model $model): array
    {
        $schema = self::schemaFor($model);
        $raw = $model->getAttributes();
        $entries = [];

        foreach ($schema as $field => $def) {
            if (! empty($def['hidden']) || ($def['type'] ?? null) === 'secret') {
                continue;
            }
            if (isset($def['current'])) {
                $value = self::safely(fn () => ($def['current'])($model));
            } elseif (array_key_exists($field, $raw)) {
                $value = self::freeze($def, $raw[$field]);
            } else {
                continue;
            }
            $entries[] = self::entry($field, $def, ['value' => $value]);
        }

        // A column the schema has not caught up with is still recorded, never dropped.
        foreach ($raw as $field => $value) {
            if (! isset($schema[$field]) && ! in_array($field, self::SKIP, true)) {
                $entries[] = self::entry($field, [], ['value' => self::freeze([], $value)]);
            }
        }

        return [...$entries, ...self::provenance($raw)];
    }

    /**
     * What one save changed: old values as they were, new values as saved.
     *
     * @param  array<string, mixed>  $old  raw column values before the request touched the record
     */
    public static function changes(Model $model, array $old): array
    {
        $schema = self::schemaFor($model);
        $raw = $model->getAttributes();
        $changes = [];

        // Schema order first, then any other column, so an entry reads like the form.
        $fields = array_values(array_unique([...array_keys($schema), ...array_keys($old)]));
        foreach ($fields as $field) {
            if (! array_key_exists($field, $old) || in_array($field, self::SKIP, true) || ! empty($schema[$field]['hidden'])) {
                continue;
            }
            $def = $schema[$field] ?? [];
            $before = $old[$field];
            $after = $raw[$field] ?? null;
            if (self::same($before, $after)) {
                continue;
            }
            $changes[] = ($def['type'] ?? null) === 'secret'
                ? self::entry($field, $def, ['old' => null, 'new' => null])
                : self::entry($field, $def, ['old' => self::freeze($def, $before), 'new' => self::freeze($def, $after)]);
        }

        return $changes;
    }

    /** A change that is not a column (a user's role): label and values as given. */
    public static function change(string $field, string $label, mixed $old, mixed $new, string $type = 'string'): array
    {
        return ['field' => $field, 'label' => $label, 'type' => $type, 'old' => $old, 'new' => $new];
    }

    public static function freeze(array $def, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($def['type'] ?? 'string') {
            'relation' => ['id' => $value, 'label' => self::safely(fn () => ($def['resolve'])($value)) ?? "#{$value} (no longer exists)"],
            'enum' => ['value' => (string) $value, 'label' => $def['labels'][$value] ?? Str::headline((string) $value)],
            'date' => self::safely(fn () => CarbonImmutable::parse($value)->format('Y-m-d')) ?? (string) $value,
            'datetime' => self::safely(fn () => CarbonImmutable::parse($value, 'UTC')->utc()->toIso8601ZuluString()) ?? (string) $value,
            'boolean' => (bool) $value,
            'number', 'money' => is_numeric($value) ? $value + 0 : $value,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    public const PAYMENT_METHODS = ['mtn_mobile_money' => 'MTN MoMo', 'airtel_mobile_money' => 'Airtel Money', 'cash' => 'Cash', 'bank' => 'Bank'];

    public const PAYMENT_MODES = ['bnpl' => 'Pay later', 'cod' => 'Cash on delivery'];

    /**
     * The fields every catalogue table shares (categories, brands, series, types...), with the
     * parent it belongs to first.
     *
     * @param  array<string, array<string, mixed>>  $parent
     */
    public static function catalog(array $parent = [], bool $code = true): array
    {
        return [
            ...$parent,
            'name' => ['label' => 'Name', 'type' => 'string'],
            ...($code ? ['code' => ['label' => 'Code', 'type' => 'string']] : []),
            'status' => ['label' => 'Status', 'type' => 'enum'],
            'details' => ['label' => 'Details', 'type' => 'text'],
            'photo_url' => ['label' => 'Photo', 'type' => 'file'],
        ];
    }

    /** A resolver for a relation column: the related row's name, read with one small query. */
    public static function nameOf(string $class, string $column = 'name'): \Closure
    {
        return fn ($id) => $class::query()->withoutGlobalScopes()->whereKey($id)->value($column);
    }

    /** Who made the record and when: kept on every snapshot, since seeded or older records have no create entry. */
    private static function provenance(array $raw): array
    {
        $out = [];
        if (! empty($raw['created_by'])) {
            $out[] = self::entry('created_by', ['label' => 'Created by', 'type' => 'relation'], [
                'value' => self::freeze(['type' => 'relation', 'resolve' => self::nameOf(User::class)], $raw['created_by']),
            ]);
        }
        if (! empty($raw['created_at'])) {
            $out[] = self::entry('created_at', ['label' => 'Created', 'type' => 'datetime'], ['value' => self::freeze(['type' => 'datetime'], $raw['created_at'])]);
        }

        return $out;
    }

    private static function entry(string $field, array $def, array $values): array
    {
        $entry = [
            'field' => $field,
            'label' => $def['label'] ?? Str::of($field)->replace('_', ' ')->ucfirst()->toString(),
            'type' => $def['type'] ?? 'string',
            ...$values,
        ];
        if (isset($def['columns'])) {
            $entry['columns'] = $def['columns'];
        }

        return $entry;
    }

    /** "100" and 100.00 are the same price; null and "" are both empty. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    private static function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
