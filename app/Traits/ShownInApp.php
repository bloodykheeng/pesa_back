<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Which catalogue records the mobile app sees (docs/catalogue-visibility.md).
 *
 * A record is shown when it is Active and every parent it points at is shown too, so setting an old brand Inactive
 * hides its products in one step. Inactive is how a record that cannot be deleted (it is on past orders, foreign keys
 * RESTRICT) leaves the app: it stays in the dashboard, hidden from the lists until the Status filter asks for it.
 */
trait ShownInApp
{
    /**
     * The parents that must be shown as well: [relation name => foreign key column]. A null key (no parent) passes.
     *
     * @return array<string, string>
     */
    abstract protected static function appParents(): array;

    public function scopeShownInApp(Builder $query): Builder
    {
        $query->where($this->qualifyColumn('status'), 'active');

        foreach (static::appParents() as $relation => $foreignKey) {
            $query->where(fn (Builder $q) => $q
                ->whereNull($this->qualifyColumn($foreignKey))
                ->orWhereHas($relation, fn (Builder $parent) => $parent->shownInApp()));
        }

        return $query;
    }
}
