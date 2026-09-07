# Package Spec: `artisanpack-ui/ecommerce` (engine)

**Status:** Draft (schema-of-record for Phase 0)
**Owner:** Jacob Martella
**Last updated:** 2026-09-06
**Parent plan:** [`12-ecommerce-package-plan.md`](../../../../../Herd/artisanpack-ui-dev/docs/plans/12-ecommerce-package-plan.md)
**Sibling reference:** [`../hooks-spec.md`](../hooks-spec.md) — the authoritative hook-name catalog that this spec's §6 mirrors and extends.

## 0. Purpose

This document is the **schema-of-record** for the engine build. Every ticket in Phase 1 → Phase 5 of the parent plan references a table, contract, registry, hook, event, REST resource, or GraphQL type defined here. When the plan and this spec disagree, this spec wins — the plan is architectural, this spec is contractual.

Scope of this file:

- **§2 Conventions** — column, naming, and payload conventions applied throughout.
- **§3 Domain schema** — every table from parent plan §5 (plus §7.5, §9, §14) with columns, indexes, types, and nullability locked.
- **§4 Contracts** — every interface signature from parent plan §6.1.
- **§5 Registries** — every registry from parent plan §6.2 with resolver key and default bindings.
- **§6 Hooks** — full action + filter catalog (parent plan §6.3), using the `ap.ecommerce.{camelCase}` naming already adopted in `docs/hooks-spec.md`.
- **§7 Events** — every Laravel event class from parent plan §6.4 with FQCN and constructor payload.
- **§8 Webhooks (outbound)** — subscription + delivery tables + signature format.
- **§9 REST resource inventory** — every endpoint from parent plan §12 with method, path, auth, idempotency scope, and rate-limit policy.
- **§10 GraphQL surface** — types, queries, mutations, subscriptions from parent plan §13.
- **§11 Cross-cutting contracts** — idempotency record shape, rate-limit policy map, PCI-column lint list.
- **§12 Traceability** — bidirectional cross-reference against every acceptance-criteria line on issue #1.

Out of scope: rationale, alternatives-considered, timeline, ecosystem map — see parent plan.

## 1. Naming reconciliation

The parent plan §6.3 used a legacy hook prefix (`ecommerce.<domain>.<event>`, snake_case). During drafting we standardized on the ecosystem-wide convention already applied in [`docs/hooks-spec.md`](../hooks-spec.md) and enforced by the `artisanpack-ui/hooks` v1.2 conventions doc:

- **Hook prefix:** `ap.ecommerce.`
- **Segments:** `camelCase`, dot-separated. Verbs use `-ing` for pre-mutation filters and past tense for post-mutation actions where the pair exists (`placing` / `placed`).
- **No snake_case, no double-prefix, no legacy `ecommerce.foo` names anywhere in shipping code.**

Every hook in §6 uses the new convention. Every event class in §7 uses PascalCase (`OrderPlaced`, not `order.placed`).

---

## 2. Conventions

### 2.1 Column conventions

