# Adverts are not loaded after a fresh sign-in

**Status: Open.** Found 18 Nov 2026 on the app v2.0.1 (profile screen), Android.

## What users see

After signing in, the home screen's advert card keeps spinning, with "No ads to show" under it. Closing the app
completely and opening it again shows the adverts (confirmed by the owner on 18 Nov).

## Evidence

- The API is fine. `GET /api/app-get-ads` (signed in) answers 200 with the active, running adverts (six since 15 Nov,
  ids 23 to 28), each with `id`, `title`, `details`, `photo_url`, `status`, `start_date`, `end_date`, `created_by`,
  `updated_by`, `created_at`, `updated_at`: exactly `AdModel.fromJson`. The pictures load (`https://api.elevatepesa.com`
  + `photo_url`, 200, image/jpeg).
- Phones that open the app **already signed in** fetch and show them. nginx log, 18 Nov 08:50 (UTC+1), one phone:
  `check-auth-token` → `app-product-categories` → `my-orders` → **`app-get-ads` 200** → `app-get-electronic-categories`
  → `app-product-types` → the advert pictures. 281 advert picture downloads by the app (`Dart/3.5`) since 15 Nov.
- A **fresh sign-in** never asks for them. The owner's phone, 18 Nov 21:04 (UTC+1): `app-login` 200 → `save-token` →
  `app-product-categories` → `app-get-electronic-categories` → `app-product-types` → brand pictures → `my-orders` →
  `my-payments`. **No `app-get-ads`.**
- In the code (`josh-feature`, 2e71280): `AdsPlaceHolder` shows the spinner while `provider.ads == null` (never
  loaded) and "No Ads Available" when the list came back empty, so a spinner means the request never ran. The home
  screen's start-up `Future.wait([...appProvider.fetchApiAds(), ...])` runs when the app starts signed in; the path
  after `app-login` loads the catalogue but not the adverts. The endpoint needs the customer's token (`auth:sanctum`):
  three 401s on 15 Nov from a phone that asked before signing in.

## Fix suggested

After a successful login (and registration), call `appProvider.fetchApiAds()` with the new token, like the other
start-up fetches. On a 401, keep the list `null` and fetch again once signed in, rather than showing an empty state.

## How to check

Sign out, sign in, stay on the home screen: the carousel shows the adverts without reopening the app. On the server,
the login is followed by `GET /api/app-get-ads 200` in the nginx log.
