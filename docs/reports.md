# Reports

The engine ships the six core reports plus a dashboard summary as query
classes (engine issue #146, parent plan §10.2 item 7). The Livewire, React,
and Vue admins all read the same numbers: Livewire calls the classes
in-process, the others call `GET admin/reports/{report}`.

| Key | Class | Range | Shows |
|---|---|---|---|
| `sales` | `SalesReport` | yes | Gross, discounts, refunds, net, tax, shipping, total, orders, average order value, per day / week / month |
| `top-products` | `TopProductsReport` | yes | Units and net revenue per product, with a per-variant breakdown |
| `revenue-by-category` | `CategoryRevenueReport` | yes | Units and net revenue per category |
| `tax` | `TaxCollectedReport` | yes | Tax charged by rate label and jurisdiction |
| `inventory` | `InventoryLevelsReport` | no | On hand, reserved, available, and stock value at cost per tracked item |
| `low-stock` | `LowStockReport` | no | Tracked items at or below their low-stock threshold |
| `summary` | `SummaryReport` | no | Dashboard KPIs: sales today and over 30 days, orders awaiting fulfillment, low stock, pending reviews |

```php
use ArtisanPackUI\Ecommerce\Reports\ReportRange;
use ArtisanPackUI\Ecommerce\Reports\ReportRunner;

$report = app( ReportRunner::class )->run(
    'sales',
    ReportRange::make( '2026-07-01', '2026-09-30', 'month', compare: true ),
);

$report['totals']['net'];       // minor units of the base currency
$report['previous']['totals'];  // the same report for Apr–Jun
```

## What counts

- **Sales** are orders with a `placed_at` in the range and a payment status of
  `paid`, `partially_refunded`, or `refunded`.
- **Refunds** count on the day they were issued (`refunds.created_at`), not the
  day the order was placed.
- `net = gross − discounts − refunds`; `total = gross − discounts + tax + shipping`;
  `average_order_value = total ÷ orders`, rounded.
- **Line reports** (top products, categories) are net of the refunds issued
  against each line so far: units minus refunded units, revenue minus refunded
  amounts. A product in several categories counts toward each, so category
  rows can add up to more than `totals`.
- **Tax** is tax charged, not net of refunds. Per-rate detail comes from
  `orders.meta.tax_breakdown` when the code that placed the order stored it, as
  a list of `{ label, amount, rate_ubps?, country_code?, region_code? }`
  (amounts in minor units of the order currency, e.g. `TaxResult::toArray()['breakdown']`
  plus the jurisdiction). Otherwise the order contributes one row: its
  `tax_amount` under the store's tax label. A missing jurisdiction falls back to
  the shipping address, then the billing address.
- **Inventory** covers tracked items only. Unit cost is the current
  `product_prices.cost_amount` in the base currency; a variant without its own
  row uses its product's. Items without one have a null `stock_value` and are
  counted in `items_without_cost`.

## Multi-currency (plan §16.4)

Every amount is converted to the store's **current** base currency, per order:

1. The order-currency amount is converted to the order's `base_currency` with
   the order's own `fx_rate_to_base_e8` snapshot. Rates are never re-fetched
   for historical orders.
2. If the order's `base_currency` is not today's base (the owner changed it),
   the result is converted again at today's cross-rate and the order is
   counted in `notices.converted_orders`. Show these as flagged in the UI.
3. If there is no cross-rate, the order is left out and counted in
   `notices.unconverted_orders`.

## Ranges and time zones

`ReportRange` holds whole days in the store time zone
(`artisanpack.ecommerce.timezone`, else `app.timezone`). `make( from, to,
interval, compare )` takes `Y-m-d` strings and defaults to the last 30 days.
Ranges are capped at 1,100 days. Intervals are `day`, `week` (ISO weeks,
keyed by their Monday), and `month` (keyed `Y-m`). `previous()` is the period
of the same length that ends the day before; whole calendar months on a
`month` interval step back by months instead (Jul–Sep → Apr–Jun).

## Result shape

Every result has `report`, `label`, `currency`, `timezone`, `range` (null for
point-in-time reports), `totals`, `rows` or `series`, `notices`, and
`previous` (null unless `compare` was set on a ranged report). Amounts are
integer minor units of `currency`.

## Options

| Report | Option | Values |
|---|---|---|
| `top-products` | `sort` | `net_revenue` (default), `units` |
| `top-products` | `limit` | 1–100, default 10 |
| `inventory` | `sort` | `stock_value` (default), `available`, `on_hand`, `reserved`, `name` |
| `inventory`, `low-stock` | `limit` | 1–1000, default 100 |

`totals` always cover every row, not just the rows returned.

## REST

`GET admin/reports` lists the reports. `GET admin/reports/{report}` runs one
with the query parameters `from`, `to`, `interval`, `compare`, and the
report's options. Both need `report.view`, and an analyst token can be limited
to `ecommerce:reports.read`.

## Adding a report

Extend `ArtisanPackUI\Ecommerce\Reports\Report` and register it:

```php
app( ReportRegistry::class )->register( 'cohorts', CohortReport::class, [ 'label' => __( 'Cohorts' ), 'position' => 70 ] );
```

Use `$this->amounts()` for currency conversion and `$this->result()` for the
shared envelope. `ap.ecommerce.reports.result` filters every result.
