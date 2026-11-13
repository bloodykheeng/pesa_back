# Audit trail

Who did what to which record, when, and from where. Admins read it at `/dashboard/audit-trail` in the
new admin (`GET /api/admin/audit-trail`, Admin role only). Modelled on Alpha's snapshot schema
(`alpha-fund-laravel/docs/snapshot-schema.md`), scaled down to this app.

| Piece | File |
| --- | --- |
| The contract each audited model implements | `app/Contracts/HasSnapshotSchema.php` |
| Resolving and freezing values | `app/Support/Audit/Snapshot.php` |
| Gathering and writing entries | `app/Support/Audit/Audit.php` |
| Model events in | `app/Observers/AuditObserver.php` (models listed in `AppServiceProvider::AUDITED`) |
| Sign-ins and sign-outs | `app/Observers/SignInObserver.php` (Sanctum tokens), the `Failed` auth event, `Admin\AuthController` |
| Reading it | `app/Http/Controllers/Admin/AuditTrailController.php` |
| Tests | `tests/Feature/Admin/AuditTrailTest.php` |

Storage is spatie/laravel-activitylog's `activity_log` table (already in the schema; it was empty
until 2026-11-14). Nothing in the app uses the package's `LogsActivity` trait.

## What is recorded

- **Create, edit, delete** of: orders (with their items), packages, order and package payments,
  products, accounts, every catalogue table, adverts, FAQs, referrals, messages to customers.
  Whether the admin, the old React admin or the mobile app did it.
- **Sign-ins** (every way in creates a Sanctum token: the app, Google, the old admin, the new admin),
  **sign-outs** (the person deleting their own token) and **failed sign-ins** (the app's
  `Auth::attempt`, and the admin login).

Not recorded: chat messages between customers and staff (private), the push token the app saves,
sign-in times (`lastlogin`; sign-ins are entries of their own), the sending job's delivery counters.

## One entry

| Column | Holds |
| --- | --- |
| `log_name` | the module: "Orders", "Electronic brands", "Sign-ins"... (`auditModule()`) |
| `event` | `created`, `updated`, `deleted`, `signed_in`, `signed_out`, `sign_in_failed` |
| `description` | "Edited Order #KKGPGDDMU0", "Signed in to the app" |
| `subject_type` / `subject_id` | the record (kept after it is deleted) |
| `causer_id` | the user who did it (none for a sign-up or a failed sign-in) |
| `batch_uuid` | the request's id: every entry one request wrote shares it |
| `properties` | `title`, `causer` {name, email, role} frozen, `source` (`admin`, `old-admin`, `app`, `system`), `request` {method, path, ip, user_agent}, then `snapshot` (create / delete) or `changes` (edit), and `extra` (the email a failed sign-in tried) |

## The rule: resolve when written, never when read

An entry stores `{"id": 12, "label": "Mobile Phones"}` for a category, `{"value": "cod", "label":
"Cash on delivery"}` for an enum, money as a number, dates as `YYYY-MM-DD`, instants as ISO UTC. The
admin shows those as they are: renaming the category later does not rewrite what the entry says.

## One request, one entry per record

Entries are gathered during a request and written when it ends (`app()->terminating`). So:

- the app's order create, which adds its items afterwards, is **one** create entry whose snapshot
  lists the items;
- an edit that saves a record twice (a payment updates the order's balance and status) is one edit
  entry, first old value against last new value;
- a role change (`Audit::change()`, not a column) joins the account's edit entry;
- an order deleted with its items keeps them: the snapshot is taken on `deleting`, before the
  database cascade.

Outside a request (a queue job, an artisan command) each entry is written at once, `source: system`.
Writing never breaks the request: a failure is logged (`Audit trail entry not written`) and the save
stands.

Bulk deletes must go through models (`$records->each->delete()`), never `Model::whereIn()->delete()`,
or no entry is written. `Admin\OrderController::destroyMany` was changed for this.

## Declaring a schema

```php
class ElectronicBrand extends Model implements HasSnapshotSchema
{
    public static function snapshotSchema(): array
    {
        return Snapshot::catalog(['electronic_categories_id' => [
            'label' => 'Category', 'type' => 'relation', 'resolve' => Snapshot::nameOf(ElectronicCategory::class),
        ]]);
    }

    public static function auditModule(): string { return 'Electronic brands'; }

    public function auditTitle(): string { return (string) $this->name; }
}
```

- Field order is the order a person reads (the form's). `Snapshot::catalog()` gives the shared
  name / code / status / details / photo fields.
- Types (closed list, `Snapshot::TYPES`): `string`, `text`, `enum` (optional `labels`), `relation`
  (needs `resolve`), `date`, `datetime`, `boolean`, `number`, `money`, `file`, `secret` (a change is
  recorded with no values: passwords), `list` (a computed child list: `current` + `columns`).
- `current => fn ($model) => ...` computes a value that is not a column (an order's items, a user's
  role). It is read for snapshots, not diffs.
- `hidden => true` keeps a column out of entries (the message job's counters).
- A column missing from the schema is still recorded with a humanised label; `Snapshot::SKIP`
  lists the bookkeeping columns that never are (ids, timestamps, tokens, `lastlogin`, Cloudinary copies).
- A new audited model: implement the contract, add it to `AppServiceProvider::AUDITED`, and add its
  admin page to `RECORD_PAGE` in the admin's `app/dashboard/audit-trail/AuditTrailList.tsx`.
  `test_every_audited_model_declares_a_schema_with_known_types` checks the first two.

## Reading it

`GET /api/admin/audit-trail` filters: `filter[search]` (description and properties),
`filter[module]`, `filter[event]`, `filter[source]`, `filter[causer]` (user id),
`filter[subject_type]` (short class name: `Order`) + `filter[subject_id]` (one record's history),
`filter[request]` (one request's entries), `filter[from]` / `filter[to]` (Kampala days). Sorted newest
first. The response carries `modules` (those with entries) for the Module filter.
`GET /api/admin/audit-trail/{id}` adds `snapshot`, `changes`, `request`, `extra` and `same_request`.

Every View dialog in the admin has a **History** link (Admins) to its record's entries.

## Retention

Nothing is pruned. `php artisan activitylog:clean` would delete entries older than the package
default (365 days); it is not scheduled. Indexes: `created_at`, `event`, `batch_uuid`, plus the
package's subject, causer and `log_name` (migration `2026_11_14_120000_add_audit_trail_indexes`).
