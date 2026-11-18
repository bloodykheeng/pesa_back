# Inactive catalogue records are no longer sent to the app

**No app change needed.** Live from the 18 Nov 2026 deploy. Details: [../catalogue-visibility.md](../catalogue-visibility.md).

The owner now sells only gas (Elevate Pesa's own cylinders, Shell, Total and other brands) and fuel. Old water and
electronics items are on past orders, so they cannot be deleted; they are set **Inactive** in the dashboard instead.

From this deploy the app's catalogue endpoints leave out every Inactive record, and everything under an Inactive
parent (an Inactive brand hides its products, an Inactive category its brands and their products):

- `GET /api/app-product-categories` (and its `brands`), `GET /api/app-product-types` (and its `products`),
  `GET /api/app-category-brands`, `GET /api/app-products`
- `GET /api/app-get-electronic-categories`, `-brands`, `-types`, and `GET /api/products` for a signed-in customer

Records are only left out: the response shapes, field names and the `data` wrapper are unchanged. A list can now be
empty where it was not (a category whose brands are all Inactive): show the app's empty state there.

The dashboard calls the electronics side "Fuel" now (fuel categories, brands, types). The API keeps the
`electronic_*` routes and fields, so nothing changes in the app.
