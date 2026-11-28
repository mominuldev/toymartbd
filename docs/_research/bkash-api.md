# bKash Tokenized Checkout — merchant API research

**Source:** bKash's developer portal, https://developer.bka.sh/docs, through its machine index
https://developer.bka.sh/llms.txt. Read 2026-11-28. Pages used, and nothing else:

- `docs/grant-token.md`, `docs/refresh-token.md`
- `docs/create-payment-3.md`, `docs/execute-payment-4.md`, `docs/query-payment.md`
- `docs/refund.md`, `docs/refund-status.md`
- `docs/payment-error-codes.md`
- Background, from the earlier market research: `docs/_research/agent4-bd-market-and-payments.md` §1.3
  (IPN page `page/instant-payment-notification.md`, onboarding, fees).

**How it was read:** the pages were fetched and summarised by a tool, not read line by line in a
browser. Field names and paths below are the ones the pages print. Where the pages contradict
themselves, both readings are listed and the driver's choice is stated. Third-party SDKs and
plugins were **not** used as sources.

**No bKash merchant account exists** (Gate 1 decision 5 is open), so there are **no sandbox keys**:
bKash issues sandbox credentials only after merchant onboarding. Nothing here has been tried against
the real sandbox. The driver is `app/Domain/Payment/Gateways/Bkash/BkashGateway.php`; its fixtures
(`tests/Fixtures/bkash/`) are hand-authored from the samples below. The provider row stays
`verified = false`, so production can never take money through it (the rule 33a pattern).

## Hosts

| Mode | Base URL | Source |
|---|---|---|
| Sandbox | `https://tokenized.sandbox.bka.sh/v2/tokenized-checkout` | every page above |
| Live | **not published** | bKash supplies it at onboarding |

The live base URL is `BKASH_LIVE_BASE_URL` (config `payments.bkash.hosts.live`). While it is empty,
live mode counts as **not configured**: no payment, no connection check. We did not copy a live URL
from community code.

## 1. Grant Token: `POST {base}/auth/grant-token`

Headers `Content-Type: application/json`, `Accept: application/json`, **`username`**, **`password`**.
Body **`app_key`**, **`app_secret`**. Success: `statusCode "0000"`, `statusMessage`, `id_token`,
`token_type "Bearer"`, `expires_in` (3600; the table says string, the sample is a number),
`refresh_token` (valid 30 days). Failure sample: `statusCode "9999"`, "Invalid or unrecognized
access credentials". 30 s timeout.

## 2. Refresh Token: `POST {base}/auth/refresh-token`

Same headers; body `app_key`, `app_secret`, `refresh_token`. Same answer shape.
**bKash: refresh at minute 50–55, and no more than twice an hour — more blocks the app for an
hour.** The driver (`BkashTokenStore`) reuses the `id_token` until 10 minutes before expiry,
counts refreshes in a rolling hour and grants afresh instead of a third refresh, renews under a
cache lock, and caches the token record **encrypted**, keyed by a hash of mode + host + username +
app key (no secret in the key).

## 3. Create Payment: `POST {base}/payment/create`

Headers `X-App-Key` (the app key), `Authorization`, `Content-Type: application/json`.
Body (all required unless noted): `payerReference` (≤255, `^[^<>&]*$`; if it is a wallet number
bKash presets it), `callbackURL`, `amount` (decimal string, e.g. `"2.90"`), `currency` (`BDT` only),
`intent` (`sale`), `merchantInvoiceNumber` (≤255, `^[^<>&]*$`); aggregator-only `subMerchantName`,
`merchantAssociationInfo` (not sent). No `mode` body field (`mode=0011` appears only inside the
sample `bkashURL`).

Answer: `paymentId` (valid 24 h; **can be executed once, and cannot be reused after an execute
attempt whatever the result**), `bkashURL` (the redirect), `callbackURL`, `successCallbackURL`,
`failureCallbackURL`, `cancelledCallbackURL`, `amount`, `intent`, `currency`, `paymentCreateTime`,
`transactionStatus "Initiated"`, `merchantInvoiceNumber`, `signature`. Error: `externalCode`
(e.g. `2007`), `errorMessageEn`; samples also carry `internalCode`, `errorMessageBn`.

