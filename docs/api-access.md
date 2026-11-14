# API access: who may call what

Defined in `routes/api.php` (mobile app + old admin) and `routes/admin.php` (new admin, `/api/admin/*`).

| Group | Who | Examples |
| --- | --- | --- |
| public | anyone | `login`, `app-login`, `register`, `forgot-password`, `reset-password`, `app-product-categories`, `app-products` |

**Password reset** (since 2026-11-15, for staff and customers alike): the mobile app's "Forgot password?" opens
`adb.elevatepesa.com/reset-password` with no token, which asks for the email and calls
`POST /api/admin/auth/forgot-password` (public, 5 per IP per 15 minutes). Any active account gets a 60-minute link to
`/reset-password?token=...`, which saves through `POST /api/reset-password`; a fresh link replaces an older one,
a 7-day welcome link included. The answer is the same for every address and names the one typed
("If x@y.com has an Elevate Pesa account, a reset link is on its way."). The old `POST /api/forgot-password`
(404 for an unknown address, link to the API's own page) is kept unchanged for old app builds.
| app | any signed-in user | `products` (GET), `orders` (POST), `my-orders`, `packages` (POST), `my-packages`, `messages`, `get-faqs`, `save-token` |
| own record | the user themselves, or staff | `users/{user}` (GET), `profile-update/{id}`, `change-password/{id}` (middleware `self.or.staff`) |
| staff | roles `Admin` or `Editor` (`User::STAFF_ROLES`) | everything else: lists, writes, statistics, exports, notifications, roles |

The Flutter app (`github.com/MarkRoi/elevate-pesa`) has the URL built in and calls the "app" and "own record"
routes. **Never change their request or response shape**; new admin needs go under `/api/admin/*`.

Removed 2026-11-13: the public `testing`, `test-email` and `test-notification` routes (anyone could send mail on our
quota and push to phones). `transaction-statistics` and `orders-excel-exports` were public; they are staff only now.

## Tests

`php artisan test` runs against the MySQL database `elevatepesa_test` (set in `phpunit.xml`), built from
`database/schema/mysql-schema.sql` (a dump of the production schema: the oldest migrations cannot build a MySQL 8
database from scratch) plus any newer migrations. Create it once:

```bash
mysql -uroot -e "CREATE DATABASE elevatepesa_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

`tests/Feature/ApiAccessTest.php` checks every group above, including that customers still reach each app route.

## Emails

| When | Email | Sent from |
| --- | --- | --- |
| An admin makes an account | Welcome, with a "choose your password" link valid 7 days (never the password) | `Admin\UserController::store` |
| A customer signs up in the app | Welcome to Elevate Pesa (with a link to set a password, for Google sign-ups) | `StaffAlertObserver` (the app's routes are untouched) |
| A password changes (profile, an admin, a reset link) | "Your password was changed", saying how | `AccountEmails::passwordChanged` |
| An admin changes a role or makes an account inactive | "Your access changed"; an inactive account is signed out everywhere | `Admin\UserController::update` |
| Forgot password | Reset link, 60 minutes | `PasswordResetController`, `Admin\AuthController` |
| A message to customers | The message | `SendAdminNotification` |

**Every email and every push message is queued** (the account mailables and `App\Mail\ViewMail` implement
`ShouldQueue`; pushes go through `App\Support\Push` → `App\Jobs\SendPush`, after the commit), so signing up,
placing an order or recording a payment answers at once instead of waiting on MailDog or Firebase. A worker must run
(`php artisan queue:work`, Supervisor on the server); Settings › Queues shows what waits and what failed, with Retry.
The audit trail is not queued: it is written after the response has gone (`app()->terminating`), so it costs the
user nothing and keeps the request's device and IP.

Account emails go through `App\Services\AccountEmails`, all on the branded layout
`resources/views/emails/layouts/branded.blade.php`. A failed send is retried by the queue and ends in Settings ›
Queues › Failed; it never breaks the request.

**Locally** mail goes to Laragon's Mailpit (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, inbox at http://localhost:8025).
Production sends through MailDog (password in `deployment/contabo/mails maildog.txt`), 150 a day on the free plan.

## Audit trail

Every create, edit and delete of the main models, and every sign-in, is recorded in `activity_log`; admins read it
at `/api/admin/audit-trail`. See [audit-trail.md](audit-trail.md). A missing record answers 404 with
"This record no longer exists. It may have been deleted." (`bootstrap/app.php`), never the model class.

## Dashboard filters

`GET /api/admin/dashboard` takes `from`, `to` and the order filters `product`, `inventory_type`, `product_category`,
`electronic_category`, `delivery_status`, `payment_status`, `payment_mode`. `GET /api/admin/orders` and
`GET /api/admin/payments` take the same keys as `filter[...]`: all three go through `Order::scopeMatching` (an order
matches when any of its items does), so a figure and its list agree. `GET /api/admin/users/{id}` is open to Editors
for customer accounts (an order's customer); staff accounts stay Admin-only.

## Orders and packages entered by staff

Admins and Editors can enter what a customer did not place in the app (re-entering what the Hostinger loss took, or a
phone order): `POST /api/admin/orders` (customer, items with quantity and price, address, payment mode, statuses,
`deduct_stock`) and `POST /api/admin/packages`. Both take `placed_at` (Kampala wall-clock or an instant, never in the
future; empty = now) and become the record's `created_at`; `created_by` stays the customer and `entered_by` holds the
staff member (null = placed in the app). Nothing is paid yet, the app's BNPL rule holds, and nobody is pushed or
emailed. A payment (`POST .../{id}/payments`) takes an optional `paid_at` for an earlier date: dated then, never before
the order, and no push. `GET /api/admin/customers` (Editors too) feeds the customer picker with name, phone and email
only. Tests: `tests/Feature/Admin/EnteredByStaffTest.php`.

**Edit is the whole record.** `POST /api/admin/orders/{id}` takes everything New does (customer, `placed_at`, address,
items, `charged_amount`, payment mode, statuses) plus `payment_status` and `adjust_stock` (item changes move stock,
default on); `POST /api/admin/packages/{id}` likewise (customer, `placed_at`, price, statuses, photo). The charge never
goes below what is paid, the placed date never after the first payment, a changed customer takes its payments along,
and the balance and payment status follow (`AdminPayments::settle`) unless the payment status was changed by hand in
the same save. Item changes are in the audit trail as "Items: before → after".

**Payments are instalments.** Record as many as needed until the balance is 0. Staff correct one with
`POST /api/admin/payments/{id}` (or `package-payments/{id}`): never more than the balance plus that payment, no push.
Admins delete one with `POST .../{id}/delete`: the balance grows back. `amount_paid` moves by each change rather than
being re-added from the rows, because the old admin let staff type a package's "amount paid" with no payment behind it.
Tests: `tests/Feature/Admin/EditOrdersAndPaymentsTest.php`.

## Dates

Every date filter (`filter[from]` / `filter[to]`, the dashboard's `from` / `to`, the old API's `startDate` / `endDate`,
`start_date` / `end_date`) takes a bare day (`2026-11-04`: the whole Kampala day) or an instant with `Z` or an offset
(used exactly); both ends are inclusive. Every moment goes out as UTC ISO with its `Z`; `date_of_birth` as a plain
day. A bad date answers 422. Rules and helpers: [timezones.md](timezones.md).
