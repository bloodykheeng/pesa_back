# Live updates (Reverb): the admin never polls

Since 15 Nov 2026 the admin learns about changes from **live signals** over Laravel Reverb instead of asking the API
on a timer (it used to poll the bell every minute, Queues every 10 s, a sending message every 3-4 s). Set up the way
Alpha does it.

## How it works

1. Something happens in the API: a bell alert is stored, an order/package/customer is created, a job is queued,
   finishes or fails, a message to customers sends a batch or ends.
2. The API sends a small **signal**, [`App\Events\StaffSignal`](../app/Events/StaffSignal.php): a topic and a few ids,
   never the records. It is `ShouldBroadcastNow` (sent at once, not queued: a queued broadcast would be a job and
   signal "queues" again, forever). `StaffSignal::fire()` never throws: Reverb being down cannot break an order, a
   payment or a send.
3. Reverb pushes it to the open admins; the admin ([hooks/useLiveSignals.ts](../../pesa_nextjs_admin/hooks/useLiveSignals.ts))
   refreshes the queries the topic names, through the normal permission-checked API.

| Topic | Channel | Sent from | The admin refreshes |
| --- | --- | --- | --- |
| `inbox` | `private-App.Models.User.{id}` (one staff member) | a `StaffAlert` stored for them (`NotificationSent` listener, `AppServiceProvider`) | the bell, which toasts the new alert |
| `records` | `private-staff` | `StaffAlertObserver::created` (order, package, customer) | orders, packages, accounts, payments, dashboard |
| `queues` | `private-staff` | `JobQueued`, `Queue::after`, `Queue::failing` | Settings › Queues |
| `messages` | `private-staff` | `SendAdminNotification` (each batch, the end, a failure) | Messages and the open message |

Channels: [routes/channels.php](../routes/channels.php). The staff channel admits Admins and Editors only; a user
channel only its owner. They are authorised at **`POST /api/admin/broadcasting/auth`** with the admin's Sanctum Bearer
token (`withBroadcasting` in `bootstrap/app.php`), never a session cookie. The admin's Echo client:
[lib/echo.ts](../../pesa_nextjs_admin/lib/echo.ts). A burst refreshes each topic at most once a second. Without a
connection the screens still refresh when the tab comes back into view.

The mobile app is not involved: it keeps getting Firebase pushes. (Web push in the admin through Firebase would need
the Firebase project's web config, which we do not have.)

## Settings

API `.env`:

| Key | Local | Production |
| --- | --- | --- |
| `BROADCAST_CONNECTION` | `reverb` | `reverb` |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | any | fresh random values (the key is public, the secret is not) |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` (where Laravel publishes) | `localhost` `8080` `http` | `127.0.0.1` `8080` `http` |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` (where Reverb listens) | `0.0.0.0` `8080` | `127.0.0.1` `8080` |
| `REVERB_ALLOWED_ORIGINS` | `localhost` | `adb.elevatepesa.com` |

`REVERB_ALLOWED_ORIGINS` holds **hostnames** (Reverb compares the Origin header's host): a full URL such as
`http://localhost:3000` refuses every connection with "Origin not allowed".

Admin `.env.local` / `.env.production.local` (public, baked in by the build): `NEXT_PUBLIC_REVERB_APP_KEY` (the
API's `REVERB_APP_KEY`), `NEXT_PUBLIC_REVERB_HOST` (`localhost` / `api.elevatepesa.com`), `NEXT_PUBLIC_REVERB_PORT`
(`8080` / `443`), `NEXT_PUBLIC_REVERB_SCHEME` (`http` / `https`).

## Running it

- Locally: `php artisan reverb:start` in pesa_back (with `queue:work` and the API).
- Production: Supervisor program `api.elevatepesa.com-reverb` (`reverb:start --host=127.0.0.1 --port=8080` as
  `www-data`); nginx on `api.elevatepesa.com` passes `location /app/` to `127.0.0.1:8080` with the WebSocket upgrade
  headers, so browsers connect to `wss://api.elevatepesa.com/app/<key>`. The publishing API (`/apps/...`) is not
  exposed: Laravel reaches Reverb on 127.0.0.1. After a code deploy: `supervisorctl restart api.elevatepesa.com-reverb`.

## Checks

- Tests: `tests/Feature/Admin/RealtimeTest.php` (channel access by role, the signals each action sends, an order still
  saves with Reverb down); admin `e2e/admin/live-alerts.spec.ts` (a bell alert reaches the open admin with no polling).
- In a browser: DevTools › Network › WS shows `wss://api.elevatepesa.com/app/...` connected; the bell updates without
  a reload when an order comes in.
- `supervisorctl status`; `storage/logs/reverb.log`; "Live signal ... not sent" in the Laravel log means Reverb was
  unreachable.
