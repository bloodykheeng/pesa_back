# Dates and timezones

The house rule from NICE and the coding note `coding-notes/ai-agents/dates-and-timezones-with-an-ai-agent.md`
(read it before touching a date), applied here on 14 Nov 2026.

## The rules

1. **Store UTC, query UTC.** `APP_TIMEZONE=UTC`; every moment column holds a UTC instant.
2. **"A day" is the Kampala day**, from one key: `config('app.business_timezone')` (`BUSINESS_TIMEZONE`, default
   `Africa/Kampala`, UTC+3 all year). Never the server's day, never the viewer's. A Kampala day runs `21:00:00` to
   `20:59:59` UTC.
3. **Clients send unambiguous values**: an instant with `Z` or an offset, or a bare day `2026-11-04` for a whole day.
4. **A bare day is the whole Kampala day; anything with a time is used exactly** (1 pm to 3 pm stays 1 pm to 3 pm).
5. **One helper turns request dates into query bounds: `App\Support\DateBounds`.** No controller hand-rolls it.
6. **Display**: the new admin shows every moment on the Kampala clock whatever the laptop is set to (`lib/format.ts`:
   `dateTime`, `shortDate`), the decision of 28 Oct 2026; all users are in Uganda.

## The helpers

| Need | Use |
| --- | --- |
| A filter's start / end on a UTC column | `DateBounds::startInstant($v)` (`>=`) / `DateBounds::endInstant($v)` (`<=`) |
| An optional pair | `[$start, $end] = DateBounds::range($from, $to)` (null ends are open) |
| A list's `filter[from]` / `filter[to]` | `...AdminList::dateRange('created_at')` |
| Today, the Kampala day | `DateBounds::today()` (never `Carbon::today()`, the UTC day) |
| The Kampala day of a moment | `DateBounds::businessDayOf($v)` (never `->toDateString()` / `->format('Y-m-d')`) |
| A Carbon built on the Kampala clock, into a query | `DateBounds::toUtc($carbon)` (Laravel binds a Carbon's wall-clock without converting) |
| Group by Kampala month in SQL | `DateBounds::businessMonthSql('orders.created_at')`; `businessTimeSql()` for anything else |
| Write a day or a moment to a column | the `App\Casts\Moment` cast: an instant stored exactly in UTC, a bare day as its Kampala first moment |

A bad date answers 422 ("not a date"), never a 500.

## Every date column (inventory of 14 Nov 2026)

From the migrations, each traced through its cast, what the clients send, and how it is read.

| Kind | Columns | Cast | Written by |
| --- | --- | --- | --- |
| **Moment** | every `created_at` / `updated_at`; `users.lastlogin`, `email_verified_at`; `admin_notifications.finished_at`; `password_reset_tokens.created_at`, `expires_at`; `personal_access_tokens.last_used_at`, `expires_at`; `notifications.read_at`; `failed_jobs.failed_at`; `activity_log.created_at` | `datetime`; `lastlogin` `Moment` (was uncast until 14 Nov 2026, so it went out with no `Z` and the old admin showed it 3 hours early) | the server (`now()`) |
| **Day or moment** | `ads.start_date`, `ads.end_date` | `Moment` | the old admin: a picked time with its offset (`14:56` Kampala = `11:56` UTC); the new admin: a bare day (its first moment). An ad runs through the whole Kampala day of its end (`Ad::scopeRunning()`, `isRunning()`) |
| **Calendar date** (`date` type) | `users.date_of_birth` | none: goes out as `1990-01-01` | the sign-up and the forms, as a bare day |

All of these are MySQL `TIMESTAMP`s except `date_of_birth`. Checked with one script over every model against
`information_schema.columns`: no moment column is uncast and none has a `date` cast.

What each client sends:

- **New admin**: bare days from "Pick days" and every list's date filter (`days: true`); the time-picking filter
  branch (`pickedMoment`, unused today) sends a `Z` instant, snapped to the day's start or end when a day is picked.
- **Old React admin**: bare days (`moment().format("YYYY-MM-DD")`) as `startDate` / `endDate`; the ads form a `Date`
  with its offset.
- **Mobile app**: no moments (orders and packages get `created_at` from the server); a date of birth as a bare day.
  If the app ever sends a time, it must be `t.toUtc().toIso8601String()` (NICE's app sent local time without the
  offset, 3 hours off).

## The MySQL session is pinned to UTC

`config/database.php` sets `'timezone' => env('DB_TIMEZONE', '+00:00')` on the mysql and mariadb connections.
MySQL converts `TIMESTAMP` columns (every `created_at`, `lastlogin`) with the **session** timezone, on write and on
read. The development laptop's MySQL runs on Kampala time (`@@system_time_zone` = E. Africa Standard Time), so it read
every one 3 hours late: the same user row reads `created_at=2025-11-05 00:55:00` under `SYSTEM` and the true
`2025-11-04 21:55:00` under `+00:00`. Pinned, it is right on any machine.

Why it is safe on production: the data came over in a mysqldump, which writes `TIMESTAMP`s in UTC
(`SET TIME_ZONE='+00:00'`), and the stored values are true UTC (orders fall 04:00 to 23:00 UTC, 7 am to 2 am in
Kampala; at a 3-hour shift they would run 10 am to 5 am). Where the server's MySQL is already UTC nothing changes.
Check after a deploy: `select @@session.time_zone` through the app gives `+00:00`.

## What was fixed on 14 Nov 2026

- `AdminList::dayStartUtc` snapped an ISO value (`...T21:00:00Z`) to its UTC midnight and dropped a picked time: every
  new-admin list and the dashboard now go through `DateBounds`.
- The old API compared UTC days (`whereDate('created_at', '>=', Carbon::parse($startDate))`) in the order and
  package statistics cards, the bar charts, `GET /api/orders` and the orders export (20 places); `GET /api/ads` the
  same on `start_date` / `end_date`; `GET /api/packages` sent the request's dates to SQL as they came.
- The app's adverts (`GET /api/app-get-ads`) disappeared during the last day of their run (`end_date > now()`).
- The dashboard's months used a hard-coded `+03:00`, the audit trail's previews a hard-coded `Africa/Kampala`, the
  audit snapshot of an ad's day the UTC date: all from the config key now.
- New admin: "Pick days" took today from the laptop's clock; the date field of a form cut an ISO value to its UTC
  date (`slice(0, 10)`); exports wrote dates in the laptop's zone.

## The checks (keep them green)

- `tests/Feature/UtcQueryBindingsGuardTest.php` (about half a second): every GET route under `api/` with each date
  style the clients send, inside `DB::pretend()`; fails on a non-UTC Carbon or a UTC midnight bound on a UTC column,
  a raw request date sent to SQL, and `whereDate` / `whereYear` / `whereMonth` on a UTC column. Proved on 14 Nov 2026
  by putting the old code back: it named every bug above that a GET reaches (the old API's statistics, charts,
  orders, ads and packages; the new admin's lists and dashboard on an ISO date). **If it fails, fix
  the code; only a real `date` column goes in `CALENDAR_COLUMNS`.**
- `tests/Feature/Admin/TimezonesTest.php`: the session is UTC; bare days and exact times; an order at 01:30 on
  1 March in Kampala counts in March; the old API's days; an ad's raw stored values and its last day.
- The new admin: `npm run check:dates` finds a clock read when a file loads (a form default frozen at the first
  import), Luxon's `DateTime.now()` included.

No `cache:clear` needed for this change (nothing caches dates), but run it after any later change to date logic that
a cache could hold.
