# Foreign key conventions

How to choose `ON DELETE` behaviour and how to write foreign keys in migrations. The same convention as NICE
(`nice-laravel-api-pase-two/docs/foreign-key-conventions.md`) and Alpha, so the projects read alike.

## 1. The core principle

> **Never let a delete silently destroy or orphan data that still has meaning.**

Every foreign key pointing at a deleted row does one of three things. Pick the one that matches the relationship:

| Relationship | Policy | Meaning |
| --- | --- | --- |
| **Composition**: the child is owned by, and meaningless without, the parent | `cascade` | Delete the children with the parent. |
| **Reference**: the row points at something that exists on its own (a customer, a product, a category, a staff member) | `restrict` | Refuse the parent's deletion while anything points at it. |
| **Disposable link**: losing the link loses nothing recoverable | `set null` | Null the link, keep the row. **Rare. Always comment why.** |

`restrict` is the default for references: `set null` orphans data ("an order by... nobody"), and `cascade` from a
reference destroys history it never owned.

This was not theoretical here. Until
[2026_11_15_120000_tighten_foreign_key_delete_rules](../database/migrations/2026_11_15_120000_tighten_foreign_key_delete_rules.php)
(15/11/2026), deleting a customer deleted every order, package and payment they had; deleting a staff member deleted
every category, brand, product and advert they had created; deleting a brand deleted its products; deleting an order
deleted its payments.

## 2. Decision checklist

For every foreign key, ask in order:

1. **Does this row exist only to describe its parent?** An order's lines, a message's recipient rows → `cascade`.
2. **Does it point at something that lives on its own**, or at a person (`created_by`, `updated_by`, `entered_by`,
   `user_id`)? → `restrict`.
3. **Would nulling the link genuinely lose nothing** (the value is copied onto the row)? → `set null` plus a comment
   saying why. If the comment does not convince, it is a `restrict`.

### People and audit columns

Every column that names a person is `restrict`, existing tables included (decided with the user on 15/11/2026,
"start restricting"; NICE keeps its older `set null` audit columns, Elevate Pesa had `cascade` ones, which had to go).
**A person who has acted in the system is made inactive, never deleted.** The admin already offers that: an account's
status Active / Inactive. So deleting an account that has placed an order, recorded a payment, chatted, or created or
changed anything is refused.

## 3. The schema today

| Rule | Foreign keys |
| --- | --- |
| `cascade` (composition) | `order_products.order_id` (an order's lines), `admin_notification_recipients.admin_notification_id` and `.user_id` (the fan-out rows of a message to customers; the message and its counters keep the history), `third_party_auth_providers.user_id` (a Google sign-in belongs to its account) |
| `set null` (commented) | `order_products.product_id`: each line carries the product's `name` and `price`, so an old order reads correctly after a product is removed |
| `restrict` | Everything else: `orders.created_by` (the customer), `packages.created_by`, `payments.user_id`, `package_payments.user_id`, `payments.order_id`, `package_payments.package_id`, `messages.senderId` / `receiverId`, every catalogue parent (`products.category_brands_id`, `product_types_id`, `inventory_types_id`, `electronic_*`; `category_brands.product_categories_id`; `electronic_brands.electronic_categories_id`; `electronic_types.electronic_brands_id`; `explore_category_blogs.explore_categories_id`), and every `created_by` / `updated_by` / `entered_by` / `statuses.user_id` |

Vendor tables (Spatie's role and permission pivots, Sanctum's tokens) keep their published style.

## 4. Style: column up, foreign key down

Define the column with the rest of the schema; declare the constraint in one explicit block at the bottom, in the
verbose string form:

```php
Schema::create('gas_offers', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->decimal('price', 15, 2);
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->timestamps();

    // Foreign keys, grouped and explicit.
    $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
    $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
    $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
});
```

Not `$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();`: it tangles column,
nullability and delete policy and hides the one thing a reviewer looks for. Always `->onDelete('restrict' | 'cascade'
| 'set null')`. A foreign key creates its own index; do not add a second one on the same column.

Changing the rule of an existing key: drop and re-add it, looking the constraint name up (the tighten migration's
`redefineForeignKey()`), with `down()` restoring the old rule. Before deploying, count rows that point at a missing
parent on production (the Hostinger restore ran with key checks off): re-adding a key fails on any orphan.

## 5. A refused delete reads as 409, never 500

`restrict` makes MySQL refuse with errno **1451** ("a parent row is still referenced"). `bootstrap/app.php` turns
that into `409 {"message": "This is still used by other records ..."}` for every API request, so nothing more is
needed when adding a `restrict`. A child insert failing its own key is 1452 and keeps the default handling.

The admin's bulk deletes check the common cases first and say exactly what blocks them (422: orders with payments,
products on orders, accounts with orders); the database rule is the backstop for everything else. A bulk delete runs
in one transaction, so a refusal half-way deletes nothing, and photos are removed only after the commit.

The old public API's delete routes (used only by the retired React admin) delete a photo before the row: with
`restrict` a refused delete there keeps the row but loses the photo. They are on the list of old routes to remove.

## 6. Tests

`tests/Feature/Admin/ForeignKeyRulesTest.php`: a customer with orders is kept; an order with payments is kept and an
unpaid order's lines go with it; a category or brand with products is kept, and an old order keeps its line when a
product goes; a staff member who created catalogue records is kept and the admin says why (409, batch rolled back);
any refused delete reads as 409, not 500. Each fails on the old rules.
