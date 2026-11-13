<?php

namespace App\Contracts;

/**
 * A model that can describe itself to a human, for the audit trail.
 *
 * One declaration per model says how each field is labelled, typed and resolved, so an entry reads
 * "Category: Mobile Phones" instead of "electronic_categories_id: 12". The values are resolved
 * when the entry is written and frozen there: renaming a category later never rewrites history.
 *
 * Entry format and the full list of types: docs/audit-trail.md (and App\Support\Audit\Snapshot).
 */
interface HasSnapshotSchema
{
    /**
     * Field => ['label' => ..., 'type' => ..., 'resolve' => fn ($id) => ?string, ...], in the order a
     * person reads them (the form's order).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function snapshotSchema(): array;

    /** The module the trail files this under: "Orders", "Products", "Accounts"... */
    public static function auditModule(): string;

    /** How one record is named in the trail: "Order #KKGPGDDMU0", "Itel A70 Series". */
    public function auditTitle(): string;
}
