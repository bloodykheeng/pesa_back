# API access: who may call what

Defined in `routes/api.php` (mobile app + old admin) and `routes/admin.php` (new admin, `/api/admin/*`).

| Group | Who | Examples |
| --- | --- | --- |
| public | anyone | `login`, `app-login`, `register`, `forgot-password`, `reset-password`, `app-product-categories`, `app-products` |
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
| A message to customers | The message | `SendAdminNotification` (queued: run `php artisan queue:work`) |

Account emails go through `App\Services\AccountEmails`, all on the branded layout
`resources/views/emails/layouts/branded.blade.php`. A failed send is logged and never breaks the request.

**Locally** mail goes to Laragon's Mailpit (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, inbox at http://localhost:8025).
Production sends through MailDog (password in `deployment/contabo/mails maildog.txt`), 150 a day on the free plan.
