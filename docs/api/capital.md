# Owner capital API (P10)

Owner decision 2026-11-16: "New investment" means **owner capital in and out** — money the owner or a partner puts into the business (`contribution`) and drawings they take out (`drawing`). Plain on purpose: no share percentages, no profit allocation.

Every entry writes one ledger row — `owner_capital_in` (in) or `owner_drawing` (out) — with `is_cash_movement = true` and `is_recognised_revenue = false`. **Capital moves cash, never the P&L** (rule 10): it shows in the ledger, the cash block of the P&L statement and the daily summary's `cash_in` / `cash_out` / `net_cash` (plus its own `owner_capital_in` / `owner_drawing` columns), never in revenue, expenses or net profit. Entries are insert-only; a mistake is **reversed**, never edited. Schema: `DATABASE-DESIGN.md` §3.7 "As built (P10 owner capital…)".

All paths are under `/api/v1/admin`, over the Sanctum cookie session (writes carry the CSRF token). Money is a decimal string with two places; dates are **Dhaka** calendar dates `YYYY-MM-DD` (rule 11); times are ISO 8601 UTC. Errors are RFC 9457 problems; `detail` and validation messages follow `Accept-Language` / `?locale=` (EN or BN).

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/finance/capital` | `finance.view` | Overview per holder + totals |
| GET | `/finance/capital/entries` | `finance.view` | Cursor list |
| POST | `/finance/capital/entries` | `finance.manage_capital` | **`Idempotency-Key` required** |
| POST | `/finance/capital/entries/{id}/reverse` | `finance.manage_capital` | **`Idempotency-Key` required** |
| GET | `/finance/capital/holders` | `finance.view` | Whole list |
| GET | `/finance/capital/staff` | `finance.manage_capital` | Staff a holder may be linked to |
| POST | `/finance/capital/holders` | `finance.manage_capital` | |
| PATCH | `/finance/capital/holders/{id}` | `finance.manage_capital` | Deactivate with `is_active: false` |

`finance.manage_capital` is a new permission: default for `super_admin`, `manager` and `accountant` (the roles that hold every other finance-write permission); the owner can change that in Settings › Staff & roles. It reveals money, so its holders sign in with two-factor (rule 20a). Unauthenticated → 401; without the permission → 403 `forbidden`.

## Filters

`filter[…]` is allowlisted (rule 16): an unlisted filter, an unlisted sort or a malformed value is a **400 `bad-request`**.

| Filter | Overview | Entries | Holders | Value |
|---|---|---|---|---|
| `filter[holder_id]` | ✓ | ✓ | | a holder id |
| `filter[kind]` | ✓ | ✓ | | `contribution` or `drawing` (one value) |
| `filter[from]` / `filter[to]` | ✓ | ✓ | | Dhaka dates on `occurred_on`, inclusive; `to` before `from` is a 400 |
| `filter[active]` | | | ✓ | `true` / `false` |
| `sort` | — (none; any `sort` is a 400) | `occurred_on`, `amount`, `-` for descending; default `-occurred_on` | — (none) | |

## GET `/finance/capital` — overview

Every holder (active first, then by name; inactive holders included — their history still counts), or just the filtered one. A reversal counts against the kind it reverses, so a reversed contribution adds nothing to `put_in`.

```json
{
  "from": "2026-11-01",
  "to": null,
  "holders": [
    {
      "holder": { "id": 1, "name": "Mominul Islam", "name_bn": "মমিনুল ইসলাম", "role": "owner", "is_active": true, "has_staff_login": true },
      "put_in": "100000.00",
      "taken_out": "25000.00",
      "net": "75000.00",
      "entries_count": 2,
      "last_entry": { "id": 2, "kind": "drawing", "amount": "25000.00", "net_effect": "-25000.00", "occurred_on": "2026-11-05", "is_reversal": false }
    }
  ],
  "totals": {
    "put_in": "100000.00", "taken_out": "25000.00", "net": "75000.00", "entries_count": 2,
    "last_entry": { "id": 2, "kind": "drawing", "amount": "25000.00", "net_effect": "-25000.00", "occurred_on": "2026-11-05", "is_reversal": false, "holder_id": 1, "holder_name": "Mominul Islam", "holder_name_bn": "মমিনুল ইসলাম" }
  }
}
```

Holders are ordered active first, owners before partners, then by name. `totals.last_entry` is the latest entry across the listed holders (the "Last entry" card), or `null`.

`net` = `put_in` − `taken_out` (may be negative). `last_entry` is the latest by `occurred_on` then id within the filters, or `null`. `from` / `to` echo the filters (`null` when absent).

## GET `/finance/capital/entries` — entries

Cursor-paginated (50 a page): `{ data: CapitalEntry[], links, meta: { next_cursor, prev_cursor, total, … } }`. `meta.total` counts every entry matching the filters ("Showing 6 of 15").

### CapitalEntry

```json
{
  "id": 7,
  "kind": "contribution",
  "holder": { "id": 1, "name": "Mominul Islam", "name_bn": "মমিনুল ইসলাম", "role": "owner", "is_active": true },
  "amount": "50000.00",
  "net_effect": "50000.00",
  "occurred_on": "2026-11-10",
  "method": "bank_transfer",
  "reference": "DBBL-7781",
  "note": "Eid stock",
  "ledger_type": "owner_capital_in",
  "is_reversal": false,
  "reversal_of_id": null,
  "reversed_by_id": null,
  "reversed_at": null,
  "reversed_on": null,
  "recorded_by": { "id": 3, "name": "Accounts" },
  "created_at": "2026-11-16T04:12:09Z",
  "can": { "reverse": true }
}
```

- `amount` is always positive; **`net_effect`** is the signed effect on the holder's capital (+ money in, − money out; a reversal is the opposite of what it reverses). Show it; never compute it.
- `method`: `cash` · `bank_transfer` · `bkash` · `nagad` · `card` · `cheque` (the expense payment methods).
- An entry already reversed has `reversed_by_id`, `reversed_at` (when the reversal was recorded, ISO UTC) and `reversed_on` (the reversal's Dhaka date).
- A reversal row has `is_reversal: true`, `reversal_of_id`, the same `kind` as the entry it reverses, and the reason in `note`. An entry already reversed has `reversed_by_id`.
- `can.reverse` = not a reversal, not already reversed, and the viewer holds `finance.manage_capital`.

## POST `/finance/capital/entries` — record money in or out

Header `Idempotency-Key` (8–255 printable ASCII; a UUID fits) — missing → 400 `idempotency-key-missing`; the same key and body replay the first response and write nothing.

```json
{ "kind": "contribution", "holder_id": 1, "amount": "50000.00", "occurred_on": "2026-11-10", "method": "bank_transfer", "reference": "DBBL-7781", "note": "Eid stock" }
```

| Field | Rule |
|---|---|
| `kind` | required: `contribution` ("New investment") or `drawing` ("Owner drawing") |
| `holder_id` | required, an existing **active** holder |
| `amount` | required decimal string, > 0, at most 2 places (`^\d{1,12}(\.\d{1,2})?$`) |
| `occurred_on` | required `YYYY-MM-DD`, a Dhaka date not after today in Dhaka |
| `method` | required, one of the methods above |
| `reference` | optional, ≤ 120 |
| `note` | optional, ≤ 500 |

→ **201** CapitalEntry. 422 `validation-error` (`errors[]` of `{field, message}`; an inactive holder is an error on `holder_id`); 409 `conflict` when the holder is deactivated between validation and the write.

## POST `/finance/capital/entries/{id}/reverse` — reverse an entry

Header `Idempotency-Key` required. Body `{ "reason": "Typed 50,000 instead of 5,000" }` (required, 3–500).

Writes a new entry (same kind, holder, amount, method; `reversal_of_id` = `{id}`; `note` = reason), dated **today in Dhaka**, and the exact mirror ledger row (same type, opposite direction, source `capital_reversal`). The original stays on its own date. Reversing is allowed for an inactive holder.

→ **201** CapitalEntry (the **reversal**). 409 `conflict` when the entry was already reversed (once only) or is itself a reversal; 422 without a reason.

## Holders

### CapitalHolder

```json
{ "id": 1, "name": "Mominul Islam", "name_bn": "মমিনুল ইসলাম", "role": "owner", "user": { "id": 3, "name": "Mominul Islam" }, "is_active": true, "entries_count": 4, "can": { "update": true } }
```

- `GET /finance/capital/holders` → `{ data: CapitalHolder[] }`, active first then by name.
- `POST /finance/capital/holders` `{ name, name_bn?, role?, user_id?, is_active? }` → 201. `role` is `owner` (default) or `partner` — a label only, no shares. `name` required, 2–120, unique; `user_id` an existing user, at most one holder per account.
- `PATCH /finance/capital/holders/{id}` — any of the same fields → 200. Holders are never deleted.
- `GET /finance/capital/staff` → `{ data: [{ id, name }] }`: active staff accounts not yet linked to a holder, names only (never an email or phone), for the "staff login" picker.

## Audit

Every write adds an `audit_sensitive` row shown in the activity log under area **`finance`**: `capital_entry_recorded` (kind `capital_contribution_recorded` / `capital_drawing_recorded`), `capital_entry_reversed` (with the reason), `capital_holder_saved` (kind `capital_holder_added` / `capital_holder_changed`, only the changed fields). Subjects are named by holder, never by amount.

## Ledger and reports

- `GET /finance/ledger?filter[type]=owner_capital_in,owner_drawing` lists the ledger rows (description "Mominul Islam · bKash"; a reversal "… · reverses #10"); `filter[source_type]=capital_entry` or `capital_reversal` narrows by source.
- The P&L statement's lines, subtotals and net profit never include capital; its `cash` block does (cash in/out by type).
- `daily_financial_summaries.owner_capital_in` / `owner_drawing` (natural direction, net of reversals), inside `cash_in` / `cash_out` / `net_cash`.

## Admin screen

Finance › Capital (`/admin/finance/capital`, `resources/js/admin/features/finance/capital/`; Figma B · 32 `428:74277` at 1440 and `428:75140` at 390): the KPI cards and holder cards read `GET /finance/capital` (all time); the entries table reads `/entries` with the person, money in/out and period filters (period → a Dhaka `from`/`to`). New investment, Owner drawing, Reverse entry and "Add an owner or partner" are dialogs; they show only with `finance.manage_capital`, and each POST that moves money sends a fresh `Idempotency-Key` per opening.
