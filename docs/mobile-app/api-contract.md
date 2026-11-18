# API rules the app can rely on

Kept stable for the app. If one has to change, it is written here first, with the date and the app version needed.

- **Base URLs**: `https://api.elevatepesa.com/api/` for the API, `https://api.elevatepesa.com` + a `photo_url` for a
  picture.
- **Names**: the routes the app calls (`app-login`, `register`, `app-product-categories`, `app-product-types`,
  `app-get-electronic-*`, `app-get-ads`, `my-orders`, `my-packages`, `my-payments`, `orders`, `packages`,
  `confirm-receipt/{id}`, `save-token`, `check-auth-token`, `profile-update/{id}`, `change-password/{id}`...) and their
  field names do not change. The electronics side of the catalogue is "Fuel" for people but keeps `electronic_*` here.
- **Catalogue**: only Active records whose parents are all Active are sent ([catalogue-visibility](../catalogue-visibility.md)).
- **Dates** are UTC ISO 8601 strings (`2026-11-14T21:00:00.000000Z`); show them in Kampala time. An advert's
  `start_date` / `end_date` are the first moment of a Kampala day (21:00 UTC the day before). Every advert has both
  dates (the app parses them with `DateTime.parse`). [../timezones.md](../timezones.md).
- **Numbers**: orders `ORD`, packages `PKG`, receipts `PAY` / `PPAY` + month and year + a count; older records keep
  their random numbers; `package_number` can be null on very old packages. [../reference-numbers.md](../reference-numbers.md).
- **Adverts**: `GET /api/app-get-ads` needs the customer's token; it returns active adverts running today.
- **Pushes** are queued and sent within seconds of the event (order status, payments, messages to customers).
- **A refused delete** answers 409 with a `message` (records in use are kept, never deleted).
