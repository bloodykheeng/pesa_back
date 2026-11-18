# What the app shows of the catalogue: Active, Inactive, and why nothing on an order is deleted

Since 18 Nov 2026. The client sells only gas (Elevate Pesa's own and other brands) and fuel now, and rebuilt the
catalogue by renaming and deleting old water and electronics items. Anything on a past order cannot be deleted (its
foreign keys RESTRICT: `foreign-key-conventions.md`), and Inactive used to change nothing in the app: the app's
catalogue endpoints never looked at `status`.

## The rule

> **The app sees a catalogue record only when it is Active and every parent it points at is shown too.**

| Record | Its parents |
| --- | --- |
| Product category (gas), product type, inventory type, electronic (fuel) category | none |
| Category brand (gas brand) | its product category |
| Electronic brand (fuel brand) | its fuel category |
| Electronic type (fuel type) | its fuel brand |
| Product | its gas brand, product type, inventory type, fuel category, fuel brand, fuel type (each only when set) |

So making an old brand Inactive takes all its products out of the app at once, without touching them, and making it
Active again brings them back. One scope does it: `App\Traits\ShownInApp` (`Model::shownInApp()`, each model lists its
parents in `appParents()`).

## Where it applies

Every catalogue endpoint the app calls (from the nginx log, 18 Nov 2026), nested lists included:

| Endpoint | Shown |
| --- | --- |
| `GET /api/app-product-categories` | categories, and their `brands` |
| `GET /api/app-product-types` (the app reads its gas products here) | types, and their `products` |
| `GET /api/app-category-brands`, `GET /api/app-products` | brands and their products; products |
| `GET /api/app-get-electronic-categories` / `-brands` / `-types` | fuel categories, brands, types |
| `GET /api/products` (signed in) | products, for a customer; staff on this old route still get everything |

Response shapes are unchanged (records are left out, nothing renamed), so the app needs no update. The API keeps the
old names in routes, tables and fields (`electronic_*`, `gas_water`) for the same reason: the dashboard calls them
Fuel and Gas, the app keeps working.

## In the dashboard

- Products and the six Product setup lists (inventory types, gas categories, gas brands, gas product types, fuel
  categories, fuel brands, fuel types) show **Active only** by default: the Filters button shows "Status: Active".
  Status › Inactive shows the hidden ones; Any status shows all (`status=all` in the address, so a reload keeps it).
  `useAdminList({ filterDefaults })` in the admin.
- The order form's product pickers still list inactive products, marked "(inactive)": staff re-entering old orders
  need them.
- The forms say "Inactive ones are hidden in the app"; the user manual explains the rest.

## Not covered

Adverts have their own status and dates (`Ad::scopeRunning`); explore articles and FAQs are unchanged.

Tests: `tests/Feature/AppCatalogueVisibilityTest.php` (an inactive product, an inactive brand with its products, an
inactive category with everything under it, fuel brands / types / products, staff still seeing all).
