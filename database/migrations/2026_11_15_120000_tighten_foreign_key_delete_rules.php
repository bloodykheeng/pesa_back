<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tighten ON DELETE rules across the schema (docs/foreign-key-conventions.md, the NICE and Alpha convention).
 *
 * Before this migration a delete silently destroyed history: deleting a customer CASCADE-deleted every order,
 * package and payment they had; deleting a staff member deleted every category, brand, product and advert they
 * had created; deleting a brand deleted its products, and an order deleted its payments. Now such deletes are
 * refused by the database and the API answers 409 "in use" (bootstrap/app.php): deactivate the account or
 * remove the dependents first, deliberately.
 *
 * Audit columns (created_by, updated_by, entered_by) become RESTRICT too, the rule for new tables in NICE and
 * Alpha: a person who has acted in the system is deactivated, never deleted. Decided with the user on
 * 15/11/2026 ("start restricting"), so deleting an account that has made or changed anything is refused.
 *
 * Kept as they are, deliberately:
 * - order_products.order_id CASCADE: an order's lines exist only to describe it.
 * - admin_notification_recipients.admin_notification_id CASCADE: the fan-out rows of one message to customers.
 * - admin_notification_recipients.user_id CASCADE: one row per recipient of a message; the message itself and its
 *   sent/failed counters keep the history, so dropping a deleted account's row loses nothing recoverable.
 * - third_party_auth_providers.user_id CASCADE: a Google sign-in link belongs to its account.
 * - order_products.product_id SET NULL: each line carries the product's name and price, so an old order still
 *   reads correctly after a product is removed from the catalogue.
 * - admin_notifications.created_by: already RESTRICT.
 * - Spatie's role and permission pivots: vendor tables, published style.
 * Checked on production before deploying: no row points at a missing parent, so every constraint re-adds cleanly.
 */
return new class extends Migration
{
    /**
     * Every foreign key whose delete rule changes to RESTRICT:
     * [table, column, referenced table, previous rule (restored in down())].
     */
    private array $fks = [
        // A customer's orders, packages and payments are their history, and the business's.
        ['orders', 'created_by', 'users', 'cascade'],
        ['packages', 'created_by', 'users', 'cascade'],
        ['payments', 'user_id', 'users', 'cascade'],
        ['package_payments', 'user_id', 'users', 'cascade'],

        // Money received stays on record: an order or package with payments is not deleted with them.
        ['payments', 'order_id', 'orders', 'cascade'],
        ['package_payments', 'package_id', 'packages', 'cascade'],

        // Chat between a customer and staff survives either side's account.
        ['messages', 'senderId', 'users', 'cascade'],
        ['messages', 'receiverId', 'users', 'cascade'],

        // The catalogue: deleting a category, brand, type or inventory type must not delete products.
        ['products', 'category_brands_id', 'category_brands', 'cascade'],
        ['products', 'product_types_id', 'product_types', 'cascade'],
        ['products', 'inventory_types_id', 'inventory_types', 'set null'],
        ['products', 'electronic_category_id', 'electronic_categories', 'cascade'],
        ['products', 'electronic_brand_id', 'electronic_brands', 'cascade'],
        ['products', 'electronic_type_id', 'electronic_types', 'cascade'],
        ['category_brands', 'product_categories_id', 'product_categories', 'cascade'],
        ['electronic_brands', 'electronic_categories_id', 'electronic_categories', 'cascade'],
        ['electronic_types', 'electronic_brands_id', 'electronic_brands', 'cascade'],
        ['explore_category_blogs', 'explore_categories_id', 'explore_categories', 'cascade'],

        // Who did what: audit columns.
        ['orders', 'updated_by', 'users', 'cascade'],
        ['orders', 'entered_by', 'users', 'set null'],
        ['packages', 'updated_by', 'users', 'cascade'],
        ['packages', 'entered_by', 'users', 'set null'],
        ['payments', 'created_by', 'users', 'set null'],
        ['payments', 'updated_by', 'users', 'set null'],
        ['package_payments', 'created_by', 'users', 'set null'],
        ['package_payments', 'updated_by', 'users', 'set null'],
        ['order_products', 'created_by', 'users', 'set null'],
        ['statuses', 'user_id', 'users', 'set null'],
        ['ads', 'created_by', 'users', 'cascade'],
        ['ads', 'updated_by', 'users', 'cascade'],
        ['category_brands', 'created_by', 'users', 'cascade'],
        ['category_brands', 'updated_by', 'users', 'cascade'],
        ['electronic_brands', 'created_by', 'users', 'cascade'],
        ['electronic_brands', 'updated_by', 'users', 'cascade'],
        ['electronic_categories', 'created_by', 'users', 'cascade'],
        ['electronic_categories', 'updated_by', 'users', 'cascade'],
        ['electronic_types', 'created_by', 'users', 'cascade'],
        ['electronic_types', 'updated_by', 'users', 'cascade'],
        ['explore_categories', 'created_by', 'users', 'cascade'],
        ['explore_categories', 'updated_by', 'users', 'cascade'],
        ['explore_category_blogs', 'created_by', 'users', 'cascade'],
        ['explore_category_blogs', 'updated_by', 'users', 'cascade'],
        ['faqs', 'created_by', 'users', 'set null'],
        ['faqs', 'updated_by', 'users', 'set null'],
        ['inventory_types', 'created_by', 'users', 'cascade'],
        ['inventory_types', 'updated_by', 'users', 'cascade'],
        ['product_categories', 'created_by', 'users', 'cascade'],
        ['product_categories', 'updated_by', 'users', 'cascade'],
        ['product_types', 'created_by', 'users', 'cascade'],
        ['product_types', 'updated_by', 'users', 'cascade'],
        ['products', 'created_by', 'users', 'cascade'],
        ['products', 'updated_by', 'users', 'cascade'],
        ['referals', 'created_by', 'users', 'set null'],
        ['referals', 'updated_by', 'users', 'set null'],
    ];

    public function up(): void
    {
        foreach ($this->fks as [$table, $column, $referencedTable]) {
            $this->redefineForeignKey($table, $column, $referencedTable, 'restrict');
        }
    }

    /** Restore the previous, looser rules. */
    public function down(): void
    {
        foreach ($this->fks as [$table, $column, $referencedTable, $previousRule]) {
            $this->redefineForeignKey($table, $column, $referencedTable, $previousRule);
        }
    }

    /**
     * Drop whatever foreign key exists on table.column and re-add it with the given ON DELETE rule. Constraint names
     * are looked up, not assumed, so this works whatever name the original migration produced.
     */
    private function redefineForeignKey(string $table, string $column, string $referencedTable, string $onDelete): void
    {
        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        foreach ($constraints as $constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
        }

        // MySQL identifiers stop at 64 characters.
        $name = strtolower("{$table}_{$column}_foreign");
        if (strlen($name) > 64) {
            $name = substr($table, 0, 28).'_'.substr(strtolower($column), 0, 28).'_fk';
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $onDelete, $name) {
            $blueprint->foreign($column, $name)->references('id')->on($referencedTable)->onDelete($onDelete);
        });
    }
};
