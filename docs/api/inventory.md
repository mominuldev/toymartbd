# Admin inventory API

`/api/v1/admin/inventory/*` — the stock overview, append-only ledger and adjustments
(06-admin-feature-checklist §2.7; Figma B · 14 Inventory `241:39494`, B · 15 Stock movement
ledger `241:39993`). Reads need `inventory.view`; cost and value figures additionally
`catalog.view_cost`; adjustments need `inventory.adjust`. Filters are an explicit allowlist:
an unlisted filter, a `sort`, or a bad value is a 400 `bad-request`. The full schema is in
`openapi.json`.

| Endpoint | Purpose |
|---|---|
| `GET /inventory/summary?filter[location_id]=` | Stat cards, location cards, tab counts for one location (default location when omitted) |
| `GET /inventory/stock?filter[location_id,state,search,product_id,variant_id]=` | One row per variant at the location, cursor-paginated |
| `GET /inventory/low-stock-count` | The Inventory nav badge: `{location_id, count}` low-stock variants at the default location |
| `GET /inventory/movements` | The append-only ledger |
| `GET /inventory/movements/reconciliation` | Opening / in / out / closing and the ledger-vs-levels check |
| `POST /inventory/adjustments` | A stock adjustment (`Idempotency-Key` required) |

## Stock states

On `available = on_hand − reserved` at the location, computed by `StockOverview` — the admin
never derives a state.

| State (`filter[state]`, row `state`) | Rule |
|---|---|
| `out` | `available ≤ 0` |
| `low` | `0 < available ≤ reorder_point` |
| `overstock` | `reorder_quantity > 0` **and** `available > reorder_point + 2 × reorder_quantity` |
| `idle` (tab only) | `on_hand > 0` and no movement at the location for 90 days |

**Overstock is an owner decision pending** (DATABASE-DESIGN §3.2, 2026-11-18): there is no
max-stock column, so the rule reuses the two planning figures already on `stock_levels` —
more than two full reorders above the reorder point. A variant without a reorder quantity is
never overstock. The summary's `counts.overstock` uses the same rule.

## Other branches and transfers

Each stock row carries `elsewhere`: one entry per other active location, default first, with
`code`, `on_hand` and `available` (zeros where the location holds no level row). A `low` or
`out` row also carries `transfer_from` — the other location with the most units available —
or `null`. Transfers are not built yet; the admin shows "Transfer N" disabled.

## Nav badge

`GET /inventory/low-stock-count` is one COUNT with the Low stock tab's rule, cached per
location (at most 5 minutes) and dropped whenever a stock level changes
(`StockLevelChanged` → `ForgetLowStockCount`) or reorder levels are set.
