# Store settings API — Business, Branches & warehouses

Admin → Settings › Store (Figma B · 38 `241:50827`). All routes are under `/api/v1/admin`,
behind the admin Sanctum cookie session (CSRF on writes). Reads need `settings.view`, writes
`settings.update`. Errors are RFC 9457 problems (`validation-error` 422, `forbidden` 403,
`conflict` 409). Each route has a 403 role-matrix test (`tests/Feature/StoreSettings`).

## Business

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/business` | settings.view |
| PUT | `/settings/business` | settings.update |

Body (whole section; `null` or blank removes the saved value):
`name_en`, `name_bn`, `trade_licence`, `bin`, `hotline`, `whatsapp`.

**One value, two screens.** `name_en`, `name_bn`, `trade_licence`, `bin` and `hotline` are the
`settings` rows `invoice.company.*` that `GET/PUT /settings/invoice` (Invoice details, now the
"Taxes & invoices" section) reads and writes, through `App\Domain\Invoicing\Services\CompanyDetails`.
There is no second copy: a save on either endpoint is visible on the other. The Business save
touches only its own keys (address, logo, footer stay); the invoice save replaces every
`CompanyDetails::FIELDS` key but never the WhatsApp key.

- `whatsapp` is a Bangladeshi mobile, 013–019 only (CLAUDE.md rule 30, `App\Support\PhoneNumber`),
  stored and returned as E.164 under the key `store.whatsapp`.
- `hotline` keeps the invoice rule (`^\+?[0-9 ()-]{5,32}$`) because a 09xxx hotline is not a mobile.
- `bin` keeps the invoice rule (`^[0-9-]{5,32}$`).

Response extras: `default_names` (what invoices print while a name is unset — the brand),
`hotline_default` (env `STORE_HOTLINE`, which order messages still use), `placeholders` (Business
fields an invoice still prints as "[placeholder]"). Values are placeholders until the owner
supplies real ones (UI-UX-DESIGN-SPEC §10).

## Branches & warehouses (stock locations)

| Method | Path | Permission |
|---|---|---|
| GET | `/settings/locations` | settings.view |
| GET | `/settings/locations/districts` | settings.view |
| POST | `/settings/locations` | settings.update (201) |
| PUT | `/settings/locations/{location}` | settings.update |

A location is a `stock_locations` row (`App\Domain\Inventory\Models\StockLocation`). Fields:
`code` (create only; `^[a-z0-9-]+$`, unique, lower-cased), `name`, `name_bn`, `type`
(`warehouse` | `retail` | `pickup`), address per rule 31 — `district_id` (division is taken
from the district), `upazila_id` (optional, must belong to the district), `area`,
`address_line`, `landmark` — `notes` (the facts line) and `is_active`.

- Writes go through `CreateStockLocation` / `UpdateStockLocation` (one transaction each).
- **Never deleted** (no DELETE route): movements are ledger history. Switch off with
  `is_active: false`. Refused with 409 `conflict` for the default location, or one whose
  `stock_levels` still hold, reserve or expect units. Inactive locations are already refused by
  transfers, receipts and adjustments and left out of the inventory overview.
- The list returns every location (default first, then active), with `holds_stock`.
- `/settings/locations/districts` returns the 64 districts with division and upazilas for the
  address picker.

Columns added by `2026_12_06_300000_add_profile_columns_to_stock_locations_table` — expand-only,
all nullable, no backfill.
