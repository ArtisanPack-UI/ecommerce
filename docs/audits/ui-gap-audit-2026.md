# UI-satellite gap audit — `artisanpack-ui/react` + `artisanpack-ui/vue`

**Date:** 2026-09-13
**Auditor:** Jacob Martella (me@jacobmartella.com)
**Scope:** [Phase 0](../../../../docs/plans/12-ecommerce-package-plan.md#phase-0--foundation-probably-weeks-not-months) of the ecommerce package plan — inventory the shipped components in each design-system package against what the six commerce UI satellites planned in Phase 11 will need, then file every gap on the correct upstream repo.
**Tracking issue:** [ecommerce#5](https://github.com/ArtisanPack-UI/ecommerce/issues/5)

## 1. Method

For each design-system package I walked `packages/*/src/components/**/*` and confirmed each shipped component's shape against a target-use list derived from the planned satellites (`ecommerce-storefront-{react,vue}`, `ecommerce-admin-{react,vue}`, `ecommerce-kanban-{react,vue}`) and the engine spec ([`13-ecommerce-engine-spec.md`](../plans/13-ecommerce-engine-spec.md)).

The audit deliberately narrows to primitives the satellites cannot ship without or would otherwise reinvent inconsistently. It does not attempt a general component-completeness pass on the design system.

## 2. What already exists (and covers the requirement)

Both `@artisanpack-ui/react` and `@artisanpack-ui/vue` currently ship parity inventories organized as `data/`, `feedback/`, `form/`, `layout/`, `navigation/`, `utility/` (Vue splits static presentation into `display/`; React groups it under `data/` — cosmetic difference only). The following requirements listed in [ecommerce#5](https://github.com/ArtisanPack-UI/ecommerce/issues/5) are already met:

| Requirement (from issue #5) | React | Vue | Notes |
| --- | --- | --- | --- |
| Chart primitives | `Chart` (ApexCharts, 8 types) + `Sparkline` | same | Covers dashboards + sparklines in stat tiles. |
| Spotlight / command palette | `SpotlightSearch` | same | Ready for admin quick-jump. |
| Data table — basic surface | `Table` (sort / select / expand / custom render / footer) | same | Sufficient for small tables; **large-admin-scale gaps below.** |
| Basic form primitives | `Button`, `Input`, `Checkbox`, `Radio`, `Select`, `Toggle`, `Range`, `Textarea`, `DatePicker`, `Password`, `File`, `Pin`, `ColorPicker`, `Editor`, `RichTextEditor` | same | Covers most vanilla form fields. `File` includes a drop zone for browser file drops. |
| Layout + navigation shells | `Card`, `Modal`, `Drawer`, `Tabs`, `Accordion`, `Menu`, `Navbar`, `Sidebar`, `Breadcrumbs`, `Pagination`, `Steps` | same | No gaps observed. |
| Feedback surfaces | `Alert`, `Toast`, `Loading`, `Skeleton`, `EmptyState`, `Error` | same | No gaps observed. |

## 3. Gaps

Each gap below is filed as an issue on **both** upstream repos. Findings are not filed against `artisanpack-ui/ecommerce` per Phase 0's instruction.

| # | Missing primitive | Blocks which satellites | react | vue | Depends on |
| --- | --- | --- | --- | --- | --- |
| 1 | Drag-and-drop (`Sortable` / `SortableList`) — a11y-first reorderable list + cross-list drop | admin, kanban, storefront (some designs) | [#48](https://github.com/ArtisanPack-UI/react/issues/48) | [#36](https://github.com/ArtisanPack-UI/vue/issues/36) | — |
| 2 | Kanban board (`Kanban`) — columns + cards + move handler | **kanban (hard blocker)** | [#49](https://github.com/ArtisanPack-UI/react/issues/49) | [#37](https://github.com/ArtisanPack-UI/vue/issues/37) | 1 |
| 3 | `Combobox` / `Autocomplete` — searchable, async, multi-select | admin, storefront | [#50](https://github.com/ArtisanPack-UI/react/issues/50) | [#38](https://github.com/ArtisanPack-UI/vue/issues/38) | — |
| 4 | Number stepper (`Stepper` / `QuantityInput`) — +/− with clamp | storefront (cart), admin (refund, inventory) | [#51](https://github.com/ArtisanPack-UI/react/issues/51) | [#39](https://github.com/ArtisanPack-UI/vue/issues/39) | — |
| 5 | Currency-aware `MoneyInput` — integer minor units in, formatted out | admin (prices, refunds, coupons, tax, shipping), storefront (refund forms) | [#52](https://github.com/ArtisanPack-UI/react/issues/52) | [#40](https://github.com/ArtisanPack-UI/vue/issues/40) | — |
| 6 | `TagsInput` / `ChipsInput` — chip entry with optional suggestions | admin (tags, coupon rules), storefront (filter chips) | [#53](https://github.com/ArtisanPack-UI/react/issues/53) | [#41](https://github.com/ArtisanPack-UI/vue/issues/41) | 3 (only when suggestions used) |
| 7 | `AddressForm` — country-driven field arrangement + autocomplete slot | storefront (checkout, address book), admin (customer edit) | [#54](https://github.com/ArtisanPack-UI/react/issues/54) | [#42](https://github.com/ArtisanPack-UI/vue/issues/42) | 3 (country picker) |
| 8 | `VariantMatrix` — options × combinations spreadsheet editor | **admin (hard blocker for product editor)** | [#55](https://github.com/ArtisanPack-UI/react/issues/55) | [#43](https://github.com/ArtisanPack-UI/vue/issues/43) | 1, 4, 5 |
| 9 | `InputGroup` — input + attached button / unit label | storefront (coupon apply), admin (search, weight/dimension units) | [#56](https://github.com/ArtisanPack-UI/react/issues/56) | [#44](https://github.com/ArtisanPack-UI/vue/issues/44) | — |
| 10 | `Rating` — half-star display + interactive input | storefront (reviews, product cards), admin (review moderation) | [#57](https://github.com/ArtisanPack-UI/react/issues/57) | [#45](https://github.com/ArtisanPack-UI/vue/issues/45) | — |
| 11 | `Table` extensions — virtualization, filter row, bulk-actions bar, column controls, sticky columns | **admin (hard blocker for product/order/customer lists)** | [#58](https://github.com/ArtisanPack-UI/react/issues/58) | [#46](https://github.com/ArtisanPack-UI/vue/issues/46) | — |
| 12 | `MediaGallery` / `ImageUploader` — previews, alt text, primary toggle, reorder | admin (product images), storefront (review photos, brand logo) | [#59](https://github.com/ArtisanPack-UI/react/issues/59) | [#47](https://github.com/ArtisanPack-UI/vue/issues/47) | 1 |

### 3.1 Which satellite blocks on which gap

| Satellite | Hard blockers (satellite cannot ship without these) | Soft blockers (ship-able but noticeably worse without) |
| --- | --- | --- |
| `ecommerce-storefront-{react,vue}` | Gap 4 (Stepper — cart quantity), Gap 7 (AddressForm — checkout) | Gap 3 (Combobox — search), Gap 5 (MoneyInput — refund forms), Gap 9 (InputGroup — coupon apply), Gap 10 (Rating — reviews), Gap 12 (MediaGallery — review photos) |
| `ecommerce-admin-{react,vue}` | Gap 8 (VariantMatrix — product editor), Gap 11 (Table extensions — every list view), Gap 5 (MoneyInput — every price field), Gap 12 (MediaGallery — product image manager) | Gap 3 (Combobox — customer/product pickers), Gap 4 (Stepper — refund partial qty, inventory adjust), Gap 6 (TagsInput — product tags), Gap 7 (AddressForm — customer address edit), Gap 10 (Rating — moderation UI) |
| `ecommerce-kanban-{react,vue}` | Gap 1 (Sortable), Gap 2 (Kanban board) | — |

## 4. Recommendations for Phase 10 (react + vue design-system gap-fill)

If Phase 10 fires — and this audit shows it must — the sequence below minimizes rework:

1. **Land Gap 1 (Sortable) first.** Gaps 2, 8, and 12 all depend on it. Skipping this and building each dependent primitive with a bespoke DnD wrapper will be regretted.
2. **Land Gap 3 (Combobox) in parallel with #1.** Gaps 6 (TagsInput suggestions) and 7 (AddressForm country picker) depend on it, and it unblocks the "search inside a select" use case that admin screens hit constantly.
3. **Land Gaps 4, 5, 9, 10 (small, independent form primitives) in parallel.** Each is a couple of days of work and enables one or two satellites to start.
4. **Land Gap 11 (Table extensions) as the admin satellite starts pulling from it.** Virtualization + bulk-actions bar are the hard blockers inside this gap; column controls and filter row can follow shortly after.
5. **Land Gaps 2, 7, 8, 12 last** — they compose on top of the earlier primitives.

Storefront and kanban satellites both have a shorter blocker path than admin; if satellite work is parallelized, kanban unblocks after Gaps 1 + 2 and storefront unblocks after Gaps 4 + 7 (with quality-of-life gaps allowed to arrive during satellite work).

## 5. Non-findings (deliberately not filed)

The following were considered and rejected as design-system gaps for this audit:

- **`CouponInput`, `CartLineItem`, `AddressCard`, `ProductCard`** — domain-specific compositions that belong inside the storefront satellite, not the design system. The design system provides the building blocks (Gap 9, existing `Card`, Gap 7).
- **Payment element wrappers (Stripe, Square)** — provider SDKs already ship framework wrappers. The satellites will render those SDK elements directly rather than re-wrap them.
- **`OrderTimeline`, `RefundTable`** — again, domain-shaped. The existing `Timeline` and enhanced `Table` (Gap 11) are the primitives.
- **File upload progress bar** — already covered by existing `Progress`. `MediaGallery` (Gap 12) composes it.

## 6. Bookkeeping

- 24 issues filed total (12 per repo). All are `enhancement` + `Project/React-Vue-Pivot`, assigned to Jacob Martella, unmilestoned.
- No code changes were required in this ecommerce repo for the audit itself.
- Reassess after Phase 1 — if the engine adds a surface that assumes a primitive not in this list, file a follow-up here rather than editing this document.
