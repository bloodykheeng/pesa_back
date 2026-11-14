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

## The MySQL session is pinned to UTC (the standard)

`config/database.php` sets `'timezone' => env('DB_TIMEZONE', '+00:00')` on the mysql and mariadb connections, so every
read and write is in UTC whatever the server's zone. Decided 14 Nov 2026 after the proof below; the house standard for
every Laravel + MySQL project (coding note `dates-and-timezones-with-an-ai-agent.md`, trap 7).

How MySQL behaves ([MySQL 8.4 manual](https://dev.mysql.com/doc/refman/8.4/en/datetime.html)):

- A `TIMESTAMP` (every `created_at`, `lastlogin`) is converted from the session zone to UTC on write and back on read;
  the session zone defaults to the **server's**. A `DATETIME` is never converted.
- `APP_TIMEZONE=UTC` is PHP's clock only; Laravel sets MySQL's session zone only when the connection has `timezone`.
- mysqldump writes `TIMESTAMP`s in UTC (`--tz-utc`, on by default), so a move keeps the stored values; what changes is
  the zone the new server READS them in.

What it does on Elevate (checked on the server, read-only, 14 Nov 2026):

- **Contabo runs `Europe/Berlin`** (`timedatectl`; MySQL `@@system_time_zone` = CET), not UTC. Hostinger wrote in UTC.
- So the live API reads every row moved from Hostinger **1 hour late (winter rows) or 2 hours late (summer rows)**:
  order `RMPH3AXKU7` is `19:10:17` UTC (the local copy and the server under `+00:00` agree) and the live app reads
  `20:10:17`; the FAQ "How does ElevatePesa work…" is `07:38:48` UTC and the live admin shows "Oct 2, 2024 12:38 PM"
  (10:38 in Kampala is right). Rows written on Contabo read right (the FAQ created at 2:58 pm showed 2:58 pm),
  because they were written and read in the same zone: the data is mixed.
- Berlin has summer time: in the hour its clocks jump forward, MySQL rejects some UTC times Laravel writes
  ("Incorrect datetime value"), and the hour they fall back is ambiguous. UTC has neither.
- On a laptop whose MySQL runs on Kampala time, a production copy reads right with the pin.

### Deploying it to Contabo (once): done 2026-11-14

Ran in the `3c2b28a` deploy: the test FAQ deleted, the 2 tokens moved, session `+00:00`, `RMPH3AXKU7` reads `19:10:17`.
Kept below as the record and for any other server moved this way.

Rows the live app wrote on Contabo since the import (13 Nov 2026 12:40 UTC) were stored in Berlin time; with the pin
they would read 1 hour early, so move them once, in the same deploy. On 14 Nov 2026 they were: `faqs` 1 row (the
"tetsing" test FAQ: delete it instead) and `personal_access_tokens` 2 rows (`created_at`, `updated_at`, `last_used_at`).

1. List them again just before deploying (read-only), on the server:

   ```sh
   mysql -N -e "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='elevatepesa' AND data_type='timestamp'" |
   while read t c; do n=$(mysql -N -e "SET time_zone='+00:00'; SELECT COUNT(*) FROM \`$t\` WHERE \`$c\` >= '2026-11-13 12:40:00'" elevatepesa); [ "$n" != 0 ] && echo "$t.$c: $n"; done
   ```

2. For each column listed, in the same deploy (written in CET, UTC+1, until 28 Mar 2027):

   ```sql
   SET time_zone = '+00:00';
   UPDATE personal_access_tokens SET created_at = created_at + INTERVAL 1 HOUR WHERE created_at >= '2026-11-13 12:40:00';
   -- the same for each column listed
   ```

3. Check through the app: `select @@session.time_zone` gives `+00:00`, and order `RMPH3AXKU7` reads `19:10:17`.

Until it is deployed, the live site shows the moved rows 1 to 2 hours late. Setting the server's MySQL to UTC
(`default-time-zone='+00:00'` in `/etc/mysql/mysql.conf.d/mysqld.cnf`, then a restart) would fix the live site at once,
with the same one-time move of the Contabo-written rows.

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