| Column pattern | Type | Notes |
|---|---|---|
| Surrogate PKs | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | Named `id` unless noted. |
| Foreign keys to a surrogate PK | `BIGINT UNSIGNED` | Named `{singular_table}_id`; nullable only when noted. FK constraints declared in migrations, indexed. |
| Money amount | `BIGINT` **(signed)** | Named `{prefix}_amount`. Paired with a `{prefix}_currency CHAR(3)` column. Cast through `MoneyCast`. See parent plan §5.1. |
| Currency | `CHAR(3) NOT NULL` | ISO 4217. |
| Timestamps | `created_at TIMESTAMP`, `updated_at TIMESTAMP` | Emitted by Eloquent unless noted. `nullable()` on tables where creation-only is intended. |
| Soft delete | Not used. Deletion of domain rows is either (a) archival flag on the row or (b) hard delete with historical snapshots on downstream rows (e.g. `order_items.product_snapshot`). |
| JSON | `JSON NOT NULL DEFAULT '{}'` unless the column is a schema-of-someone-else's-payload where `NULL` is a distinct state. |
| Boolean | `BOOLEAN NOT NULL DEFAULT FALSE` unless noted. |
| Enum-like discriminator | `VARCHAR(60) NOT NULL` — engine treats these as registry keys, not DB `ENUM` (registry keys must be extensible). The one exception is `products.status`, which is a closed set. |
| Country / region codes | `CHAR(2)` for ISO 3166-1 alpha-2 country, `VARCHAR(10)` for ISO 3166-2 region subcode. |
| Postal | `VARCHAR(20)`. |
| Slugs | `VARCHAR(255)`, unique. |
| SKUs | `VARCHAR(100)`, unique (nullable — some product types don't need one). |
| Tokens (opaque, server-issued) | `CHAR(64)` where `sha256` output is stored; `CHAR(40)` where a `Str::random(40)` value is stored. |

### 2.2 Money representation

Every monetary value is stored as a pair:

```
{prefix}_amount   BIGINT       -- signed minor units
{prefix}_currency CHAR(3)      -- ISO 4217
```

and hydrated through a `MoneyCast`:

```php
protected function casts(): array
{
    return [
        'subtotal' => MoneyCast::class,   // hydrates subtotal_amount + subtotal_currency
    ];
}
```

`MoneyCast::get()` returns `Money\Money`. `MoneyCast::set()` accepts `Money\Money`, an integer + currency tuple, or an array `['amount' => int, 'currency' => string]`.

**Signedness:** every `_amount` column is signed `BIGINT`. See parent plan §5.1 rationale.

### 2.3 FX rate representation

`fx_rate_to_base_e8` on `orders` is a `BIGINT` holding the rate × 10⁸. Signed so future negative-rate quirks (currency reforms, denomination changes) do not require a schema break. Never `DECIMAL`, never `FLOAT`.

### 2.4 Tax rate representation

`tax_rates.rate_ubps` is `INT` holding the rate in micro-basis-points (1 unit = 0.0001% = 10⁻⁶). 8.375% = `83_750_000`. Signed representation preserves headroom for future negative-adjustment rates.

### 2.5 Registry key format

Registry keys are lowercase, kebab-case, dot-namespaced by publisher:

- Core keys: no namespace (e.g. `stripe`, `simple`, `percent-off-cart`).
- Satellite keys: package-slug-namespaced (e.g. `printful:printful-product`, `shippo:shippo-rates`).
- Reserved characters: alphanumerics, `-`, `:`. No `.`, no `/`, no whitespace.

### 2.6 JSON payload conventions

Where a JSON column holds structured data documented by this spec, the shape is enumerated in that section. Where a JSON column is an extension surface (`meta` on cart/order/item), the engine writes nothing to it by default; satellites are expected to namespace their keys (`printful.order_id`, `subscriptions.plan_id`) to prevent collisions.

### 2.7 Timestamps and time zones

All `TIMESTAMP` columns are stored in UTC. Presentation layers convert. Where a column represents a business-day boundary (e.g. `products.published_at`), UTC is still the storage representation; store owners specify their store time zone in settings for display.

### 2.8 Naming — tables, models, events

| Kind | Convention | Example |
|---|---|---|
| Table | `snake_case`, plural | `product_variants` |
| Model | `PascalCase`, singular | `ProductVariant` |
| Event class | `PascalCase`, past-tense verb, under `ArtisanPackUI\Ecommerce\Events\` | `OrderPlaced` |
| Contract | `PascalCase`, noun, under `ArtisanPackUI\Ecommerce\Contracts\` | `PaymentGateway` |
| Registry | `PascalCase`, `{Noun}Registry`, under `ArtisanPackUI\Ecommerce\Registries\` | `ProductTypeRegistry` |
| Hook (action/filter) | `ap.ecommerce.{camelCase}...` | `ap.ecommerce.order.placed` |
| Migration file | `create_ecommerce_{table}_table.php` (prefixed to cluster) | `create_ecommerce_products_table.php` |
| Config key | `artisanpack.ecommerce.{section}.{setting}` | `artisanpack.ecommerce.checkout.reservation_ttl_minutes` |

---

## 3. Domain schema

Every table below is a **complete definition**. Column order in migrations matches the order given here. All character columns are `utf8mb4` collation `utf8mb4_unicode_ci` unless a specific technical need exists (`CHAR(3)` currency and `CHAR(2)` country codes use `utf8mb4_bin` for exact-match speed).

### 3.1 `products`

Base row for every product type. Type-specific columns live on satellite tables or in per-product-type meta rows (never as nullable columns here).

```sql
CREATE TABLE products (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                      VARCHAR(60)  NOT NULL,                       -- ProductTypeRegistry key
    name                      VARCHAR(255) NOT NULL,
    slug                      VARCHAR(255) NOT NULL,
    sku                       VARCHAR(100) NULL,
    barcode                   VARCHAR(100) NULL,
    description               LONGTEXT     NULL,
    short_description         TEXT         NULL,
    status                    ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    featured_image_media_id   BIGINT UNSIGNED NULL,
    is_taxable                BOOLEAN      NOT NULL DEFAULT TRUE,
    tax_class_key             VARCHAR(60)  NULL,                           -- FK tax_classes.key (loose; enforced at service layer)
    weight                    DECIMAL(8,3) NULL,
    weight_unit               ENUM('g','kg','oz','lb') NULL,
    length                    DECIMAL(8,3) NULL,
    width                     DECIMAL(8,3) NULL,
    height                    DECIMAL(8,3) NULL,
    dim_unit                  ENUM('mm','cm','in') NULL,
    avg_rating                DECIMAL(3,2) NOT NULL DEFAULT 0.00,          -- denormalized from product_reviews
    reviews_count             INT UNSIGNED NOT NULL DEFAULT 0,             -- denormalized
    warehouse_id              BIGINT UNSIGNED NULL,                        -- reserved for ecommerce-inventory-warehouses satellite (open question §19.2)
    meta                      JSON         NOT NULL DEFAULT (JSON_OBJECT()),
    published_at              TIMESTAMP    NULL,
    created_at                TIMESTAMP    NULL,
    updated_at                TIMESTAMP    NULL,
    UNIQUE KEY products_slug_uk (slug),
    UNIQUE KEY products_sku_uk  (sku),
    KEY products_type_idx       (type),
    KEY products_status_idx     (status),
    KEY products_published_idx  (published_at)
);
```

Notes:

- `featured_image_media_id` is intentionally unconstrained at the DB level so `media-library` remains a soft dep. If installed, its migrations add the FK; if not, an integer id points at a URL resolved by the fallback strategy.
- `warehouse_id` is present from day 1 per parent-plan §19.2 recommendation ("design core with `warehouse_id NULL DEFAULT NULL` from the start"). The engine ignores it; `ecommerce-inventory-warehouses` populates + queries.
- `tax_class_key` is not a hard FK — see §3.24.

### 3.2 `product_prices`

Per-currency prices for products *and* variants (polymorphic).

```sql
CREATE TABLE product_prices (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    priceable_type         VARCHAR(191) NOT NULL,                         -- 'ArtisanPackUI\Ecommerce\Models\Product' | '...\ProductVariant'
    priceable_id           BIGINT UNSIGNED NOT NULL,
    currency               CHAR(3) NOT NULL,
    price_amount           BIGINT  NOT NULL,                              -- signed per §2.1
    compare_at_amount      BIGINT  NULL,
    cost_amount            BIGINT  NULL,
    starts_at              TIMESTAMP NULL,
    ends_at                TIMESTAMP NULL,
    created_at             TIMESTAMP NULL,
    updated_at             TIMESTAMP NULL,
    UNIQUE KEY product_prices_currency_uk (priceable_type, priceable_id, currency),
    KEY product_prices_priceable_idx      (priceable_type, priceable_id)
);
```

`starts_at` / `ends_at` support scheduled price changes (sales). Effective price at time `t` is the row whose `[starts_at, ends_at]` contains `t`, or the row with both null (the base price), with `starts_at` preferred over unbounded on tie.

### 3.3 `product_variants`

```sql
CREATE TABLE product_variants (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          BIGINT UNSIGNED NOT NULL,
    sku                 VARCHAR(100) NULL,
    barcode             VARCHAR(100) NULL,
    name                VARCHAR(255) NULL,                                -- optional override; else composed from option values
    image_media_id      BIGINT UNSIGNED NULL,
    weight              DECIMAL(8,3) NULL,
    weight_unit         ENUM('g','kg','oz','lb') NULL,
    length              DECIMAL(8,3) NULL,
    width               DECIMAL(8,3) NULL,
    height              DECIMAL(8,3) NULL,
    dim_unit            ENUM('mm','cm','in') NULL,
    position            INT UNSIGNED NOT NULL DEFAULT 0,
    meta                JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    UNIQUE KEY product_variants_sku_uk (sku),
    KEY product_variants_product_idx   (product_id),
    CONSTRAINT product_variants_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
```

### 3.4 `product_attributes`

Attribute definitions (unbounded, unlike WooCommerce's 2-option limit).

```sql
CREATE TABLE product_attributes (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    BIGINT UNSIGNED NOT NULL,
    key           VARCHAR(60)  NOT NULL,                                  -- 'size', 'color', 'material'
    label         VARCHAR(120) NOT NULL,
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    is_variation  BOOLEAN NOT NULL DEFAULT TRUE,                          -- if FALSE, informational only (not used to build variants)
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    UNIQUE KEY product_attributes_key_uk (product_id, key),
    CONSTRAINT product_attributes_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
```

### 3.5 `product_attribute_values`

The catalog of possible values for each attribute (e.g. `size` → `S, M, L, XL`).

```sql
CREATE TABLE product_attribute_values (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_attribute_id BIGINT UNSIGNED NOT NULL,
    value               VARCHAR(120) NOT NULL,
    label               VARCHAR(120) NOT NULL,
    swatch              VARCHAR(60)  NULL,                                -- hex color, image ref, etc.
    position            INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY product_attribute_values_value_uk (product_attribute_id, value),
    CONSTRAINT product_attribute_values_attr_fk FOREIGN KEY (product_attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE
);
```

### 3.6 `product_variant_option_values`

Pivot: which attribute values compose which variant.

```sql
CREATE TABLE product_variant_option_values (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_variant_id        BIGINT UNSIGNED NOT NULL,
    product_attribute_id      BIGINT UNSIGNED NOT NULL,
    product_attribute_value_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY product_variant_option_values_uk (product_variant_id, product_attribute_id),
    KEY product_variant_option_values_value_idx (product_attribute_value_id),
    CONSTRAINT product_variant_option_values_variant_fk FOREIGN KEY (product_variant_id)        REFERENCES product_variants(id)        ON DELETE CASCADE,
    CONSTRAINT product_variant_option_values_attr_fk    FOREIGN KEY (product_attribute_id)      REFERENCES product_attributes(id)      ON DELETE CASCADE,
    CONSTRAINT product_variant_option_values_val_fk     FOREIGN KEY (product_attribute_value_id) REFERENCES product_attribute_values(id) ON DELETE CASCADE
);
```

### 3.7 `product_categories`

```sql
CREATE TABLE product_categories (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id      BIGINT UNSIGNED NULL,
    name           VARCHAR(255) NOT NULL,
    slug           VARCHAR(255) NOT NULL,
    description    TEXT NULL,
    image_media_id BIGINT UNSIGNED NULL,
    icon           VARCHAR(80)  NULL,                                     -- icons registry key
    position       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    UNIQUE KEY product_categories_slug_uk (slug),
    KEY product_categories_parent_idx     (parent_id),
    CONSTRAINT product_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
);
```

### 3.8 `product_category_product` (pivot, many-to-many)

```sql
CREATE TABLE product_category_product (
    product_id           BIGINT UNSIGNED NOT NULL,
    product_category_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, product_category_id),
    KEY product_category_product_cat_idx (product_category_id),
    CONSTRAINT product_category_product_product_fk FOREIGN KEY (product_id)          REFERENCES products(id)           ON DELETE CASCADE,
    CONSTRAINT product_category_product_cat_fk     FOREIGN KEY (product_category_id) REFERENCES product_categories(id) ON DELETE CASCADE
);
```

### 3.9 `product_tags` and `product_tag_product`

```sql
CREATE TABLE product_tags (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    slug       VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY product_tags_slug_uk (slug)
);

CREATE TABLE product_tag_product (
    product_id     BIGINT UNSIGNED NOT NULL,
    product_tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, product_tag_id),
    KEY product_tag_product_tag_idx (product_tag_id),
    CONSTRAINT product_tag_product_product_fk FOREIGN KEY (product_id)     REFERENCES products(id)     ON DELETE CASCADE,
    CONSTRAINT product_tag_product_tag_fk     FOREIGN KEY (product_tag_id) REFERENCES product_tags(id) ON DELETE CASCADE
);
```

### 3.10 `product_images`

Ordered gallery. Independent of the featured image column on `products`.

```sql
CREATE TABLE product_images (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    BIGINT UNSIGNED NOT NULL,
    media_id      BIGINT UNSIGNED NULL,                                   -- media-library id when installed
    image_url     VARCHAR(1000) NULL,                                     -- fallback when media-library absent
    alt_text      VARCHAR(255) NULL,
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    KEY product_images_product_idx (product_id),
    CONSTRAINT product_images_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
```

### 3.11 `inventory_items`

```sql
CREATE TABLE inventory_items (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stockable_type       VARCHAR(191) NOT NULL,                           -- Product | ProductVariant
    stockable_id         BIGINT UNSIGNED NOT NULL,
    track_inventory      BOOLEAN NOT NULL DEFAULT TRUE,
    quantity_on_hand     INT NOT NULL DEFAULT 0,                          -- signed so returns can produce negatives before reconciliation
    quantity_reserved    INT UNSIGNED NOT NULL DEFAULT 0,
    allow_backorder      BOOLEAN NOT NULL DEFAULT FALSE,
    low_stock_threshold  INT UNSIGNED NULL,
    warehouse_id         BIGINT UNSIGNED NULL,                            -- reserved for satellite (§19.2)
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    UNIQUE KEY inventory_items_stockable_uk (stockable_type, stockable_id, warehouse_id),
    KEY inventory_items_stockable_idx       (stockable_type, stockable_id)
);
```

### 3.12 `inventory_reservations`

```sql
CREATE TABLE inventory_reservations (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id  BIGINT UNSIGNED NOT NULL,
    reservable_type    VARCHAR(191) NOT NULL,                             -- Cart | Order
    reservable_id      BIGINT UNSIGNED NOT NULL,
    quantity           INT UNSIGNED NOT NULL,
    expires_at         TIMESTAMP NULL,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    KEY inventory_reservations_expires_idx    (expires_at),
    KEY inventory_reservations_reservable_idx (reservable_type, reservable_id),
    CONSTRAINT inventory_reservations_item_fk FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
);
```

Effective available = `quantity_on_hand - quantity_reserved`. Cleared by `ecommerce:release-expired-reservations` every minute (see §11.6).

### 3.13 `carts`

```sql
CREATE TABLE carts (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token                CHAR(40) NOT NULL,
    customer_id          BIGINT UNSIGNED NULL,
    currency             CHAR(3) NOT NULL,
    email                VARCHAR(255) NULL,
    subtotal_amount      BIGINT NOT NULL DEFAULT 0,
    subtotal_currency    CHAR(3) NOT NULL,
    discount_amount      BIGINT NOT NULL DEFAULT 0,
    discount_currency    CHAR(3) NOT NULL,
    tax_amount           BIGINT NOT NULL DEFAULT 0,
    tax_currency         CHAR(3) NOT NULL,
    shipping_amount      BIGINT NOT NULL DEFAULT 0,
    shipping_currency    CHAR(3) NOT NULL,
    total_amount         BIGINT NOT NULL DEFAULT 0,
    total_currency       CHAR(3) NOT NULL,
    checkout_started_at  TIMESTAMP NULL,
    abandoned_at         TIMESTAMP NULL,
    completed_order_id   BIGINT UNSIGNED NULL,
    meta                 JSON NOT NULL DEFAULT (JSON_OBJECT()),
    expires_at           TIMESTAMP NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    UNIQUE KEY carts_token_uk       (token),
    KEY carts_customer_idx          (customer_id),
    KEY carts_abandoned_idx         (abandoned_at),
    KEY carts_expires_idx           (expires_at),
    KEY carts_completed_order_idx   (completed_order_id)
);
```

### 3.14 `cart_items`

```sql
CREATE TABLE cart_items (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id                BIGINT UNSIGNED NOT NULL,
    product_id             BIGINT UNSIGNED NOT NULL,
    product_variant_id     BIGINT UNSIGNED NULL,
    quantity               INT UNSIGNED NOT NULL,
    unit_price_amount      BIGINT NOT NULL,
    unit_price_currency    CHAR(3) NOT NULL,
    line_subtotal_amount   BIGINT NOT NULL,
    line_subtotal_currency CHAR(3) NOT NULL,
    line_total_amount      BIGINT NOT NULL,
    line_total_currency    CHAR(3) NOT NULL,
    options                JSON NOT NULL DEFAULT (JSON_OBJECT()),
    meta                   JSON NOT NULL DEFAULT (JSON_OBJECT()),
    options_hash           CHAR(64) NOT NULL,                             -- sha256(json_encode(options)) — used by cart-merge to detect "same line"
    created_at             TIMESTAMP NULL,
    updated_at             TIMESTAMP NULL,
    KEY cart_items_cart_idx     (cart_id),
    KEY cart_items_dedupe_idx   (cart_id, product_id, product_variant_id, options_hash),
    CONSTRAINT cart_items_cart_fk    FOREIGN KEY (cart_id)            REFERENCES carts(id)             ON DELETE CASCADE,
    CONSTRAINT cart_items_product_fk FOREIGN KEY (product_id)         REFERENCES products(id)          ON DELETE RESTRICT,
    CONSTRAINT cart_items_variant_fk FOREIGN KEY (product_variant_id) REFERENCES product_variants(id)  ON DELETE RESTRICT
);
```

### 3.15 `orders`

```sql
CREATE TABLE orders (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number            VARCHAR(50) NOT NULL,
    customer_id             BIGINT UNSIGNED NULL,
    email                   VARCHAR(255) NOT NULL,
    phone                   VARCHAR(50) NULL,
    system_status           VARCHAR(60) NOT NULL,                         -- pending|processing|complete|cancelled|refunded|failed
    substatus_id            BIGINT UNSIGNED NULL,                         -- global default; per-board lives on order_board_assignments
    payment_status          VARCHAR(60) NOT NULL,                         -- pending|paid|partially_refunded|refunded|failed
    fulfillment_status      VARCHAR(60) NOT NULL,                         -- unfulfilled|partial|fulfilled
    currency                CHAR(3) NOT NULL,
    base_currency           CHAR(3) NOT NULL,
    fx_rate_to_base_e8      BIGINT NOT NULL,
    subtotal_amount         BIGINT NOT NULL,
    subtotal_currency       CHAR(3) NOT NULL,
    discount_amount         BIGINT NOT NULL DEFAULT 0,
    discount_currency       CHAR(3) NOT NULL,
    tax_amount              BIGINT NOT NULL DEFAULT 0,
    tax_currency            CHAR(3) NOT NULL,
    shipping_amount         BIGINT NOT NULL DEFAULT 0,
    shipping_currency       CHAR(3) NOT NULL,
    total_amount            BIGINT NOT NULL,
    total_currency          CHAR(3) NOT NULL,
    total_refunded_amount   BIGINT NOT NULL DEFAULT 0,
    total_refunded_currency CHAR(3) NOT NULL,
    shipping_address        JSON NULL,
    billing_address         JSON NULL,
    shipping_method_key     VARCHAR(120) NULL,
    payment_gateway_key     VARCHAR(120) NULL,
    payment_reference       VARCHAR(255) NULL,
    ip_address              VARCHAR(45)  NULL,
    user_agent              TEXT NULL,
    customer_note           TEXT NULL,
    is_claimed              BOOLEAN NOT NULL DEFAULT TRUE,                -- FALSE for guest orders until claimed per §5.8
    meta                    JSON NOT NULL DEFAULT (JSON_OBJECT()),
    placed_at               TIMESTAMP NULL,
    created_at              TIMESTAMP NULL,
    updated_at              TIMESTAMP NULL,
    UNIQUE KEY orders_order_number_uk (order_number),
    KEY orders_customer_idx           (customer_id),
    KEY orders_email_idx              (email),
    KEY orders_system_status_idx      (system_status),
    KEY orders_payment_status_idx     (payment_status),
    KEY orders_fulfillment_status_idx (fulfillment_status),
    KEY orders_placed_at_idx          (placed_at),
    CONSTRAINT orders_customer_fk  FOREIGN KEY (customer_id)  REFERENCES customers(id)         ON DELETE SET NULL,
    CONSTRAINT orders_substatus_fk FOREIGN KEY (substatus_id) REFERENCES order_substatuses(id) ON DELETE SET NULL
);
```

`shipping_address` / `billing_address` JSON shape:

```json
{
  "first_name": "…", "last_name": "…", "company": "…", "phone": "…",
  "address1": "…", "address2": "…",
  "city": "…", "region": "…", "region_code": "…",
  "postal_code": "…", "country_code": "US"
}
```

### 3.16 `order_items`

```sql
CREATE TABLE order_items (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id              BIGINT UNSIGNED NOT NULL,
    product_id            BIGINT UNSIGNED NULL,                           -- nullable: product may be deleted post-order
    product_variant_id    BIGINT UNSIGNED NULL,
    product_snapshot      JSON NOT NULL,                                  -- name, sku, image, type, options at order time
    quantity              INT UNSIGNED NOT NULL,
    unit_price_amount     BIGINT NOT NULL,
    unit_price_currency   CHAR(3) NOT NULL,
    discount_amount       BIGINT NOT NULL DEFAULT 0,
    discount_currency     CHAR(3) NOT NULL,
    tax_amount            BIGINT NOT NULL DEFAULT 0,
    tax_currency          CHAR(3) NOT NULL,
    shipping_amount       BIGINT NOT NULL DEFAULT 0,                      -- allocated per §16.7
    shipping_currency     CHAR(3) NOT NULL,
    total_amount          BIGINT NOT NULL,
    total_currency        CHAR(3) NOT NULL,
    fulfillment_status    VARCHAR(60) NOT NULL,                           -- unfulfilled|partial|fulfilled
    meta                  JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,
    KEY order_items_order_idx   (order_id),
    KEY order_items_product_idx (product_id),
    CONSTRAINT order_items_order_fk   FOREIGN KEY (order_id)           REFERENCES orders(id)           ON DELETE CASCADE,
    CONSTRAINT order_items_product_fk FOREIGN KEY (product_id)         REFERENCES products(id)         ON DELETE SET NULL,
    CONSTRAINT order_items_variant_fk FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);
```

`product_snapshot` JSON shape (minimum):

```json
{
  "name": "…", "sku": "…", "type": "simple",
  "image_url": "…",
  "options": [ { "key": "size", "label": "Size", "value": "L", "value_label": "Large" } ]
}
```

### 3.17 `order_substatuses`

```sql
CREATE TABLE order_substatuses (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_status  VARCHAR(60)  NOT NULL,                                 -- which system status this substatus belongs to
    `key`          VARCHAR(80)  NOT NULL,
    label          VARCHAR(120) NOT NULL,
    color          CHAR(7)      NULL,                                     -- '#RRGGBB'
    icon           VARCHAR(80)  NULL,                                     -- icons registry key
    position       INT UNSIGNED NOT NULL DEFAULT 0,
    is_terminal    BOOLEAN NOT NULL DEFAULT FALSE,                        -- terminal within its system status (used by multi-board completion rollup)
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    UNIQUE KEY order_substatuses_system_key_uk (system_status, `key`),
    KEY order_substatuses_system_idx           (system_status)
);
```

### 3.18 `order_notes`

```sql
CREATE TABLE order_notes (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id             BIGINT UNSIGNED NOT NULL,
    author_user_id       BIGINT UNSIGNED NULL,                            -- null = system
    body                 TEXT NOT NULL,
    is_customer_visible  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    KEY order_notes_order_idx (order_id),
    CONSTRAINT order_notes_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
```

### 3.19 `order_timeline_entries`

Append-only activity log.

```sql
CREATE TABLE order_timeline_entries (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id       BIGINT UNSIGNED NOT NULL,
    actor_user_id  BIGINT UNSIGNED NULL,                                  -- null = system
    event_type     VARCHAR(120) NOT NULL,                                 -- e.g. 'status.changed', 'note.added', 'order.edited'
    payload        JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at     TIMESTAMP NULL,
    KEY order_timeline_entries_order_time_idx (order_id, created_at),
    CONSTRAINT order_timeline_entries_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
```

### 3.20 `order_edits`

```sql
CREATE TABLE order_edits (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id           BIGINT UNSIGNED NOT NULL,
    actor_user_id      BIGINT UNSIGNED NULL,
    reason             VARCHAR(255) NULL,
    diff               JSON NOT NULL,                                     -- {fields:{...}, items:{added,removed,changed}, totals:{before,after}}
    pre_edit_snapshot  JSON NOT NULL,
    created_at         TIMESTAMP NULL,
    KEY order_edits_order_time_idx (order_id, created_at),
    CONSTRAINT order_edits_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
```

### 3.21 `refunds` and `refund_items`

```sql
CREATE TABLE refunds (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id           BIGINT UNSIGNED NOT NULL,
    amount             BIGINT NOT NULL,                                    -- signed; refunds are positive from customer's POV
    currency           CHAR(3) NOT NULL,
    reason             VARCHAR(255) NULL,
    gateway_reference  VARCHAR(255) NULL,
    issued_by_user_id  BIGINT UNSIGNED NULL,
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    KEY refunds_order_idx (order_id),
    CONSTRAINT refunds_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE refund_items (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id      BIGINT UNSIGNED NOT NULL,
    order_item_id  BIGINT UNSIGNED NOT NULL,
    quantity       INT UNSIGNED NOT NULL,
    amount         BIGINT NOT NULL,
    currency       CHAR(3) NOT NULL,
    restock        BOOLEAN NOT NULL DEFAULT FALSE,
    KEY refund_items_refund_idx     (refund_id),
    KEY refund_items_order_item_idx (order_item_id),
    CONSTRAINT refund_items_refund_fk     FOREIGN KEY (refund_id)     REFERENCES refunds(id)      ON DELETE CASCADE,
    CONSTRAINT refund_items_order_item_fk FOREIGN KEY (order_item_id) REFERENCES order_items(id)  ON DELETE CASCADE
);
```

### 3.22 `customers`, `customer_addresses`, `customer_claim_attempts`

```sql
CREATE TABLE customers (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NULL,                          -- FK auth users; NULL for guest snapshot
    email                  VARCHAR(255) NOT NULL,
    first_name             VARCHAR(120) NULL,
    last_name              VARCHAR(120) NULL,
    phone                  VARCHAR(50)  NULL,
    accepts_marketing      BOOLEAN NOT NULL DEFAULT FALSE,
    accepts_marketing_at   TIMESTAMP NULL,
    total_spent_amount     BIGINT NOT NULL DEFAULT 0,                     -- denormalized (base currency)
    total_spent_currency   CHAR(3) NULL,
    orders_count           INT UNSIGNED NOT NULL DEFAULT 0,
    last_ordered_at        TIMESTAMP NULL,
    meta                   JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at             TIMESTAMP NULL,
    updated_at             TIMESTAMP NULL,
    KEY customers_email_idx   (email),
    KEY customers_user_idx    (user_id)
);

CREATE TABLE customer_addresses (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id           BIGINT UNSIGNED NOT NULL,
    label                 VARCHAR(120) NULL,
    is_default_shipping   BOOLEAN NOT NULL DEFAULT FALSE,
    is_default_billing    BOOLEAN NOT NULL DEFAULT FALSE,
    first_name            VARCHAR(120) NULL,
    last_name             VARCHAR(120) NULL,
    company               VARCHAR(120) NULL,
    phone                 VARCHAR(50)  NULL,
    address1              VARCHAR(255) NULL,
    address2              VARCHAR(255) NULL,
    city                  VARCHAR(120) NULL,
    region                VARCHAR(120) NULL,
    region_code           VARCHAR(10)  NULL,
    postal_code           VARCHAR(20)  NULL,
    country_code          CHAR(2)      NULL,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,
    KEY customer_addresses_customer_idx (customer_id),
    CONSTRAINT customer_addresses_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE customer_claim_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(50) NULL,
    ip_address   VARCHAR(45) NULL,
    was_success  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMP NULL,
    KEY customer_claim_attempts_customer_time_idx (customer_id, created_at),
    CONSTRAINT customer_claim_attempts_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);
```

Rate limit for claim attempts: 5/hour per customer (§5.8 in parent plan). Enforced via `ecommerce.claim.attempt` rate policy (§11.5) *and* validated against this table for a defense-in-depth check.

### 3.23 `promotions`, `promotion_conditions`, `promotion_actions`, `coupons`, `promotion_usages`

```sql
CREATE TABLE promotions (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`                     VARCHAR(120) NOT NULL,
    name                      VARCHAR(255) NOT NULL,
    description               TEXT NULL,
    source_type               VARCHAR(80) NOT NULL,                       -- PromotionSourceRegistry key
    is_exclusive              BOOLEAN NOT NULL DEFAULT FALSE,
    priority                  INT NOT NULL DEFAULT 0,
    starts_at                 TIMESTAMP NULL,
    ends_at                   TIMESTAMP NULL,
    usage_limit_total         INT UNSIGNED NULL,
    usage_limit_per_customer  INT UNSIGNED NULL,
    times_used                INT UNSIGNED NOT NULL DEFAULT 0,
    is_active                 BOOLEAN NOT NULL DEFAULT TRUE,
    created_at                TIMESTAMP NULL,
    updated_at                TIMESTAMP NULL,
    UNIQUE KEY promotions_key_uk (`key`),
    KEY promotions_active_range_idx (is_active, starts_at, ends_at)
);

CREATE TABLE promotion_conditions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    type         VARCHAR(80) NOT NULL,                                    -- PromotionConditionRegistry key
    config       JSON NOT NULL,
    KEY promotion_conditions_promotion_idx (promotion_id),
    CONSTRAINT promotion_conditions_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
);

CREATE TABLE promotion_actions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    type         VARCHAR(80) NOT NULL,                                    -- PromotionActionRegistry key
    config       JSON NOT NULL,
    KEY promotion_actions_promotion_idx (promotion_id),
    CONSTRAINT promotion_actions_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
);

CREATE TABLE coupons (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(80) NOT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    UNIQUE KEY coupons_code_uk (code),
    KEY coupons_promotion_idx  (promotion_id),
    CONSTRAINT coupons_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
);

CREATE TABLE promotion_usages (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id      BIGINT UNSIGNED NOT NULL,
    order_id          BIGINT UNSIGNED NOT NULL,
    customer_id       BIGINT UNSIGNED NULL,
    amount_discounted BIGINT NOT NULL,
    currency          CHAR(3) NOT NULL,
    created_at        TIMESTAMP NULL,
    KEY promotion_usages_customer_idx  (customer_id),
    KEY promotion_usages_promotion_idx (promotion_id),
    CONSTRAINT promotion_usages_promotion_fk FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT promotion_usages_order_fk     FOREIGN KEY (order_id)     REFERENCES orders(id)     ON DELETE CASCADE
);
```

### 3.24 `tax_classes`, `tax_rates`

```sql
CREATE TABLE tax_classes (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`      VARCHAR(60) NOT NULL,                                      -- 'standard', 'reduced', 'zero', 'digital'
    label      VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY tax_classes_key_uk (`key`)
);

CREATE TABLE tax_rates (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tax_class_key        VARCHAR(60) NOT NULL,
    country_code         CHAR(2) NOT NULL,
    region_code          VARCHAR(10) NULL,
    postal_pattern       VARCHAR(60) NULL,
    rate_ubps            INT NOT NULL,                                    -- micro-basis-points (§2.4)
    is_compound          BOOLEAN NOT NULL DEFAULT FALSE,
    priority             INT NOT NULL DEFAULT 0,
    label                VARCHAR(120) NOT NULL,
    is_shipping_taxable  BOOLEAN NOT NULL DEFAULT FALSE,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    KEY tax_rates_country_region_idx (country_code, region_code),
    KEY tax_rates_class_active_idx   (tax_class_key, is_active)
);
```

`tax_class_key` is not a hard FK because `tax_classes` may be seeded/reseeded per store, and `products` may be created before a `tax_classes` row exists (default `'standard'`). Service layer validates on write.

### 3.25 `shipping_zones`, `shipping_methods`, `shipments`, `shipment_items`

```sql
CREATE TABLE shipping_zones (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    country_codes     JSON NOT NULL,                                      -- ["US","CA"]
    region_codes      JSON NULL,                                          -- optional narrower
    postal_patterns   JSON NULL,
    priority          INT NOT NULL DEFAULT 0,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL
);

CREATE TABLE shipping_methods (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id      BIGINT UNSIGNED NOT NULL,
    `key`        VARCHAR(120) NOT NULL,                                   -- registry key (flat-rate|free-shipping|local-pickup|weight-based|price-based|provider:...)
    label        VARCHAR(255) NOT NULL,
    config       JSON NOT NULL,
    tax_class_key VARCHAR(60) NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    position     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    KEY shipping_methods_zone_idx (zone_id),
    CONSTRAINT shipping_methods_zone_fk FOREIGN KEY (zone_id) REFERENCES shipping_zones(id) ON DELETE CASCADE
);

CREATE TABLE shipments (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id          BIGINT UNSIGNED NOT NULL,
    method_key        VARCHAR(120) NOT NULL,
    carrier           VARCHAR(120) NULL,
    service           VARCHAR(120) NULL,
    tracking_number   VARCHAR(255) NULL,
    tracking_url      VARCHAR(500) NULL,
    label_id          BIGINT UNSIGNED NULL,                               -- soft FK to shipping-labels satellite
    status            VARCHAR(60) NOT NULL,                               -- pending|in_transit|delivered|exception
    shipped_at        TIMESTAMP NULL,
    delivered_at      TIMESTAMP NULL,
    meta              JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    KEY shipments_order_idx  (order_id),
    KEY shipments_status_idx (status),
    CONSTRAINT shipments_order_fk FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE shipment_items (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id   BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    quantity      INT UNSIGNED NOT NULL,
    KEY shipment_items_shipment_idx   (shipment_id),
    KEY shipment_items_order_item_idx (order_item_id),
    CONSTRAINT shipment_items_shipment_fk   FOREIGN KEY (shipment_id)   REFERENCES shipments(id)   ON DELETE CASCADE,
    CONSTRAINT shipment_items_order_item_fk FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
);
```

### 3.26 `product_reviews`, `product_review_media`

```sql
CREATE TABLE product_reviews (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id            BIGINT UNSIGNED NOT NULL,
    customer_id           BIGINT UNSIGNED NULL,
    order_id              BIGINT UNSIGNED NULL,
    author_name           VARCHAR(255) NOT NULL,
    author_email          VARCHAR(255) NULL,
    rating                TINYINT UNSIGNED NOT NULL,                      -- 1-5
    title                 VARCHAR(255) NULL,
    body                  TEXT NULL,
    is_verified_purchase  BOOLEAN NOT NULL DEFAULT FALSE,
    status                ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending',
    approved_at           TIMESTAMP NULL,
    reviewed_by_user_id   BIGINT UNSIGNED NULL,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL,
    KEY product_reviews_product_status_idx (product_id, status),
    KEY product_reviews_customer_idx       (customer_id),
    CONSTRAINT product_reviews_product_fk FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_review_media (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id  BIGINT UNSIGNED NOT NULL,
    media_id   BIGINT UNSIGNED NOT NULL,
    KEY product_review_media_review_idx (review_id),
    CONSTRAINT product_review_media_review_fk FOREIGN KEY (review_id) REFERENCES product_reviews(id) ON DELETE CASCADE
);
```

### 3.27 Digital delivery

```sql
CREATE TABLE digital_files (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          BIGINT UNSIGNED NULL,
    product_variant_id  BIGINT UNSIGNED NULL,
    media_id            BIGINT UNSIGNED NULL,
    disk                VARCHAR(60) NULL,
    path                VARCHAR(1000) NULL,
    label               VARCHAR(255) NOT NULL,
    version             VARCHAR(60) NULL,
    is_streaming_only   BOOLEAN NOT NULL DEFAULT FALSE,
    checksum_sha256     CHAR(64) NULL,
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,
    KEY digital_files_product_idx (product_id),
    KEY digital_files_variant_idx (product_variant_id),
    CONSTRAINT digital_files_product_fk FOREIGN KEY (product_id)         REFERENCES products(id)         ON DELETE SET NULL,
    CONSTRAINT digital_files_variant_fk FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

CREATE TABLE digital_downloads (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_item_id        BIGINT UNSIGNED NOT NULL,
    digital_file_id      BIGINT UNSIGNED NOT NULL,
    token                CHAR(64) NOT NULL,
    downloads_remaining  INT UNSIGNED NULL,
    expires_at           TIMESTAMP NULL,
    first_downloaded_at  TIMESTAMP NULL,
    last_downloaded_at   TIMESTAMP NULL,
    download_count       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    UNIQUE KEY digital_downloads_token_uk (token),
    KEY digital_downloads_order_item_idx  (order_item_id),
    CONSTRAINT digital_downloads_order_item_fk FOREIGN KEY (order_item_id)   REFERENCES order_items(id)   ON DELETE CASCADE,
    CONSTRAINT digital_downloads_file_fk       FOREIGN KEY (digital_file_id) REFERENCES digital_files(id) ON DELETE CASCADE
);

CREATE TABLE digital_download_events (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    digital_download_id   BIGINT UNSIGNED NOT NULL,
    ip_address            VARCHAR(45)  NULL,
    user_agent            TEXT NULL,
    event_type            VARCHAR(30)  NOT NULL,                          -- download|stream|forbidden
    created_at            TIMESTAMP NULL,
    KEY digital_download_events_download_idx (digital_download_id),
    CONSTRAINT digital_download_events_download_fk FOREIGN KEY (digital_download_id) REFERENCES digital_downloads(id) ON DELETE CASCADE
);

CREATE TABLE license_keys (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_item_id      BIGINT UNSIGNED NOT NULL,
    digital_file_id    BIGINT UNSIGNED NULL,
    `key`              VARCHAR(255) NOT NULL,
    activations_limit  INT UNSIGNED NULL,
    activations_count  INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at         TIMESTAMP NULL,
    is_revoked         BOOLEAN NOT NULL DEFAULT FALSE,
    revoked_at         TIMESTAMP NULL,
    meta               JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at         TIMESTAMP NULL,
    updated_at         TIMESTAMP NULL,
    UNIQUE KEY license_keys_key_uk (`key`),
    KEY license_keys_order_item_idx (order_item_id),
    CONSTRAINT license_keys_order_item_fk FOREIGN KEY (order_item_id)   REFERENCES order_items(id)   ON DELETE CASCADE,
    CONSTRAINT license_keys_file_fk       FOREIGN KEY (digital_file_id) REFERENCES digital_files(id) ON DELETE SET NULL
);

CREATE TABLE license_activations (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_key_id       BIGINT UNSIGNED NOT NULL,
    machine_fingerprint  VARCHAR(128) NULL,
    activated_at         TIMESTAMP NULL,
    last_seen_at         TIMESTAMP NULL,
    ip_address           VARCHAR(45) NULL,
    KEY license_activations_key_idx (license_key_id),
    CONSTRAINT license_activations_key_fk FOREIGN KEY (license_key_id) REFERENCES license_keys(id) ON DELETE CASCADE
);
```

### 3.28 Kanban

```sql
CREATE TABLE kanban_boards (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`          VARCHAR(120) NOT NULL,
    name           VARCHAR(255) NOT NULL,
    description    TEXT NULL,
    routing_rules  JSON NOT NULL DEFAULT (JSON_OBJECT()),
    is_default     BOOLEAN NOT NULL DEFAULT FALSE,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    position       INT UNSIGNED NOT NULL DEFAULT 0,
    settings       JSON NOT NULL DEFAULT (JSON_OBJECT()),
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    UNIQUE KEY kanban_boards_key_uk (`key`)
);

CREATE TABLE kanban_columns (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id        BIGINT UNSIGNED NOT NULL,
    substatus_id    BIGINT UNSIGNED NOT NULL,
    label_override  VARCHAR(120) NULL,
    color_override  CHAR(7) NULL,
    icon_override   VARCHAR(80) NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    wip_limit       INT UNSIGNED NULL,
    card_widgets    JSON NOT NULL DEFAULT (JSON_ARRAY()),
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    UNIQUE KEY kanban_columns_board_substatus_uk (board_id, substatus_id),
    KEY kanban_columns_board_idx                 (board_id),
    CONSTRAINT kanban_columns_board_fk     FOREIGN KEY (board_id)     REFERENCES kanban_boards(id)     ON DELETE CASCADE,
    CONSTRAINT kanban_columns_substatus_fk FOREIGN KEY (substatus_id) REFERENCES order_substatuses(id) ON DELETE CASCADE
);

CREATE TABLE kanban_automations (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id        BIGINT UNSIGNED NOT NULL,
    from_column_id  BIGINT UNSIGNED NULL,                                 -- NULL = any column
    to_column_id    BIGINT UNSIGNED NOT NULL,
    trigger_key     VARCHAR(120) NOT NULL,                                -- KanbanAutomationRegistry key
    trigger_config  JSON NOT NULL,
    conditions      JSON NOT NULL DEFAULT (JSON_OBJECT()),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    KEY kanban_automations_board_idx (board_id),
    KEY kanban_automations_move_idx  (from_column_id, to_column_id),
    CONSTRAINT kanban_automations_board_fk FOREIGN KEY (board_id)       REFERENCES kanban_boards(id)  ON DELETE CASCADE,
    CONSTRAINT kanban_automations_from_fk  FOREIGN KEY (from_column_id) REFERENCES kanban_columns(id) ON DELETE CASCADE,
    CONSTRAINT kanban_automations_to_fk    FOREIGN KEY (to_column_id)   REFERENCES kanban_columns(id) ON DELETE CASCADE
);

CREATE TABLE kanban_card_widgets (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(120) NOT NULL,                                -- KanbanCardWidgetRegistry key
    label           VARCHAR(120) NOT NULL,
    default_config  JSON NOT NULL DEFAULT (JSON_OBJECT()),
    provided_by     VARCHAR(191) NOT NULL,                                -- 'ecommerce' | satellite name
    UNIQUE KEY kanban_card_widgets_key_uk (`key`)
);

CREATE TABLE order_board_assignments (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      BIGINT UNSIGNED NOT NULL,
    board_id      BIGINT UNSIGNED NOT NULL,
    substatus_id  BIGINT UNSIGNED NOT NULL,
    assigned_at   TIMESTAMP NOT NULL,
    removed_at    TIMESTAMP NULL,
    UNIQUE KEY order_board_assignments_uk (order_id, board_id),
    KEY order_board_assignments_board_substatus_idx (board_id, substatus_id),
    CONSTRAINT order_board_assignments_order_fk     FOREIGN KEY (order_id)     REFERENCES orders(id)            ON DELETE CASCADE,
    CONSTRAINT order_board_assignments_board_fk     FOREIGN KEY (board_id)     REFERENCES kanban_boards(id)     ON DELETE CASCADE,
    CONSTRAINT order_board_assignments_substatus_fk FOREIGN KEY (substatus_id) REFERENCES order_substatuses(id) ON DELETE CASCADE
);
```

### 3.29 Webhooks (outbound)

```sql
CREATE TABLE webhook_subscriptions (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                  VARCHAR(255) NOT NULL,
    url                   VARCHAR(1000) NOT NULL,
    secret                VARCHAR(255) NOT NULL,
    events                JSON NOT NULL,                                  -- ['order.placed','order.refunded',...]
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    last_success_at       TIMESTAMP NULL,
    last_failure_at       TIMESTAMP NULL,
    consecutive_failures  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at            TIMESTAMP NULL,
    updated_at            TIMESTAMP NULL
);

CREATE TABLE webhook_deliveries (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id  BIGINT UNSIGNED NOT NULL,
    event            VARCHAR(120) NOT NULL,
    payload_hash     CHAR(64) NOT NULL,
    payload          JSON NOT NULL,
    response_status  SMALLINT UNSIGNED NULL,
    response_body    TEXT NULL,
    attempts         INT UNSIGNED NOT NULL DEFAULT 0,
    delivered_at     TIMESTAMP NULL,
    next_retry_at    TIMESTAMP NULL,
    created_at       TIMESTAMP NULL,
    KEY webhook_deliveries_subscription_idx (subscription_id),
    KEY webhook_deliveries_retry_idx        (next_retry_at),
    CONSTRAINT webhook_deliveries_subscription_fk FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE
);
```

### 3.30 Notifications

```sql
CREATE TABLE notification_templates (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(120) NOT NULL,                                -- e.g. 'order.confirmation.customer'
    channel         VARCHAR(60)  NOT NULL,                                -- 'mail'|'database'|satellite key
    locale          VARCHAR(10)  NOT NULL DEFAULT 'en',
    subject         TEXT NULL,                                            -- Twig source (mail only)
    body            LONGTEXT NOT NULL,                                    -- Twig source
    variables       JSON NOT NULL DEFAULT (JSON_ARRAY()),                 -- documented available variables
    preview_data    JSON NOT NULL DEFAULT (JSON_OBJECT()),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    UNIQUE KEY notification_templates_key_channel_locale_uk (`key`, channel, locale)
);

CREATE TABLE customer_notification_preferences (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    channel      VARCHAR(60) NOT NULL,                                    -- 'mail'|'database'|satellite key
    category     VARCHAR(60) NOT NULL,                                    -- 'transactional'|'shipping-updates'|'review-requests'|'marketing'|'abandoned-cart'|'back-in-stock'
    is_enabled   BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at   TIMESTAMP NULL,
    UNIQUE KEY customer_notification_preferences_uk (customer_id, channel, category),
    CONSTRAINT customer_notification_preferences_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);
```

`transactional` preferences ignore `is_enabled = false` at delivery time — the row exists for auditability but every transactional notification always sends.

### 3.31 Idempotency records

```sql
CREATE TABLE idempotency_records (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_scope       VARCHAR(191) NOT NULL,                              -- 'sanctum-token:{id}' | 'session:{id}' | 'service:{name}'
    endpoint_key      VARCHAR(191) NOT NULL,                              -- route name or normalized method+path
    idempotency_key   VARCHAR(255) NOT NULL,
    request_hash      CHAR(64) NOT NULL,                                  -- sha256(canonical json of the request body)
    response_status   SMALLINT UNSIGNED NULL,                             -- NULL while in-flight
    response_headers  JSON NULL,
    response_body     LONGTEXT NULL,
    locked_at         TIMESTAMP NULL,                                     -- row-lock start; distinguishes in-flight from completed
    expires_at        TIMESTAMP NOT NULL,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    UNIQUE KEY idempotency_records_key_uk (actor_scope, endpoint_key, idempotency_key),
    KEY idempotency_records_expires_idx   (expires_at)
);
```

Pruning: `ecommerce:prune-idempotency-records` runs hourly, deletes rows past `expires_at`. TTL default 24h, overridable per endpoint via `artisanpack.ecommerce.idempotency.ttls`.

### 3.32 Satellite registry

Backing store for §16.6 of the parent plan (satellite lifecycle).

```sql
CREATE TABLE ecommerce_satellites (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_name         VARCHAR(191) NOT NULL,                           -- composer name, e.g. 'artisanpack-ui/ecommerce-subscriptions'
    version              VARCHAR(60)  NOT NULL,                           -- installed version at boot time
    migrations_namespace VARCHAR(191) NULL,
    config_keys          JSON NOT NULL DEFAULT (JSON_ARRAY()),
    meta_namespaces      JSON NOT NULL DEFAULT (JSON_ARRAY()),            -- ['orders.meta.subscription','cart.meta.subscription']
    verified_report_hash CHAR(64) NULL,                                   -- sha256 of the current verify report if the satellite is contract-verified
    registered_at        TIMESTAMP NOT NULL,
    uninstalled_at       TIMESTAMP NULL,
    UNIQUE KEY ecommerce_satellites_package_uk (package_name)
);
```

### 3.33 Migration ordering

Migrations run in dependency order to satisfy FK constraints:

1. `products` (and its sub-tables: `product_prices`, `product_variants`, `product_attributes`, `product_attribute_values`, `product_variant_option_values`, `product_categories`, `product_category_product`, `product_tags`, `product_tag_product`, `product_images`)
2. `tax_classes`, `tax_rates`
3. `shipping_zones`, `shipping_methods`
4. `customers`, `customer_addresses`, `customer_claim_attempts`
5. `carts`, `cart_items`
6. `order_substatuses` (before `orders`, since `orders.substatus_id` FKs it)
7. `orders`, `order_items`, `order_notes`, `order_timeline_entries`, `order_edits`
8. `refunds`, `refund_items`
9. `inventory_items`, `inventory_reservations`
10. `shipments`, `shipment_items`
11. `promotions`, `promotion_conditions`, `promotion_actions`, `coupons`, `promotion_usages`
12. `product_reviews`, `product_review_media`
13. `digital_files`, `digital_downloads`, `digital_download_events`, `license_keys`, `license_activations`
14. `kanban_boards`, `kanban_columns`, `kanban_automations`, `kanban_card_widgets`, `order_board_assignments`
15. `webhook_subscriptions`, `webhook_deliveries`
16. `notification_templates`, `customer_notification_preferences`
17. `idempotency_records`
18. `ecommerce_satellites`

Migration file names use the `create_ecommerce_{table}_table` prefix (per parent plan §20) so all engine migrations sort together in `database/migrations/` regardless of consuming-app ordering.

---

## 4. Contracts

All contracts live under `ArtisanPackUI\Ecommerce\Contracts\`. Every contract has a companion `ContractTest` abstract class under `ArtisanPackUI\Ecommerce\Testing\Contracts\` — satellite authors extend it (see parent plan §15.2).

Type helpers used below:

```php
use ArtisanPackUI\Ecommerce\Support\Address;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use ArtisanPackUI\Ecommerce\Support\FraudDecision;
use ArtisanPackUI\Ecommerce\Support\Money;              // re-exports Money\Money
use ArtisanPackUI\Ecommerce\Support\PaymentResult;
use ArtisanPackUI\Ecommerce\Support\PaymentSession;
use ArtisanPackUI\Ecommerce\Support\Rate;
use ArtisanPackUI\Ecommerce\Support\RefundResult;
use ArtisanPackUI\Ecommerce\Support\TaxResult;
use ArtisanPackUI\Ecommerce\Support\TrackingStatus;
use ArtisanPackUI\Ecommerce\Support\WebhookResult;
```

### 4.1 `ProductType`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Support\Money;

interface ProductType
{
    public function key(): string;
    public function label(): string;
    public function icon(): ?string;

    /**
     * Validate the payload used to add a product of this type to a cart.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>  Sanitized options to persist on cart_items.options.
     */
    public function validateCartOptions( Product $product, array $options ): array;

    /** Price a single line of this product type (may vary by option/config). */
    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money;

    /** Whether the type is fulfillment-relevant (i.e. produces a physical shipment). */
    public function requiresFulfillment(): bool;

    /** Whether the type is inventory-tracked at all. */
    public function isInventoryTracked(): bool;

    /**
     * Hook to build the product_snapshot JSON persisted on order_items.
     *
     * @return array<string, mixed>
     */
    public function buildOrderSnapshot( CartItem $item ): array;

    /** Post-placement side-effects (e.g. digital: issue download tokens; license: mint keys). */
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void;
}
```

### 4.2 `PaymentGateway`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\Money;
use ArtisanPackUI\Ecommerce\Support\PaymentResult;
use ArtisanPackUI\Ecommerce\Support\PaymentSession;
use ArtisanPackUI\Ecommerce\Support\RefundResult;
use ArtisanPackUI\Ecommerce\Support\WebhookResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function key(): string;
    public function label(): string;

    public function supportsRefunds(): bool;
    public function supportsPartialRefunds(): bool;
    public function supportsSavedInstruments(): bool;

    /**
     * Create a provider session (Stripe PaymentIntent, PayPal Order, etc.).
     * The engine passes through the current request's Idempotency-Key so
     * retries at the API layer are safe end-to-end (§11.2).
     *
     * @param  array<string, mixed>  $context   Optional provider-specific hints (return URLs, etc.).
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession;

    /** Capture a previously-authorized payment. Idempotent. */
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult;

    /** Void an authorization that has not yet been captured (used by fraud block, §8.4). */
    public function voidPendingPayment( Order $order ): void;

    /** Issue a refund. Amount MUST be in the order's payment currency. */
    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult;

    /** Handle a signed inbound provider webhook. Returns a normalized result. */
    public function handleWebhook( Request $request ): WebhookResult;
}
```

### 4.3 `ShippingRateProvider`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\Address;
use ArtisanPackUI\Ecommerce\Support\Rate;
use Illuminate\Support\Collection;

interface ShippingRateProvider
{
    public function key(): string;
    public function label(): string;

    /**
     * @return Collection<int, Rate>
     */
    public function getRatesForCart( Cart $cart, Address $destination ): Collection;
}
```

### 4.4 `ShippingLabelProvider`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Support\Label;
use ArtisanPackUI\Ecommerce\Support\TrackingStatus;

interface ShippingLabelProvider
{
    public function key(): string;
    public function buyLabel( Shipment $shipment ): Label;
    public function voidLabel( Label $label ): void;
    public function trackLabel( Label $label ): TrackingStatus;
}
```

The `Label` type + full contract lives in the `artisanpack-ui/shipping-labels` package; this interface duplicates the two-method surface the ecommerce engine uses so satellites can implement `ShippingLabelProvider` without a hard dep on the labels package.

### 4.5 `TaxProvider`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\Address;
use ArtisanPackUI\Ecommerce\Support\TaxResult;

interface TaxProvider
{
    public function key(): string;
    public function label(): string;
    public function calculate( Cart $cart, Address $destination ): TaxResult;
}
```

`TaxResult` shape: `{ total: Money, breakdown: array<int, { label: string, rate_ubps: int, amount: Money, is_compound: bool }>, per_line: array<int, Money> }`.

### 4.6 `FulfillmentAllocationStrategy`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Support\Money;

interface FulfillmentAllocationStrategy
{
    public function key(): string;

    /**
     * @param  iterable<OrderItem>  $items
     * @return array<int, array{ shipping: Money, tax: Money }>  Keyed by OrderItem id.
     */
    public function allocate( Order $order, iterable $items ): array;
}
```

Default: `ProportionalByLineTotalStrategy` (parent plan §16.7). Banker's rounding, residual pushed to last item.

### 4.7 `CurrencyRateProvider`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use Money\Currency;

interface CurrencyRateProvider
{
    public function key(): string;

    /**
     * Rate to multiply an amount in $from by to get the equivalent in $to.
     * Returned as an integer times 10^8 (see §2.3) — never a float.
     */
    public function getRateE8( Currency $from, Currency $to ): int;
}
```

Default providers: `ConfigRateProvider` (rates from `artisanpack.ecommerce.currency.rates`), `FrankfurterRateProvider` (public API — cached daily).

### 4.8 `CartStorage`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;

interface CartStorage
{
    public function find( string $token ): ?Cart;
    public function findForCustomer( int $customerId ): ?Cart;
    public function persist( Cart $cart ): void;
    public function delete( Cart $cart ): void;
}
```

Default: `DatabaseCartStorage`. Alternative: `RedisCartStorage` (satellite territory for high-traffic stores).

### 4.9 `OrderNumberGenerator`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Order;

interface OrderNumberGenerator
{
    /**
     * Generate a unique order number. Called inside a transaction; MUST be
     * collision-safe under concurrent placement. Returning a duplicate is a fatal
     * (order placement retries once, then errors).
     */
    public function generate( Order $order ): string;
}
```

Default: `RandomEightCharGenerator` (uppercase alphanumerics, ambiguous chars removed).

### 4.10 `PromotionCondition`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;

interface PromotionCondition
{
    public function key(): string;
    public function label(): string;

    /**
     * @param  array<string, mixed>  $config  User-supplied condition config from promotion_conditions.config.
     */
    public function evaluate( Cart $cart, array $config ): bool;
}
```

### 4.11 `PromotionAction`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;

interface PromotionAction
{
    public function key(): string;
    public function label(): string;

    /**
     * Mutate the ledger; do not mutate the cart's Money totals directly.
     *
     * @param  array<string, mixed>  $config
     */
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void;
}
```

### 4.12 `KanbanCardWidget`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;

interface KanbanCardWidget
{
    public function key(): string;
    public function label(): string;

    /**
     * Framework-agnostic render payload. Never HTML — UI implementations decide markup.
     *
     * @return array{
     *   label: string,
     *   value: string,
     *   tone?: 'neutral'|'success'|'warning'|'danger'|'info',
     *   icon?: string,
     *   tooltip?: string,
     *   href?: string,
     * }
     */
    public function render( Order $order, KanbanColumn $column ): array;

    /**
     * Optional broadcast channel name whose messages should trigger a client-side
     * refresh of just this widget (avoids full-card re-render).
     */
    public function refreshSubscription( Order $order ): ?string;
}
```

### 4.13 `KanbanAutomationTrigger`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;

interface KanbanAutomationTrigger
{
    public function key(): string;
    public function label(): string;

    /**
     * @param  array<string, mixed>  $config  From kanban_automations.trigger_config.
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void;
}
```

### 4.14 `NotificationTemplate`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

interface NotificationTemplate
{
    public function key(): string;
    public function label(): string;
    public function channel(): string;                     // 'mail'|'database'|satellite key

    /** @return array<int, string>  Names of variables the template exposes. */
    public function variables(): array;

    /** @return array<string, mixed>  Preview data injected into the admin editor. */
    public function previewData(): array;
}
```

Templates are stored in `notification_templates` (see §3.30). The `NotificationTemplateRenderer` service uses this contract's declared `variables()` to validate template source at save-time (rejects references to undeclared variables).

### 4.15 `SearchIndexer`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Product;

interface SearchIndexer
{
    public function key(): string;

    /** @param  iterable<Product>  $products */
    public function indexMany( iterable $products ): void;

    public function delete( Product $product ): void;
    public function flush(): void;
}
```

Complements Laravel Scout — for engines that need a custom transport (e.g. an in-house index) alongside Scout's drivers.

### 4.16 `ReviewModerator`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\ProductReview;

interface ReviewModerator
{
    public function key(): string;

    /**
     * @return 'approve'|'reject'|'spam'|'pending'
     */
    public function moderate( ProductReview $review ): string;
}
```

Default: `NoopReviewModerator` (always returns `pending`, i.e. hand-moderated).

### 4.17 `FraudProvider`

```php
namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\Address;
use ArtisanPackUI\Ecommerce\Support\FraudDecision;
use ArtisanPackUI\Ecommerce\Support\PaymentSession;

interface FraudProvider
{
    public function key(): string;
    public function label(): string;

    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision;
}
```

`FraudDecision` value object:

```php
final class FraudDecision
{
    public function __construct(
        public readonly string $verdict,          // 'approve'|'challenge'|'block'
        public readonly int $score,               // 0..100
        /** @var array<int, string> */
        public readonly array $reasons = [],
        public readonly ?string $providerReference = null,
    ) {}
}
```

Reference implementations: `StripeRadarFraudProvider`, `AlwaysApproveFraudProvider` (both in core).

---

## 5. Registries

All registries live under `ArtisanPackUI\Ecommerce\Registries\` and are bound as singletons in the service provider. Each exposes `register(string $key, string|object $entry, array $meta = [])`, `has(string $key): bool`, `get(string $key): mixed`, `all(): array`. Double-registering the same key throws in `local`/`testing`, warns via `Log::warning` in other environments (parent plan §6.2).

| # | Registry | Container binding | Registers implementations of | Notes / default entries |
|---|---|---|---|---|
| 1 | `ProductTypeRegistry` | `ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry` | `Contracts\ProductType` | Core registers: `simple`, `variable`, `digital`, `grouped`, `bundled`. |
| 2 | `PaymentGatewayRegistry` | `ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry` | `Contracts\PaymentGateway` | Core registers `stripe`. |
| 3 | `ShippingRateProviderRegistry` | `ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry` | `Contracts\ShippingRateProvider` | Core registers the built-in method drivers (`flat-rate`, `free-shipping`, `local-pickup`, `weight-based`, `price-based`) as internal rate providers. Carrier providers ship as satellites. |
| 4 | `ShippingLabelProviderRegistry` | `ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry` | `Contracts\ShippingLabelProvider` | Empty by default; populated by `shipping-labels-*` satellites. |
| 5 | `TaxProviderRegistry` | `ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry` | `Contracts\TaxProvider` | Core registers `manual` (uses `tax_rates`). Only one provider active at a time (settings). |
| 6 | `CurrencyRateProviderRegistry` | `ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry` | `Contracts\CurrencyRateProvider` | Core registers `config`, `frankfurter`. |
| 7 | `PromotionConditionRegistry` | `ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry` | `Contracts\PromotionCondition` | Core registers `min-subtotal`, `min-quantity`, `cart-contains-product`, `cart-contains-category`, `cart-contains-tag`, `customer-first-order`, `customer-lifetime-value-over`, `day-of-week`, `date-range`, `currency-is`. |
| 8 | `PromotionActionRegistry` | `ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry` | `Contracts\PromotionAction` | Core registers `percent-off-cart`, `fixed-off-cart`, `percent-off-product`, `fixed-off-product`, `free-shipping`, `buy-x-get-y`, `add-free-item`, `tiered-discount`. |
| 9 | `PromotionSourceRegistry` | `ArtisanPackUI\Ecommerce\Registries\PromotionSourceRegistry` | (marker sources) | Core registers `coupon`, `automatic`. Satellites add `gift-card`, `referral`, `loyalty`, etc. |
| 10 | `KanbanCardWidgetRegistry` | `ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry` | `Contracts\KanbanCardWidget` | Core registers: `total`, `item-count`, `customer`, `shipping-method`, `tags`, `days-in-column`, `substatus-age`, `payment-status`, `fulfillment-status`. |
| 11 | `KanbanAutomationRegistry` | `ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry` | `Contracts\KanbanAutomationTrigger` | Core registers: `send-email`, `dispatch-job`, `webhook`, `update-order-field`, `create-shipment`. Satellites add `print-shipping-label`, `notify-slack`, etc. |
| 12 | `FraudProviderRegistry` | `ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry` | `Contracts\FraudProvider` | Core registers `stripe-radar`, `always-approve`. Supports `chain` mode (§6.1 parent plan) — a comma-list in settings runs providers in sequence, most-conservative verdict wins. |
| 13 | `NotificationChannelRegistry` | `ArtisanPackUI\Ecommerce\Registries\NotificationChannelRegistry` | Laravel channel drivers | Thin wrapper for discoverability. Core surfaces `mail`, `database`. |
| 14 | `SubStatusRegistry` | `ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry` | DB-backed | Populated from `order_substatuses`, cached in the settings tag. Not directly extensible via `register()`; use the admin API to add rows. Exposed as a registry so downstream code shares one lookup surface. |
| 15 | `AdminMenuRegistry` | `ArtisanPackUI\Ecommerce\Registries\AdminMenuRegistry` | menu entries (array shape) | Satellites append admin-nav entries here. Consumed by admin UI packages. Shape: `{ key, label, icon?, route, position, permission?, badge? }`. |
| 16 | `SatelliteRegistry` | `ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry` | descriptor (array) | Backed by `ecommerce_satellites` (§3.32). Satellites `register()` in service-provider `boot()`; the entry powers `php artisan ecommerce:satellite:uninstall`. |

Registration example (from a satellite):

```php
$this->app->make( \ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry::class )->register(
    key: 'subscription',
    entry: SubscriptionProduct::class,
    meta: [
        'label' => __( 'Subscription' ),
        'icon'  => 'hero-arrow-path',
    ],
);
```

`entry` may be a class name (resolved from the container) or a pre-built instance.

---

## 6. Hooks (actions + filters)

Powered by `artisanpack-ui/hooks` v1.2+. Naming convention per §1: `ap.ecommerce.{camelCase}...`. Every hook in the parent `docs/hooks-spec.md` is included here (verbatim payload signatures) and this section is a strict superset — it adds the promotions, kanban, digital, notification, refund, review, license, and API-augmentation hooks the parent plan's §6.3 called out but that pre-dated `hooks-spec.md`.

### 6.1 Cart lifecycle

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.cart.itemAdding` | filter | before `CartService::add()` persists | `(array $line, Cart $cart)` | Modified `$line` (array) or `null` to abort |
| `ap.ecommerce.cart.itemAdded` | action | after add | `(CartItem $item, Cart $cart)` | — |
| `ap.ecommerce.cart.itemUpdated` | action | after quantity/variant/options change | `(CartItem $item, Cart $cart)` | — |
| `ap.ecommerce.cart.itemRemoved` | action | after remove | `(CartItem $item, Cart $cart)` | — |
| `ap.ecommerce.cart.merging` | filter | guest→user cart merge | `(Cart $guestCart, Cart $userCart)` | `Cart` (the merged cart) |
| `ap.ecommerce.cart.merged` | action | after merge | `(Cart $result, int $guestItemsMerged)` | — |
| `ap.ecommerce.cart.cleared` | action | on manual clear / expiry | `(Cart $cart, string $reason)` | — |
| `ap.ecommerce.cart.abandoned` | action | when scheduler flags a cart abandoned | `(Cart $cart)` | — |

### 6.2 Checkout

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.checkout.started` | action | first render / API call that flips `checkout_started_at` | `(Cart $cart)` | — |
| `ap.ecommerce.checkout.addressCaptured` | action | after shipping/billing set | `(Cart $cart, Address $address, string $type)` | — |
| `ap.ecommerce.checkout.canTransitionTo` | filter | before each checkout-state transition | `(bool $allowed, Cart $cart, string $toState)` | Modified `bool` |
| `ap.ecommerce.checkout.availableGateways` | filter | when UI needs a gateway chooser | `(Collection<PaymentGateway> $gateways, Cart $cart)` | Filtered collection |
| `ap.ecommerce.checkout.paymentInitiated` | action | after `createPaymentSession()` succeeds | `(Cart $cart, PaymentGateway $gateway, PaymentSession $session)` | — |
| `ap.ecommerce.checkout.finalized` | action | after successful `finalize()` | `(Order $order, Cart $cart)` | — |
| `ap.ecommerce.checkout.failed` | action | on any terminal checkout failure | `(Cart $cart, \Throwable $reason)` | — |

### 6.3 Order lifecycle

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.order.placing` | filter | before order persist inside placement txn | `(array $attributes, Cart $cart)` | Modified attributes |
| `ap.ecommerce.order.placed` | action | after order + items persisted | `(Order $order)` | — |
| `ap.ecommerce.order.paid` | action | on `payment_status` → `paid` | `(Order $order, PaymentResult $payment)` | — |
| `ap.ecommerce.order.fulfilling` | action | before any fulfillment side-effect | `(Order $order)` | — |
| `ap.ecommerce.order.fulfilled` | action | after `fulfillment_status` → `fulfilled` | `(Order $order)` | — |
| `ap.ecommerce.order.shipped` | action | on shipment creation | `(Order $order, Shipment $shipment)` | — |
| `ap.ecommerce.order.delivered` | action | on delivery confirmation | `(Order $order, Shipment $shipment)` | — |
| `ap.ecommerce.order.cancelling` | action | before cancel side-effects | `(Order $order, string $reason)` | — |
| `ap.ecommerce.order.cancelled` | action | after cancel | `(Order $order)` | — |
| `ap.ecommerce.order.refunded` | action | after refund persisted | `(Order $order, Refund $refund)` | — |
| `ap.ecommerce.order.statusChanged` | action | any `system_status` transition | `(Order $order, string $from, string $to)` | — |
| `ap.ecommerce.order.substatusChanged` | action | any `substatus_id` change | `(Order $order, ?OrderSubstatus $from, OrderSubstatus $to, ?KanbanBoard $board)` | — |
| `ap.ecommerce.order.editing` | filter | inside `OrderEditService::apply()` before recalc | `(array $edit, Order $order)` | Modified `$edit` |
| `ap.ecommerce.order.edited` | action | after edit persisted | `(Order $order, array $diff, ?OrderEdit $edit)` | — |
| `ap.ecommerce.order.itemFulfilled` | action | per line-item `fulfillment_status` → `fulfilled` | `(Order $order, OrderItem $item)` | — |
| `ap.ecommerce.order.number` | filter | after `OrderNumberGenerator::generate()` | `(string $number, Order $order)` | Modified string |

### 6.4 Pricing pipeline

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.pricing.itemPrice` | filter | per-line-item price calc | `(Money $price, CartItem $item, Cart $cart)` | `Money` |
| `ap.ecommerce.pricing.priceDisplay` | filter | display-time price (catalog, PDP, cart) | `(Money $price, Product\|ProductVariant $subject, ?Customer $customer)` | `Money` |
| `ap.ecommerce.pricing.subtotal` | filter | after subtotal calc | `(Money $subtotal, Cart $cart)` | `Money` |
| `ap.ecommerce.pricing.total` | filter | after full totals calc | `(Money $total, Cart $cart, array $breakdown)` | `Money` |
| `ap.ecommerce.pricing.discountApplied` | action | per `PromotionAction::apply()` result | `(Discount $discount, Cart $cart, Money $amount)` | — |
| `ap.ecommerce.pricing.registeredDiscountTypes` | filter | promotion-action registry lookup | `(array $types)` | `array` |

### 6.5 Tax calculation

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.tax.calculating` | filter | before `TaxProvider::calculate()` | `(TaxContext $context, Cart $cart)` | Modified `TaxContext` (or a different one — enables provider swap) |
| `ap.ecommerce.tax.rates` | filter | rate resolution inside `ManualTaxProvider` | `(array $rates, TaxContext $context)` | Modified array |
| `ap.ecommerce.tax.calculated` | filter | after provider returns | `(TaxBreakdown $breakdown, Cart $cart)` | Modified breakdown |

### 6.6 Shipping

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.shipping.registeredMethods` | filter | on shipping-method registry lookup | `(array $methods)` | `array` |
| `ap.ecommerce.shipping.availableMethods` | filter | per-cart method filtering | `(array $methods, Cart $cart, Address $destination)` | `array` |
| `ap.ecommerce.shipping.rateCalculated` | filter | after each method rate | `(Money $rate, ShippingMethod $method, Cart $cart)` | `Money` |
| `ap.ecommerce.shipping.methodSelected` | action | customer picks method at checkout | `(ShippingMethod $method, Cart $cart)` | — |
| `ap.ecommerce.shipping.shipmentCreated` | action | after `Shipment` row persisted | `(Shipment $shipment, Order $order)` | — |
| `ap.ecommerce.shipping.trackingUpdated` | action | when carrier callback / poll updates tracking | `(Shipment $shipment, TrackingStatus $status)` | — |

### 6.7 Payment gateways

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.payment.registeredGateways` | filter | on gateway registry lookup | `(array $gateways)` | `array` |
| `ap.ecommerce.payment.availableGateways` | filter | per-cart gateway filtering | `(array $gateways, Cart $cart)` | `array` |
| `ap.ecommerce.payment.charging` | action | just before `capturePayment()` | `(PaymentGateway $gateway, Money $amount, Order $order)` | — |
| `ap.ecommerce.payment.succeeded` | action | after successful capture | `(PaymentResult $payment, Order $order)` | — |
| `ap.ecommerce.payment.failed` | action | on any capture failure | `(PaymentGateway $gateway, \Throwable $e, Order $order)` | — |
| `ap.ecommerce.payment.webhookReceived` | action | inbound provider webhook (post signature-verify) | `(array $payload, string $gatewaySlug)` | — |
| `ap.ecommerce.payment.refunding` | filter | before `refund()` call | `(Money $amount, Order $order, ?string $reason)` | Modified `Money` (or `null` to abort) |
| `ap.ecommerce.payment.refunded` | action | after refund persisted | `(RefundResult $result, Order $order)` | — |

### 6.8 Fraud

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.fraud.assessing` | filter | before `FraudProvider::assess()` | `(array $context, Cart $cart)` | Modified context array |
| `ap.ecommerce.fraud.assessed` | filter | after `assess()` returns | `(FraudDecision $decision, Cart $cart, PaymentSession $session)` | `FraudDecision` |
| `ap.ecommerce.fraud.blocked` | action | on `block` verdict application | `(Order $order, FraudDecision $decision)` | — |

### 6.9 Product / variant lifecycle

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.product.saving` | action | Eloquent `saving` on `Product` | `(Product $product)` | — |
| `ap.ecommerce.product.saved` | action | Eloquent `saved` on `Product` | `(Product $product)` | — |
| `ap.ecommerce.product.published` | action | `status` → `active` transition | `(Product $product)` | — |
| `ap.ecommerce.product.unpublished` | action | `status` → `archived` transition | `(Product $product)` | — |
| `ap.ecommerce.product.deleted` | action | Eloquent `deleted` on `Product` | `(Product $product)` | — |
| `ap.ecommerce.variant.saved` | action | Eloquent `saved` on `ProductVariant` | `(ProductVariant $variant, Product $product)` | — |
| `ap.ecommerce.product.registeredTypes` | filter | product-type registry lookup | `(array $types)` | `array` |
| `ap.ecommerce.product.listQuery` | filter | admin/API list query builder | `(Builder $query, array $filters)` | `Builder` |
| `ap.ecommerce.product.searchableData` | filter | Scout `toSearchableArray()` | `(array $data, Product $product)` | `array` |

### 6.10 Inventory

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.inventory.adjusting` | filter | before adjustment persisted | `(int $delta, InventoryItem $item, string $reason)` | Modified `$delta` |
| `ap.ecommerce.inventory.adjusted` | action | after adjustment persisted | `(InventoryItem $item, int $delta, int $newLevel)` | — |
| `ap.ecommerce.inventory.reserved` | action | after reservation created | `(InventoryReservation $reservation)` | — |
| `ap.ecommerce.inventory.reservationReleased` | action | after expired-reservation release | `(InventoryReservation $reservation)` | — |
| `ap.ecommerce.inventory.lowStock` | action | when threshold crossed downward | `(InventoryItem $item, int $level)` | — |
| `ap.ecommerce.inventory.outOfStock` | action | on hitting zero | `(InventoryItem $item)` | — |

### 6.11 Customer lifecycle

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.customer.registered` | action | first `customers` row for an email | `(Customer $customer)` | — |
| `ap.ecommerce.customer.userLinked` | action | after `customers.user_id` back-fill on verified registration | `(Customer $customer, User $user)` | — |
| `ap.ecommerce.customer.orderClaimed` | action | successful guest-order claim | `(Customer $customer, Order $order)` | — |
| `ap.ecommerce.customer.firstOrder` | action | on first paid order | `(Customer $customer, Order $order)` | — |
| `ap.ecommerce.customer.becameVip` | action | on VIP threshold cross | `(Customer $customer, string $reason)` | — |
| `ap.ecommerce.customer.deleted` | action | after GDPR delete + anonymize | `(int $customerIdWas, string $emailWas)` | — |

### 6.12 Reviews

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.review.submitting` | filter | before persist | `(array $attributes, ?Order $order)` | Modified attributes (or `null` to abort) |
| `ap.ecommerce.review.submitted` | action | after persist | `(ProductReview $review)` | — |
| `ap.ecommerce.review.moderating` | filter | before `ReviewModerator::moderate()` | `(ProductReview $review)` | `ProductReview` |
| `ap.ecommerce.review.approved` | action | on approve | `(ProductReview $review)` | — |
| `ap.ecommerce.review.rejected` | action | on reject | `(ProductReview $review, string $reason)` | — |
| `ap.ecommerce.review.markedSpam` | action | on spam flag | `(ProductReview $review)` | — |

### 6.13 Digital delivery + licenses

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.digital.tokenIssued` | action | after `digital_downloads` row created | `(DigitalDownload $download, OrderItem $item)` | — |
| `ap.ecommerce.digital.downloading` | filter | before file stream begins | `(array $context, DigitalDownload $download)` | Modified context |
| `ap.ecommerce.digital.downloaded` | action | after successful stream | `(DigitalDownload $download)` | — |
| `ap.ecommerce.digital.streamWatermark` | filter | streaming pipeline hook | `(StreamedResponse $stream, DigitalDownload $download)` | `StreamedResponse` |
| `ap.ecommerce.digital.productUpdated` | action | `digital_files.version` changed | `(Product $product, DigitalFile $file)` | — |
| `ap.ecommerce.license.issued` | action | after `license_keys` row created | `(LicenseKey $key, OrderItem $item)` | — |
| `ap.ecommerce.license.validating` | filter | before `POST /license/validate` responds | `(array $result, string $key, string $fingerprint)` | Modified result array |
| `ap.ecommerce.license.activated` | action | after `license_activations` row | `(LicenseActivation $activation)` | — |
| `ap.ecommerce.license.revoked` | action | after revoke | `(LicenseKey $key, ?string $reason)` | — |

### 6.14 Kanban

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.kanban.routingBoards` | filter | on placement, determining which boards catch this order | `(array $boardIds, Order $order)` | `array` |
| `ap.ecommerce.kanban.boardAssignmentAdded` | action | after `order_board_assignments` insert | `(OrderBoardAssignment $assignment)` | — |
| `ap.ecommerce.kanban.boardAssignmentRemoved` | action | after `removed_at` set | `(OrderBoardAssignment $assignment)` | — |
| `ap.ecommerce.kanban.boardReassigned` | action | when routing re-runs after order edit | `(Order $order, array $addedBoardIds, array $removedBoardIds)` | — |
| `ap.ecommerce.kanban.cardMoving` | filter | before column mutation | `(array $move, OrderBoardAssignment $assignment)` | Modified `$move` (or `null` to abort) |
| `ap.ecommerce.kanban.cardMoved` | action | after move persisted | `(Order $order, KanbanColumn $from, KanbanColumn $to, KanbanBoard $board)` | — |
| `ap.ecommerce.kanban.automationFired` | action | after each matching automation runs | `(KanbanAutomation $automation, Order $order)` | — |
| `ap.ecommerce.kanban.widgetRendered` | filter | after `KanbanCardWidget::render()` | `(array $payload, KanbanCardWidget $widget, Order $order)` | `array` |

### 6.15 Notifications

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.notification.templateVariables` | filter | building the render context | `(array $vars, string $templateKey, mixed $subject)` | `array` |
| `ap.ecommerce.notification.rendering` | filter | before Twig render | `(string $body, string $templateKey, array $vars)` | `string` |
| `ap.ecommerce.notification.sending` | action | pre-dispatch | `(Notification $notification, mixed $notifiable, string $channel)` | — |
| `ap.ecommerce.notification.sent` | action | post-dispatch | `(Notification $notification, mixed $notifiable, string $channel)` | — |

### 6.16 Webhooks (outbound)

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.webhook.subscribing` | filter | before `webhook_subscriptions` insert | `(array $attributes)` | `array` (or `null` to abort) |
| `ap.ecommerce.webhook.delivering` | filter | before HTTP delivery | `(array $payload, WebhookSubscription $sub, string $event)` | `array` |
| `ap.ecommerce.webhook.delivered` | action | on 2xx response | `(WebhookDelivery $delivery)` | — |
| `ap.ecommerce.webhook.failed` | action | on non-2xx or timeout | `(WebhookDelivery $delivery, \Throwable $reason)` | — |
| `ap.ecommerce.webhook.subscriptionDisabled` | action | when consecutive-failure ceiling hit | `(WebhookSubscription $sub)` | — |

### 6.17 API augmentation

| Hook | Type | Fired | Payload | Return |
|---|---|---|---|---|
| `ap.ecommerce.api.resource.{name}` | filter | in each Eloquent API Resource `toArray()` — `{name}` is `product`, `order`, `cart`, `customer`, `variant`, `refund`, `shipment`, `review`, `kanbanBoard`, `kanbanCard`, `promotion`, `coupon`, `taxRate`, `shippingMethod`, `notificationTemplate`, `webhookSubscription`, `digitalDownload`, `licenseKey` | `(array $data, Model $subject, Request $request)` | `array` |
| `ap.ecommerce.api.list.{name}` | filter | before serializing a listing response | `(array $items, Builder $query, Request $request)` | `array` |
| `ap.ecommerce.graphql.extend` | filter | GraphQL schema build (§10) | `(array $types)` | `array` |

### 6.18 Policy ability filters

Every Laravel Gate ability check routes through `ap.ecommerce.abilities.{resource}.{action}` per the existing `docs/hooks-spec.md` policy-filters section. Concretely (list is closed at engine v1.0; satellites add their own):

| Resource | Actions |
|---|---|
| `product` | `viewAny`, `view`, `create`, `update`, `delete`, `restore` |
| `order` | `viewAny`, `view`, `create`, `update`, `edit-fulfilled`, `cancel`, `refund` |
| `refund` | `create`, `view` |
| `customer` | `viewAny`, `view`, `update`, `delete` |
| `promotion` | `viewAny`, `view`, `create`, `update`, `delete` |
| `coupon` | `create`, `update`, `delete` |
| `taxRate` | `viewAny`, `create`, `update`, `delete` |
| `shippingZone` | `viewAny`, `create`, `update`, `delete` |
| `kanbanBoard` | `viewAny`, `view`, `create`, `update`, `delete` |
| `kanbanCard` | `move` |
| `notificationTemplate` | `viewAny`, `view`, `update` |
| `webhookSubscription` | `viewAny`, `create`, `update`, `delete` |
| `digitalFile` | `create`, `update`, `delete` |
| `licenseKey` | `view`, `revoke` |
| `review` | `viewAny`, `view`, `moderate`, `delete` |

---

## 7. Events (Laravel)

Every event lives under `ArtisanPackUI\Ecommerce\Events\` and is dispatched via `Event::dispatch()` from the service that owns the state transition. Listeners are queued by default (`ShouldQueue` implemented on the *listener*, not the event — so hook subscribers get sync handling, event subscribers get async).

Notation: `EventClass(payload)` where the payload is the constructor arg list.

| # | Event | Constructor | Fired from |
|---|---|---|---|
| 1 | `CartCreated` | `(Cart $cart)` | `CartService::create()` |
| 2 | `CartUpdated` | `(Cart $cart, array $changes)` | Any mutating cart service method |
| 3 | `CartAbandoned` | `(Cart $cart)` | Scheduled `carts:flag-abandoned` job |
| 4 | `CartCompleted` | `(Cart $cart, Order $order)` | `CheckoutService::finalize()` |
| 5 | `CartMerged` | `(Cart $result, int $guestItemsMerged)` | `CartMergeService::merge()` |
| 6 | `OrderPlaced` | `(Order $order)` | `OrderPlacementService::place()` |
| 7 | `OrderStatusChanged` | `(Order $order, string $from, string $to)` | `OrderStatusMachine::transition()` |
| 8 | `OrderSubstatusChanged` | `(Order $order, ?OrderSubstatus $from, OrderSubstatus $to, ?KanbanBoard $board)` | Substatus setter |
| 9 | `OrderFulfilled` | `(Order $order)` | `FulfillmentService::markFulfilled()` |
| 10 | `OrderCancelled` | `(Order $order, string $reason)` | `OrderCancellationService::cancel()` |
| 11 | `OrderRefunded` | `(Order $order, Refund $refund)` | `RefundService::issue()` |
| 12 | `OrderEdited` | `(Order $order, array $diff)` | `OrderEditService::apply()` |
| 13 | `PaymentSucceeded` | `(Order $order, PaymentResult $payment)` | `PaymentOrchestrator::capture()` |
| 14 | `PaymentFailed` | `(?Order $order, PaymentGateway $gateway, \Throwable $reason)` | `PaymentOrchestrator::capture()` catch |
| 15 | `PaymentRefunded` | `(Order $order, RefundResult $result)` | `RefundService::issue()` |
| 16 | `FraudBlocked` | `(Order $order, FraudDecision $decision)` | `PaymentOrchestrator::assess()` |
| 17 | `FraudChallenged` | `(Cart $cart, FraudDecision $decision, PaymentSession $session)` | `PaymentOrchestrator::assess()` |
| 18 | `ShipmentCreated` | `(Shipment $shipment, Order $order)` | `ShipmentService::create()` |
| 19 | `ShipmentDelivered` | `(Shipment $shipment, Order $order)` | Carrier callback / poll |
| 20 | `CustomerRegistered` | `(Customer $customer)` | `CustomerRegistrationService::register()` |
| 21 | `CustomerUpdated` | `(Customer $customer, array $changes)` | Any mutating customer service |
| 22 | `CustomerUserLinked` | `(Customer $customer, User $user)` | `CustomerLinkService::linkOnVerification()` |
| 23 | `CustomerOrderClaimed` | `(Customer $customer, Order $order)` | `CustomerClaimService::claim()` |
| 24 | `ProductCreated` | `(Product $product)` | Eloquent listener |
| 25 | `ProductUpdated` | `(Product $product, array $changes)` | Eloquent listener |
| 26 | `ProductStockLow` | `(InventoryItem $item, int $level)` | `InventoryService::adjust()` |
| 27 | `ProductOutOfStock` | `(InventoryItem $item)` | `InventoryService::adjust()` |
| 28 | `ReviewSubmitted` | `(ProductReview $review)` | `ReviewService::submit()` |
| 29 | `ReviewApproved` | `(ProductReview $review)` | `ReviewService::approve()` |
| 30 | `PromotionApplied` | `(Promotion $promotion, Cart $cart, Money $amount)` | `PromotionEngine::evaluate()` |
| 31 | `CouponRedeemed` | `(Coupon $coupon, Order $order, Money $amount)` | `PromotionEngine::finalize()` |
| 32 | `DigitalDownloadTokenIssued` | `(DigitalDownload $download, OrderItem $item)` | `DigitalDownloadService::issue()` |
| 33 | `DigitalProductUpdated` | `(Product $product, DigitalFile $file)` | `DigitalFileService::updateVersion()` |
| 34 | `LicenseIssued` | `(LicenseKey $key, OrderItem $item)` | `LicenseService::issue()` |
| 35 | `LicenseRevoked` | `(LicenseKey $key, ?string $reason)` | `LicenseService::revoke()` |
| 36 | `KanbanCardMoved` | `(Order $order, KanbanColumn $from, KanbanColumn $to, KanbanBoard $board)` | `KanbanBoardService::move()` |
| 37 | `KanbanAutomationTriggered` | `(KanbanAutomation $automation, Order $order)` | `KanbanAutomationRunner::run()` |
| 38 | `KanbanBoardAssignmentAdded` | `(OrderBoardAssignment $assignment)` | `KanbanRoutingService::route()` |
| 39 | `KanbanBoardAssignmentRemoved` | `(OrderBoardAssignment $assignment)` | `KanbanRoutingService::unroute()` |
| 40 | `WebhookDelivered` | `(WebhookDelivery $delivery)` | `WebhookDeliveryJob` |
| 41 | `WebhookFailed` | `(WebhookDelivery $delivery, \Throwable $reason)` | `WebhookDeliveryJob` |

Hook/event pairing rules (parent plan §6.4 restated): every state-changing service fires the matching sync hook *first* (so filters and same-request listeners run) and *then* dispatches the async event. If the hook throws (e.g. validation), the event does not dispatch and the transaction rolls back.

---

## 8. Webhooks (outbound)

### 8.1 Signature format

```
X-ArtisanPack-Signature: t=<unix_ts>, v1=<hex(hmac_sha256(t + '.' + payload, subscription.secret))>
```

Verifiers compare `v1` in constant time and reject if `|now - t| > 300s`. Receivers get the raw request body verbatim to reproduce the HMAC.

### 8.2 Delivery lifecycle

1. Domain event fires (§7).
2. `WebhookDispatchListener` (queued) finds active subscriptions whose `events` JSON includes the event key.
3. For each subscription, inserts a `webhook_deliveries` row with `payload_hash = sha256(payload)`.
4. `WebhookDeliveryJob` (rate-limited per §11.5) POSTs. On 2xx: `delivered_at = now`, `subscriptions.consecutive_failures = 0`. On non-2xx: increment `attempts`, set `next_retry_at = now + backoff(attempts)`, and increment `subscriptions.consecutive_failures`.
5. Backoff: `[1min, 5min, 15min, 30min, 1h, 2h, 4h, 8h, 12h, 24h]` — 10 attempts, ~24h total.
6. After 15 consecutive failures across subscriptions, mark `is_active = false` and dispatch `ap.ecommerce.webhook.subscriptionDisabled`.

### 8.3 Event names delivered

Same event key list as §7 (PascalCase class → dot-notation event name for the wire — `OrderPlaced` → `order.placed`), plus a small set of provider-independent aliases documented in the OpenAPI spec.

---

## 9. REST resource inventory

All routes are under `/api/ecommerce/v1/`. Every mutating endpoint requires an `Idempotency-Key` header (§11.2). Every endpoint carries a named rate-limit policy from §11.5. Auth column values: `public` (no auth), `sanctum` (Sanctum token), `session` (storefront session cookie), `admin` (Sanctum + admin permission), `webhook` (provider-signed body).

### 9.1 Catalog (public + session)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `products` | public | — | `ecommerce.catalog.read` |
| GET | `products/{product}` | public | — | `ecommerce.catalog.read` |
| GET | `products/{product}/variants` | public | — | `ecommerce.catalog.read` |
| GET | `products/{product}/reviews` | public | — | `ecommerce.catalog.read` |
| POST | `products/{product}/reviews` | session | required | `ecommerce.review.submit` |
| GET | `categories` | public | — | `ecommerce.catalog.read` |
| GET | `categories/{category}` | public | — | `ecommerce.catalog.read` |
| GET | `categories/{category}/products` | public | — | `ecommerce.catalog.read` |
| GET | `tags` | public | — | `ecommerce.catalog.read` |
| GET | `search` | public | — | `ecommerce.catalog.read` |

### 9.2 Cart + checkout (session, plus token for guests)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| POST | `carts` | public | required | `ecommerce.cart.mutate` |
| GET | `carts/{token}` | session | — | `ecommerce.cart.mutate` |
| POST | `carts/{token}/items` | session | required | `ecommerce.cart.mutate` |
| PATCH | `carts/{token}/items/{item}` | session | required | `ecommerce.cart.mutate` |
| DELETE | `carts/{token}/items/{item}` | session | required | `ecommerce.cart.mutate` |
| POST | `carts/{token}/coupons` | session | required | `ecommerce.coupon.attempt` |
| DELETE | `carts/{token}/coupons/{code}` | session | required | `ecommerce.cart.mutate` |
| POST | `carts/{token}/merge` | session | required | `ecommerce.cart.mutate` |
| POST | `checkout/{token}/address` | session | required | `ecommerce.cart.mutate` |
| POST | `checkout/{token}/shipping-method` | session | required | `ecommerce.cart.mutate` |
| POST | `checkout/{token}/payment-gateway` | session | required | `ecommerce.cart.mutate` |
| POST | `checkout/{token}/session` | session | required | `ecommerce.checkout.finalize` |
| POST | `checkout/{token}/finalize` | session | required | `ecommerce.checkout.finalize` |

### 9.3 Orders (customer + admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `orders` | sanctum | — | `ecommerce.admin.mutate` |
| GET | `orders/{order}` | sanctum | — | `ecommerce.admin.mutate` |
| PATCH | `orders/{order}` | admin | required | `ecommerce.admin.mutate` |
| POST | `orders/{order}/cancel` | admin | required | `ecommerce.admin.mutate` |
| POST | `orders/{order}/refunds` | admin | required | `ecommerce.admin.mutate` |
| GET | `orders/{order}/timeline` | admin | — | `ecommerce.admin.mutate` |
| POST | `orders/{order}/notes` | admin | required | `ecommerce.admin.mutate` |
| POST | `orders/{order}/shipments` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `orders/{order}/shipments/{shipment}` | admin | required | `ecommerce.admin.mutate` |
| GET | `orders/guest-lookup` | public | — | `ecommerce.coupon.attempt` (reuses brute-force policy) |
| POST | `orders/{order}/claim` | sanctum | required | `ecommerce.claim.attempt` |

### 9.4 Customers

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `customers` | admin | — | `ecommerce.admin.mutate` |
| GET | `customers/{customer}` | admin | — | `ecommerce.admin.mutate` |
| PATCH | `customers/{customer}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `customers/{customer}` | admin | required | `ecommerce.admin.mutate` |
| GET | `me` | sanctum | — | `ecommerce.admin.mutate` |
| PATCH | `me` | sanctum | required | `ecommerce.admin.mutate` |
| GET | `me/addresses` | sanctum | — | `ecommerce.admin.mutate` |
| POST | `me/addresses` | sanctum | required | `ecommerce.admin.mutate` |
| PATCH | `me/addresses/{address}` | sanctum | required | `ecommerce.admin.mutate` |
| DELETE | `me/addresses/{address}` | sanctum | required | `ecommerce.admin.mutate` |
| GET | `me/orders` | sanctum | — | `ecommerce.admin.mutate` |
| GET | `me/notification-preferences` | sanctum | — | `ecommerce.admin.mutate` |
| PATCH | `me/notification-preferences` | sanctum | required | `ecommerce.admin.mutate` |

### 9.5 Products (admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| POST | `admin/products` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/products/{product}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/products/{product}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/products/{product}/variants` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/products/{product}/variants/{variant}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/products/{product}/variants/{variant}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/products/{product}/prices` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/products/{product}/images` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/products/{product}/categories` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/product-categories` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/product-categories/{category}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/product-categories/{category}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/product-tags` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/product-tags/{tag}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/product-tags/{tag}` | admin | required | `ecommerce.admin.mutate` |

### 9.6 Inventory (admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `admin/inventory` | admin | — | `ecommerce.admin.mutate` |
| PATCH | `admin/inventory/{item}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/inventory/{item}/adjust` | admin | required | `ecommerce.admin.mutate` |

### 9.7 Promotions + coupons (admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `admin/promotions` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/promotions` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/promotions/{promotion}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/promotions/{promotion}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/promotions/{promotion}/coupons` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/coupons/{coupon}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/coupons/{coupon}` | admin | required | `ecommerce.admin.mutate` |

### 9.8 Tax + shipping (admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `admin/tax-classes` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/tax-classes` | admin | required | `ecommerce.admin.mutate` |
| GET | `admin/tax-rates` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/tax-rates` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/tax-rates/{rate}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/tax-rates/{rate}` | admin | required | `ecommerce.admin.mutate` |
| GET | `admin/shipping-zones` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/shipping-zones` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/shipping-zones/{zone}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/shipping-zones/{zone}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/shipping-zones/{zone}/methods` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/shipping-methods/{method}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/shipping-methods/{method}` | admin | required | `ecommerce.admin.mutate` |

### 9.9 Digital + licenses

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `downloads/{token}` | public (token in URL) | — | `ecommerce.catalog.read` |
| GET | `downloads/{token}/stream` | public (token in URL, byte-range) | — | `ecommerce.catalog.read` |
| POST | `license/validate` | public | required | `ecommerce.license.validate` |
| GET | `admin/digital-files` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/digital-files` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/digital-files/{file}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/license-keys/{key}/revoke` | admin | required | `ecommerce.admin.mutate` |

### 9.10 Kanban

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `kanban/boards` | admin | — | `ecommerce.admin.mutate` |
| GET | `kanban/boards/{board}` | admin | — | `ecommerce.admin.mutate` |
| GET | `kanban/boards/{board}/cards` | admin | — | `ecommerce.admin.mutate` |
| POST | `kanban/cards/{order}/move` | admin | required | `ecommerce.admin.mutate` |
| POST | `kanban/boards` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `kanban/boards/{board}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `kanban/boards/{board}` | admin | required | `ecommerce.admin.mutate` |
| POST | `kanban/boards/{board}/columns` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `kanban/columns/{column}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `kanban/columns/{column}` | admin | required | `ecommerce.admin.mutate` |
| POST | `kanban/boards/{board}/automations` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `kanban/automations/{automation}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `kanban/automations/{automation}` | admin | required | `ecommerce.admin.mutate` |
| POST | `kanban/boards/{board}/assignments/{order}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `kanban/boards/{board}/assignments/{order}` | admin | required | `ecommerce.admin.mutate` |

### 9.11 Notifications + webhooks (admin)

| Method | Path | Auth | Idempotent | Rate policy |
|---|---|---|---|---|
| GET | `admin/notification-templates` | admin | — | `ecommerce.admin.mutate` |
| GET | `admin/notification-templates/{template}` | admin | — | `ecommerce.admin.mutate` |
| PATCH | `admin/notification-templates/{template}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/notification-templates/{template}/preview` | admin | — | `ecommerce.admin.mutate` |
| GET | `admin/webhook-subscriptions` | admin | — | `ecommerce.admin.mutate` |
| POST | `admin/webhook-subscriptions` | admin | required | `ecommerce.admin.mutate` |
| PATCH | `admin/webhook-subscriptions/{sub}` | admin | required | `ecommerce.admin.mutate` |
| DELETE | `admin/webhook-subscriptions/{sub}` | admin | required | `ecommerce.admin.mutate` |
| POST | `admin/webhook-subscriptions/{sub}/replay/{delivery}` | admin | required | `ecommerce.admin.mutate` |

### 9.12 Inbound webhooks (payment providers)

Path pattern: `/ecommerce/webhooks/{provider}` (note: NOT under `/api/`, so the Sanctum middleware group is skipped).

| Method | Path | Auth | Rate policy |
|---|---|---|---|
| POST | `ecommerce/webhooks/{provider}` | webhook (provider-signed body) | `ecommerce.webhook.inbound` |

Providers register handlers via `PaymentGateway::handleWebhook()`. The route file loads gateways from the registry; unknown providers → 404.

### 9.13 Resource inventory (JSON:API-inspired resource types)

The `Resource` classes in `ArtisanPackUI\Ecommerce\Http\Resources\` map 1:1 to these types (used in REST payloads and mirrored in GraphQL types §10.1):

`Product`, `ProductVariant`, `ProductPrice`, `ProductAttribute`, `ProductAttributeValue`, `ProductCategory`, `ProductTag`, `ProductImage`, `ProductReview`,
`Customer`, `CustomerAddress`, `Me`,
`Cart`, `CartItem`,
`Order`, `OrderItem`, `OrderNote`, `OrderTimelineEntry`, `OrderEdit`, `Refund`, `RefundItem`,
`InventoryItem`, `InventoryReservation`,
`ShippingZone`, `ShippingMethod`, `Shipment`, `ShipmentItem`,
`TaxClass`, `TaxRate`,
`Promotion`, `PromotionCondition`, `PromotionAction`, `Coupon`, `PromotionUsage`,
`DigitalFile`, `DigitalDownload`, `LicenseKey`, `LicenseActivation`,
`KanbanBoard`, `KanbanColumn`, `KanbanAutomation`, `KanbanCardWidget`, `KanbanCard`, `OrderBoardAssignment`,
`NotificationTemplate`, `CustomerNotificationPreference`,
`WebhookSubscription`, `WebhookDelivery`.

30 resource types total. Every one has a filter hook (§6.17: `ap.ecommerce.api.resource.{name}`) using the camelCase form of the type name.

---

## 10. GraphQL surface

Powered by `rebing/graphql-laravel`. Schema exposed at `/graphql/ecommerce`. GraphiQL enabled in `local`. Third parties extend via `rebing/graphql-laravel`'s type registry + the `ap.ecommerce.graphql.extend` filter (§6.17).

### 10.1 Object types

One GraphQL type per REST resource (§9.13). Naming: PascalCase matches the resource class name (`Product`, `ProductVariant`, `Order`, `OrderItem`, `KanbanBoard`, …).

Additional GraphQL-specific types:

- **Enums:** `SystemOrderStatus`, `PaymentStatus`, `FulfillmentStatus`, `ProductStatus`, `PromotionSourceType`, `ReviewStatus`, `ShipmentStatus`, `FraudVerdict`, `WeightUnit`, `DimensionUnit`.
- **Scalars:** `Money` (serialized as `{amount: Int, currency: String}`), `DateTime` (ISO 8601), `JSON` (arbitrary object), `Cursor`.
- **Input types:** every mutation payload has a matching `*Input` type — `AddToCartInput`, `PlaceOrderInput`, `IssueRefundInput`, `MoveKanbanCardInput`, etc.
- **Connection types** for every list query (Relay-style: `PageInfo`, `{Type}Edge`, `{Type}Connection`).

### 10.2 Root Query fields

| Field | Returns | Notes |
|---|---|---|
| `product(id: ID!)` | `Product` | public |
| `productBySlug(slug: String!)` | `Product` | public |
| `products(filter: ProductFilter, sort: ProductSort, first: Int, after: Cursor)` | `ProductConnection` | public |
| `category(id: ID!)` / `categoryBySlug(slug: String!)` | `ProductCategory` | public |
| `categories(first: Int, after: Cursor)` | `ProductCategoryConnection` | public |
| `search(query: String!, ...)` | `ProductConnection` | public |
| `cart(token: String!)` | `Cart` | session |
| `me` | `Me` | sanctum |
| `myOrders(first: Int, after: Cursor)` | `OrderConnection` | sanctum |
| `order(id: ID!)` | `Order` | sanctum / admin |
| `guestOrderLookup(email: String!, orderNumber: String!)` | `Order` | public |
| `orders(filter: OrderFilter, sort: OrderSort, first: Int, after: Cursor)` | `OrderConnection` | admin |
| `refund(id: ID!)` | `Refund` | admin |
| `customer(id: ID!)` | `Customer` | admin |
| `customers(filter: CustomerFilter, first: Int, after: Cursor)` | `CustomerConnection` | admin |
| `promotion(id: ID!)` / `promotions(...)` | `Promotion` / `PromotionConnection` | admin |
| `coupon(code: String!)` | `Coupon` | session |
| `taxClasses` / `taxRates(...)` | list | admin |
| `shippingZones` / `shippingZone(id: ID!)` | list / one | admin |
| `kanbanBoards` | list | admin |
| `kanbanBoard(id: ID!)` | `KanbanBoard` | admin |
| `kanbanCards(boardId: ID!, first: Int, after: Cursor)` | `KanbanCardConnection` | admin |
| `notificationTemplates(...)` / `notificationTemplate(id: ID!)` | list / one | admin |
| `webhookSubscriptions` / `webhookSubscription(id: ID!)` | list / one | admin |
| `digitalFiles(...)` / `licenseKey(key: String!)` | list / one | admin |
| `inventoryItems(...)` | list | admin |

### 10.3 Root Mutation fields

Every mutation carries an implicit `clientMutationId` in accordance with Relay. Auth column omitted for brevity — matches the corresponding REST route in §9. Every mutation surfaces `errors: [UserError!]!` for expected failures.

| Mutation | Input | Returns |
|---|---|---|
| `createCart` | `CreateCartInput` | `CreateCartPayload{ cart, errors }` |
| `addToCart` | `AddToCartInput` | `AddToCartPayload{ cart, item, errors }` |
| `updateCartItem` | `UpdateCartItemInput` | `UpdateCartItemPayload{ cart, item, errors }` |
| `removeCartItem` | `RemoveCartItemInput` | `RemoveCartItemPayload{ cart, errors }` |
| `applyCoupon` | `ApplyCouponInput` | `ApplyCouponPayload{ cart, errors }` |
| `removeCoupon` | `RemoveCouponInput` | `RemoveCouponPayload{ cart, errors }` |
| `mergeCart` | `MergeCartInput` | `MergeCartPayload{ cart, merged, errors }` |
| `setCheckoutAddress` | `SetCheckoutAddressInput` | `SetCheckoutAddressPayload{ cart, errors }` |
| `setShippingMethod` | `SetShippingMethodInput` | `SetShippingMethodPayload{ cart, errors }` |
| `setPaymentGateway` | `SetPaymentGatewayInput` | `SetPaymentGatewayPayload{ cart, errors }` |
| `createPaymentSession` | `CreatePaymentSessionInput` | `CreatePaymentSessionPayload{ cart, session, errors }` |
| `placeOrder` | `PlaceOrderInput` | `PlaceOrderPayload{ order, errors }` |
| `editOrder` | `EditOrderInput` | `EditOrderPayload{ order, diff, paymentActionRequired, errors }` |
| `cancelOrder` | `CancelOrderInput` | `CancelOrderPayload{ order, errors }` |
| `issueRefund` | `IssueRefundInput` | `IssueRefundPayload{ order, refund, errors }` |
| `addOrderNote` | `AddOrderNoteInput` | `AddOrderNotePayload{ note, errors }` |
| `createShipment` | `CreateShipmentInput` | `CreateShipmentPayload{ shipment, order, errors }` |
| `updateShipment` | `UpdateShipmentInput` | `UpdateShipmentPayload{ shipment, errors }` |
| `claimOrder` | `ClaimOrderInput` | `ClaimOrderPayload{ order, errors }` |
| `submitReview` | `SubmitReviewInput` | `SubmitReviewPayload{ review, errors }` |
| `moderateReview` | `ModerateReviewInput` | `ModerateReviewPayload{ review, errors }` |
| `createProduct` / `updateProduct` / `deleteProduct` | matching inputs | matching payloads |
| `createProductVariant` / `updateProductVariant` / `deleteProductVariant` | matching inputs | matching payloads |
| `createProductPrice` / `updateProductPrice` / `deleteProductPrice` | matching inputs | matching payloads |
| `createCategory` / `updateCategory` / `deleteCategory` | matching inputs | matching payloads |
| `createTag` / `updateTag` / `deleteTag` | matching inputs | matching payloads |
| `createTaxClass` / `createTaxRate` / `updateTaxRate` / `deleteTaxRate` | matching inputs | matching payloads |
| `createShippingZone` / `updateShippingZone` / `deleteShippingZone` | matching inputs | matching payloads |
| `createShippingMethod` / `updateShippingMethod` / `deleteShippingMethod` | matching inputs | matching payloads |
| `createPromotion` / `updatePromotion` / `deletePromotion` | matching inputs | matching payloads |
| `createCoupon` / `updateCoupon` / `deleteCoupon` | matching inputs | matching payloads |
| `adjustInventory` | `AdjustInventoryInput` | `AdjustInventoryPayload` |
| `createKanbanBoard` / `updateKanbanBoard` / `deleteKanbanBoard` | matching inputs | matching payloads |
| `createKanbanColumn` / `updateKanbanColumn` / `deleteKanbanColumn` | matching inputs | matching payloads |
| `moveKanbanCard` | `MoveKanbanCardInput` | `MoveKanbanCardPayload{ order, from, to, board, errors }` |
| `createKanbanAutomation` / `updateKanbanAutomation` / `deleteKanbanAutomation` | matching inputs | matching payloads |
| `assignOrderToBoard` / `unassignOrderFromBoard` | matching inputs | matching payloads |
| `updateNotificationTemplate` | `UpdateNotificationTemplateInput` | `UpdateNotificationTemplatePayload` |
| `previewNotificationTemplate` | `PreviewNotificationTemplateInput` | `PreviewNotificationTemplatePayload{ rendered, errors }` |
| `updateMyNotificationPreferences` | `UpdateMyNotificationPreferencesInput` | matching payload |
| `createWebhookSubscription` / `updateWebhookSubscription` / `deleteWebhookSubscription` | matching inputs | matching payloads |
| `replayWebhookDelivery` | `ReplayWebhookDeliveryInput` | matching payload |
| `validateLicense` | `ValidateLicenseInput` | `ValidateLicensePayload{ valid, expiresAt, product, revoked, errors }` |
| `revokeLicense` | `RevokeLicenseInput` | matching payload |

Every mutation honors `Idempotency-Key` when the request arrives via HTTP (see §11.2).

### 10.4 Root Subscription fields

Real-time subscriptions (broadcast via Laravel Echo / Reverb):

| Subscription | Payload | Channel |
|---|---|---|
| `orderPlaced` | `Order` | `private-ecommerce.admin` |
| `orderStatusChanged` | `Order, from, to` | `private-ecommerce.admin` |
| `paymentSucceeded` | `Order, PaymentResult` | `private-ecommerce.admin` |
| `kanbanCardMoved` | `Order, from, to, board` | `private-ecommerce.kanban.board.{boardId}` |
| `stockChanged` | `InventoryItem, delta, newLevel` | `private-ecommerce.admin` |
| `reviewSubmitted` | `ProductReview` | `private-ecommerce.admin` |
| `webhookDeliveryFailed` | `WebhookDelivery, reason` | `private-ecommerce.admin` |

### 10.5 Schema-extension surface

Satellites extend the schema by:

1. Registering new object types + input types via `rebing/graphql-laravel`'s type registry.
2. Adding query/mutation fields with `Query::extend()` / `Mutation::extend()`.
3. Adding fields to existing types via the `ap.ecommerce.graphql.extend` filter, whose payload is the type-definition array being built.
4. Using the same `ap.ecommerce.api.resource.{name}` filter as REST — resource shape is single-sourced.

---

## 11. Cross-cutting contracts

### 11.1 PCI column lint

The `ci:lint:pci-columns` command (Phase 2 deliverable) scans every migration file matching `database/migrations/*.php` (plus satellite migrations discovered via `SatelliteRegistry`) for column-name substrings:

`card_number`, `cardnumber`, `pan`, `cvv`, `cvc`, `card_cvc`, `card_cvv`, `card_expiry`, `cardholder`, `raw_card`, `card_track`, `magstripe`.

Any match fails the build. Overriding a specific line is allowed only via an inline `// pci-lint:ignore reason:<text>` comment on the offending line; the reason string is required and logged in the build output.

### 11.2 Idempotency record contract

`IdempotencyMiddleware` (registered on every mutating route) resolves the composite key from:

- **`actor_scope`:** from the resolved auth guard — `sanctum-token:{id}` for token auth, `session:{id}` for storefront session, `service:{name}` for the service-to-service Bearer signature (§11.4).
- **`endpoint_key`:** the Laravel route name (e.g. `ecommerce.checkout.finalize`); falls back to `{method}:{normalized_path}` where param values are replaced with `{param}`.
- **`idempotency_key`:** verbatim from the request header. Missing → 400 (`problem+json`, `type: /problems/missing-idempotency-key`).

Middleware behavior matches parent plan §7.5. All three record columns (`response_status`, `response_headers`, `response_body`) are populated in a single UPSERT inside the response terminating middleware. In-flight collision: second request `SELECT ... FOR UPDATE`s the row and blocks up to `artisanpack.ecommerce.idempotency.wait_ms` (default 8000ms) for `locked_at` to release before erroring `409`.

### 11.3 Rate-limit policy map

Registered in `EcommerceServiceProvider::registerRateLimiters()`. Every route class has one policy from this map (values from parent plan §16.1):

| Policy | Default limits | Applies to |
|---|---|---|
| `ecommerce.catalog.read` | 300/min per IP | Public catalog reads |
| `ecommerce.cart.mutate` | 60/min per cart token | Cart item CRUD, checkout state changes |
| `ecommerce.checkout.finalize` | 6/min per IP + 12/hr per cart | Checkout finalize |
| `ecommerce.coupon.attempt` | 10/hr per cart + 30/hr per IP | Coupon apply + guest-order lookup |
| `ecommerce.claim.attempt` | 5/hr per customer (§3.22) | Order-claim endpoint |
| `ecommerce.login` | 5/min per IP + 20/hr per email | Login endpoints (both storefront + admin) |
| `ecommerce.review.submit` | 3/hr per customer + 10/hr per IP | Review submission |
| `ecommerce.license.validate` | 60/min per license key + 600/min per IP | License validate endpoint |
| `ecommerce.webhook.inbound` | 1000/min per provider | Inbound gateway webhooks |
| `ecommerce.admin.mutate` | 120/min per user | Admin API mutations |

429 responses use `problem+json` (`type: /problems/rate-limited`) and include `retry_after` in the response body plus `Retry-After` header.

### 11.4 Service-to-service auth

Header pattern: `Authorization: Signature keyId="{service}",algorithm="hmac-sha256",headers="(request-target) host date digest",signature="{base64}"`. Verified by `ServiceSignatureMiddleware`. Used by internal integrations (satellite → engine RPC) where session/token auth doesn't fit. Not exposed publicly.

### 11.5 Error format

All 4xx / 5xx responses use `Content-Type: application/problem+json` (RFC 7807):

```json
{
  "type": "https://docs.artisanpack-ui.dev/ecommerce/problems/idempotency-key-conflict",
  "title": "Idempotency key conflict",
  "status": 409,
  "detail": "The Idempotency-Key was reused with a different request payload.",
  "instance": "/api/ecommerce/v1/checkout/T_abc123/finalize",
  "errors": [
    { "field": "quantity", "code": "min", "message": "Quantity must be at least 1." }
  ]
}
```

The `type` URLs are stable and versioned under `docs.artisanpack-ui.dev/ecommerce/problems/`.

### 11.6 Scheduled jobs

| Command | Cadence | Purpose |
|---|---|---|
| `ecommerce:release-expired-reservations` | every minute | Delete `inventory_reservations` rows past `expires_at`; fires `ap.ecommerce.inventory.reservationReleased`. |
| `ecommerce:flag-abandoned-carts` | every 5 minutes | Set `carts.abandoned_at` per rule in parent §7.3; dispatches `CartAbandoned`. |
| `ecommerce:audit-order-status` | nightly | Detects drift between `orders.system_status` and its board-assignments' sub-statuses (parent §5.7). |
| `ecommerce:prune-idempotency-records` | hourly | Delete `idempotency_records` past `expires_at`. |
| `ecommerce:reconcile-payments` | every 15 min | Reconcile in-flight payments with providers for orders in `payment_status = pending` for > N min. |
| `ecommerce:refresh-fx-rates` | daily | Warm the `CurrencyRateProvider` cache. |
| `ecommerce:retry-webhook-deliveries` | every minute | Pick up `webhook_deliveries` with `next_retry_at <= now`. |

### 11.7 Broadcast channels

- `private-ecommerce.admin` — admin surfaces (orders list, kanban admin, inventory).
- `private-ecommerce.kanban.board.{boardId}` — per-board card moves.
- `private-ecommerce.customer.{customerId}` — customer-scoped events (order updates, refund issued).

Authorization: `EcommerceChannelPolicy` gates all three.

---

## 12. Traceability against issue #1 acceptance criteria

| Acceptance criterion (issue #1) | Where satisfied |
|---|---|
| Every table shown in plan §5 has a fully-populated schema block (columns, indexes, types, nullability) | §3 (33 tables across §3.1–§3.32, plus §3.33 migration ordering). Every table from parent plan §5.3–§5.13 present; additional tables from §7.5 (idempotency), §9 (kanban), §6.5 (webhooks), §14 (notifications) also fully specified. |
| Every contract listed in §6.1 has an interface signature | §4 (17 contracts, one subsection each with full signature: `ProductType`, `PaymentGateway`, `ShippingRateProvider`, `ShippingLabelProvider`, `TaxProvider`, `FulfillmentAllocationStrategy`, `CurrencyRateProvider`, `CartStorage`, `OrderNumberGenerator`, `PromotionCondition`, `PromotionAction`, `KanbanCardWidget`, `KanbanAutomationTrigger`, `NotificationTemplate`, `SearchIndexer`, `ReviewModerator`, `FraudProvider`) |
| Every hook/action/filter/event named in §6.3–§6.4 is enumerated with signature | §6 (hooks — 100+ entries across 18 subsections) and §7 (41 events with FQCN + constructor payload) |
| Registry list from §6.2 is complete | §5 (16 registries table: `ProductTypeRegistry`, `PaymentGatewayRegistry`, `ShippingRateProviderRegistry`, `ShippingLabelProviderRegistry`, `TaxProviderRegistry`, `CurrencyRateProviderRegistry`, `PromotionConditionRegistry`, `PromotionActionRegistry`, `PromotionSourceRegistry`, `KanbanCardWidgetRegistry`, `KanbanAutomationRegistry`, `FraudProviderRegistry`, `NotificationChannelRegistry`, `SubStatusRegistry`, `AdminMenuRegistry`, `SatelliteRegistry`) |
| REST + GraphQL resource inventory from §12–§13 is enumerated | §9 (13 REST subsections, 100+ endpoints, all with auth + idempotency + rate-limit policy metadata; §9.13 lists all 30 resource types) and §10 (types, 30+ queries, 60+ mutations, 7 subscriptions) |

---

## 13. Changelog

- **2026-09-06** — Draft v0.1. Initial authoring against parent plan v2.
