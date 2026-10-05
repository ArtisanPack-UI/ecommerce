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
| `tax` | `TaxCollectedReport` | yes | Tax collected, net of refunds, by rate label and jurisdiction |
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
- **Refunds** are succeeded refunds. They count on the day they were issued
  (`refunds.created_at`), not the day the order was placed.
- Each refund is split into the merchandise, tax, and shipping it returned
  (`RefundSplit`). A refund with lines uses the tax and shipping recorded on
  them. Any part that isn't on a line, or a refund with no lines, is split in
  proportion to the order's tax and shipping.
- In the sales report, `refunds` is the whole amount refunded.
  `net = gross − discounts − merchandise refunded`. `tax` and `shipping` are
  the orders' tax and shipping minus the tax and shipping refunded.
  `total = gross − discounts + tax + shipping` as charged on the orders, before
  refunds. `average_order_value = total ÷ orders`, rounded. So a fully
  refunded order nets to zero on `net`, `tax`, and `shipping`.
- **Line reports** (top products, categories) are net of the refunds issued
  against each line so far: units minus refunded units, revenue minus refunded
  amounts. A product in several categories counts toward each, so category
  rows can add up to more than `totals`.
- **Tax** is tax collected net of refunds. The tax a refund returned is
  taken off the rows in the period it was issued, split across the order's
  breakdown. Per-rate detail comes from
  `orders.meta.tax_breakdown` when the code that placed the order stored it, as
  a list of `{ label, amount, rate_ubps?, country_code?, region_code? }`
  (amounts in minor units of the order currency, e.g. `TaxResult::toArray()['breakdown']`
  plus the jurisdiction). A breakdown that doesn't add up to the order's
  `tax_amount` is ignored. Otherwise the order contributes one row: its
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
   `notices.unconverted_orders`. A refund that can't be converted is left
   out too.

## Ranges and time zones

`ReportRange` holds whole days in the store time zone
(`artisanpack.ecommerce.timezone`, else `app.timezone`). `make( from, to,
interval, compare )` takes `Y-m-d` strings and defaults to the last 30 days.
Ranges are capped at 1,100 days (`ReportRange::MAX_DAYS`). A bad range
throws `ReportRangeException`, whose `field` names the parameter at fault:

| Problem | `field` |
|---|---|
| A date isn't `Y-m-d` | `from` or `to` |
| The range starts after it ends | `to` |
| The range is longer than 1,100 days | `from` |
| The interval is unknown | `interval` |
 Intervals are `day`, `week` (ISO weeks,
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
to `ecommerce:reports.read`. A bad range gets a 422 `validation-failed`
response whose `errors` entry names the `field`, with a translated message.

## Adding a report

Extend `ArtisanPackUI\Ecommerce\Reports\Report` and register it:

```php
app( ReportRegistry::class )->register( 'cohorts', CohortReport::class, [ 'label' => __( 'Cohorts' ), 'position' => 70 ] );
```

Use `$this->amounts()` for currency conversion and `$this->result()` for the
shared envelope. `ap.ecommerce.reports.result` filters every result.