**Driver:** `payerReference` = our order number (we do not send the customer's phone);
`merchantInvoiceNumber` = `{order number}-P{payment id}`; `amount` = the exact decimal; the
`paymentId` is stored as `payments.transaction_id`. The redirect must be `https://`.

## 4. Execute Payment: `POST {base}/payment/execute` — the capture

Body `paymentId`. Success sample: `paymentId`, `trxId`, `transactionStatus "Completed"`, `amount`,
`currency`, `intent`, `paymentExecuteTime`, `merchantInvoiceNumber`, `payerType`, `payerReference`,
`payerAccount` (the payer's wallet — **never stored**), `maxRefundableAmount`; with a coupon also
`couponAmount`, `creditedAmount`. Error sample: `externalCode "2062"` "The payment has already been
completed". bKash: call it **only after the success callback**; if it does not answer in 30 s,
**call Query Payment**; retry from Create only if the status is `Initiated`.

**Driver:** Execute's answer is never applied as money. After Execute, the driver queries and
applies the query. A definite error answer (an `externalCode`) with the query still `Initiated`
closes the attempt `failed` (the paymentId is spent); a timeout is left open for a retry or the
sweeper.

## 5. The callback (browser return)

bKash appends `paymentID`, `status` and `signature` to `callbackURL`. Documented `status` values
(samples): `success`, `failure`, `cancel`. **Not documented:** how to verify `signature` (no
algorithm, key or covered fields are given), and what happens when `callbackURL` already has a
query string. So:

- the callback is a **trigger only**, like a Pathao webhook (rule 24c); the receipt is stored with
  `signature_valid = false`;
- our `callbackURL` never has a query string: it is the storefront's
  `/api/payments/bkash/{order}/success` with the `?locale=` part dropped. The storefront handler
  must read bKash's `status` parameter, **not** the `success` path segment, and pick the locale
  some other way (cookie / default Bangla).

## 6. Query Payment: `POST {base}/query/payment` — the authority (rule 19)

Body `paymentId`. Answer: `paymentId`, `payerReference`, `payerAccount`, `payerType`,
`paymentCreateTime`, `paymentExecuteTime`, `paymentUpdateTime`, `trxId`, `transactionStatus`,
`amount` (`"20.00"`), `currency`, `intent`, **`merchantInvoice`** (not `merchantInvoiceNumber`),
`subMerchantName`, `verificationStatus` (`Incomplete` | `Complete`), `serviceFee`, `creditedAmount`,
`couponAmount`, `maxRefundableAmount`, agreement fields. Error: `externalCode "2002"` "Invalid
Payment ID".

Documented `transactionStatus`: **`Completed`** (success) and **`Initiated`** ("cancelled or failed
verification"). Nothing else is documented. The success sample itself shows `Initiated` — a doc
inconsistency.

**Driver:** paid only when the query says `Completed`, for our `paymentId`, with our invoice (if
echoed), currency BDT, a `trxId`, and an amount equal to ours (PaymentTransitions refuses a
mismatch and the payment is flagged for review). `Initiated` changes nothing. Any other status
changes nothing.

## 7. Refund (v2): `POST {base}/refund/payment/transaction`

Body (all required): `paymentId`, `trxId`, `refundAmount` (≤2 decimals, ≤ `maxRefundableAmount`),
`sku` (≤255), `reason` (≤255). Answer: `originalTrxId`, `refundTrxId`, `refundTransactionStatus
"Completed"`, `originalTrxAmount`, `refundAmount`, `currency`, `completedTime`, `sku`, `reason`.
Limits: up to 10 partial refunds per transaction, within 60 days, **never repeat a refund within
10 minutes**; on a 30 s timeout call Refund Status and retry only if no matching refund exists.
The sample request has a JSON typo (`"refundAmount": 10""`).

## 8. Refund Status: `POST {base}/refund/payment/status`

Body `paymentId`, `trxId`. Answer: `originalTrxId`, `originalTrxAmount`,
`originalTrxCompletedTime`, `refundTransactions[]` of `refundTrxId`, `refundTransactionStatus`,
`refundAmount`, `completedTime`. Only `Completed` is documented.

**Driver:** on a refund timeout, a `Completed` refund of exactly this amount that none of our
refunds already records is taken as this one; otherwise the refund is marked failed, so staff
retry later rather than paying twice.

## 9. Payment error codes listed

2001 Invalid App Key · 2002 Invalid Payment ID · 2003 Process failed · 2004 Invalid
firstPaymentDate · 2005 Invalid frequency · 2006 Invalid amount · 2007 Invalid currency · 2008
Invalid intent · 2009 Invalid Wallet · 2010 Invalid OTP · 2023 Insufficient Balance · 2029
Duplicate for all transactions · 2056 Invalid Payment State · 2062 The payment has already been
completed · 2117 The payment execution has already been completed. `0000` and `9999` appear on
the token pages, not in this list.

## 10. IPN

bKash's IPN is an Amazon SNS envelope, provisioned by bKash support (no self-service URL), sent for
**successful payments only**, with a signature whose verification procedure is **not documented**
(`agent4-bd-market-and-payments.md` §1.3). **Not built.** The callback plus the expiry sweeper's
query are the settlement paths; an IPN listener can be added later as one more re-query trigger.

## Not documented / unknown (the driver's safe behaviour)

1. **`Authorization` header format.** The header tables say "Bearer token"; every curl sample sends
   the bare token (`authorization: id_token`). The driver sends the bare token
   (`BKASH_AUTHORIZATION_SCHEME=plain`); set `bearer` if the sandbox refuses it.
2. **Callback `signature`** — no verification method. Never trusted (above).
3. **`callbackURL` with a query string** — undocumented; ours never has one.
4. **Live base URL** — unpublished; live is off until it is set.
5. **Statuses other than `Initiated` / `Completed`** (failed, cancelled, expired) — not documented;
   they change nothing. A `failure` / `cancel` callback with the query still `Initiated` closes our
   attempt (no money moves; a retry opens a new paymentId; a later `Completed` would be refused as
   paid-after-close and flagged for a human).
6. **Field casing** — the v2 pages use `paymentId` / `trxId` / `bkashURL`; older samples use
   `paymentID` / `trxID` / `bKashURL`. The driver reads either, sends the v2 casing.
7. **A 401 or token-expired answer** — its shape is not documented. The driver treats HTTP 401 as
   "token refused": it drops the cached token and retries once with a fresh one.
8. **Fees** — bKash publishes no PGW rate (secondary sources say ~1.85–2.1%, unverified). The query
   documents a `serviceFee` field; it is kept in the payload but **not booked**, like the SSLCommerz
   fee (IMPLEMENTATION-PLAN: "gateway fee not booked").
9. **Settlement timing** — not published.
10. **Sandbox test wallets / OTP / PIN** — not on the pages read; bKash gives them with sandbox keys.
