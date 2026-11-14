# POS register API (P10, Figma B · 33)

Admin API under `/api/v1/admin`, Sanctum cookie session. Schema in `docs/api/openapi.json`; tables in `DATABASE-DESIGN.md` §11.

## Permissions

| Permission | Default roles | Grants |
|---|---|---|
| `pos.use` | super_admin, manager, order_operator | open a drawer; on **one's own** drawer: grid, quote, charge, hold / resume, receipts; close one's own drawer |
| `pos.manage` | super_admin, manager | read and close **anyone's** drawer; read any sale's receipt |

`order_operator` is the closest of the seven fixed roles to a cashier (it already creates orders and sees customer phones). Roles are owner-editable; these are defaults. Policies: `PosSessionPolicy` (`viewAny`, `create`, `view`, `operate`, `close`), `PosSalePolicy::view`, `PosHeldSalePolicy::resume`.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/pos/context` | branches a drawer can open at (active, not pickup; retail first), tenders (`requires_reference`, `gives_change`), top-level category tabs, my open drawer (`session` or null), `can_manage`, `whatsapp_receipt {enabled: false, reason: no_provider}` |
| POST | `/pos/sessions` | `{location_id, register, opening_float}` → 201 `PosSession`. 409 when the register or the cashier already has an open drawer, or the location does not sell |
| GET | `/pos/sessions/{session}` | the drawer with `totals` (sales count, Σ per tender, `expected_cash`) |
| POST | `/pos/sessions/{session}/close` | `{counted_cash, note?}` → `expected_cash`, `counted_cash`, `over_short`. 409 if already closed |
| GET | `/pos/sessions/{session}/catalog` | `?category_id=` `&q=` `&barcode=` (exact). Allowlisted filters only — anything else is a 400. ≤ 60 tiles with `available` at the drawer's branch; only `products.channel_pos` |
| GET | `/pos/customers?phone=` | `{phone, customer: {id, name, orders_count} | null}`; 422 for a non-013–019 number |
| POST | `/pos/sessions/{session}/quote` | `{lines[], coupon_code?, customer_phone?, tenders?[]}` → `PosQuote`: lines with totals, itemised `rows` (subtotal, promotions, coupon — no delivery), `grand_total`, `blockers[]`, `chargeable`, and with tenders `{tendered, cash_tendered, remaining, change, errors[]}`. Writes nothing |
| POST | `/pos/sessions/{session}/sales` | **Charge. `Idempotency-Key` required.** Quote body + `expected_total` + `customer_name?` → 201 `PosSale` (`order_number`, `change_due`…) |
| GET | `/pos/sessions/{session}/holds` | parked carts not yet resumed |
| POST | `/pos/sessions/{session}/holds` | `{lines[], coupon_code?, customer_phone?, label?}` → 201 |
| POST | `/pos/holds/{hold}/resume` | once; 409 the second time |
| GET | `/pos/sales/{sale}/receipt` | printable receipt: store (CompanyDetails — Business / Invoice details, `placeholders` = required fields unset), branch, register, cashier, order number, sold at, customer (name, masked phone), lines, totals, payments with references, cash tendered, change |

## Charge rules

- Every figure is re-derived on the server through the checkout pricing pipeline (rule 1). `expected_total` different from the server's total → 409 `price-changed`.
- 422 `validation-error` with field-level `errors[]` for any blocker: `lines` empty; `lines.{id}` unsellable, not sold at the counter, or more than the branch has; `coupon_code` not applicable; `customer_phone` — **any discount (promotion or coupon) needs the customer's phone**, because promotion and coupon limits are counted per phone; `tenders…` (below).
- Tenders: `cash`, `bkash`, `nagad`, `card` — counter drivers in `App\Domain\Pos\Tenders` (rule 5), all **manual**: no gateway or card terminal is integrated, the cashier enters the TrxID / approval code, which is required for every non-cash tender. Split allowed; one cash line at most; non-cash tenders together may not exceed the total; change = tendered − total, only from cash. Payments are written at the amount kept (cash net of change), so Σ payments = total.
- Stock is reserved and consumed at the drawer's branch under ordered locks; a shortfall found under lock is 409 `insufficient-stock` and nothing is written.
- Free-delivery promotions do not apply at the counter (nothing is delivered): not shown, not recorded as used.
- After commit: revenue via `OrderDelivered` (Finance), cash via `RecordPosTendersInLedger` (`pos_receipt`, pending the Finance enum case — see DATABASE-DESIGN §11).

## Not built / open

- Loyalty tier and points (Figma "Gold member 5%", "Points used") — no loyalty system exists.
- WhatsApp receipt — no WhatsApp provider is live; the button is disabled with the reason.
- Refunds of a POS sale go through the normal returns flow; returned stock and refunds there assume the default location and the COD/online payment drivers (no counter-tender refund driver yet).
