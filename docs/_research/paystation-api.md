# PayStation (paystation.com.bd) — merchant API research

**Source:** https://paystation.com.bd/documentation (the owner-supplied link), 2026-11-19.

**How it was read: incompletely.** Direct fetches of the page (WebFetch, curl) got a
Cloudflare "managed challenge" (HTTP 403, "Just a moment…"), so the page's HTML was never
retrieved. Everything below comes from search-engine extracts of **that page only**
(several searches limited to `paystation.com.bd`, 2026-11-19). Third-party SDKs and plugins
were deliberately **not** used as sources. The search index dated the page at about 640 days
old, so it may be stale. **Before any live use, re-read the page in a browser and correct
this file and the driver.**

The driver is `app/Domain/Payment/Gateways/Paystation/PaystationGateway.php`. Its test
fixtures (`tests/Fixtures/paystation/`) are hand-authored from the shapes below. They were
not recorded from a sandbox, because no merchant account exists yet.

## Hosts

| Mode | Base URL |
|---|---|
| Sandbox | `https://sandbox.paystation.com.bd` |
| Live | `https://api.paystation.com.bd` |

PayStation issues the credentials (`merchantId`, `password`). The docs publish **no sandbox
test credentials**.

## 1. Initiate payment: `POST {host}/initiate-payment`

The credentials go in the request body.

| Field | Required | Notes from the doc |
|---|---|---|
| `merchantId` | yes | example `204-16537301811` |
| `password` | yes | merchant password |
| `invoice_number` | yes | must be unique (a reused one gets error code 1008) |
| `currency` | optional | example `BDT` |
| `payment_amount` | yes | **integer**, example `1` |
| `pay_with_charge` | marked "Required" | `1` = the customer pays the charge, `0` = the merchant pays it. Default `0` (the table contradicts itself) |
| `reference` | optional | echoed back in status queries |
| `cust_name` | yes | customer full name |
| `cust_phone` | yes | |
| `cust_email` | yes | |
| `cust_address` | optional | |
| `callback_url` | yes | where the customer is sent back after paying |
| `checkout_items` | optional | string or JSON describing the items |
| `opt_a` | optional | free optional info (string or JSON) |
| `emi` | optional | sent only for an EMI transaction |

**Response:** `status_code` `"200"` means success, and any other code is a failure. The
other fields are `status` (`success`/`failed`), `message` (for example "Payment Link
Created Successfully."), `payment_amount`, `invoice_number` and `payment_url` (the hosted
checkout to redirect to).

## 2. Transaction status v1: `POST {host}/transaction-status`

- Header `merchantId: <merchant id>`. Body `invoice_number` (the one sent to initiate).
- Success sample: `status_code "200"`, `status "success"`, `message "Transaction found."`,
  and `data` containing `invoice_number`, `trx_status`, `trx_id`, `payment_amount`,
  `order_date_time`, `payer_mobile_no`, `payment_method` (bKash, Nagad, Rocket, Upay,
  Mastercard, Visa) and `reference`.
- `trx_status` is in the v1 **sample** but not in the v1 parameter table. Its casing varies
  between samples ("Success" and "success").
- The doc warns that a response can carry top-level `status: success` while
  `trx_status: Failed` and `trx_id` is empty. Always read `trx_status`.

## 3. Transaction status v2: `POST {host}/v2/transaction-status`

- Header `merchantId`. JSON body `{ "trxId": "…" }` (PayStation's trx id, which exists only
  after a successful checkout).
- `data` contains `invoice_number`, `trx_status`, `trx_id`, `trx_amount`, `request_amount`,
  `payment_amount`, `order_date_time`, `payer_mobile_no`, `payment_method` and `reference`.
- `trx_status` is one of `processing`, `success`, `failed` or `refund`.
- The failure sample uses `status_code "2001"`.
- **Not used:** a payment that has not succeeded has no trx id. The driver queries v1 by
  our own invoice number.

## 4. Error codes listed

| Code | Meaning |
|---|---|
| `1008` | Duplicate invoice number |
| `2001` | Invalid token / transaction not resolved |

## 5. IPN (server to server)

- A POST with a JSON body containing `invoice_number`, `trx_status`, `trx_id`, `trx_amount`,
  `order_date_time`, `payment_method` and `reference`.
- It is sent **only for successful transactions** and **may be retried**, so the handler
  must be idempotent.
- **No signature or HMAC.** The doc's verification checklist is: the invoice exists and is
  pending, the amount matches, the trx id has not been processed already, and (optionally)
  the request comes from PayStation's server IPs. **The IPs are not listed.**

## 6. Browser return

After the payment, "we direct the customer back to your `callback_url` with parameters
indicating payment outcomes". **The parameter names and values are not documented.**

## Not documented / unknown (the driver's safe behaviour)

1. **Content type of `/initiate-payment`.** The driver sends form-encoded fields. *Verify.*
2. **How a non-whole-taka amount is handled** (`payment_amount` is an integer). The driver
   refuses to open a session for an amount with paisa (`amount_not_whole_taka`) and never
   rounds money.
3. **Redirect parameters on `callback_url`.** The driver ignores them. Our callback URL
   carries the order number, and the API re-queries PayStation by the payment's own
   invoice number. A forwarded `invoice_number` is used only to pick the attempt, and only
   when it belongs to that order.
4. **The full set of `trx_status` values.** `success` → paid (after the amount and invoice
   checks). `failed` → failed. `processing` → unchanged (still open). Anything else
   (`refund`, anything canceled-like, an unknown value) → unchanged, recorded in the
   payload, and left for the expiry sweeper or a human. Matching is case-insensitive.
5. **Whether `payment_amount` in the v1 status answer is the requested amount or the
   amount paid** (with `pay_with_charge = 0`, which the driver sends, the two should be
   equal). The driver requires an exact match. A mismatch is never written as paid: the
   payment is flagged for review.
6. **IPN signature.** None exists. The IPN is a **trigger to re-query only** (like Pathao,
   rule 24c). The body never moves money. An IPN for an invoice that is not a known
   PayStation payment is refused (404) before any write. An optional source-IP allowlist
   (`PAYSTATION_IPN_ALLOWED_IPS`) exists, but PayStation publishes no IPs, so it is empty
   by default.
7. **Where the IPN URL is configured.** The initiate request has no IPN-URL field, so it is
   presumably set in the merchant panel. *Ask PayStation.* The endpoint is
   `POST /api/v1/webhooks/payments/paystation/ipn`.
8. **Refunds.** No refund API is documented. `refund()` returns `failed` /
   `not_supported`, so refunds are made in PayStation's panel.
9. **A safe credential check.** Transaction-status needs only `merchantId` (no password),
   and its `2001` code means both "invalid" and "not found", so it proves nothing about the
   keys. The connection check therefore opens a ৳1 payment link with a probe invoice
   (`CHK-…`), discards the link and charges nobody. A `200` → `ok`, an answered refusal →
   `credentials_rejected`, and a timeout, 5xx or unreadable body → `unreachable`. This
   leaves an unpaid probe link in the merchant panel, so (owner decision 2026-11-19) the
   check runs in **sandbox mode only**; in live mode it sends nothing and answers
   `sandbox_only`. *Ask PayStation for a dedicated auth/ping endpoint to check live keys.*
10. **Sandbox test wallets or cards.** Not published.
11. **How long a payment link lives** before PayStation expires it. Not published. Our own
    expiry window (`payments.online.expiry_minutes`) applies.
