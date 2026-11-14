# Order, package and receipt numbers

> Every number Elevate Pesa makes for people to read comes from one helper,
> [`App\Support\SerialCounter::take()`](../app/Support/SerialCounter.php), through the
> [`HasSerialNumber`](../app/Traits/HasSerialNumber.php) trait on the model. It is the house pattern from Alpha and NICE
> (Alpha's `docs/reference-numbers.md` has the full reasoning and the incidents that shaped it), adopted here on
> 14 Nov 2026.

## The series

| Number | Example | Column | Made when |
| --- | --- | --- | --- |
| Order | `ORD1120265` | `orders.order_number` | an order is created (the app or the admin) |
| Package | `PKG1120262` | `packages.package_number` | a package is booked (the app or the admin) |
| Order payment receipt | `PAY11202614` | `payments.receipt_number` | a payment is recorded |
| Package payment receipt | `PPAY1120263` | `package_payments.receipt_number` | a payment is recorded |

Shape: **prefix + the Kampala month (`MMYYYY`) + a counter** that restarts each month, unpadded. Each column has a unique
index. A payment's `transaction_number` is different: the customer's mobile money or bank reference, typed in, never
generated.

**Older records keep their numbers.** Orders and packages made before 14 Nov 2026 have random 10-character numbers
(`GJO8CVEWV2`); customers have those in push messages and emails, so they were never renumbered. Payments made before
then were given receipt numbers by the migration (`2026_11_14_200000_...`), in the order they were made, month by month.

## The rules (the same as Alpha's)

- **A number, once handed out, means one record forever.** `serial_counters` keeps the last counter of each series and
  raises it under a row lock: two saves at the same moment get different numbers, and a deleted record's number is never
  given again (it stays a gap; the audit trail says who deleted it). Never `count() + 1`, never "the highest still in
  the table + 1", never id order.
- **The month is Kampala's**: an order placed at 01:00 on the 1st (22:00 UTC the day before) is numbered in the new month,
  the month every report counts it in (`SerialCounter::monthStem()`).
- The first number of a month seeds its counter from the numbers already in the table, and a number already on a record
  (written by hand) is stepped over.
- A number given explicitly on create is kept; the trait only numbers records that have none.

## Adding a series

1. A string column with a **unique** index.
2. `use HasSerialNumber;` on the model with `SERIAL_COLUMN` and `SERIAL_PREFIX` (a prefix no other series uses).
3. Tests like `tests/Feature/Admin/SerialNumbersTest.php`: the newest deleted (its number must not come back), 22:00 UTC
   on the last day of a month, and numbers already in the table.
