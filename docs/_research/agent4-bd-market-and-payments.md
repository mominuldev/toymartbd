# Toy Mart BD — Bangladesh Market, Payments & Operational Constraints

**Research Agent 4** · Observation date for all pricing unless otherwise stated: **2026-11-07**
Stack context: Laravel API + React admin + Next.js-style storefront. COD is expected to dominate.

> **How to read this document.** Every factual claim carries a source URL. Where a figure comes from
> a secondary source (comparison site, blog, news article) rather than the vendor's own page, it is
> labelled **[secondary]**. Where I could not verify something at all it is labelled
> **[UNVERIFIED]** and repeated in the final checklist. Pricing in Bangladesh changes without
> notice and is frequently negotiated per-merchant — treat every number as "confirm before signing".

---

## 1. Payment Gateways

### 1.1 At a glance

| Gateway | Official dev docs | Integration model | Sandbox | Methods covered | Published fee | Laravel/PHP SDK |
|---|---|---|---|---|---|---|
| **SSLCommerz** | [developer.sslcommerz.com/doc/v4/](https://developer.sslcommerz.com/doc/v4/) | Hosted redirect (`GatewayPageURL`) **or** Easy Checkout JS popup | Yes, public, self-serve registration | Cards (VISA/MC/AMEX/Nexus), internet banking (City, Bank Asia, IBBL, MTBL…), MFS (bKash, Rocket, Nagad, Upay), QCash, Tap N Pay | **2.5%** per txn, **3.5%** AMEX, **BDT 25,500 non-refundable setup** ([pricing page](https://sslcommerz.com/pricing/), obs. 2026-11-07) | **Official**: [sslcommerz/SSLCommerz-PHP](https://github.com/sslcommerz/SSLCommerz-PHP), [sslcommerz/SSLCommerz-Laravel](https://github.com/sslcommerz/SSLCommerz-Laravel) |
| **bKash PGW** | [developer.bka.sh](https://developer.bka.sh/docs) | API + bKash-hosted payment page (redirect) or iFrame | Yes (`tokenized.sandbox.bka.sh`), but credentials issued by bKash after merchant onboarding | bKash wallet **only** | No official published rate found **[UNVERIFIED]**; secondary sources say ~1.85–2.1% | No official Laravel SDK. Official WooCommerce plugin exists but stale (v1.0.8, 2022) |
| **Nagad** | **No public developer portal found [UNVERIFIED]** | API with RSA-signed `sensitiveData` envelope (per community code) | Per community packages, yes — but credentials/docs only via Nagad | Nagad wallet only | No published rate **[UNVERIFIED]**; one comparison site says 1.30% **[secondary]** | Community only |
| **ShurjoPay** | [shurjopay.com.bd/frontend-developers/direct-integration-with-api](https://shurjopay.com.bd/frontend-developers/direct-integration-with-api) | Hosted redirect (`checkout_url`) after server-side initiate | Yes, public sandbox + published test credentials | Aggregator: cards, MFS, internet banking | No published rate found **[UNVERIFIED]** | **Official plugin org**: [github.com/shurjopay-plugins/sp-plugin-php](https://github.com/shurjopay-plugins/sp-plugin-php) |
| **aamarPay** | [aamarpay.readme.io](https://aamarpay.readme.io/reference/initiate-payment-json) | Hosted redirect (`payment_url`) after JSON POST | Yes, public — store `aamarpaytest` + published sandbox signature key | Aggregator: cards, MFS, internet banking | No published rate found **[UNVERIFIED]**; comparison site says 1.85–2.75% **[secondary]** | Community: [shipu/php-aamarpay-payment](https://packagist.org/packages/shipu/php-aamarpay-payment) (stale, last tag 2021) |

Observation date for the whole table: **2026-11-07**.

### 1.2 SSLCommerz

**Docs**: <https://developer.sslcommerz.com/doc/v4/> (v4). An older v3.5 doc set exists at
<https://developer.sslcommerz.com/docs.html> and is dated 2017 — do not use it. Developer portal
and self-serve sandbox signup: <https://developer.sslcommerz.com/>.

**Integration model.** Two options, both server-initiated:
- **Hosted Checkout** — POST a session request from your server, get back `GatewayPageURL`, redirect
  the browser there. This is the one to use.
- **Easy Checkout** — an embedded JS popup (`embed.min.js`). Still requires the server-side session.

**Endpoints** (from the v4 doc):

| Purpose | Sandbox | Live |
|---|---|---|
| Create session | `POST https://sandbox.sslcommerz.com/gwprocess/v4/api.php` | `POST https://securepay.sslcommerz.com/gwprocess/v4/api.php` |
| Order validation | `GET https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php` | `GET https://securepay.sslcommerz.com/validator/api/validationserverAPI.php` |

TLS 1.2+ only. Separate store IDs for sandbox and live.

**Call sequence.**
1. Server POSTs `store_id`, `store_passwd`, `tran_id`, `total_amount`, `currency`, `success_url`,
   `fail_url`, `cancel_url` (+ customer/shipping fields) to the session API.
2. Response JSON contains `sessionkey` and `GatewayPageURL`. Persist the session key against your order.
3. Redirect the customer to `GatewayPageURL`.
4. Customer returns to your `success_url` / `fail_url` / `cancel_url` (POST).
5. **Independently**, SSLCommerz POSTs an IPN to the listener URL you configured in the merchant panel.
6. Your server calls the **Order Validation API** with `val_id` + `store_id` + `store_passwd` and only
   marks the order paid if status is `VALID` or `VALIDATED` **and** the amount and currency match your DB.

**IPN / webhook verification — this is the security-critical part.** The IPN POST includes
`tran_id`, `val_id`, `amount`, `currency`, `status`, plus `verify_sign` and `verify_key`. Status values
are `VALID`, `FAILED`, `CANCELLED`, `UNATTEMPTED`, `EXPIRED`.

The v4 HTML doc describes `verify_sign` only as a "Data Validation Key" and does **not** publish the
algorithm. The algorithm *is* published, authoritatively, in SSLCommerz's own PHP library
([`lib/SslCommerzNotification.php`](https://github.com/sslcommerz/SSLCommerz-PHP/blob/master/lib/SslCommerzNotification.php)):

1. Split the POSTed `verify_key` on `,` — it is a comma-separated list of field names that are covered
   by the signature (e.g. `amount,bank_tran_id,currency,status,store_amount,store_id,tran_id,val_id`).
2. Build an array of those `field => posted value` pairs.
3. Add one extra pair: `store_passwd => md5(<your store password>)`.
4. `ksort()` the array (sort keys ascending).
5. Join as `key=value` pairs with `&`, no trailing `&`.
6. `md5()` that string and compare to the posted `verify_sign`.

A `verify_sign_sha2` field also appears in sample payloads (seen in the `pysslcommerz` fixtures,
<https://pypi.org/project/pysslcommerz/>), implying a SHA-256 variant exists, but its construction is
**[UNVERIFIED]** — SSLCommerz does not document it.

**Important:** signature verification is *necessary but not sufficient*. SSLCommerz's own docs instruct
merchants to call the server-to-server Order Validation API before fulfilling, because the redirect/IPN
payload is attacker-reachable. Implement **both**: verify the MD5 signature, then call the validator,
then compare `tran_id`/amount/currency against your own order row. Also allowlist the IPN source IPs
documented in the v4 guide — sandbox `103.26.139.87`; live `103.26.139.81` and `103.132.153.81`
(outbound `103.26.139.148`, `103.132.153.148`). Listener must be on port 80/443 and publicly reachable.

**Merchant onboarding** (<https://sslcommerz.com/onboarding-requirements/>). Requirements are listed
per entity type. For the two shapes a small toy store is likely to take:

| Entity | Documents listed |
|---|---|
| **Proprietorship** | Updated trade licence, TIN certificate, company bank account + cheque leaf, signatory's NID copy, signed & sealed MEF (Merchant Enrolment Form), DBID, blacklist check. VAT appears in the page's summary but not in its detailed list — inconsistent. |
| **Private Limited** | Updated trade licence, TIN certificate, company bank cheque leaf, company BIN/VAT certificate, owner/signatory NID plus NID of each shareholder in Form XII, MEF, DBID, blacklist check. |

Note that **DBID** (Digital Business Identification) is required for most commercial types — see §7.
The official page's summary and detail lists are not fully consistent; confirm with SSLCommerz.

**Fees & settlement.** The official pricing page (<https://sslcommerz.com/pricing/>, observed
2026-11-07) publishes a single "Basic" plan:
- **BDT 25,500 setup, non-refundable** (page does not say whether one-time or recurring)
- **2.5% per successful transaction** on standard channels (Visa, Mastercard, bKash, Nagad, Rocket "and more")
- **3.5% for AMEX**
- Includes basic API integration and merchant dashboard access.

The pricing page does **not** publish a settlement timeline, and neither does the FAQ
(<https://sslcommerz.com/faq/>), which only says a settlement report is available under the merchant
panel's *Accounting* tab and that **refunds take 5–7 working days**. Secondary sources give conflicting
settlement figures (same-day, T+1–T+2, and 5–7 business days) — all **[UNVERIFIED]**; e.g.
<https://www.photonpay.com/hk/blog/article/payment-methods-in-Bangladesh> and
<https://paymentproviders.io/compare/vcan-vs-sslcommerz>. **Get settlement terms in writing.**

**SDKs.** SSLCommerz maintains its own GitHub org, <https://github.com/sslcommerz> (19 repos). Relevant:
- `SSLCommerz-PHP` — raw PHP library, last updated **2026-06-14** (most actively maintained in the org)
- `SSLCommerz-Laravel` — Laravel library, last updated **2025-10-12**, 144 stars
- Also Node.js (2025-08-18), Java, WooCommerce, Odoo, etc.

Best-maintained community Laravel package: **[`raziul/sslcommerz-laravel`](https://packagist.org/packages/raziul/sslcommerz-laravel)**
— v1.2.0 released **2026-04-20**, PHP 8.2–8.5, Laravel 10/11/12/13, MIT, 76 stars, 4,583 installs,
covers IPN handling, server-to-server validation, MD5 signature verification, and full/partial refunds.
This is the one I'd actually reach for on a modern Laravel app; keep the official repo open as the
reference implementation for the hash logic.

### 1.3 bKash PGW (Tokenized Checkout)

**Docs**: <https://developer.bka.sh/docs>, machine index at <https://developer.bka.sh/llms.txt>.
The current public doc set covers **Tokenized Checkout only** (with-agreement and without-agreement
variants, plus Auth & Capture). I found **no page for the older non-tokenized / iFrame checkout** in the
current doc set, although bKash's own WooCommerce plugin still references a non-tokenized iFrame flow.
Treat "which products are still offered to new merchants" as **[UNVERIFIED]** — ask bKash.

**Auth model.** Two-layer: static merchant credentials in headers, dynamic bearer token in the body/header.

`POST https://tokenized.sandbox.bka.sh/v2/tokenized-checkout/auth/grant-token`
([grant-token docs](https://developer.bka.sh/docs/grant-token.md))
- Headers: `Content-Type: application/json`, `Accept: application/json`, **`username`**, **`password`**
- Body: **`app_key`**, **`app_secret`**
- Response: `id_token` (send on later calls as the authorization token), `token_type` (`Bearer`),
  `expires_in` (default **3600s**), `refresh_token` (valid **30 days**)
- bKash recommends refreshing at **minute 50–55**. **Do not call refresh more than twice per hour** —
  exceeding it errors and **blocks the app for one hour**. Cache the token in Redis with a ~55-minute TTL
  and a mutex so concurrent requests cannot stampede the refresh endpoint.
- The **live** base URL is not published on the grant-token page; bKash supplies it at onboarding **[UNVERIFIED]**.

**Call sequence (tokenized, without agreement — the normal one-off e-commerce case).**
1. **Grant Token** (or reuse cached) → `id_token`.
2. **Create Payment** ([docs](https://developer.bka.sh/docs/create-payment-3.md)) → returns `paymentID`
   and a `bkashURL`. **`statusCode: "0000"` here means the *session* was created, not that money moved.**
3. Redirect the customer to `bkashURL`. They authorise in bKash.
4. bKash redirects the browser back to the `callbackURL` you supplied in Create Payment.
5. **Only if the callback reports success**, call **Execute Payment**
   ([docs](https://developer.bka.sh/docs/execute-payment-4.md)). `statusCode: "0000"` = paid.
6. If Execute Payment times out or is ambiguous, call **Query Payment**
   ([docs](https://developer.bka.sh/docs/query-payment.md)) — bKash explicitly documents this as the
   standard timeout fallback. **This step is mandatory for correctness**, not optional: a timed-out
   Execute can still have succeeded.
7. **Refunds** via the Refund + Refund Status APIs
   ([docs](https://developer.bka.sh/docs/refund-transaction.md)). Partial refunds supported.
8. **Search Transaction** ([docs](https://developer.bka.sh/docs/search-transaction.md)) for reconciliation.

With-agreement flow (for saved-wallet / repeat purchases) adds Create Agreement → Execute Agreement →
Create Payment with Agreement → Execute Payment with Agreement → Cancel Agreement. Not needed at launch.

**IPN / webhook verification.** Documented at
<https://developer.bka.sh/page/instant-payment-notification.md>. This is materially different from
SSLCommerz and worth understanding before you plan effort:
- You build a listener, then **give the URL to bKash technical support** — there is no self-service
  webhook config. bKash provisions it against your app.
- The listener first receives a **subscription confirmation** it must acknowledge. The doc does not
  say how. **[UNVERIFIED]**
- Notifications are fired for **successfully completed payments only** — you will not get failure
  events. Covers PGW, `*247#` menu, bKash app, and Bangla QR scan payments.
- The payload is an **Amazon SNS envelope**: `Type`, `MessageId`, `TopicArn`, `Message`, `Timestamp`,
  `SignatureVersion`, `Signature`, `SigningCertURL`, `UnsubscribeURL`. Transaction fields
  (`trxID`, `transactionStatus`, `amount`, `currency`, `debitMSISDN`, `merchantInvoiceNumber`, …)
  are inside `Message`.
- bKash states "every notification is signed, so you can confirm it genuinely came from bKash", and
  supplies `Signature` / `SignatureVersion` / `SigningCertURL`. It does **not** document the signing
  algorithm, which fields are covered, or how to validate the certificate chain.
- **Practical read:** because it is an SNS envelope, standard AWS SNS message-signature verification
  (fetch cert from `SigningCertURL`, verify over the canonical SNS string-to-sign) is the obvious
  implementation — but bKash does not say so, and the doc's own sample payloads reuse an identical
  `Signature` across three examples, so they are illustrative only. **Treat the exact verification
  procedure as [UNVERIFIED] and confirm with bKash before relying on IPN as a source of truth.**
- **Design implication:** do not make IPN your primary settlement path on bKash. Make
  **Execute Payment + Query Payment** authoritative and treat IPN as a redundant nudge.

**Onboarding.** bKash's online-business page (<https://www.bkash.com/en/business/online-business>,
403s to automated fetch; content summarised from search results) indicates: a trade licence is the
normal route, and merchants **without** a trade licence may instead open a bKash Personal Retail
Account. Secondary sources describe the application as asking for **NID + trade licence + bank account
+ website/app URL** ([Antom guide](https://knowledge.antom.com/bkash-payment-guide-psp)). An older
World Bank/FHI 360 merchant-onboarding toolkit lists photo ID, passport photo, phone with SIM, trade
licence and shop address, with **TIN and bank declaration not mandatory**
(<https://documents1.worldbank.org/curated/en/399121562045068486/pdf/Merchant-Onboarding-Toolkit.pdf>)
— but that is 2018 and predates current rules. There is reporting that **BIN is being made mandatory
for MFS merchants** (<https://www.thedailystar.net/business/economy/news/govt-make-bin-mandatory-mfs-merchants-4193956>).
**I found no official bKash checklist for PGW/API onboarding, approval timelines, or fees. [UNVERIFIED]**

**Fees & settlement. [UNVERIFIED]** bKash publishes no PGW rate card I could find. Secondary:
1.85% with "instant" settlement per <https://paymentproviders.io/compare/bkash-vs-a-pay>;
1.85–2.1% MFS with T+1–T+2 per <https://www.photonpay.com/hk/blog/article/payment-methods-in-Bangladesh>.
Note the 1.85% figure also circulates as bKash's *cash-out* charge, so it may be the wrong number
entirely. Regulatory context: Bangladesh Bank has set a **1% minimum MDR** for merchant payments over
NPSB (<https://www.tbsnews.net/economy/banking/bb-caps-npsb-merchant-charge-1-boost-cashless-transactions-1477456>),
which does not govern bKash's own gateway, and a BB official was quoted in June 2026 saying there is no
cap on what MFS providers may charge (<https://observerbd.com/news/564792>).

**Methods supported:** bKash wallet only. This is a single-method gateway, not an aggregator.

**SDKs.** No official Laravel/PHP SDK. bKash Limited publishes an official WooCommerce plugin
(`bkash-for-woocommerce`, v1.0.8, last updated **2022-05-29**, flagged possibly abandoned —
<https://wordpress.org/plugins/bkash-for-woocommerce/>). Community Laravel options exist
([`mrcarb0n/laravel-bkash-pgw`](https://packagist.org/packages/mrcarb0n/laravel-bkash-pgw),
[`irfan-chowdhury/bkash-tokenized-checkout`](https://packagist.org/packages/irfan-chowdhury/bkash-tokenized-checkout))
but none is official and maturity is **[UNVERIFIED]**. bKash's API is small enough (4 calls) that a
hand-rolled Laravel service class is defensible and probably safer than an unmaintained package.

### 1.4 Nagad

**This is the weakest-documented option and the finding matters for planning.**

- **No public developer portal or API documentation could be found.** `nagad.com.bd/en/merchant` and
  `/en/product/merchant-payment` both 404. The only merchant entry point on the live site is a
  lead-capture form with a CAPTCHA: <https://nagad.com.bd/en/forms/?form=be-a-merchant> (verified
  2026-11-07 — the form has no document checklist, no pricing, no API information).
- **Integration model (reconstructed from community code — [UNVERIFIED]):** API-first with an RSA
  envelope. You build a JSON payload of merchant/order fields, encrypt it to `sensitiveData`, sign the
  plaintext with your merchant private key using **SHA1withRSA** to produce `signature`, and POST
  `{datetime, sensitiveData, signature}` to an `initialize` endpoint; then a `complete` endpoint using
  the payment reference ID from the decrypted response. Sources:
  <https://github.com/muhibbin-munna/nagad_pg_php>, <https://github.com/bluedot-bd/nagad-payment-gateway>,
  <https://github.com/MahmudulHassan5809/nagadpy>, <https://pub.dev/packages/nagad_payment_gateway>.
  One community package reports API **v4.0.1** (<https://packagist.org/packages/softscholar/laravel-payments>).
- **Credentials needed:** merchant ID, your merchant private key, Nagad's gateway public key
  (<https://pub.dev/packages/nagad_payment_gateway>). Keys are generated in a "Merchant Integration
  Details" area of the Nagad merchant panel, and **your callback URL (and, if hosted abroad, your
  server IP) must be given to Nagad for allowlisting** before it will work
  (<https://wordpress.org/plugins/nagad-payment-gateway/>).
- **Verification mechanism:** the "complete" call is itself the verification, and the response is
  RSA-encrypted/signed. There is no published hash-based webhook scheme. The exact padding, key
  formats and field ordering are **[UNVERIFIED]** — community code warns about exactly this.
- **Onboarding:** trade licence required per secondary sources; Islamic merchant accounts handled
  separately via nagadislamic.com.bd. **[secondary / UNVERIFIED]**
- **Fees:** no official rate card. One comparison site states **1.30%**
  (<https://paymentproviders.io/compare/nagad-vs-bole-atlantic>) **[secondary]**. Settlement **[UNVERIFIED]**.
- **Methods:** Nagad wallet only.
- **SDKs:** community only ([`karim007/laravel-nagad`](https://packagist.org/packages/karim007/laravel-nagad),
  whose README itself says to *"contact Nagad for their API documentation and sandbox access"* —
  which tells you everything about the integration experience).

**Planning read:** direct Nagad integration is a support-ticket-driven, key-exchange project with no
public spec. For a small store, get Nagad **through an aggregator** instead.

### 1.5 ShurjoPay

**Docs**: <https://shurjopay.com.bd/frontend-developers/direct-integration-with-api> (this is the page
that actually resolves; `/developers/shurjopay-restapi` and `/developers` currently 404 — verified
2026-11-07). FAQ: <https://shurjopay.com.bd/developers/faq>.

**Base URLs** (published):
- Production: `https://engine.shurjopayment.com/api`
- Sandbox: `https://sandbox.shurjopayment.com/api`

Public sandbox credentials are printed in the official docs (username `sp_sandbox`), so you can build
against it before onboarding — a real advantage over bKash and Nagad. Live credentials (username,
password, and a **`prefix` of up to 5 characters**) are emailed by ShurjoPay support after onboarding.

**Call sequence (3 REST calls).**
1. `POST /get_token` with `{username, password}`.
   Returns `token`, `store_id`, `token_type` (`Bearer`), `sp_code`, `expires_in`, `execute_url`
   (marked **Obsolete** in the docs).
   **Doc inconsistency:** the prose says the token is valid **15 minutes**, while the sample response
   shows `"expires_in": 3600` and `"sp_code": "200"` (the field description says 1000 = success).
   Assume the shorter 15 minutes and cache conservatively. Flagged **[UNVERIFIED]**.
2. `POST /secret-pay` with `Authorization: Bearer <token>` and body including `prefix`, `token`,
   `store_id`, `amount`, `order_id`, `currency`, `return_url`, `cancel_url`, plus mandatory customer
   fields (`customer_name`, `customer_address`, `customer_phone`, `customer_city`, `customer_email`).
   Optional: shipping fields, `discount_amount`, `disc_percent`, `client_ip`, `value1`–`value4`
   (useful for stashing your internal order UUID).
   Returns `checkout_url`, `sp_order_id`, `amount`, `currency`, `transactionStatus` (`Initiated`).
   Content type may be `application/json` **or** `multipart/form-data`.
3. Redirect the customer to `checkout_url`.
4. `POST /verification` with the bearer token and the **order id** in the body. The docs state this
   call returns the order's full response data and is **mandatory** for rendering your own return page
   with order information. **This is your authenticity mechanism** — there is no signature/hash to
   check; you re-query the gateway server-to-server.

**Webhook/IPN.** ShurjoPay's developer section advertises "webhook/IPN setup for payment status
updates", but I could not reach a page documenting its payload or verification. **[UNVERIFIED]** —
plan on `/verification` polling as the source of truth and ask ShurjoPay for the IPN spec.

**`sp_code` values.** Official docs say **1000 = success** and point to a response-code section I
could not retrieve (the page is a tabbed SPA). A third-party Laravel package treats 1000 = success,
1001 = declined, 1002 = cancelled (<https://github.com/Nazmul7989/laravel-surjopay>) — **[UNVERIFIED]**.

**Onboarding / fees / settlement.** Not published. **[UNVERIFIED]**

**SDKs.** ShurjoPay runs an official plugin GitHub org, **<https://github.com/shurjopay-plugins>**,
including **[`sp-plugin-php`](https://github.com/shurjopay-plugins/sp-plugin-php)** (v2.1, authored by
ShurjoMukhi Limited; wraps `authenticate` / `makePayment` / `verifyPayment`), published on Packagist as
[`shurjomukhi/shurjopay-plugin-php`](https://packagist.org/packages/shurjomukhi/shurjopay-plugin-php).
Having a *vendor-maintained PHP plugin* plus *public sandbox credentials* makes ShurjoPay the easiest
of these to prototype against. Community Laravel wrappers:
[`dits/shurjopay-laravel-package`](https://packagist.org/packages/dits/shurjopay-laravel-package)
(Laravel 8–12, v2 REST API, token caching) and
[`raziul/shurjopay-laravel`](https://github.com/iraziul/shurjopay-laravel).

### 1.6 aamarPay

**Docs**: <https://aamarpay.readme.io/> (`developer.aamarpay.com` 301-redirects here). Machine index at
<https://aamarpay.readme.io/llms.txt>. Merchant portal: `merchant.aamarpay.com`.

**Integration model.** Single hosted-redirect flow. Server POSTs JSON, gets `payment_url`, redirect.

| Purpose | Sandbox | Live |
|---|---|---|
| Initiate payment | `POST https://sandbox.aamarpay.com/jsonpost.php` | `POST https://secure.aamarpay.com/jsonpost.php` |
| Search / verify transaction | `GET https://sandbox.aamarpay.com/api/v1/trxcheck/request.php` | `GET https://secure.aamarpay.com/api/v1/trxcheck/request.php` |

Sandbox store ID is the public `aamarpaytest` with a published sandbox signature key — build
immediately, no onboarding needed. Live credentials from support@aamarpay.com.

**Call sequence.**
1. `POST /jsonpost.php` with `store_id`, `signature_key`, `tran_id` (≤32 chars, your order ref),
   `amount`, `currency`, `desc`, `cus_name`/`cus_email`/`cus_phone`, `success_url`, `fail_url`,
   `cancel_url`, `type: "json"`, plus optional `cus_*` address fields and `opt_a`–`opt_d`.
2. Response: `{result, payment_url}`. Redirect the customer to `payment_url`.
3. aamarPay POSTs a JSON payload to your `success_url` / `fail_url`. Key fields: `status_code`
   (**0** = initiated, **2** = successful, **3** = expired, **7** = failed), `pay_status`,
   `amount`, `amount_original`, **`store_amount`** (what you actually receive after gateway charges —
   useful for P&L), `pg_service_charge_bdt`, `pg_txnid`, `mer_txnid` (= your `tran_id`), `bank_txn`,
   `card_type`, masked `card_number`, `pg_card_risklevel`, `opt_a`–`opt_d`, `ip_address`.
4. **Verification: there is no signature on the callback.** The docs explicitly say the preferred
   approach is to call the **Search Transaction API** from your success endpoint:
   `GET .../api/v1/trxcheck/request.php?request_id=<your tran_id>&store_id=…&signature_key=…&type=json`.
   Response includes `pay_status`, `status_code`, `verify_status`, `checkout_status`, `pg_txnid`,
   `bank_trxid`, `approval_code`, `amount`, `amount_bdt`, `rec_amount`, `processing_ratio`,
   `processing_charge`, card-BIN details, `risk_level`, etc. Compare `status_code` **and** `amount`
   against your own order before fulfilling.
   **Security note:** the verify call requires your `signature_key` in a **GET query string**. Keep
   this strictly server-side and be mindful it will appear in outbound-proxy/access logs.
5. **Webhook/IPN:** the reference pages I could reach document only the redirect-callback + search
   pattern. A separate asynchronous IPN is **[UNVERIFIED]**.

**Methods supported.** The docs don't publish a list; aamarPay markets itself as an aggregator (cards +
MFS + internet banking) and callback `card_type` examples like `DBBL-VISA` confirm card routing.
Exact current channel list **[UNVERIFIED]**.

**Fees & settlement. [UNVERIFIED]** No official pricing page found. A comparison site states
**1.85%–2.75%** depending on plan/method with **2–3 business day** settlement
(<https://paymentproviders.io/providers/aamarpay.md>) **[secondary]**. Onboarding verification is
described as 1–2 business days by third-party guides **[secondary]**.

**SDKs.** No official PHP/Laravel SDK found. aamarPay maintains some first-party samples
(e.g. <https://github.com/aamarpay-dev/aamarPay-asp-dot-net-core>). Community PHP:
[`shipu/php-aamarpay-payment`](https://packagist.org/packages/shipu/php-aamarpay-payment)
(~15k installs but last tagged release **v2.0.0, 2021**) and the newer multi-gateway
[`byteminus/laravel-bdpaygate`](https://packagist.org/packages/byteminus/laravel-bdpaygate), whose own
README grades gateways "Stable" vs "Beta" and does not confirm aamarPay has been run against a real
merchant sandbox. The API is 2 endpoints; write it yourself.

### 1.7 Recommendation — launch with SSLCommerz

**Launch with SSLCommerz as the single online gateway, and treat COD as the primary tender.**

Reasoning, in priority order for a small COD-dominant toy store:

1. **One integration buys every method.** SSLCommerz is an aggregator covering cards, all the major
   MFS wallets (bKash, Nagad, Rocket, Upay) and internet banking behind one session API. bKash-direct
   and Nagad-direct each give you exactly one wallet. When only ~10–25% of your orders are prepaid
   (§2), building and maintaining 2–3 single-wallet integrations is unjustifiable engineering cost.
2. **It is the only one of the five with complete, public, primary-source documentation *and* a
   vendor-maintained Laravel library.** Official repos are current (`SSLCommerz-PHP` updated
   2026-06-14), and `raziul/sslcommerz-laravel` v1.2.0 (2026-04-20) supports Laravel 13 / PHP 8.5
   with IPN + MD5 signature verification + refunds already implemented. Nagad has *no public docs at
   all*; bKash's IPN signature scheme is undocumented; ShurjoPay and aamarPay publish no pricing.
3. **Verification story is the strongest.** SSLCommerz gives you a documented, independently
   reimplementable MD5 signature **plus** a server-to-server Order Validation API **plus** published
   source IPs to allowlist. aamarPay and ShurjoPay rely on re-query only (acceptable, but weaker
   defence-in-depth). bKash's SNS signature is asserted but not specified.
4. **Published pricing you can actually model.** 2.5% flat (3.5% AMEX) is the only rate on this list
   that comes from the vendor's own page. You can put it in the P&L today.
5. **Self-serve sandbox** at developer.sslcommerz.com means engineering is not blocked on a
   commercial onboarding conversation.

**The one real objection, stated plainly:** the **BDT 25,500 non-refundable setup fee** is a material
cash cost for a startup toy store, and at 2.5% SSLCommerz is at the *expensive* end of the range
secondary sources quote for aamarPay (1.85–2.75%) and Nagad (1.30%). If that setup fee is a blocker:

- **Cheaper alternative to price-check: aamarPay.** It is the only other option here with a *public
  zero-friction sandbox* (`aamarpaytest`), a clean 2-endpoint API, a documented server-side verify
  call, and aggregator coverage. Get a written quote from both and compare total 12-month cost at your
  forecast prepaid volume. If aamarPay waives setup and lands near 2%, it is a rational launch choice —
  the integration is a day's work either way. Its weakness is no official Laravel SDK and no published
  settlement terms.
- **ShurjoPay** is a credible third quote, and is the easiest to prototype (official PHP plugin +
  public sandbox creds), but publishes no pricing at all.

**Do not** launch with bKash-direct or Nagad-direct. bKash-direct is worth adding *later* as a
**second** rail if bKash becomes a large share of prepaid volume and the per-transaction saving over
SSLCommerz's 2.5% exceeds the build + maintenance cost — and only then, because it also unlocks
bKash's 30-day refresh-token/agreement flow for repeat buyers. Nagad-direct should only ever be
reached via an aggregator unless Nagad becomes strategically unavoidable.

**Architecture advice regardless of choice.** Put a `PaymentGateway` interface in front of it
(`createSession()`, `verifyCallback()`, `validateTransaction()`, `refund()`) with one driver per
gateway, and persist the raw callback/IPN payload plus the raw validation response on every order.
All five gateways share the same shape (server-side session → hosted redirect → server-side
re-validation), so the abstraction is cheap now and makes a later switch or a second rail a
configuration change rather than a rewrite. Never mark an order paid from a browser redirect alone.

---

## 2. COD Reality in Bangladesh

> **Read this section before building the risk-scoring model.** The headline numbers everyone repeats
> are weaker than they look. One of the three is a *user preference* survey answer being misquoted as
> an order share, and **there is no credible Bangladesh-specific return-rate statistic at all**.

### 2.1 COD share of e-commerce — no current primary statistic exists

| Figure | Source | Primary? | Date | What it actually measures |
|---|---|---|---|---|
| **~90% of e-commerce is COD** | AKM Fahim Mashroor (CEO, bdjobs.com) quoted in [The Financial Express](https://today.thefinancialexpress.com.bd/metro-news/online-shopping-boosts-e-commerce-deals-thru-cards-1745772960) | Named industry figure in a national daily — **the strongest recent attribution found** | 28 Apr 2025 | "e-commerce" generally; no method stated |
| **>90% of users *prefer* COD**, attributed to e-CAB research | [US ITA / export.gov](https://legacy.export.gov/article?id=Bangladesh-ECommerce), repeated by [DHL BD](https://www.dhl.com/discover/en-bd/e-commerce-advice/e-commerce-trends/what-is-e-commerce-2025-blangladeshi-trends) | Secondary, citing e-CAB; **the underlying e-CAB report could not be located** | Undated (ITA page is legacy) | **User preference, NOT order share** — routinely misquoted as order share |
| **86.8% of online shoppers named COD their top method** | [BSS](https://www.bssnews.net/special-stories/391786) | State news agency reporting a survey; survey date not shown | — | Shopper preference |
| **~70% COD** (≈30% of transactions online) | [e-CAB's own blog](https://e-cab.net/blog/The-Future-of-e-Commerce) | e-CAB's own site, so primary-ish — but **undated**, and the page is a JS SPA that renders empty to a plain fetch | Unknown | Transaction share |
| **75% COD** by volume (wallets 11%, credit 8%, debit 6%) | [PCMI data library](https://paymentscmi.com/insights/bangladesh-ecommerce-market-insights/) | Commercial research | Last updated Nov 2024 | Payment share by volume |
| **80% COD / 15–20% gateway** | [FE op-ed](https://thefinancialexpress.com.bd/views/views/e-commerce-in-bangladesh-where-are-we-headed-1578666791) | Secondary, no method | Jan 2020 | Payment share |
| **60–80%**, "depending on who you ask" | [Future Startup](https://futurestartup.com/2022/10/09/cash-on-delivery-still-dominates-ecommerce-payments-instacod-aims-to-change-it-for-online-sellers/) | Trade press, explicitly uncertain | Oct 2022 | Transactions |

**Verified negative finding — Bangladesh Bank is not a source for this.** The
[Bangladesh Bank Payment Systems Report 2025](https://www.bb.org.bd/pub/annual/psdreport/paymentreport_dec2025.pdf)
(2.8 MB PDF) was downloaded and full-text searched: **the phrase "cash on delivery" does not appear
anywhere in it, and it publishes no COD-vs-prepaid e-commerce split.** It covers cards, MFS, Bangla QR
and NPSB volumes only.

**LightCastle Partners** has no 2025/2026 COD figure. Its most recent relevant piece is
[E-Commerce Growth in Bangladesh (Jan 2022)](https://lightcastlepartners.com/insights/2022/01/e-commerce-growth-bangladesh/)
— "90% of customers are more comfortable with this method", again **preference, not share**.

**Directional context from the prepaid side (hard data).** Bangladesh Bank card-based e-commerce
transaction values via FE: Feb 2025 Tk 20.35bn; Jan 2025 Tk 23.05bn; Dec 2024 Tk 23.16bn (highest
since Dec 2018); Feb 2024 Tk 15.55bn. **The same article attributes that card growth mainly to utility
bills, domestic airfare and hotel bookings — not physical-goods retail.** Read-across for a toy store:
**card/prepaid share for physical consumer goods is likely materially *lower* than the aggregate card
e-commerce figures suggest.**

**Planning assumption: 85–90% of orders will be COD.** Label it an assumption, not a statistic. The
one defensible citation is Mashroor-via-FE (Apr 2025).

### 2.2 Return / failed-delivery / RTO rate — no Bangladesh data exists

**This is the single weakest-evidenced input to the business model.** Searches across e-CAB,
Bangladesh Bank, Daily Star, TBS, Prothom Alo, Financial Express and the courier sites themselves, in
both English and Bengali, found **no hard Bangladesh-specific RTO data from any primary source.**
**Bangladeshi couriers do not publish delivery-success or RTO statistics.**

| Claim | Source | Status |
|---|---|---|
| RTO "regularly exceeds 40%"; COD loses "40–60% of orders to failed deliveries" in Pakistan + Bangladesh | [easysellapp.com](https://easysellapp.com/blogs/wiki/cod-ecommerce-pakistan-bangladesh-cash-collection-delivery-2026) | **ANECDOTAL / MARKETING.** Vendor blog, no named dataset, two countries lumped together. **Do not cite.** |
| "70% of COD delivery failures are operational, not fraud" | same | **ANECDOTAL / unsourced** |
| Returns in Bangladesh are "largely unmanaged, under-measured and structurally ignored"; COD rejection rates "remain high" | [Abul Faisal Jony, *Reverse Logistics: The Forgotten Side of E-Commerce Growth*](https://www.abulfaisaljony.com/reverse-logistics-the-forgotten-side-of-e-commerce-growth/), 16 May 2026 | **Qualitative industry commentary**, gives no percentages. Useful mainly as evidence that reliable national data does not exist. |
| "There is no official number for a safe success rate" | [Kommercly, *Fraud Checker BD*](https://kommercly.com/blog/fraud-checker-bd), 8 Oct 2026 | Merchant-tool vendor, but a **credible negative confirmation** |

**What Bangladeshi merchants actually use instead — "courier success rate" thresholds.** Local practice
is to look up a customer's phone number in a fraud checker that aggregates past parcel history across
Pathao/Steadfast/RedX and read a per-number delivered-vs-returned ratio. **The published risk bands are
tool-authors' heuristics, not measured market data:**

| Tool / author | "Safe" | "Caution" | "High risk" |
|---|---|---|---|
| WooEasyLife checker ([DEV.to](https://dev.to/muhibbullah_ansary_2fc1d9/what-a-courier-success-rate-actually-tells-a-cash-on-delivery-seller-l2l), Nov 2026; page labelled AI-assisted) | ≥75% | 45–<75% | <45% |
| Unnamed third-party tools per [Kommercly](https://kommercly.com/blog/fraud-checker-bd) | ≥80% | 60–80% | <60% |
| Variant cited in the same article | ≥90% | 70–90% | <70% |

Both authors warn the ratio is **meaningless on small samples** (1-of-2 and 10-of-20 both read 50%) and
that a clean record with one courier can hide returns at another.

**Global/India benchmarks, for sanity-checking only — NOT Bangladesh:** COD RTO typically 20–30%
([clickpost.ai](https://www.clickpost.ai/blog/rto-in-ecommerce)); Qikink India order data **30.1% RTO
for COD vs 1.5% prepaid** ([qikink.com](https://qikink.com/blog/what-is-return-to-origin-how-it-affects-online-businesses/));
Unicommerce ~39% at the Nov 2025 India festive peak.

**Range to plan against: 15–30% RTO on COD orders, widening to 40%+ for unverified first-time buyers.**
**This is an inference from India comparables plus the Bangladeshi 75–80% "safe success rate"
thresholds, not a sourced Bangladesh figure. Flag it as an assumption in the P&L.** Toys are a
discretionary, impulse, gift-driven category, which pushes toward the upper end of any range.

**The 30.1% vs 1.5% COD-vs-prepaid spread from Qikink is the most useful number in this section** — not
as a BD figure, but because it quantifies the *shape* of the problem: prepaid orders fail at a tiny
fraction of the COD rate. That is the economic case for every prepayment incentive and every
pre-dispatch confirmation message in the build.

### 2.3 COD service charge taken by couriers — hard-sourced

| Courier | Inside Dhaka | Outside Dhaka | Charged on | Source |
|---|---|---|---|---|
| **Pathao** | **0.5%** (FAQ) / **1%** (rate table) — *the page contradicts itself* | **1%** | Product price | [pathao.com/courier/](https://pathao.com/courier/) |
| **Steadfast** | **1%** | **1%** | **(collected amount − delivery charge)** | [steadfast.com.bd/pricing](https://steadfast.com.bd/pricing), [terms](https://steadfast.com.bd/terms-and-condition) |
| **RedX** | **0%** (unverified) | **1%** (unverified) | Collected amount | **No official page found** — third-party only |
| **Paperfly** | **0%** same-city | **1%** suburbs + rest of Bangladesh | Amount collected | [paperfly.com.bd/pricing](https://paperfly.com.bd/pricing) |

**Pathao's own page is internally inconsistent.** Its FAQ says *"a COD charge of 0.5% for parcels within
Dhaka Metropolitan City and 1% for parcels outside Dhaka City is applicable"*, but every pricing-table
row — including the Dhaka same-city row — is annotated *"1% COD charge will be applicable."*
**Resolve this programmatically** via Pathao's `/merchant/price-plan` endpoint, which returns
`cod_percentage` per store (§3.4).

**Steadfast's base is cheaper than the headline.** Verified against their live quote endpoint: on a
Tk 1,000 COD, Dhaka city, 0.5 kg — delivery 65, COD charge **9**, merchant receives **926**. That is
1% of (1000 − 65) = 9.35, floored to 9. Their T&C confirms the mechanism: *"A 1% Cash Handling & Risk
Management charge, applied after delivery charges are deducted from the amount collected."*

### 2.4 What this means for risk-scoring and P&L design

1. **Model COD at 85–90% of order volume** and treat any prepaid uplift as upside, not baseline.
2. **Carry RTO as an explicit, tunable parameter (default 20%, sensitivity 15–30%)** rather than a
   hard-coded constant. There is no national figure to calibrate against, so the model must survive
   being wrong. If RTO economics are load-bearing for the business case, **the only way to get real
   numbers is to ship a few hundred parcels and measure your own.**
3. **The COD service charge (0.5–1%) is the small cost; the return charge is the large one.** At a
   plausible 20% RTO, return freight (§3.3) dominates COD handling fees by an order of magnitude.
   Build the P&L around cost-per-*attempted*-delivery, not cost-per-successful-delivery.
4. **Use Steadfast's first-party `GET /fraud_check/{phone}` at checkout** (§3.4) to gate COD. It
   replaces guesswork with the carrier's own history for that number — the only real data available.
   Respect the small-sample warning: do not block a number with 1–2 parcels of history.
5. **Note the fee base differs by carrier.** Steadfast charges 1% of (collected − delivery); Pathao
   charges on product price. Same nominal percentage, different money.

---

## 3. Courier Landscape & Pricing

**All rates observed 2026-11-07.** BDT, excluding VAT/tax, standard home delivery.

### 3.1 Rate comparison

| | **Pathao** (published table) | **Steadfast** (live quote API) | **RedX** | **Paperfly** (published table) |
|---|---|---|---|---|
| Dhaka city, 0.5 kg | 60 | **65** | not published | — |
| Dhaka city, 1 kg | 70 | **75** | not published | **70** |
| Dhaka city, 2 kg | 90 | **95** | not published | — |
| Dhaka suburb, 0.5 kg (Narayanganj/Gazipur/Keraniganj/Savar) | 80 | **105** | not published | — |
| Dhaka suburb, 1 kg | 100 | **105** | not published | — |
| Dhaka suburb, 2 kg | 130 | **125** | not published | — |
| Dhaka ↔ outside Dhaka, 0.5 kg | 110 | **115** | not published | — |
| Dhaka ↔ outside Dhaka, 1 kg | 130 | **135** | not published | — |
| Dhaka ↔ outside Dhaka, 2 kg | 170 | **155** | not published | — |
| Outside ↔ outside, 0.5 / 1 / 2 kg | 120 / 145 / 180 | **115 / 135 / 155** | not published | — |
| **Extra per kg, inside Dhaka** | +15/kg **over 2 kg** | **+20/kg over 1 kg** | not published | +15/kg over 2 kg |
| **Extra per kg, outside Dhaka** | +25/kg **over 2 kg** | **+20/kg over 1 kg** | not published | +25/kg over 2 kg |
| Same-city standard, 1 kg flat | 70 | 75 | not published | **70, +20/additional kg** |
| Delivery time | 24h same-city, 72h else | same day Dhaka, next day elsewhere | — | 24h same-city |

Sources: [pathao.com/courier/](https://pathao.com/courier/), [steadfast.com.bd/pricing](https://steadfast.com.bd/pricing),
[paperfly.com.bd/pricing](https://paperfly.com.bd/pricing).

**⚠️ The most important line in this table for a toy store: Steadfast's price step is at 1 kg, not
2 kg.** Pathao and Paperfly both start charging extra weight above 2 kg; Steadfast starts above 1 kg.
That makes Steadfast **cheapest on heavier inter-city parcels** (155 vs Pathao 170 at 2 kg) but **more
expensive at 1.5–2 kg inside Dhaka**. Boxed toys are bulky and routinely land in the 1–3 kg band —
**exactly where the three carriers diverge most.** Do not copy Pathao's "+per kg over 2 kg" rule onto
Steadfast in the shipping calculator; it will understate Steadfast's cost on the parcels you ship most.

**Steadfast extras observed from their own quote endpoint (not on any published table):**
- **Point delivery** (customer collects from a Steadfast point): **−15** off home delivery at every
  weight/lane sampled. Dhaka city 1 kg = 60 point vs 75 home.
- **Express** (same day, within one district): Dhaka city **110** ≤1 kg, 130 at 2 kg, 150 at 3 kg,
  190 at 5 kg. Returns `express_off: "district"` on all cross-district lanes.
- Lane identifiers: `inside_dhaka`, `dhaka_to_sub`, `dhaka_to_outside`, `between_districts`.
- 5 kg worked examples: Dhaka city 155; Dhaka→suburb 185; Dhaka→Chittagong 215.
- Pick-and-drop minimum ৳80. Max parcel weight 600 kg. Cheaper tiers exist for `Document`
  (paper only, **no COD allowed**) and `Book` categories.

**Methodology note on the Steadfast column — these are primary observations, not a published rate
card.** Steadfast **publishes no rate table**; its pricing page is a calculator only. The figures above
came from querying its own public quote endpoint
`GET https://steadfast.com.bd/pricing/quote?from={district_id}&to={district_id}&category=1&weight={kg}&service=1&delivery=0&cod={amount}`
(Dhaka City = 1, Dhaka Sub-Urban = 18, Chittagong = 2, Sylhet = 3; 65 districts in their datalist).
**Steadfast's own label on the result: *"Standard rates. Merchants on negotiated rates are billed on
theirs."*** So these are list rates and a real merchant account may differ.

**RedX — no published rate card exists. This is a firm negative finding, not a gap in effort.**
`https://redx.com.bd/pricing` and `/price` both 301 → `/404/`. RedX's Next.js `_buildManifest.js`
(buildId `w69PfbqRs-pNCohO4s6Ah`) was pulled and **all ~100 routes enumerated: there is no pricing,
rates or charge route.** Rates are visible only inside the merchant panel after login. Independently
confirmed by [banikh.com (updated 30 Jul 2026)](https://banikh.com/en/blog/steadfast-vs-pathao-vs-redx):
*"not published as one flat card; your exact rate shows in the merchant panel."*
**Third-party figures — UNVERIFIED, do not rely on these:**
[fleetsplit](https://fleetsplit.com/redx-delivery-charge-calculator) claims ৳65 inside city (1 kg,
+৳15/kg), ৳90 Dhaka suburb, ৳120 outside Dhaka (+৳30/kg);
[couriertrace](https://couriertrace.com/blog/redx-courier-service-delivery-charge-cost-and-price-list/)
and [courierinformation](https://courierinformation.com/couriers-info/redx-courier-delivery-charge-cost-and-price-list/)
show ৳60–70 inside Dhaka and ৳130–150 outside. **Register a merchant account to get real numbers.**

**Paperfly — only one of five rate tabs is actually populated.**
[paperfly.com.bd/pricing](https://paperfly.com.bd/pricing) shows five tabs (একই শহরে ডেলিভারি /
উপ-এলাকা / ডেলিভারি কেন্দ্রগুলো / আন্তঃনগর ঢাকা / ঢাকার বাইরের আন্তঃনগর) but **only "same
district/city pickup & delivery" has figures** (৳70 up to 1 kg, +৳20/additional kg, 24h). Both the
server-rendered HTML and the full React Server Component flight payload (~39 KB) were parsed — **the
other four tabs contain no rate data at all**, so this is not a client-side rendering artifact.
Note the site footer still reads "© 2025 Paperfly" while Steadfast's reads "© 2026" — **Paperfly's
site looks less actively maintained.**

### 3.2 COD charge percentage

Covered in §2.3. Summary: Pathao 0.5% (FAQ) or 1% (table) inside Dhaka / 1% outside, on product price;
Steadfast 1% everywhere, on (collected − delivery charge); Paperfly 0% same-city / 1% elsewhere;
RedX unverified.

### 3.3 Return / RTO charge to the merchant — **the line item that may decide the carrier**

| Courier | Policy | Source | Confidence |
|---|---|---|---|
| **Pathao** | **Outside Dhaka: 50% of the delivery charge added, on top of the delivery charge.** Dhaka↔Dhaka metro: **no return charge.** But partial & exchange deliveries inside Dhaka metro **do** incur return charges. | [pathao.com/courier/](https://pathao.com/courier/) FAQ, verbatim | **High — courier's own page** |
| **Steadfast** | **CONTRADICTORY.** [Terms](https://steadfast.com.bd/terms-and-condition): *"Steadfast does not charge for return parcels."* [Business page](https://steadfast.com.bd/business): *"Return charges apply separately."* | Both are Steadfast's own pages | **Unresolved — get it in writing** |
| **RedX** | **Not found.** No public terms page renders (`/terms-n-conditions` is an empty SPA shell). | — | **Unverified** |
| **Paperfly** | Calculator note: *"রিটার্ন ফি (ডেলিভারি ফি-এর ৫০%) প্রযোজ্য হতে পারে"* — return fee (50% of delivery fee) **may** apply. Separately: exchange & partial delivery = forward charge **+ an extra 50%**. | [paperfly.com.bd/pricing](https://paperfly.com.bd/pricing) | **Medium — "may apply", conditions unstated** |

The Steadfast contradiction is independently flagged by
[Kommercly (8 Oct 2026)](https://kommercly.com/blog/fraud-checker-bd), so it is a genuine long-standing
inconsistency rather than a transient edit.

**Why this matters more than the forward rate.** At a plausible 15–30% RTO on COD toys, Pathao's
outside-Dhaka refusal costs ~**৳165 all-in on a ৳110 parcel** (110 forward + 55 return). If Steadfast's
"no return charge" holds in writing, that is a **large structural advantage** — it should be the
deciding question in carrier negotiation, ahead of the forward tariff.

### 3.4 API availability

| Courier | Public API? | Docs | Auth |
|---|---|---|---|
| **Pathao** | **Yes, well documented** | `https://merchant.pathao.com/courier/developer-api` — **behind merchant login**; there is no public, unauthenticated Pathao API reference page | OAuth2 password grant, Bearer |
| **Steadfast** | **Yes** | **Publicly accessible Postman collection**: [documenter.getpostman.com/view/26211192/2sAYJ1mhpk](https://documenter.getpostman.com/view/26211192/2sAYJ1mhpk) ("SteadFast API Documentation"); keys from merchant panel → API | `api-key` + `secret-key` headers |
| **RedX** | **Yes, host confirmed live** | `https://redx.com.bd/developer-api` (route exists, login-gated). An `/api-integration-request` route exists → **API access appears request/approval-gated.** No public docs. | `API-ACCESS-TOKEN: Bearer <token>` (third-party, **unverified**) |
| **Paperfly** | **Effectively none** | `/developers` and `/docs` are **linked in the footer but return Paperfly's own 404**: *"এই পেজটি এখনো প্রস্তুত নয়"* ("this page is not ready yet"). `api.paperfly.com.bd` exists (empty Apache dir index). | Unknown |

#### Pathao Merchant API — from Pathao's own code (primary)

Pathao's official engineering org is **<https://github.com/pathao-eng>**, which publishes
[courier-woocommerce-plugin](https://github.com/pathao-eng/courier-woocommerce-plugin) (last pushed
2026-09-28, GPLv3). The strings below are **Pathao's own**, read from `pathao-bridge.php`, and
cross-checked against the merchant portal's `developer-api` JS chunk.

**Base URLs** (verbatim from `get_base_url()`):
- Sandbox: `https://courier-api-sandbox.pathao.com`
- Production: `https://api-hermes.pathao.com`

**OAuth token flow** — `POST <base>/aladdin/api/v1/issue-token`:
```
Issue:   { client_id, client_secret, username, password, grant_type: "password" }
Refresh: { client_id, client_secret, refresh_token, grant_type: "refresh_token" }   // SAME endpoint
200   -> { access_token, refresh_token, token_type: "Bearer", expires_in }   // mirrored copy: 432000 (5 days)
400   -> { message: "The user credentials were incorrect.", type: "error", code: 400 }
```
All other endpoints require `Authorization: Bearer <access_token>`.

**City / zone / area hierarchy — note the paths are PLURAL:**
```
GET /aladdin/api/v1/countries/1/city-list      -> data.data[] { city_id, city_name }
GET /aladdin/api/v1/cities/{city_id}/zone-list -> data.data[] { zone_id, zone_name }
GET /aladdin/api/v1/zones/{zone_id}/area-list  -> data.data[] { area_id, area_name, home_delivery_available, pickup_available }
GET /aladdin/api/v1/zones/list                 -> ALL zones (bulk sync)
GET /aladdin/api/v1/areas/list                 -> ALL areas (bulk sync)
```
A bare `/aladdin/api/v1/city-list` also appears in a community Postman collection; **Pathao's own
current plugin uses `/countries/1/city-list`** — prefer that. Which is deprecated: **[UNVERIFIED]**.
Pathao's sandbox also carries Nepal data (`city_id` 65 = Kathmandu), so filter by country if enumerating.

**Full endpoint list:** `POST /issue-token`, `POST /external/login`, `GET /user/short-info`,
`GET|POST /stores` (store creation needs **~1 hour Pathao approval** before usable),
`POST /orders`, `POST /orders/bulk` (async, returns **202**), `GET /orders/{consignment_id}/info`,
`POST /merchant/price-plan`, plus the five geography endpoints above.

**Order create body** (required unless noted):
```
store_id <int>            recipient_city <int>      delivery_type <int>  // 48 = Normal, 12 = On Demand
recipient_name <string>   recipient_zone <int>      item_type <int>      // 1 = Document, 2 = Parcel
recipient_phone <string>  recipient_area <int?>     item_quantity <int>
recipient_address <string, min 10 chars>            item_weight <float, min 0.5, MAX 10.0>
amount_to_collect <int>   // 0 for non-COD
200 -> { message: "Order Created Successfully", data: { consignment_id <string, len 14>, ... } }
```
**`item_weight` is capped at 10.0 kg via the API** — fine for toys, but a bulky multi-item order needs splitting.

**`POST /merchant/price-plan` is the most useful endpoint here** — it resolves the COD-rate
contradiction programmatically instead of hard-coding 0.5%/1%:
```
body: { store_id, item_type, delivery_type: 48, item_weight: 0.5, recipient_city: 1, recipient_zone: 2 }
200 -> { price: 100, discount: 10, promo_discount: 0, plan_id: 15,
         cod_enabled: 1, cod_percentage: 0.01, additional_charge: 0, final_price: 0 }
```
**You cannot quote a Pathao shipping fee without a resolved `city_id` + `zone_id`** — that, not order
creation, is the real reason the zone mapping in §5 is unavoidable.

**Webhooks:** merchant supplies a URL + `webhook_secret`. Pathao sends `X-PATHAO-Signature`; **your
endpoint must reply with the header `X-Pathao-Merchant-Webhook-Integration-Secret` set to the same
value.** Confirmed in Pathao's own plugin (`plugin-api.php` verifies `HTTP_X_PATHAO_SIGNATURE`).

**Auto-address feature (may let you skip the hierarchy):** [Pathao's Bengali blog post](https://pathao.com/bn/blog/api-merchant-auto-address-feature/)
says API merchants can send only the full address string and Pathao resolves city/zone/area itself —
but **the city/zone/area fields must be omitted from the payload** for it to engage. That post is
~467 days old and **conflicts with `recipient_city`/`recipient_zone` being documented as required.**
**[UNVERIFIED — confirm with Pathao.]**

⚠️ **Avoid [pathao-courier-api on PyPI](https://pypi.org/project/pathao-courier-api/)** — it uses a
dead staging host `hermes-api.p-stageenv.xyz`.

#### Steadfast Merchant API — from the official Postman collection (primary)

**Base URL `https://portal.packzy.com/api/v1`.** Auth on **every** request: `api-key`, `secret-key`,
`Content-Type` (headers are case-insensitive, so `Api-Key`/`Secret-Key` is equivalent).
Live-probed: `POST /create_order` returns `{"status":401,"message":"Unauthorized Access (missing API credentials)"}`,
GET returns 405 — confirming host, path and method.

| Method | Path | Purpose |
|---|---|---|
| POST | `/create_order` | single order |
| POST | `/create_order/bulk-order` | bulk — **top-level JSON array**, not wrapped in an object |
| POST | `/create_return_request` | by `consignment_id` *or* `invoice` *or* `tracking_code`, + `reason` |
| POST | `/create_pickup_request` | `{ address_id, police_station_id, address, contact_number, note, estim_qty }` |
| GET | `/get_return_request/{id}` / `/get_return_requests` | returns |
| GET | `/get_balance` | **balance check** |
| GET | `/payments`, `/payments/{payment_id}` | settlements |
| GET | `/status_by_cid/{consignment_id}` | **status check** |
| GET | `/trackings_by_invoice/{invoice}` | tracking by your invoice no. |
| GET | `/police_stations` | lookup, **for your own pickup location only** |
| GET | **`/fraud_check/{phone_number}`** | **customer risk check** |

**Official `create_order` body, verbatim:**
```json
{ "invoice":"13151523", "recipient_name":"...", "recipient_phone":"+8801309055966",
  "recipient_address":"Dhanmondi, Zigatola", "recipient_email":"...", "alternative_phone":"...",
  "item_description":"...", "cod_amount":"3010", "note":"..." }
```
**There is no `city`, `zone`, `area`, `district`, `upazila` or `postcode` field anywhere** — see §5.4.

Two things worth calling out for the build:
1. **`/fraud_check/{phone}` is a first-party endpoint.** Given COD RTO is the biggest cost risk and
   there is no national data to model it (§2.2), calling this at checkout is **the single
   highest-leverage integration available.** Pathao has no equivalent.
2. **Caveats:** there is no `/status_by_invoice` or `/status_by_tracking_code` — use
   `/trackings_by_invoice/{invoice}`. An **extended bulk endpoint `/create_order/bulk-order/extended`
   with a 500-parcel cap** appears in [Sifat07's SDK](https://github.com/Sifat07/steadfast-merchant-sdk)
   but is **not in the official collection — [UNVERIFIED]**. Field max-lengths (invoice 100,
   recipient_address 250 or 490) are widely repeated by third-party SDKs but **are not stated in the
   official collection — [UNVERIFIED]**; cap `recipient_address` at **250** to be safe under either
   figure. Avoid the [Scribd PDF copy](https://www.scribd.com/document/984642512/SteadFast-Courier-Limited-API-Documentation)
   and [TeamRSBDigital/STEADFAST_API](https://github.com/TeamRSBDigital/STEADFAST_API) (all
   placeholders, self-contradictory on auth).

Steadfast's coverage claim: *"All eight divisions and 64 districts, down to the upazila and union"*
([steadfast.com.bd/business](https://steadfast.com.bd/business)), district list at
[/coverage](https://steadfast.com.bd/coverage).

#### RedX Open API — host and paths confirmed by direct probe (2026-11-07)

No public docs exist, so the host was probed directly. Base `https://openapi.redx.com.bd/v1.0.0-beta`.
Unauthenticated responses distinguish real paths from fake: a real path returns
`{"message":"Unauthorized","status_code":401}`; a non-existent one returns
`{"message":"Please check your specified endpoint and request method"}` (404).

| Path | Method | Unauth response | Verdict |
|---|---|---|---|
| `/areas` | GET | **401** | **real** |
| `/parcel` | **POST** | **401** | **real** (GET → 404, method-gated) |
| `/pickup/stores` | GET | **401** | **real** |
| `/parcel/info/{tracking}` | GET | **401** | **real** |
| `/parcel/track/{tracking}` | GET | **401** | **real** |
| `/` and `/pickup/store` (singular) | — | 404 | not real |

Auth header `API-ACCESS-TOKEN: Bearer <token>`, token from the merchant dashboard's Developer/API
section — from [redx_courier (pub.dev)](https://pub.dev/packages/redx_courier) and
[codeboxrcodehub/redx-courier](https://github.com/codeboxrcodehub/redx-courier), **not independently
verified**. Parcel create takes `parcel_weight` **in grams** and a `pickup_store_id`.

### 3.5 Courier recommendation

1. **Steadfast as primary, Pathao as secondary.** Steadfast wins on inter-city at 2 kg (155 vs 170 —
   the band toys actually fall in), has the **only openly accessible API documentation**, and ships a
   first-party `fraud_check` endpoint. Pathao wins inside Dhaka at 1–2 kg and has the better-specified
   price-plan and webhook surface.
2. **Get two things in writing before signing.** (a) **Steadfast's return-charge policy** — its own two
   pages contradict each other, and at 15–30% RTO this line may exceed any forward-freight saving.
   (b) **Pathao's actual Dhaka COD rate** — 0.5% or 1%; for Pathao you can resolve this in code via
   `price-plan`'s `cod_percentage`.
3. **Call `fraud_check` at checkout and gate COD on it.** Use ≥75–80% as "allow COD"; below that,
   require prepayment or a confirmation call. Respect the small-sample warning.
4. **Do not model Pathao's "+per kg over 2 kg" onto Steadfast** — Steadfast steps at 1 kg.
5. **Register a RedX merchant account before ruling it in or out.** Its claimed 0% Dhaka COD would be a
   real advantage over Steadfast's 1%, but **every RedX rate and COD number in circulation is
   third-party and unverified**, and its API needs an approval request. Treat RedX as unpriced.
6. **Treat Paperfly as a fallback only.** One of five rate tabs populated, footer still says 2025, and
   the footer-linked `/developers` and `/docs` both serve "this page is not ready yet". No usable API.

---

## 4. SMS & WhatsApp Notifications

All prices observed **2026-11-07**. USD→BDT conversions use ≈৳122/USD; **the FX rate itself was not
verified**, so BDT equivalents are approximate.

### 4.1 Bangladesh SMS gateways — API style and documentation

| Provider | Official API docs | API style | Endpoint | Auth |
|---|---|---|---|---|
| **Alpha Net / Alpha SMS** (sms.net.bd now redirects to **sms.bd**) | [alpha.net.bd/SMS/api/](https://www.alpha.net.bd/SMS/api/), [sms.bd/api](https://sms.bd/api) | "RESTful", GET or POST form/query params, JSON responses | `https://api.sms.net.bd/sendsms`, `/report/request/{id}/`, `/user/balance/` | `api_key` as a request **param**, not a header |
| **SSL Wireless (ISMS Plus)** | [ismsplus.sslwireless.com/api-documentation](https://ismsplus.sslwireless.com/api-documentation) | REST + JSON (POST) | `https://smsplus.sslwireless.com/api/v3/send-sms`, `/secure/otp-sms`, `/send-sms/bulk`, `/send-sms/dynamic` | `api_token` + `sid` in body; OTP endpoint additionally needs `X-Signature` (HMAC-SHA256) and AES-256-CBC-encrypted `sms`; **source IP must be allowlisted** (error 4003) |
| **MIM SMS** | [mimsms.com/api-documentation](https://www.mimsms.com/api-documentation) — page claims a "secure REST API" but **the reference body is JS/panel-gated and does not render to a plain fetch** | Simple HTTP GET/POST query params | `https://api.mimsms.com/api/SmsSending/SMS` with `ApiKey`, `UserName`, `SenderName`, `TransactionType` (`T`=transactional / `P`=promotional), `CampaignId`, `MobileNumber`, `Message` — **[UNVERIFIED against MIM's own docs]**, taken from [xenon/laravelbdsms](https://github.com/arif98741/laravelbdsms) | `ApiKey` + `UserName` params |
| **Bulk SMS BD** (bulksmsbd.net) | **None public** — the site is a login-only app; `/pricing`, `/price`, `/sitemap.xml` all 404 | Simple HTTP GET/POST query params | `https://bulksmsbd.net/api/smsapi` with `api_key`, `senderid`, `type=text`, `number`, `message`. Codes: 202 success, 1002 sender ID wrong/disabled, 1007 insufficient balance, 1031 unverified account, 1032 IP not allowlisted. **[UNVERIFIED]** — from [laravelbdsms](https://github.com/arif98741/laravelbdsms) and [pub.dev/packages/bulksmsbd](https://pub.dev/packages/bulksmsbd) | `api_key` param |
| **REVE SMS** (revesms.com) | **No public API docs found** | **[UNVERIFIED]** — an open-source wrapper routes REVE via an `AjuraTech` provider pointing at `smpp.revesms.com`, suggesting **SMPP** rather than documented REST | — |
| **Twilio** | [twilio.com/docs/messaging/api](https://www.twilio.com/docs/messaging/api), BD guidelines: [twilio.com/en-us/guidelines/bd/sms](https://www.twilio.com/en-us/guidelines/bd/sms) | REST, form-encoded POST, JSON responses | `https://api.twilio.com/2010-04-01/Accounts/{SID}/Messages.json` | Basic auth (Account SID + token) |
| **Infobip** | [infobip.com/docs/api/channels/sms](https://www.infobip.com/docs/api/channels/sms) | REST + JSON | `https://{base}.api.infobip.com/sms/2/text/advanced` | `Authorization: App <key>` |

### 4.2 Per-SMS cost in BDT

**Alpha SMS / sms.bd** — <https://sms.bd/Pricing> (primary, observed 2026-11-07). MNP charge included.

| Slab | Min. recharge | Non-masking | Masking | Validity |
|---|---|---|---|---|
| Basic | ৳1,500 | **৳0.40** | **৳0.64** | 6 months |
| Standard | ৳10,000 | ৳0.38 | ৳0.62 | 6 months |
| Business | ৳22,000 | ৳0.35 | ৳0.60 | 1 year |
| Enterprise | ৳50,000 | ৳0.33 | ৳0.56 | 1 year |
| Platinum | ৳90,000 | ৳0.31 | ৳0.55 | 1 year |

**MIM SMS** — <https://mimsms.com/> `#pricing-list` (primary, observed 2026-11-07): masking **from ৳0.48**,
non-masking **from ৳0.25**, OTP **from ৳0.35**, location-based from ৳0.26. Every tier states
"GSM, Extended GSM & Unicode".

**REVE SMS** — <https://www.revesms.com/> (primary, observed 2026-11-07):

| Package | Validity | Volume | Non-masking | Masking | API |
|---|---|---|---|---|---|
| Standard | 6 mo | ≤10,000 | ৳0.36 | ৳0.55 | not listed |
| Advance | 9 mo | ≤50,000 | ৳0.34 | ৳0.53 | included |
| Premium | 12 mo | ≤100,000 | ৳0.32 | ৳0.51 | included |
| Custom | — | >100,000 | quote | quote | — |

**Bulk SMS BD (bulksmsbd.net): no price obtainable** — login-gated.
⚠️ **Name-collision warning:** `bulksmsbd.com`, `bulksmsbd.co` and `bulksmsbd.xyz` are **different
companies** from `bulksmsbd.net`. **[UNVERIFIED]** figures from those sites: bulksmsbd.com ≈৳0.25
non-masking / ৳0.45 masking excl. MNP (<https://bulksmsbd.com/bulksms-price.php>); bulksmsbd.co ≈৳0.28 /
৳0.48 with a **one-time ৳500 per new sender ID** and a 5,500-SMS minimum (<https://bulksmsbd.co/en/>).

**SSL Wireless**: no published rate card — enterprise-quoted only. **[UNVERIFIED]**

**Twilio** — official CSV from <https://www.twilio.com/en-us/sms/pricing> →
<https://assets.cdn.prod.twilio.com/pricing-csv/SMSPricing.csv> (observed 2026-11-07):
**USD 0.5962 per SMS segment, flat across Grameenphone, Robi, Banglalink, Airtel, Teletalk and
"Other"** ≈ **৳72.7 per SMS**. That is roughly **110–290× a local masking SMS**.
**Twilio is not economically viable for Bangladeshi transactional SMS volume.**

**Infobip**: no public Bangladesh rate card; account-negotiated. **[UNVERIFIED]**

### 4.3 English vs Bangla (Unicode) — confirmed

- **Confirmed.** Bangla SMS is Unicode/UCS-2 at **70 characters per segment**. Alpha SMS states it
  directly: Bangla SMS up to 600 characters total, **70 characters per SMS segment**
  (<https://sms.bd/Masking_SMS>); English/GSM-7 long messages reach ~800 characters
  (<https://www.alpha.net.bd/SMS/api/>). The general rule (GSM-7 160/153 vs UCS-2 70/67) is
  corroborated at <https://www.infobip.com/docs/sms/sms-multilingual>.
- **Important nuance:** none of the BD providers checked charge a *different unit price* for Bangla.
  Alpha SMS, MIM SMS and REVE publish one masking and one non-masking rate regardless of language.
  **The extra cost comes purely from segment count.** A 140-character Bangla order confirmation is
  **2 segments = 2× the rate**; the same message in English is 1 segment. Budget **2–3× the headline
  per-SMS price** for a realistic Bangla order notification.
- SSL Wireless reports encoding per message as `sms_type: EN` or `BN` in API responses, so segment
  billing can be reconciled from DLRs (<https://ismsplus.sslwireless.com/api-documentation>).

### 4.4 Masking / Sender ID approval

| Provider | Requirements | Time | Cost |
|---|---|---|---|
| **Alpha SMS** (<https://sms.bd/Masking_SMS>, primary) | Clear **NID** copy; valid **Trade Licence** copy; completed Masking SMS application form **on company letterhead with seal + signature**, emailed. Educational institutions also need an authorization letter from the head of institution. Sender ID **max 11 chars**, letters/digits/space only. | **~6–7 business days** after form received | Not stated |
| **REVE SMS** (<https://www.revesms.com/>, primary) | Authorization letter on official letterhead | **3–5 working days** | Not stated |
| **MIM SMS** (<https://www.mimsms.com/document-acceptance-policy>, primary) | KYC first: **photo ID** (NID/passport/driving licence) **plus one supporting doc** — Trade Licence, **RJSC** Certificate of Incorporation (incl. Form X/XI/XII), or **VAT/BIN** certificate. Notably flexible alternatives exist for freelancers (Freelancer ID, **DBID**, bKash/Nagad merchant certificate), govt offices, NGOs, clubs and schools. | **KYC 1–2 business days**; masking approval time not stated | Not stated |
| **Infobip** (<https://infobip.com/docs/essentials/asia-registration/bangladesh-letter-of-authorization-loa-guidelines>, primary) | Local senders: company **BIN/TIN**, client authorization letter, **plus a separate network-specific authorization letter for every operator** (Grameenphone needs signature + stamp on both pages; Robi Axiata requires a distinct "new" vs "re-registration" letter naming the previous aggregator; Banglalink signature + stamp; Teletalk letterhead + representative signature). Max 11 chars, no personal names. **Aggregators/resellers cannot register locally.** Promotional traffic must be **Bengali**; English *transactional* templates must be **safelisted**. | Not published | Not published |
| **Twilio** (<https://www.twilio.com/en-us/guidelines/bd/sms>, primary) | Alphanumeric sender ID **is supported**, but **pre-registration is mandatory for Grameenphone, Robi/Axiata and Teletalk**. **Since 8 September 2025, messages with unregistered sender IDs to those three networks are blocked.** Long codes **not supported** by BD operators; short codes **not supported**; **two-way SMS not supported**. | **~3 weeks provisioning** | Not stated |

### 4.5 Is a DLT-style or BTRC registration required?

- **No India-style DLT.** Bangladesh has no real-time template-scrubbing registry; message content is
  not checked against pre-registered templates. Source:
  <https://www.telerivet.com/blog/bangladesh-sms-compliance-btrc-masking> — **third-party,
  [UNVERIFIED] against a BTRC document.** A primary BTRC regulation page could not be retrieved.
- **What *is* required in practice:**
  1. **Route through a BTRC-enlisted A2P aggregator.** All local vendors self-describe this way (REVE:
     "BTRC Authorized Bulk SMS Vendor"; MIM SMS tags include "BTRC Aggregator"). **As the merchant you
     do not hold the licence — your gateway does. Verify your chosen vendor's current BTRC enlistment
     yourself.** Press reports claim BTRC revoked licences of 41 A2P aggregators over expired
     certifications (<https://meatechwatch.com/?p=13609>) — **[UNVERIFIED]**.
  2. **Per-operator masking / sender-ID registration** (§4.4) — the closest BD analogue to DLT.
  3. **Promotional SMS must be in Bangla.** MIM SMS reproduces the citation on-site: **BTRC notice
     dated 03 January 2019, sharok 14.32.0000.600.36.001.19.51** — English promotional SMS can get the
     connection disconnected (<https://www.mimsms.com/document-acceptance-policy>). Alpha SMS states
     the same rule and Infobip's docs independently confirm "promotional traffic must be in Bengali".
     **Corroborated across three independent vendors.** Transactional/OTP in English is acceptable.
  4. **NDNC (National Do Not Call) registry** applies to promotional traffic — **[UNVERIFIED]**.
  5. **BTRC floor price**: BTRC reportedly directed operators to sell bulk SMS to aggregators at
     **Tk 0.19–0.23/SMS excl. VAT** (aggregators reselling ~Tk 0.30–0.34), versus as low as Tk 0.01
     previously (<https://samenacouncil.org/samena_daily_news?news=83169>) — **[UNVERIFIED]**, exact
     issue date not confirmed. This explains why no credible BD vendor quotes below ~৳0.25.

### 4.6 Laravel / PHP SMS packages (Packagist metadata read 2026-11-07)

| Package | Repo | Covers | Health |
|---|---|---|---|
| **`xenon/laravelbdsms`** | [arif98741/laravelbdsms](https://github.com/arif98741/laravelbdsms) | ~60 BD gateways incl. BulkSMSBD, Alpha, SMS.net.bd, MimSms, SSL, Infobip, REVE (via `AjuraTech`), GP, Robi, Banglalink, plus `CustomGateway` | **Best-maintained option.** 10,795 downloads, `V2.0.5.0` released **2026-10-19**, PHP ^8.0, ~152 stars. Repo caveats: `RedmoITSms` closed, `DnsBd` unimplemented (throws), `Onnorokom` is SOAP (not queueable), `Robi` never verified live. |
| **`amdadulhaq/bd-sms-laravel`** | [amdad121/bd-sms-laravel](https://github.com/amdad121/bd-sms-laravel) | BulkSMSBD, MimSMS, SSL Wireless, Alpha SMS, GreenWeb BD + custom HTTP | Newer and unproven: 114 downloads, `v2.0.1` **2026-10-17**, requires PHP ^8.3 / Laravel 12–13 |
| **`sms_net_bd/sms`** | [sms-bd/sms-bd-laravel](https://github.com/sms-bd/sms-bd-laravel) | Alpha SMS only — **linked from Alpha's own API docs**, so semi-official | 435 downloads, last release **2024-03-24** (stale) |
| `infobip/infobip-api-php-client` | Infobip | Infobip | Vendor-official |
| `twilio/sdk` + `laravel-notification-channels/twilio` | Twilio | Twilio | Vendor-official |

Usage pattern: `SMS::via(Ssl::class)->shoot('017XXYYZZAA', 'test message');` — fits cleanly behind a
custom Laravel notification channel.

### 4.7 WhatsApp Business Cloud API — pricing model changed twice

**Confirmed: Meta moved from per-conversation to PER-MESSAGE pricing.** From the official
<https://developers.facebook.com/docs/whatsapp/pricing> (read 2026-11-07):

| Date | Change |
|---|---|
| 1 Nov 2024 | Service messages became free |
| **1 Jul 2025** | **Conversation-based pricing (24-hour windows) REPLACED by per-message pricing.** Meta charges per **delivered** template message. |
| 1 Jul 2025 | Utility messages inside an open customer service window became free |
| 1 Jul 2026 | New **Meta Business Agent** category added |
| **1 Oct 2026** | **Meta RESUMED CHARGING** for service messages *and* for utility messages sent inside an open customer service window |
| **1 Oct 2026** | **Bangladesh moved OUT of the "Rest of Asia Pacific" rate group** — utility + authentication rates **decreased**, and a **new authentication-international rate band** was introduced covering BD |

**Pricing categories now:** Marketing, Utility, Authentication, Service, Meta Business Agent.
*Authentication-International* is **not a template category** — it is a higher rate band applied in
specific markets, and **Bangladesh is in it** (Meta's own calculator lists `BD` among
`authIntlMarkets`).

### 4.8 Bangladesh WhatsApp rates (USD per delivered message, observed 2026-11-07)

Pulled from Meta's own pricing-calculator endpoint behind
<https://business.whatsapp.com/products/platform-pricing>
(`https://whatsappbusiness.com/wp-json/wab/v1/pricing?market=BD&currency=USD&category=…`):

| Category | List rate | Volume tiers (down to) | ≈BDT @৳122/USD |
|---|---|---|---|
| **Marketing** | **$0.0732** | flat, no tiers | ≈৳8.93 |
| **Utility** | **$0.0037** | → $0.0028 above 50M/mo | ≈**৳0.45** |
| **Authentication** | **$0.0037** | → $0.0028 above 20M/mo | ≈৳0.45 |
| **Service** | **$0.0037** | flat | ≈৳0.45 |
| **Authentication-International** | **$0.0960** | → $0.0720 above 20M/mo | ≈**৳11.71** |

These resolve conflicts in third-party blogs: the widely-republished **$0.08418 marketing rate is
wrong** (it is a reseller markup on Meta's $0.0732), and "**$0.0043 utility**" is also wrong — it is
**$0.0037**.

**Headline finding for the store:** a WhatsApp **utility** message to a BD customer costs **≈৳0.45** —
about the same as or cheaper than a single masking SMS (৳0.48–0.64), and **2–3× cheaper than a
multi-segment Bangla SMS**, with no 70-character limit, plus buttons and read receipts.
**WhatsApp marketing at ≈৳8.93 is ~15× an SMS and should be avoided.**

⚠️ **Authentication-International trap:** at $0.096 (≈৳11.71) it is **26× the domestic authentication
rate**, and Bangladesh is on that list. **Confirm with Meta/your BSP exactly when the international
band applies to your traffic before putting OTP on WhatsApp.**

### 4.9 Free tiers — mostly gone

From <https://developers.facebook.com/docs/whatsapp/pricing> (official, read 2026-11-07):
- **Utility messages inside an open customer service window are NO LONGER free** (free 1 Jul 2025 →
  charged again from **1 Oct 2026**).
- **Service messages are NO LONGER free**, but Meta gives **1,000 free service messages per month per
  business phone number**, with no rollover.
- **Free entry point**: messages of any category are not charged for **7 days** after a user messages
  you from a Click-to-WhatsApp ad (Meta Business Agent messages are still charged).
- **User → business messages are never charged.**

⚠️ **Stale official page warning:** the marketing site
<https://business.whatsapp.com/products/platform-pricing> still claims service messages are not
charged, utility replies are not charged, and the free entry point is "72 hours". The developer docs
say service/utility-in-window are charged since 1 Oct 2026 and the entry point is **7 days**.
**Trust developers.facebook.com over the marketing page.**

### 4.10 WhatsApp — what it takes to go live

From <https://developers.facebook.com/docs/whatsapp/overview> and `/cloud-api/get-started`:
1. **Business portfolio** (Meta Business account) — mandatory.
2. **WhatsApp account** (holds numbers, display name, partner access).
3. **Messaging account** (templates, billing, payment method).
4. **Meta app** with the WhatsApp use case, linked to the portfolio.
5. **System user + long-lived token** with `business_management`,
   `whatsapp_business_messaging`, `whatsapp_business_management`. The dashboard's temporary token
   "expires quickly and is not suitable for development purposes."
6. **Business verification** — *not strictly required to send*, but it is one of three ways to lift
   the messaging limit from 250 to 2,000/24h, and it unlocks Official Business Account status,
   20 phone numbers (vs 2) and 6,000 templates (vs 250).

**Phone number rules** (<https://developers.facebook.com/docs/whatsapp/cloud-api/phone-numbers>):
- **A number already on WhatsApp CANNOT be registered as-is** — "Numbers already in use with WhatsApp
  cannot be registered unless they are deleted first."
- Two paths: **(a) delete & register** — delete the account in the WhatsApp app, wait up to 3 minutes;
  **message history is lost**. **(b) partner onboarding** via a BSP that supports business-app
  onboarding — **history is preserved** and the WhatsApp Business app can run concurrently.
  **If the store already runs a WhatsApp Business number with customer history, use path (b).**
- You must own the number; short codes unsupported; must receive SMS or voice verification.
- Adding a number in the dashboard **does not register it** — you must call
  `POST /<PHONE_NUMBER_ID>/register` with a two-step verification PIN. Status must be `connected`.
- Caps: **2 registered numbers** for a new portfolio → **20** once verified.

**Display name review** (<https://developers.facebook.com/documentation/business-messaging/whatsapp/display-names>):
required at registration; `name_status` values `APPROVED`, `AVAILABLE_WITHOUT_REVIEW`,
`PENDING_REVIEW`, `DECLINED`, `EXPIRED`, `NONE` (the last two cannot register a number). Webhook
`phone_number_name_update` carries the decision. **10 name changes per 30 days**; after an approved
change you have **14 days to re-register** the number. **Meta publishes no official review timeline** —
BSP claims of 24h / 1–3 business days are **[UNVERIFIED]**.

**Messaging limits** (<https://developers.facebook.com/docs/whatsapp/messaging-limits>):
tiers **250 → 2,000 → 10,000 → 100,000 → unlimited** unique users per rolling 24h. New portfolios start
at **250**. Throughput 80 msg/s per number; **1 message per 6 seconds to the same user** (bursts to 45).

### 4.11 WhatsApp templates

From <https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates>:
- **There are only three template categories: `authentication`, `marketing`, `utility`.**
  **There is no `service` template category** — *Service* and *Meta Business Agent* exist only as
  **pricing** categories (free-form replies inside a customer service window). This is the single most
  common error in third-party blogs.
- Created via the Message Templates API or WhatsApp Manager. **Review is automatic on create and on
  edit**; WhatsApp Manager states review "can take up to 24 hours". Only `APPROVED` templates can send.
- Statuses include In-Review, Rejected, Active (Quality pending/High/Medium/Low), **Paused** and
  **Disabled** (the last two cannot send). Changes arrive via `message_template_status_update`.
- Limits: 100 template creations per messaging account per hour; **250 templates** unverified,
  **6,000** verified. Templates idle ≥12 months are archived then deleted after 28 days.
- Opt-in is required; **templates are the only message type allowed outside a customer service window**.

### 4.12 WhatsApp Laravel/PHP integration

**Meta publishes no official PHP or Laravel SDK for Cloud API.**

| Option | Notes |
|---|---|
| **`netflie/whatsapp-cloud-api`** ([repo](https://github.com/netflie/whatsapp-cloud-api)) | De facto standard: 787,601 downloads, **4.0.0 released 2026-04-14**, PHP ≥8.1. Framework-agnostic plain PHP — **no Laravel service provider or facade**, so bind it yourself and wrap in a custom notification channel. |
| Forks (`milan/`, `jackie-source/`, `codexten/`) | Derivatives, not official — pin `netflie/whatsapp-cloud-api` exactly. |
| **Raw Graph API via `Http::`** | Entirely reasonable: Cloud API is a handful of JSON POSTs to `https://graph.facebook.com/v*/<PHONE_NUMBER_ID>/messages`. Avoids a dependency that lags Meta's versioned API. |
| Webhooks | Verify the `hub.verify_token` challenge and validate **`X-Hub-Signature-256`**; subscribe to `messages`, `message_template_status_update`, `account_alerts`, `phone_number_name_update` (<https://developers.facebook.com/docs/whatsapp/cloud-api/guides/set-up-webhooks>). |

### 4.13 Recommendation for Toy Mart BD

| Use case | Recommendation | Unit cost |
|---|---|---|
| OTP / login | Non-masking or OTP SMS (instant, no opt-in, works on every handset) | ৳0.25–0.40 |
| Order / shipping / payment notifications | **WhatsApp utility template** as primary (≈৳0.45, no Bangla segment penalty, buttons + read receipts), **masking SMS as fallback** when the number is not on WhatsApp | ৳0.45 / ৳0.48–0.64 |
| Promotions | **Masking SMS in Bangla only** (BTRC 2019 notice). Avoid WhatsApp marketing (≈৳8.93, ~15× SMS). | ৳0.48–0.64 |
| Gateway pick | **MIM SMS** (cheapest published: ৳0.25 non-mask / ৳0.48 mask; notably flexible KYC accepting DBID or a bKash/Nagad merchant certificate if no trade licence yet) **or Alpha SMS / sms.bd** (best public API docs, semi-official Laravel package, explicit 70-char Bangla documentation). **SSL Wireless** if the hardened AES+HMAC OTP endpoint matters. **Do not use Twilio for BD SMS.** | — |
| Laravel wiring | `xenon/laravelbdsms` behind a custom notification channel + `netflie/whatsapp-cloud-api` or raw `Http::` for WhatsApp | — |

**COD-specific operational note:** because COD dominates (§2), the single highest-ROI notification is a
**pre-dispatch confirmation** ("reply YES to confirm your order") sent *before* the parcel is handed to
the courier. On WhatsApp utility at ≈৳0.45 with reply buttons, that costs a fraction of one failed-COD
return charge (§3), which makes it the cheapest lever available against the return rate.

---

## 5. Address Data

### 5.1 The canonical open dataset: `nuhil/bangladesh-geocode`

<https://github.com/nuhil/bangladesh-geocode> — verified via the GitHub API: **725 stars, 479 forks,
MIT, created 2014-05-12, last pushed 2023-03-17, 17 open issues, not archived.**

Formats: for each of `divisions/`, `districts/`, `upazilas/`, `unions/` there is a `.csv`, `.json`,
`.php`, `.sql`, `.xml`, plus `geojson/districts.geojson` (4.6 MB).

**Record counts (JSON downloaded and counted directly):** divisions **8**, districts **64**,
upazilas **494**, unions **4,540**. Bangla names: **yes** — every row has `bn_name`. Districts also
carry `lat`/`lon` (DC office) and a `.gov.bd` URL.

Sample rows: `{"id":"1","name":"Chattagram","bn_name":"চট্টগ্রাম","url":"www.chittagongdiv.gov.bd"}`,
`{"id":"1","division_id":"1","name":"Comilla","bn_name":"কুমিল্লা",...}`

**Postcodes: NOT included.** The README lists "Postal Code, Area, Population" under *"Upcoming
columns"* — still unimplemented three years later.

**Maintenance verdict: effectively dormant.** No commits since March 2023, 17 open issues,
**outdated spellings** ("Comilla" not Cumilla, "Chattagram" not Chattogram), and 494 upazilas rather
than the current 495. **This repo is the upstream ancestor of almost every other BD geo package** —
nearly all of them are reskins with the same ids, the same `bn_name` keys and the same 4,540-union count.
**Do not build on it directly despite the star count.**

### 5.2 RECOMMENDED for Laravel: `devfaysal/laravel-bangladesh-geocode`

- GitHub: <https://github.com/devfaysal/laravel-bangladesh-geocode> · Packagist:
  <https://packagist.org/packages/devfaysal/laravel-bangladesh-geocode>
- Verified: **76 stars, MIT, last pushed 2026-11-01** (six days before observation); **13,815 total
  downloads**, 77 favers.
- **Bundled record counts** (from the README's own table): Divisions **8**, Districts **64**,
  Upazilas **495**, Unions **4,548**, Pourashavas **330** (optional command, also into `unions`),
  **Post offices 1,357** (`post_offices` table).
- Bangla names: **yes** (`bn_name` on all models).
- Install: `composer require devfaysal/laravel-bangladesh-geocode` then
  `php artisan BangladeshGeocode:setup` (publishes migrations, migrates, seeds all five datasets).
  Also `BangladeshGeocode:addnew` (idempotent upsert) and `BangladeshGeocode:addpourashava`.
- Eloquent models under `Devfaysal\BangladeshGeocode\Models`: `Division`, `District`, `Upazila`,
  `Union`, `PostOffice`, with relationships for cascading dropdowns. Ships a cascading-dropdown doc.
- Requires Laravel 12.69+/PHP 8.2 or Laravel 13.30+/PHP 8.3. CI tests SQLite + MySQL 8.4.
- **Two caveats from its own README:** the bundled post offices *"cover all 64 districts but are not a
  complete directory of every post office"*; and **pourashavas land in the same `unions` table with no
  type column**, so union queries will include municipalities if you run that command.

**Why this one wins:** it is the only well-maintained option bundling **all four admin levels plus
post offices in one package**, MIT, with real migrations/models/seeders rather than loose JSON, and
updated days before this research.

### 5.3 RECOMMENDED for the JS/TS front end: `ifahimreza/bangladesh-geojson`

- GitHub <https://github.com/ifahimreza/bangladesh-geojson> · npm
  <https://www.npmjs.com/package/bangladesh-geojson> (v1.0.0, 2026-10-14) · demo <https://geojson.bd>
- Verified: **332 stars, last pushed 2026-05-07.**
- Files under `src/data/`: `bd-divisions.json` (8), `bd-districts.json` (64), `bd-upazilas.json`
  (**494**), `bd-postcodes.json` (**1,349 records, counted directly**), `bangladesh.geojson` (3.6 MB),
  `dhaka-city.json`, plus `bd-bazars.geojson` and `bd-major-routes.geojson`.
- Postcode record shape:
  `{"division_id":"1","district_id":"34","upazila":"Amtali","postOffice":"Amtali","postCode":"8710"}`
- Bangla names: **yes**; `search()` matches both English and বাংলা.
- API: `getDivisions()`, `getDistrictsByDivision()`, `getUpazilasByDistrict()`, `getPostcodes()`,
  `getPostcodesByDistrict()`, `getFullHierarchy()`, `search()`.
- **⚠️ Licence is split — read before shipping.** Code MIT; `bangladesh.geojson` is **CC BY 4.0**
  (from geoBoundaries gbOpen ADM3, underlying authority BBS/OCHA); `bd-bazars.geojson` and
  `bd-major-routes.geojson` are **ODbL 1.0** (OSM-derived — requires attribution **and share-alike on
  derived databases**); the plain admin JSON + postcodes are MIT. **For a checkout form you only need
  the MIT admin/postcode files — do not bundle the ODbL layers.**

### 5.4 Other verified options

| Repo / package | Verified metadata | Contents | Bangla | Licence | Maintained |
|---|---|---|---|---|---|
| [techno-stupid/places-in-bangladesh](https://github.com/techno-stupid/places-in-bangladesh) | 22★, pushed 2023-09-04 | nuhil's 4 levels + Go structs | yes | MIT | no |
| [sandofvega/laravel-bangladesh-geocode](https://github.com/sandofvega/laravel-bangladesh-geocode) | 23★, pushed 2025-11-04 | 8 / 64 / **491** thana / **4,541** union | yes | MIT | sporadic |
| [lemonpatwari/laravel-bangladesh-geocode](https://github.com/lemonpatwari/laravel-bangladesh-geocode) | 12★, pushed 2026-03-05; 663 downloads | div/dist/upazila/union | yes | MIT | light |
| [rajuAhmed1705/bangladesh-address](https://github.com/rajuAhmed1705/bangladesh-address) + npm `@bangladeshi/bangladesh-address` (v2.1.0, 2025-12-21) | 19★ | div/dist/upazila/thana; **ships ready-made MySQL, PostgreSQL, SQLite and MongoDB dumps** under `db/` | yes | MIT | yes |
| [open-admin-data/bangladesh-administrative-divisions](https://github.com/open-admin-data/bangladesh-administrative-divisions) | 3★, pushed 2026-09-08 | 8/64/**495**; `all-flat.csv`, `all-flat.ndjson`, `hierarchy.json`, `schema.json`, `CITATION.cff`. Well engineered, low adoption | bilingual | NOASSERTION — check `LICENSE` | yes |
| [iamtashanto/bd-geo-info](https://github.com/iamtashanto/bd-geo-info) | 3★, pushed **2026-11-06** | TS lib incl. `bd-postcodes.json` + ready React `DivisionSelect`/`DistrictSelect`/`UpazilaSelect`/`UnionSelect`/`AddressForm` | yes | MIT | yes, but tiny |
| [DarkAsfu/bd-geodata](https://github.com/DarkAsfu/bd-geodata) + npm `bd-geodata` (v1.0.7, 2024-10-12) | 2★ | div/dist/upazila/union/area | — | **none declared** | no |
| npm `bd-geojson` (v1.0.5, 2026-06-22) | — | fork/reskin of bangladesh-geojson | yes | MIT | marginal |
| [kejubayer/bd-address-manager](https://github.com/kejubayer/bd-address-manager) | **0★, 0 downloads, no licence** | Laravel address manager | — | none | **do not use** |

**Note:** there is **no notable repo named "bangladesh-divisions-districts-upazilas"**. The closest real
ones are npm `bangladesh-districts-upazilas` (v1.0.0, 2025-09-02, one release only) and
[jummania/Bangladesh-Divisions-Districts-and-Upazilas-Information](https://github.com/jummania/Bangladesh-Divisions-Districts-and-Upazilas-Information)
(13★, pushed 2024-01-21, Apache-2.0, JSON only).

### 5.5 Official / government sources

**`data.gov.bd` is NOT useful for address dropdowns — verified.** It is a **Drupal** site, not CKAN
(`https://data.gov.bd/api/3/action/package_list` returns junk `{"result":["l",null]}`; `?q=` searches
404). The dataset list at <https://data.gov.bd/dataset> contains BBS survey/census *reports*
("Poverty Monitoring Survey 1999", "Slum Census 1997", "Labour Force Survey 2002-03", inflation data).
**No administrative-geography or geocode dataset. Rule it out.**

**BEST OFFICIAL REFERENCE — OCHA/BBS COD-AB** (verified via the HDX CKAN API):
- <https://data.humdata.org/dataset/cod-ab-bgd> (dataset id `401d3fae-4262-48c9-891f-461fd776d49b`)
- "Bangladesh - Subnational Administrative Boundaries"; org **OCHA Field Information Services Section**;
  licence **CC BY-IGO**; `last_modified` **2026-01-26**; reviewed for accuracy 2025-06-01.
- Official structure, quoted from the dataset notes: *"Admin 1: 8 Division; Admin 2: 64 District;
  **Admin 3: 507 Upazila** … the normal administrative level 3 feature type(s) are **Upazila (495
  features) and City Corporation (12 units)**."*
- **This resolves the 494 / 495 / 507 confusion: 495 upazilas + 12 city corporations = 507 ADM3 units.**
- Resources: `bgd_admin_boundaries.xlsx` at
  <https://data.humdata.org/dataset/401d3fae-4262-48c9-891f-461fd776d49b/resource/5f7e534d-9b06-442e-a706-083789bff2c9/download/bgd_admin_boundaries.xlsx>,
  plus `.shp.zip`, `.geojson.zip`, `.gdb.zip` under the same dataset id. **Use the XLSX as your
  reconciliation reference and your source of official P-codes.**

**BBS itself** (<https://bbs.gov.bd>) has been rebuilt onto a `/pages/static-pages/<hash>` CMS and no
working direct geocode download could be found. The old pointer
`http://www.bbs.gov.bd/PageWebMenuContent.aspx?MenuKey=150` is **dead [UNVERIFIED]**, and
`https://app.dghs.gov.bd/bbscode/pages/geohierarchy` (a DGHS-hosted BBS geocode browser that appeared
in search) returned **404 [UNVERIFIED]**. **LGD** (<https://lgd.gov.bd>) is on the same new CMS with no
union/upazila download.

### 5.6 Bangladesh postal codes

**Official source — Bangladesh Post Office (Directorate of Posts):**
- Post code browser: **<https://bdpost.gov.bd/views/post-code/>** (`/site/view/post_code` 302-redirects here)
- Official postcode **search** tool: **<https://ekdak.com/>** — linked from bdpost.gov.bd as
  "পোস্টকোড অনুসন্ধান"
- **There is no bulk download, CSV, XLS or public API.** The page source was checked for a JSON
  endpoint behind its `PostCodeViewWidget` and none exists. Its **TLS chain is also broken** (automated
  fetch fails on "unable to verify the first certificate"; `curl -k` works), so even scraping it is
  awkward. **Treat "official bulk postcode list" as not existing.**

**Practical postcode datasets (all community-compiled):**

| Source | Count | Notes |
|---|---|---|
| `devfaysal/laravel-bangladesh-geocode` `post_offices` | **1,357** | best-maintained; seeded + refreshable |
| `ifahimreza/bangladesh-geojson` `bd-postcodes.json` | **1,349** | maps to division/district/upazila/postOffice/postCode |
| [saaiful/postcode-bd](https://github.com/saaiful/postcode-bd) | **1,355** rows in `postcode_en.csv` | also `postcode_bn.csv`, `postcode.json`, `bdpostcode.php`. **Licence GPL-2.0**, README states *"Data Source: Wikipedia"*. 34★ but **last pushed 2020-04-05 — stale** |
| [aiFdn/Postcodes-of-Bangladesh](https://github.com/aiFdn/Postcodes-of-Bangladesh) | 6★, 2017 | MIT, abandoned |
| [TanjidulIslamToha/bangladesh-postal-codes-database](https://github.com/TanjidulIslamToha/bangladesh-postal-codes-database) | 2★, pushed 2026-11-03 | MIT, new/unproven |

Background: <https://en.wikipedia.org/wiki/Postal_codes_in_Bangladesh> — 4-digit codes; BPO has
appended "00" to all codes with the 5th/6th digits currently optional; blocks per district (Dhaka
1000–1399, Chattogram 4000–4399, Rajshahi 6000–6399, Khulna 9000–9399); Dhaka GPO 1000.

### 5.7 Pathao's city/zone/area hierarchy vs postal geography — **the mismatch warning**

Pathao's endpoints are documented in §3.4. Everything is **numeric IDs**; Pathao's own plugin casts
them in a `$numericKeys` list (`store_id`, `recipient_city`, `recipient_zone`, `recipient_area`).

**Concrete scale of the mismatch.** The npm package `@classytic/bd-areas` bundles cached provider
tables; its README reports for `@classytic/bd-areas/pathao`: **64 Pathao cities and 1,258 Pathao
zones**, against its unified **8 divisions / 64 districts / 2,836 thana-level areas**
(<https://cdn.jsdelivr.net/npm/@classytic/bd-areas@1.3.0/README.md>). *Secondary source — a cached
third-party snapshot, not Pathao's live API. The order of magnitude is the point: ~1,258 zones is
roughly 2.5× the 495 upazilas.*

1. **Pathao "city" is not a district — but at 64 it is deceptively close.** The matching count invites
   a naive 1:1 join. **Don't.** Pathao's city list is a **commercial coverage list**, renamed and
   re-scoped as operations change, not the BBS zila list. Pathao markets coverage of "all 64 districts"
   (<https://pathao.com/?p=7746>) while Wikipedia still says "50+ cities"
   (<https://en.wikipedia.org/wiki/Pathao>) — different concepts that happen to collide numerically.
   **A name-based join will silently mis-route** on the renamed districts
   (Cumilla/Comilla, Chattogram/Chittagong, Bogura/Bogra, Jashore/Jessore, Barishal/Barisal) and on any
   city Pathao has split or merged.
2. **Pathao "zone" has no counterpart in the official hierarchy at all.** With ~1,258 zones across
   64 cities (~20 per city), zones sit *below* upazila in Dhaka and *across* upazila elsewhere. They
   are **operational delivery/landmark clusters** — "Uttara Sector 12", "Dhanmondi", "Mirpur 10".
   `@classytic/bd-areas` says it outright: *"Pathao zones are landmark or neighborhood level, so they
   don't map one-to-one to the thana-level unified areas."* Its own `convertProviderId()` returns
   **mostly null** for Pathao.
3. **Pathao "area" is street/sector granularity** ("Sector 12 Road 3") — absent from any government dataset.
4. **The mapping is many-to-many in both directions.** One upazila (e.g. Savar) spans several Pathao
   zones; one peri-urban zone can straddle two upazilas. **There is no stable shared key** — no P-code,
   no postcode. The only available join is fuzzy name matching on city, which is exactly where the
   renamed districts break it.
5. **Pathao's IDs are opaque and mutable.** Zones are added, merged and retired as the hub network
   changes; nothing is versioned and there is no changelog. **A zone_id cached a year ago may no longer
   price or route correctly.**
6. **Pathao's own plugin dodges the problem entirely.** Rather than deriving IDs from the WooCommerce
   address, it stores them as **separate order meta** (`_shipping_pathao_city/_zone/_area`) populated by
   a dedicated JS picker with `wp_ajax_get_zones`/`wp_ajax_get_areas` handlers. **Pathao's own engineers
   treat the courier hierarchy as a parallel, independently-captured dataset.** That is the strongest
   possible signal for how to model it.

### 5.8 Recommended database model

**Store postal geography and courier zone as two independent facts on the address. Never derive one
from the other at order time.**

```
addresses
  id
  division_id, district_id, upazila_id   -- FK to geocode tables (system of record)
  union_id            NULL               -- optional
  postcode            NULL               -- optional, free text
  address_line        TEXT NOT NULL      -- house/road/landmark; this is what Steadfast gets
  recipient_phone
  -- resolved courier zone, captured SEPARATELY:
  pathao_city_id      INT NULL
  pathao_zone_id      INT NULL
  pathao_area_id      INT NULL
  pathao_resolved_at  TIMESTAMP NULL
  pathao_resolved_by  ENUM('mapping','customer','admin') NULL

courier_zone_map                         -- your own mapping table, your own data
  id
  courier          ENUM('pathao','redx',...)
  upazila_id       FK                    -- or (district_id, postcode) for coarser rows
  courier_city_id  INT
  courier_zone_id  INT
  confidence       ENUM('exact','likely','fallback')
  verified_at
  UNIQUE (courier, upazila_id)

courier_cities / courier_zones / courier_areas   -- nightly mirror of Pathao's lists
  courier, remote_id, name, parent_remote_id, last_seen_at, is_active
```

**Resolution order at checkout:**
1. **Customer picks the Pathao zone directly** when the cart is Pathao-bound — render the real
   `city → zone → area` cascade from your mirrored tables (exactly what Pathao's own plugin does).
   Highest confidence, costs one extra dropdown.
2. To skip that dropdown, **pre-fill from `courier_zone_map`** on the chosen upazila/postcode and let
   the customer confirm or change it. Record `pathao_resolved_by`.
3. **Fall back to manual selection in admin** when no mapping row exists — flag the order
   `needs_zone`, **never guess**. A wrong zone_id means a mis-priced and mis-routed parcel, which costs
   far more than one unresolved order sitting in a queue.
4. **Never auto-create a Pathao order from an unresolved address.** Block it and surface it.

**Operational rules:**
- **Mirror `/zones/list` and `/areas/list` nightly** (they exist precisely for bulk sync) with a
  `last_seen_at`. A zone that stops appearing is retired — mark it inactive and **re-flag every open
  address pointing at it.** Keep historical IDs; don't delete.
- **Always send `recipient_address` free text as well as the IDs.** Pathao's own payload carries both;
  the rider reads the text.
- **Quote shipping from `/merchant/price-plan`** using the resolved city + zone, not a local tariff
  table. If the zone is unresolved you cannot quote — fall back to a flat estimate and say so.
- **Build `courier_zone_map` incrementally from real orders.** Seed your top-selling upazilas, then
  promote `fallback` → `verified` as deliveries succeed. **Don't try to map all 495 upazilas up front.**
- Keep the map **per-courier**; RedX and others have their own incompatible area trees.

### 5.9 Steadfast needs no zone IDs — verified definitively

From Steadfast's **official** Postman collection ("SteadFast API Documentation", `_postman_id`
`b85bd92e-855b-44f5-a959-8f1a3a5ed1f4`, 618 KB; raw JSON at
<https://documenter.gw.postman.com/api/collections/26211192/2sAYJ1mhpk?segregateAuth=true&versionTag=latest>):
**the `create_order` body contains no `city`, `zone`, `area`, `district`, `upazila` or `postcode`
field. The destination is entirely the free-text `recipient_address`.**

**One trap:** Steadfast *does* have `GET /police_stations` and a `police_station_id`, but these appear
**only in `create_pickup_request`** — that is for **your own pickup location**, not the recipient.
Don't mistake it for a destination-zone requirement.

**Design consequence — this asymmetry is itself the argument for §5.8's schema.** The postal-geography
columns serve Steadfast and your own records directly; the `pathao_*` columns are a courier-specific
annotation you populate only when Pathao is in play. **Modelling Pathao's tree as your canonical
address would needlessly break Steadfast.**

### 5.10 Address-data recommendation

1. `composer require devfaysal/laravel-bangladesh-geocode` + `php artisan BangladeshGeocode:setup`
   as the **system of record** (8 / 64 / 495 / 4,548 + 1,357 post offices, EN + BN, MIT, updated
   2026-11-01).
2. **Do not** run `BangladeshGeocode:addpourashava` unless you want 330 municipalities mixed into
   `unions` with no distinguishing column.
3. **Download `bgd_admin_boundaries.xlsx` from HDX COD-AB and diff it once** against the seeded tables.
   Fix spellings to current official forms (Cumilla, Chattogram, Bogura, Jashore, Barishal) and
   **store the official P-code in a nullable column** — it is your stable join key for any future
   dataset swap.
4. **Make postcode an optional free-text field with a lookup assist, not a required dropdown.** The
   1,349–1,357 figure is a post-office count, not a complete coverage map, and there is no official
   bulk list to validate against. Suggest a postcode from the selected upazila; let the customer override.
5. For a client-side cascade without round-trips, add npm `bangladesh-geojson` — **MIT admin + postcode
   files only, omitting the ODbL bazar/route GeoJSONs.**

---

## 6. Phone Validation

### 6.1 Currently-valid mobile prefixes — verified, with corrections

**Authoritative statement of the current allocation**, from BTRC's own numbering-review committee as
reported by The Daily Star, 4–5 December 2025:
<https://www.thedailystar.net/business/news/btrc-introduce-separate-sim-series-iot-m2m-devices-4050931>

| 01X prefix | Operator | Status |
|---|---|---|
| **013** | **Grameenphone** | live |
| **014** | **Banglalink** | live |
| **015** | **Teletalk** (state-owned) | live |
| **016** | **Robi Axiata** (ex-Airtel; retail brand now **Cirkle**) | live |
| **017** | **Grameenphone** | live |
| **018** | **Robi Axiata** | live |
| **019** | **Banglalink** | live |
| 010 | — | **unused**, reserved for future mobile |
| 011 | (was Citycell) | **dead** — operator defunct; reserved |
| 012 | — | **unused**, reserved for future mobile |

The article states the holders exactly as: *Grameenphone 017, 013; Banglalink 019, 014; Robi 018, 016;
Teletalk 015*, and that *"010, 011 and 012 are unused and kept for future mobile needs."*

**Independently corroborated by Google's libphonenumber metadata** (primary, actively maintained) —
<https://github.com/google/libphonenumber/blob/master/resources/metadata/880/ranges.csv>:
```
11      ; 10 ; MOBILE ; STANDARD_RATE ; "citycell"     ; ITU
1[37]   ; 10 ; MOBILE ; STANDARD_RATE ; "grameenphone" ; ITU
1[49]   ; 10 ; MOBILE ; STANDARD_RATE ; "banglalink"   ; IR21
15      ; 10 ; MOBILE ; STANDARD_RATE ; "teletalk"     ; ITU
16      ; 10 ; MOBILE ; STANDARD_RATE ; "robi"         ; IR21
18      ; 10 ; MOBILE ; STANDARD_RATE ; "robi"         ; ITU
64      ; 10 ; MOBILE ; STANDARD_RATE ; "grameenphone" ; IR21
```
Its `operators.csv` lists `banglalink, citycell, grameenphone, robi, teletalk` — **note there is no
"airtel" entry.** libphonenumber assigns **016 to `robi`**, with provenance **IR21** (the GSMA
inter-operator roaming document, i.e. sourced from the operator itself).

**Corrections worth recording:**
- **"Grameenblue" is not a thing.** The operator is **Grameenphone**, prefixes **017 and 013**.
  Wikipedia notes 013 carries the **Skito** digital sub-brand
  (<https://en.wikipedia.org/wiki/Telephone_numbers_in_Bangladesh>) — *lightly verified, no bearing on
  validation.*
- **Treating "Robi (018, 016)" and "Airtel (016)" as two operators is wrong today.** There is **one**
  licensee. Robi and Airtel Bangladesh signed their merger 28 Jan 2016
  (<https://www.thedailystar.net/business/robi-airtel-ink-merger-deal-209128>), BTRC approved it
  (<https://www.thedailystar.net/business/btrc-gives-nod-robi-airtel-merger-1043584>), and it took
  effect **16 November 2016** (<https://www.thedailystar.net/node/1315822>). BTRC originally required
  016 to be retired and migrated to 018, then allowed it to continue
  (<https://www.dhakatribune.com/business/2644/after-merger-robi-can-sell-airtel-s-number-for-two>).
- **016 is still live and has outlived the Airtel brand.** On **17 August 2026** Robi Axiata **retired
  the Airtel brand and replaced it with "Cirkle"**, with existing 016 numbers, services, packages,
  balances and MFS accounts unchanged
  (<https://bangladeshmonitor.com.bd/en/robi-replaces-airtel-brand-with-cirkle-in-bangladesh>,
  <https://developingtelecoms.com/telecom-technology/60-consumer-ecosystems/20699-robi-axiata-drops-airtel-youth-brand-renames-it-cirkle.html>).
  **Practical upshot: accept 016, and label it "Robi" or "Cirkle" in any UI — never "Airtel".**

**Newly allocated prefixes: the 06 series, for IoT/M2M only** (BTRC committee recommendation, Dec 2025,
same Daily Star article): **061 Banglalink, 062 Teletalk, 063 Robi, 064 Grameenphone**; 060 and 065–069
reserved. 01X remains reserved for human mobile subscribers. Corroboration that this is already in
operator-sourced data: libphonenumber's `ranges.csv` carries `64 ; 10 ; MOBILE ; grameenphone ; IR21`,
i.e. **`064XXXXXXXX` is already a live GP mobile range**.
**For a checkout form, reject the 06 series** — these are machine SIMs; no human will take your
delivery call or OTP on one. The regexes below exclude them by construction.

**No new 01X prefix has been allocated**; 010/011/012 remained unassigned as of the Dec 2025 review.
*Whether BTRC has formally adopted the committee's 06 recommendation since then: **[UNVERIFIED]**.*

**On BTRC as a source:** <https://btrc.gov.bd> is live but rebuilt; `/en/site/page/Mobile-Phone-Subscribers`,
`/en/licensee-lists` and `/en/site/page/Telecom-and-Internet-Subscribers` all returned **404**, and the
homepage nav surfaces no numbering-plan page. libphonenumber's own `comments.csv` cites the generic
`https://btrc.gov.bd/pages/static-pages` as its BD source. **No primary BTRC numbering-plan document
could be retrieved [UNVERIFIED]**; the Daily Star report of the BTRC committee plus libphonenumber's
IR21/ITU-sourced metadata are the strongest available, and they agree.

### 6.2 Regexes — exact PCRE strings, tested

All patterns below were run through a PHP test harness: **0 failures across 41 positive and negative
cases.**

**(a) 11-digit local format**
```
/^01[3-9]\d{8}$/
```
- `^`…`$` anchor both ends — without them `preg_match` finds the pattern *inside* a longer string and
  `"junk01712345678junk"` passes.
- `01` = national trunk prefix plus the mobile service digit.
- `[3-9]` = the operator digit. **This single character class is the whole validation story:** it
  admits 013–019 and **excludes 010, 011, 012.**
- `\d{8}` = exactly 8 subscriber digits → 11 characters total.

Accepts `01712345678`, `01312345678`, `01812345678`, `01612345678`, `01912345678`, `01412345678`,
`01512345678`. Rejects `01012345678`, `01112345678`, `01212345678`, `0171234567` (10),
`017123456789` (12), `8801712345678`, `+8801712345678`, `01712-345678`, `017 12345678`.

**(b) +880 international form**
```
/^\+8801[3-9]\d{8}$/
```
- `\+` is a **literal** `+`; the backslash is required — a bare `+` is the one-or-more quantifier.
- `880` country code, then `1[3-9]` and `\d{8}` → 14 characters total.

Accepts `+8801712345678`, `+8801612345678`. Rejects `+8801112345678`, `8801712345678` (no `+`),
`01712345678`, **`+88001712345678`** (the classic error — trunk `0` wrongly kept after the country
code), `+880171234567`.

**(c) Combined permissive — use this one at the form boundary**
```
/^(?:\+?880|0)1[3-9]\d{8}$/
```
- `(?:…)` is a **non-capturing** group — keeps `$matches` clean and is marginally faster.
- `\+?880` — optional `+`, so both `+8801…` and `8801…` pass.
- `|0` — or the bare trunk zero for the local form.
- The alternation deliberately offers **no `8800…` branch**, which is what rejects the double-prefix mistake.

Accepts `01712345678`, `8801712345678`, `+8801712345678`, `+8801412345678`, `01512345678`,
`8801612345678`. Rejects `01112345678`, `01012345678`, `01212345678`, `88001712345678`,
`+88001712345678`, `1712345678`, `+8801712345`, `0088801712345678`, `+880171234567`,
`880171234567890`, `+8801012345678`.

**(d) Normaliser — capture the 10-digit national significant number**
```
/^(?:\+?88)?0?(1[3-9]\d{8})$/
```
`01712345678` → `1712345678`; `8801712345678` → `1712345678`; `+8801712345678` → `1712345678`.
Store `'0' . $m[1]` (→ `01712345678`) or `'+880' . $m[1]` (→ `+8801712345678`) so one customer has one
row regardless of how they typed it.
**Caveat:** because both prefix groups are optional this also accepts a bare `1712345678`. Fine for a
normaliser, wrong as a validator — **validate with (c), then normalise with (d).**

**(e) Lenient input-tolerant variant (optional)**
```
/^(?:\+?880[\s-]?|0)1[3-9](?:[\s-]?\d){8}$/
```
Matches `01712-345678`, `+880 1712 345678`, `017 1234 5678`, `01712345678`; still rejects `01112345678`.
**Better approach — strip separators first, then use (c):**
```php
$digits = preg_replace('/[\s\-()]/', '', $input);
if (!preg_match('/^(?:\+?880|0)1[3-9]\d{8}$/', $digits)) { /* reject */ }
```

### 6.3 Which prefixes to reject, and why

| Reject | Reason |
|---|---|
| `010`, `012` | Never allocated; BTRC holds them in reserve (Daily Star, Dec 2025) |
| `011` | Citycell — operator defunct, no live subscribers. **Note: libphonenumber still accepts 011** (its pattern is `1[13-9]`, and `ranges.csv` keeps a `citycell` row). **Your `[3-9]` is stricter than libphonenumber here, and that is correct for a 2026 checkout form.** |
| `061`–`064`, 06x | IoT/M2M machine SIMs — no human recipient |
| `02`, `03`–`09` (non-`01`) | Landline / VoIP / toll-free, not mobile |
| `8800…`, `+8800…` | Double prefix — trunk `0` kept after the country code |

### 6.4 libphonenumber — the robust layer

**Google's own BD metadata**, from
<https://github.com/google/libphonenumber/blob/master/resources/PhoneNumberMetadata.xml>:
```xml
<territory id="BD" countryCode="880" internationalPrefix="00" nationalPrefix="0">
  <mobile>
    <possibleLengths national="10"/>
    <exampleNumber>1812345678</exampleNumber>
    <nationalNumberPattern>
      (?:1[13-9]|64)\d{8}|(?:3[78]|44|66)[02-9]\d{7}
    </nationalNumberPattern>
```
Three things follow:
- **`1[13-9]`** confirms 013–019 valid and 010/012 invalid, **but also admits 011** (legacy Citycell).
  So `isValidNumber()` will pass an `011…` number no operator can deliver to. **If you use
  libphonenumber, add your own `[3-9]` check on top.**
- **`|64`** — the new GP IoT range is already in Google's mobile pattern, independently confirming §6.1.
- `(?:3[78]|44|66)[02-9]\d{7}` — legacy non-`01` mobile ranges, marked `GOVERNMENT` provenance. These
  are `MOBILE` to libphonenumber but **not consumer mobile** — another reason to layer your own regex.
- `possibleLengths national="10"` — libphonenumber works in **national significant numbers** (10 digits,
  trunk `0` stripped), which is why pattern (d) captures exactly 10 digits. Keep storage aligned.

**PHP/Laravel packages (Packagist, verified 2026-11-07):**

| Package | Latest | Released | Downloads | Favers |
|---|---|---|---|---|
| [giggsey/libphonenumber-for-php](https://packagist.org/packages/giggsey/libphonenumber-for-php) | **9.0.43** | 2026-11-06 | **179,531,753** | 5,139 |
| [giggsey/libphonenumber-for-php-lite](https://packagist.org/packages/giggsey/libphonenumber-for-php-lite) | 9.0.43 | 2026-11-06 | 25,375,315 | 102 |
| [propaganistas/laravel-phone](https://packagist.org/packages/propaganistas/laravel-phone) | **6.1.0** | 2026-10-02 | **47,870,101** | 3,081 |

Both are **actively maintained** — the PHP port was released the day before observation, tracking
Google's upstream.

**Laravel has no built-in `phone` validation rule.** `propaganistas/laravel-phone` adds one:
```php
// string form
'phone' => 'phone:BD,mobile',

// Rule-object form (documented)
use Propaganistas\LaravelPhone\Rules\Phone;
'phone' => (new Phone)->country('BD')->type('mobile'),
```
`->type('mobile')` is the critical part — it restricts to libphonenumber's `MOBILE` type and rejects
landline/VoIP/toll-free. Installation requires a message line in `lang/*/validation.php`:
`'phone' => 'The :attribute field must be a valid number.'`. Eloquent cast for normalised storage:
```php
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;
public $casts = ['phone' => E164PhoneNumberCast::class.':BD'];   // stores +8801712345678
```
**The casts do not enforce the mobile restriction** — validate before assigning.
*The exact `'phone:BD,mobile'` string spelling is inferred from the documented separate `country`/`type`
parameters — **lightly [UNVERIFIED]**; the Rule-object form is documented.*

### 6.5 Recommendation — layer both

Regex alone cannot know that 011 is dead or that 064 is a machine SIM; libphonenumber alone is too
permissive for commerce (it accepts 011 and the legacy 3x/44/66 ranges). **Use both:**
```php
'recipient_phone' => [
    'required',
    'regex:/^(?:\+?880|0)1[3-9]\d{8}$/',          // BD consumer-mobile policy
    (new \Propaganistas\LaravelPhone\Rules\Phone) // structural validity
        ->country('BD')->type('mobile'),
],
```
Then normalise with (d) and **store E.164** (`+8801712345678`) — the format Steadfast's own official
sample uses (`"recipient_phone": "+8801309055966"`), so it passes straight through.

**Two Laravel-specific gotchas:**
- When using `regex:` **inside a pipe-delimited rule string**, a `|` in your pattern is parsed as a
  rule separator. Pattern (c) contains `|`, so **always pass rules as an array** (as above).
- **Run `trim()` before validating.** A trailing space fails every anchored pattern and is the single
  most common false rejection on mobile keyboards.

**Finally: Mobile Number Portability has been live in Bangladesh since 2018**
(<https://en.wikipedia.org/wiki/Telephone_numbers_in_Bangladesh> — subscribers switch operator while
keeping the number *including the operator code*). **So never infer the current operator from the
prefix** for routing, SMS-gateway selection or least-cost routing. The prefix tells you the number is
validly *allocated*; it no longer tells you who serves it. Use prefix→operator only for cosmetic UI
hints, if at all.

---

## 7. Legal / Compliance

> ## ⚠️ STANDING CAVEAT — READ FIRST
>
> **Nothing in this section is legal advice or a legal conclusion.** It is a list of **requirements to
> verify with a Bangladeshi chartered accountant and an advocate before launch.** Three specific
> reasons for caution:
>
> 1. **The primary text could not be read.** The official gazette PDF *is* downloadable from the
>    Ministry of Commerce, but it is typeset in a legacy **Bijoy/SutonnyMJ 8-bit encoding**, so machine
>    text extraction returns garbage. Everything below about clause content is therefore
>    **second-hand** (law firms, The Daily Star Law Desk, specialist blogs), and **those sources
>    contradict each other on at least two material points.**
> 2. **Amendment status is genuinely unclear, and the brief's premise needs correcting** — see below.
> 3. **The tax rules moved recently and secondary sources disagree.** The VAT registration threshold
>    has two mutually exclusive figures in 2026 sources (Tk 50 lakh vs Tk 3 crore). **This must be
>    settled by an accountant against the Finance Act text, not from the web.**

### 7.1 Digital Commerce Operation Guidelines — which version exists

**Correction to the research brief:** the brief assumed the guidelines "have been amended more than
once." **That could not be verified, and the premise may be a conflation.** What was actually found:

| Instrument | Date | Status | Source |
|---|---|---|---|
| **ডিজিটাল কমার্স পরিচালনা নির্দেশিকা, ২০২১** — Bangladesh Gazette, additional issue, Sunday 4 July 2021, pp. 11245–11252, 8 pages, memo ref. beginning 26.00.0000.033.52.001.05.004 | **4 July 2021** | Downloaded and verified as a real 8-page gazette extract. **Text unreadable (legacy encoding).** | [direct PDF](https://objectstorage.ap-dcc-gazipur-1.oraclecloud15.com/n/axvjbnqprylg/b/V2Ministry/o/office-mincom/2024/12/13ad70feb24d40b3b2ec98d40c7b8f82.pdf) |
| **National Digital Commerce Policy 2018 — *as amended 2020*.** **This is the thing that was amended**, and it is the parent *policy*, not the guidelines. | 2018, amended 2020 | In force | [BRCP-1/gov.bd copy](http://brcp-1.gov.bd/wp-content/uploads/2022/03/National-Digital-Commerce-Policy-2018_10-03-2022.pdf); [VDB Loi briefing](https://www.vdb-loi.com/wp-content/uploads/2021/10/Bangladesh-New-Rules-for-E-Commerce-Marketplaces-Need-to-Register-Deliver-within-5-or-10-days.pdf) |
| **ডিজিটাল বিজনেস আইডেন্টিফিকেশন (DBID) নিবন্ধন নির্দেশিকা ২০২২** — a *separate* guideline | listed 17 Oct 2022 | In force | [dbid.gov.bd, cl. 14](https://dbid.gov.bd/application-info) |

**On amendments specifically:** the Commerce Minister said in July 2021 that the guidelines "may be
amended" if necessary ([Financial Express, 7 Jul 2021](https://thefinancialexpress.com.bd/trade/digital-commerce-sop-may-be-amended-minister-1625629813)).
One law-marketing site asserts in passing that they were "issued on 4 July 2021 and later amended" but
gives no gazette, number or date ([commoner-law.com](https://commoner-law.com/bangladesh/consumer-rights/e-commerce-dispute-rights)).
Against that, three independent write-ups dated **Nov 2022**, **Jun 2023** and **Jul 2023 / updated
Oct 2024** all describe the 4 July 2021 text with **no reference to any amendment**
([Daily Star, 16 Nov 2022](https://www.thedailystar.net/law-our-rights/news/responsibilities-online-pagesstores-the-digital-commerce-operation-guidelines-3171656);
[echost.net](https://echost.net/18344/); [DeshiCommerce](https://deshicommerce.com/blog/bangladesh-digital-commerce-operation-guidelines-2021-analysis/)).
Five searches in Bangla and English across mincom.gov.bd, e-cab.net, dbid.gov.bd and the news archives
returned **no amending প্রজ্ঞাপন/gazette.**

**→ Action for the lawyer:** pull the *current consolidated* text from the Ministry of Commerce WTO
Cell / Central Digital Commerce Cell and confirm whether any সংশোধনী exists. **Do not assume the 2021
text is the final word, and do not assume it was amended twice either.** Reading: **one version found,
4 July 2021, amendment status unconfirmed.**

### 7.2 Delivery timelines — relatively well corroborated

| Scenario | Timeline reported | Sources |
|---|---|---|
| Buyer and seller in the **same city** (order paid) | **5 days** | [TBS](https://www.tbsnews.net/economy/e-commerce-companies-must-deliver-products-within-5-days-govt-270685); [Daily Star, 12 Jul 2021](https://www.thedailystar.net/law-our-rights/news/overview-the-digital-commerce-operation-guidelines-2021-2128871); [VDB Loi](https://www.vdb-loi.com/wp-content/uploads/2021/10/Bangladesh-New-Rules-for-E-Commerce-Marketplaces-Need-to-Register-Deliver-within-5-or-10-days.pdf) |
| **Different city / village / district** | **10 days** | same three |
| Daily essentials / perishables | Faster than the above, and the shorter timeline must be disclosed up front | Daily Star; DeshiCommerce |
| Hand-over to the courier after full payment | **48 hours** | [DeshiCommerce](https://deshicommerce.com/blog/bangladesh-digital-commerce-operation-guidelines-2021-analysis/); [echost](https://echost.net/18344/) |

**Unresolved:** whether the clock starts at **order acceptance** or at **payment confirmation** —
sources use both phrasings. VDB Loi also notes the guidelines permit extensions and are silent on
whether parties can vary the timeline by contract, and argues clear T&Cs probably mitigate the risk —
**their opinion, not a ruling.**

### 7.3 Refund timelines — SOURCES CONFLICT, must be verified

| Trigger | Timeline | Source |
|---|---|---|
| Supply becomes impossible (force majeure) after full payment | notify buyer within **48 h**, full refund within the next **72 h**; **buyer cannot be forced to accept substitute goods** | [Daily Star 2022](https://www.thedailystar.net/law-our-rights/news/responsibilities-online-pagesstores-the-digital-commerce-operation-guidelines-3171656); DeshiCommerce |
| Prepaid order not delivered on time | full refund within **10 days**, **through the same channel used to pay** | [mahbub-law.com](https://mahbub-law.com/ministry-of-commerce-issues-detailed-guidelines-on-e-commerce-business/); DeshiCommerce |
| Consumer complaint of any kind | resolve within **72 h** of receipt | Daily Star; VDB Loi |
| Marketplace → third-party seller payout | **10 days**, net of commission, unless otherwise agreed | VDB Loi; Daily Star |

**A Prothom Alo headline refers to a 7-day refund rule** (*"পণ্য না দিলে টাকা ফেরত ৭ দিনে, নইলে মামলা"*,
[link](https://www.prothomalo.com/business/economics/পণ্য-না-দিলে-টাকা-ফেরত-৭-দিনে-নইলে-মামলা)) — **7 vs
10 days could not be reconciled from the body text. Flag this to the lawyer as an open conflict.**
**Safe commercial posture: build the store's own policy to the strictest number — refund within
7 days, same payment channel.**

### 7.4 Advance payment / escrow — the most commercially important clause

- Goods offered for advance payment must be **ready to ship / under the seller's (or a registered third
  party's) control inside Bangladesh** (Daily Star 2022; echost).
- **If the item cannot be handed to the delivery provider within 48 hours of full payment, the seller
  may not take more than 10% of the price in advance** (Daily Star 2022; echost; DeshiCommerce).
  - ⚠️ **One source says 72 hours, not 48** ([mahbub-law.com](https://mahbub-law.com/ministry-of-commerce-issues-detailed-guidelines-on-e-commerce-business/)).
    Three sources say 48. **Unresolved — verify.**
- **The 10% cap does not apply where payment runs through a Bangladesh Bank–approved escrow service** —
  in that case 100% advance is permitted (echost; DeshiCommerce; mahbub-law.com).
- **Gateway role (Bangladesh Bank circular, 30 June 2021 — a separate instrument predating the
  guidelines by four days):** PSPs/PSOs *"will retain the advance payment of the customer to itself till
  having confirmation of product delivery or will fully refund the advance payment to the customer
  through the same channel within a specific timeline upon cancellation of the order."* **The merchant
  draws the money only after submitting delivery documents for inspection**
  ([mahbub-law.com on the BB circular](https://mahbub-law.com/bangladesh-bank-issues-guidelines-on-settling-payments-in-e-commerce-transactions/);
  [TBS on BB escrow](https://www.tbsnews.net/economy/bangladesh-bank-introduce-escrow-service-e-commerce-267139);
  [Rahman SC](https://rahmansc.com/management-of-the-digital-commerce-calls-for-the-escrow-service/)).
  **This directly explains why gateway settlement timelines in §1 matter so much — and why you should
  get them in writing.**
- **Practical consequence for a toy store: COD and card/MFS-via-escrow are the low-risk models.**
  Taking 100% prepayment directly into your own account for anything not shippable within 48 h is the
  exact pattern that triggered the 2021 Evaly/e-Orange crackdown. In 2021 the High Court directed
  Bangladesh Bank to investigate gateways (including bKash and Nagad) over escrow-held funds not
  refunded ([Daily Star](https://www.thedailystar.net/law-our-rights/news/business-through-internet-quest-legal-framework-2159241)),
  and banks and landlords became reluctant to serve e-commerce firms (VDB Loi).
- **Payment instruments:** no digital wallet, gift card or cash voucher without Bangladesh Bank
  permission; no MLM/network business; no betting/gambling; no lottery or raffle without prior approval
  (Daily Star 2021; VDB Loi). **Relevant if you plan gift cards or a raffle promotion — don't, without
  advice.**

### 7.5 Enforceability — note the soft edges

VDB Loi's analysis (the most careful source found) says the guidelines are a **mixture**: some rules
bind only because they restate existing statutes; some are *"written in binding language, but there
does not seem to be a special or explicit sanction"* — they name **the 5/10-day delivery duty and the
10-day payment clearance** as examples; and some are mere statements of future policy. Sanctions
referenced are indirect: cancellation of **trade licence, company registration or VAT registration**,
plus consumer suits via the **Directorate of National Consumer Rights Protection (DNCRP)** under the
Consumer Protection Act. DNCRP had received **58,000+ e-commerce complaints** as of that reporting
([Sonali News](https://www.sonalinews.com/economy/news/275082)).

### 7.6 Digital Commerce Act / draft law status as of 2026

**No Digital Commerce Act has been enacted.**

| Item | Status | Source |
|---|---|---|
| Draft **Digital Commerce Act** (2023) — proposed fines of 3× the value of undelivered goods | Circulated for comment, mixed reactions; **not enacted** | [Daily Star](https://www.thedailystar.net/business/economy/news/draft-digital-commerce-act-draws-mixed-reactions-3233206) |
| Draft **Digital Commerce Authority Act** (a regulator with registration + complaint powers) | Prepared at industry request; status after consultation unconfirmed | [Dhaka Tribune](https://www.dhakatribune.com/business/331954/govt-drafts-new-e-commerce-law-to-protect) |
| **Cross-Border Digital Commerce Policy 2024** | Stalled awaiting Commerce Adviser approval as of 8 Mar 2025 | [Financial Express](https://thefinancialexpress.com.bd/trade/cross-border-digital-commerce-policy-awaits-final-approval) |
| **Cross-Border Digital Trade Policy 2026** (draft) — would require foreign sellers/platforms incl. Meta, Google, YouTube, TikTok to register under DBID; feedback closed **6 Aug 2026** | Draft; no report of finalisation found | same |
| Draft policy for **mandatory registration of digital ads** (2026) | Draft | [Dhaka Tribune](https://www.dhakatribune.com/business/416168/govt-drafts-policy-for-mandatory-registration-of) |

**Operative framework as of now: Digital Commerce Policy 2020 + Digital Commerce Operation Guidelines
2021 + DBID Registration Guideline 2022 + Bangladesh Bank payment circulars.** A blog guide also cites
a **Payment and Settlement Systems Act 2024** as governing gateways/escrow — **unverified**, worth
asking the lawyer about ([nashirahmed.com](https://nashirahmed.com/ecommerce-business-registration-legal-compliance-bangladesh/)).

### 7.7 DBID (Digital Business Identification)

From the **official portal** (<https://dbid.gov.bd/>, <https://dbid.gov.bd/application-info>):

| Question | Answer found | Caveat |
|---|---|---|
| Required? | The portal states plainly: **"ডিবিআইডি ব্যতীত ডিজিটাল প্লাটফর্মে ব্যবসা করা যাইবে না"** — business cannot be done on a digital platform without a DBID. One DBID per business; multiple branches covered by one. | FE reports the government made registration mandatory **"without setting forth any legal obligation"** for start-ups to obtain it — i.e. mandatory in policy, thin in statute ([FE](https://thefinancialexpress.com.bd/economy/digital-dilemmas-complicate-online-reg-for-e-commerce-startups)) |
| Who issues | Ministry of Commerce one-stop service; earlier reporting routed applications via **mygov.bd** ([Dhaka Tribune](https://www.dhakatribune.com/business/264372/government%E2%80%99s-dbid-registration-faces-hiccups)) | Confirm the current channel |
| Cost | **Free (বিনামূল্যে)** per the official portal. Independent guides agree there is no government fee and **warn against paying for "faster approval"** ([praggo.com.bd](https://praggo.com.bd/blog/how-to-get-a-dbid-in-bangladesh/)) | Private consultants charge their own service fees |
| Processing | **60 working days**; existing businesses were to apply within **90 days** of publication | |
| Prerequisites | Verified **MyInfo** account with a mobile number tied to the applicant's NID; **the website or social page must already exist** before applying | **Plan the DBID application around the site launch, not after it** |
| Documents | Photo + signature; applicant NID; chairman/director NIDs (if applicable); premises proof (landlord NID / electricity bill / rental agreement); **trade licence, company registration no., VAT registration no., income-tax registration no. — each marked "if any"**; local chairman/ward commissioner recommendation (if any) | Trade licence is "if applicable" on this page but **mandatory** per the 2022 Dhaka Tribune report — resolve with the accountant |
| Display | **The DBID must be displayed on the business's website / social pages.** Can be suspended or cancelled for false information or unlawful activity | |

**Terminology trap:** the 2021 guidelines use **UBID** (Unique Business Identification Number); the
portal and current practice use **DBID**. Sources use the two interchangeably and one checklist mixes
both plus **PRA (Personal Retail Account)**. **Ask the lawyer which identifier must actually appear in
the footer.**

### 7.8 VAT / BIN

**This is the weakest-evidenced subsection and the one most in need of an accountant.** The critical
distinction nobody online states cleanly: **a retailer selling physical goods is in a different VAT
position from a marketplace earning commission**, and most news coverage is about the latter.

**What changed:**
- **FY2025-26 (Finance Ordinance 2025):** VAT on **commission from online product sales** raised
  **5% → 15%** — a tripling. Confirmed across
  [Prothom Alo](https://www.prothomalo.com/business/industry/cn1cj12cgh),
  [bdnews24](https://bdnews24.com/budget2025-26/c778d57e5d28),
  [TBS](https://www.tbsnews.net/economy/e-commerce-sector-worried-over-vat-tripling-1160661),
  [Janakantha](https://www.dailyjanakantha.com/economy/news/819153). Industry demanded withdrawal.
- **FY2026-27 (Finance Act 2026):** a VAT rate chart citing the Finance Act 2026 still lists
  **Digital Services & E-commerce Platform Commission at 15%**, applied to the platform's
  brokerage/marketplace cut ([financfy chart PDF](https://financfy.com/wp-content/uploads/2026/08/Bangladesh_VAT_Rates_fy2026_2027.pdf)).
  A commentary says marketplaces pay 15% **only on service fee/commission, not on full sale value**,
  and puts the **online retail seller rate at 7.5% at the trading stage under the Third Schedule**,
  with registered sellers able to opt for 15%
  ([Chartered Journal](https://charteredjournal.com/ecommerce-marketplace-vat-2026/)).
- **Good news for the marketing budget:** Parliament amended the FY2026-27 budget to cut
  **VAT on digital advertising from 15% → 5%** ([bdnews24](https://bdnews24.com/budget2026-27/af480897762f)).

**The rate that probably applies to Toy Mart BD: standard goods-trading VAT on retail sales of
physical toys, NOT the 5%/15% "e-commerce service" rate** — because the "online product sales
commission" line is a *service* code aimed at marketplaces taking a cut, which a single-brand
own-inventory toy store does not do. The candidate figure is **7.5% at the trading (ব্যবসায়ী) stage**
per the Third Schedule, or **15% standard**. **Do not act on this without the accountant confirming the
service/goods code.** The NBR SRO and Finance Act text could not be retrieved (nbr.gov.bd tariff/SRO
PDFs did not serve machine-readable content). SRO reference seen in secondary sources:
**SRO 182-Law/2025/310-VAT, dated 27 May 2025** (VDS rules)
([caripon](https://www.caripon.com/vat-deduction-rate-2025-26-in-bangladesh/)).

**Registration threshold — DIRECT CONFLICT, must be settled:**

| Figure | Source |
|---|---|
| Mandatory VAT registration above **Tk 50 lakh** — law amended Dec 2025 / ordinance Jan 2025, replacing Tk 3 crore; 15-day window after crossing | [TBS](https://www.tbsnews.net/nbr/nbr-registers-131000-new-vat-payers-december-1325021); [taxvatpoint](https://www.taxvatpoint.com/mandatory-vat-registration-in-bangladesh/) |
| Mandatory registration remains above **Tk 3 crore**, with turnover-tax enlistment Tk 50 lakh–3 crore at 3–4% | [TRW Law Firm, 2026](https://trw.org/common-vat-mistakes-bangladesh/); [Khan Akber FY2026-27 calendar](https://khanakber.com/bangladesh-annual-compliance-calendar/); [TaxDo](https://taxdo.com/resources/countries/ap/bangladesh) |

Also reported for FY2026-27: **BIN becomes mandatory for all business interactions** (bank accounts,
contracts) even for small and tax-exempt businesses, and **quarterly VAT returns** become standard with
monthly payments still required ([ACCFAM](https://accfam.co.jp/en/bangladesh-fy2026-27-national-budget-overview-of-tax-reforms/)).
"Electronic sales" is defined for tax as **all offers and acceptances made via online platforms, even
if delivery or payment happens offline** (same source). **A 15% VAT on imported services** with bank
withholding also applies — **relevant if you buy foreign SaaS, hosting or ads.**

**Practical conclusion to verify:** given DBID wants a VAT registration number "if any" and a BIN may
be needed for a business bank account anyway, **get the BIN regardless of turnover.** Ask the
accountant to confirm the service/goods classification code and the applicable rate **in writing**.

### 7.9 Trade licence

- A **trade licence from the city corporation** (or municipality/union parishad outside city areas) is
  the baseline business permission, renewable **annually by 30 June**
  ([Jural Acuity 2026 guide](https://juralacuity.com/trade-license-requirements-in-bd)).
- Fees vary widely by business type, size, location and paid-up capital — a 2026 guide gives a range of
  **BDT 100 to 40,000+**, plus signboard tax and 15% VAT. Actual DNCC e-trade licence documents show
  totals of **BDT 7,312** (supplier) and **BDT 9,497** (travel agency) inclusive of taxes and charges.
  A renewal source-tax line of BDT 3,000 appears in a DNCC fee breakdown
  ([Biztrade](https://blog.biztradebd.com/bangladesh-trade-license-fees-list-dhaka-city-corporation/)).
  DNCC has historically extended renewal deadlines and offered a **10% rebate for paying all four
  instalments online** ([FE](https://thefinancialexpress.com.bd/trade/dncc-extends-deadline-for-renewal-of-trade-licence-fee)).
- **Must it be displayed on the website? Reported YES.** The 2021 guidelines are summarised as
  requiring sellers to *"obtain **and display** their trade licence and Unique Business Authentication
  Number (UBID) on the platform"* ([Daily Star 2022](https://www.thedailystar.net/law-our-rights/news/responsibilities-online-pagesstores-the-digital-commerce-operation-guidelines-3171656)).
  A compliance checklist puts it more loosely: the seller must hold **at least one of** PRA, DBID, trade
  licence, TIN or BIN, displayed on the website or social page ([echost](https://echost.net/18344/));
  another says trade licence, TIN **and** VAT registration
  ([DeshiCommerce](https://deshicommerce.com/blog/bangladesh-digital-commerce-operation-guidelines-2021-analysis/)).
  **Verify the exact obligation; the cheapest safe action is to display all of them in the footer.**
- Note also that obtaining or renewing a trade licence now **automatically enrols merchants in the
  Bangla QR network** ([Dhaka Tribune](https://www.dhakatribune.com/business/419203/mandatory-bangla-qr-required-for-trade-licences)).

### 7.10 Mandatory website disclosures — checklist to verify

Compiled from [Daily Star 2021](https://www.thedailystar.net/law-our-rights/news/overview-the-digital-commerce-operation-guidelines-2021-2128871),
[Daily Star 2022](https://www.thedailystar.net/law-our-rights/news/responsibilities-online-pagesstores-the-digital-commerce-operation-guidelines-3171656),
[echost](https://echost.net/18344/), [DeshiCommerce](https://deshicommerce.com/blog/bangladesh-digital-commerce-operation-guidelines-2021-analysis/),
[VDB Loi](https://www.vdb-loi.com/wp-content/uploads/2021/10/Bangladesh-New-Rules-for-E-Commerce-Marketplaces-Need-to-Register-Deliver-within-5-or-10-days.pdf):

| # | Requirement | Note for a toy store |
|---|---|---|
| 1 | **Terms & conditions in Bengali** (other languages optional, must not conflict with BD law) | Called out explicitly by VDB Loi and DeshiCommerce. **Most incumbents are English-only — this is both a compliance gap and an SEO opportunity (§8)** |
| 2 | Return / exchange / **refund policy** stated clearly, with delivery method and timing | Write to the strictest timeline you can honour (§7.3) |
| 3 | **Privacy policy** — what personal data is collected, why, where stored, how processed; **prior consent required** | |
| 4 | **Cookie / tracking disclosure** — Google Analytics, Facebook Pixel and cookies must be disclosed beforehand | GA4 + Meta Pixel both trigger this |
| 5 | **"I have read and agree" checkbox** in the order flow | Optional-but-advisable per one source, required per another |
| 6 | **Product info:** quantity, size, material, colour, quality, ingredients; price; delivery and other charges; brand, model, **country of origin**, delivery time; images/video | |
| 7 | **Manufacture & expiry date; any health hazard**, including harm to **children** or the environment, with type and ingredients | ⚠️ **Directly load-bearing for toys** — age grading, choking-hazard and small-parts warnings |
| 8 | **Quality certificate mentioned in the listing** where a regulator requires one | ⚠️ **This now pulls in BSTI for toys — see §7.11** |
| 9 | **Live stock quantity**, updated after each sale; "Out of Stock" when sold out; **no payment accepted for out-of-stock items**; "Available for Delivery" for uncountable goods | Build real stock decrementing, not a cosmetic badge |
| 10 | **Pricing in BDT** with a **printed invoice/receipt at delivery showing VAT/tax** where applicable | |
| 11 | **Contact details and complaint channel** (phone, email or other), a named liaison officer for DNCRP, complaints logged and resolved in 72 h | Physical address required in substance (DBID needs premises proof) |
| 12 | **Trade licence + DBID/UBID** (and per some sources TIN/BIN) displayed | See §7.9 |
| 13 | **Ratings & reviews must exist**; fake/affiliated reviews banned; **buyer reviews may not be deleted** by the platform | **Design the review system so deletion is impossible by default** |
| 14 | **Third-party seller names shown** (marketplace may withhold contact details until a dispute) | N/A if own inventory only |
| 15 | **Transaction records kept 6 years**, produced to any government body on demand | Set data retention accordingly — do not auto-purge orders |

### 7.11 CHILDREN'S TOYS — the headline compliance finding

> **Toys became subject to mandatory BSTI certification and the BSTI Standard Mark in 2026. This is
> new, it post-dates most compliance guides online, and it changes the import economics of the
> business.**

| Item | Detail | Source |
|---|---|---|
| Instrument | **Ministry of Industries gazette R.O. No. 232-Act/2026**, published **23 June 2026**, bringing specified consumer and industrial products — **including toys** — under mandatory Bangladesh Standards | [UL Solutions](https://www.ul.com/news/bangladesh-mandates-toy-and-plastic-feeding-bottle-standards); [SGS Safeguards 112/26](https://www.sgs.com/en/news/2026/08/safeguards-11226-bangladesh-mandates-national-standards-and-certification-for-toys-and-other-consumer-goods) |
| Companion instrument | **R.O. No. 233-Act/2026**, same date — goods listed in 232 must bear the **Bangladesh Standard Mark on the goods, packaging, or an attached label** when conforming | [UL](https://www.ul.com/news/bangladesh-mandates-toy-and-plastic-feeding-bottle-standards) |
| Effective date | **23 August 2026** per SGS (UL's page does not state one) | [SGS](https://www.sgs.com/en/news/2026/08/safeguards-11226-bangladesh-mandates-national-standards-and-certification-for-toys-and-other-consumer-goods) |
| Standards for toys | **BDS ISO 8124-2:2025** Flammability · **BDS ISO 8124-3:2025** Migration of certain elements · **BDS ISO 8124-6:2025** Certain phthalate esters · **BDS ISO 8124-12:2025** Microbiological safety | [UL](https://www.ul.com/news/bangladesh-mandates-toy-and-plastic-feeding-bottle-standards) |
| Scope | Reported to cover **all locally manufactured AND imported toys**; manufacturers and importers must comply with the relevant BDS and, where applicable, obtain the BSTI Standard Mark **before marketing** | [Prothom Alo op-ed, 14 Jul 2026](https://en.prothomalo.com/opinion/moc474gsk9); [Daily Star, 28 Jun 2026](https://www.thedailystar.net/business/economy/news/bsti-label-mandatory-seven-new-products-4209751); [Bangladesh Post](https://bangladeshpost.net/posts/bangladesh-introduces-mandatory-toy-safety-standards-170632) |
| Other six products in the same notification | Fortified maize oil; saris; woven women's/girls' dress fabrics of man-made fibre; LED luminaires; plastic feeding bottles (BDS 2045:2025); autoclaved aerated concrete blocks | [Daily Star](https://www.thedailystar.net/business/economy/news/bsti-label-mandatory-seven-new-products-4209751) |

**So: YES, toys are now on the BSTI mandatory certification list.** Verify the current consolidated list
with BSTI directly — **product counts cited across sources vary wildly (315 / 328 / 191), so treat all
of them as unreliable.** TÜV maintains a tracker of the compulsory list
([TÜV](https://www.tuv.com/regulations-and-standards/en/bangladesh-bsti-extended-list-of-compulsory-products.html)).

**Importer vs manufacturer route — unresolved and important.** A **CM licence** is valid 3 years and
follows factory inspection plus lab testing, but one source states *"BSTI do not issue CM
license/certificate for the imported goods. BSTI issues CM License to the manufacturer only"* — for
imports, BSTI instead **samples the consignment at customs/port, lab-tests it, and issues a clearance
certificate** if it conforms ([LegalSeba](https://legalseba.com/bd-licenses/how-to-obtain-bsti-license-in-bangladesh/);
[tnp.legal 2026 guide](https://tnp.legal/blogs/new-guide-to-obtain-bsti-license-in-bangladesh-2026)).
**Confirm the exact import pathway with BSTI and a licensed C&F agent before placing a first order** —
it determines lead time and landed cost, and it is the **highest-impact unknown in the whole plan.**

**Why enforcement risk is real and rising:** ESDO's 2014 study (Bangladesh's first on lead in
children's toys) found alarming lead levels; a 2018 follow-up found the problem widespread; a **2025**
investigation found hazardous toys still circulating freely. The Prothom Alo op-ed author calls for
BSTI surveillance covering **online marketplaces specifically**, and flags weak lab capacity,
counterfeit certification and poor BSTI–NBR–customs coordination
([Prothom Alo](https://en.prothomalo.com/opinion/moc474gsk9);
[The Climate Watch](https://theclimatewatch.com/bangladesh-tightens-childrens-toy-safety-standards-over-lead-poisoning-risks/)).

**Import duty on toys (HS 95.03) — partially verified:**
- NBR's tariff schedule lists heading **95.03** (tricycles, scooters, pedal cars, dolls, other toys,
  scale models, puzzles) at **25% customs duty**, with lines **9503.00.10** (puzzles) and
  **9503.00.90** (other) both at 25% ([NBR Chapter 95](https://nbr.gov.bd/uploads/tariff_schedule/Chapter-95.pdf))
  — ⚠️ **this file could not be machine-read; the 25% figure comes from its search index and the
  schedule version may predate FY2026-27.**
- Duty is assessed on **CIF + 1% landing charge**. A customs guide gives a typical
  finished-consumer-goods stack of **CD 25% / RD 5% / SD 0% / VAT 15% / AIT 5% / AT 5%**, explicitly
  **not** toy-specific ([BD Advocacy](https://bdadvocacy.com/guides/customs-duty-import-bangladesh/)).
  A third-party guide computes roughly **~58% total tax incidence** for plastic toys under 9503.00.10 —
  **unverified and ~9 months old** ([Total Import Solution](https://totalimportsolution.com/hs-code-list-for-common-imported-items-in-bangladesh/)).
- **→ Have the C&F agent produce a written landed-cost calculation per HS line for FY2026-27. Ignore
  the ~58% figure until then.** Note that US-tariff pages dominate searches for "9503" and are irrelevant.
- Beyond the BSTI Standard Mark, disclosure duties #7 and #8 above require health-hazard and
  child-harm disclosure plus mention of required certificates in the listing.

---

## 8. SEO & Market Context

### 8.1 English, Bangla or Banglish? — no primary data exists

**Honest answer: no primary, quantified Bangla-vs-English search-share data exists in public.** Searches
for Google Trends commentary, official Google data and academic work found only an academic Google
Trends paper on Bangladesh covering **sports and entertainment, not retail**
([ResearchGate](https://www.researchgate.net/publication/358708837_Using_Google_Trend_Data_To_Understand_Search_Popularity_Among_Internet_Users_A_Case_for_Bangladesh)).
What exists is **agency-blog consensus** — directionally consistent but uncited:

| Claim | Source | Credibility |
|---|---|---|
| Bangladeshi searches "rarely happen in clean English or clean Bangla"; users blend Bangla script, Banglish and English **within one session**; behaviour is "inherently bilingual" | [ViserX](https://viserx.com/blog/seo/how-ai-is-changing-search-visibility-for-bangladeshi-brands) | Agency blog, uncited |
| Users type the same intent three ways. Worked example: `best cardiologist Dhaka` / `সেরা কার্ডিওলজিস্ট ঢাকা` / `sera cardiologist dhaka`. "Many users type Bengali words using English letters, especially those not comfortable with Bengali typing." Also notes **Bengali numerals** in queries and spelling variants (Dacca/Dhaka, Chattogram) | [KuiperZ field guide](https://kuiperz.io/blog/local-seo-in-bangladesh-a-field-guide-to-ranks-maps-citations-and-bengali-keyword-nuances/) (modified 2026-02-14) | Agency blog, "sources" listed without links |
| "More than 70% of Bangladeshi internet users search in mixed Bengali-English (Banglish) or pure Bengali" | [Shoaib Santo](https://shoaibsanto.com/blog/local-seo-strategies-for-bangladeshi-businesses) | **Uncited. Treat as folklore, not data.** |
| Ahrefs/Semrush have **limited Bangla keyword data**; use Google Keyword Planner with language=Bengali, region=Bangladesh, and research **all three script variants**; avoid machine translation (e.g. "ডিজিটাল বিপণন" is the correct translation of "digital marketing" but nobody searches it) | [Shoaib Santo](https://shoaibsanto.com/blog/bangla-content-seo-google-rank); [KuiperZ](https://kuiperz.io/blog/local-seo-in-bangladesh-a-field-guide-to-ranks-maps-citations-and-bengali-keyword-nuances/) | **Methodologically sound advice** |
| Bangla equivalents have "80-90% lower competition" | [Shoaib Santo](https://shoaibsanto.com/blog/bangla-content-seo-google-rank); [Tanveer Evan Rahat](https://www.tanveerevanrahat.com/local-seo-vs-national-seo-bangladesh/) | **Sales-oriented assumption, no source** |
| Country-level top-query lists are dominated by **navigational and news terms** (YouTube, Facebook) and English↔Bangla translation queries — a picture of public attention, not a target list | [SEOptimer BD top 500](https://www.seoptimer.com/top-google-searches/bangladesh); [Clicks.so top 200 Bengali](https://resources.clicks.so/top-google-searches/Bangladesh/Bengali) | Third-party tool estimates |

One structural note: Google's Bangladesh results have been criticised for **English-language bias** in
surfacing content ([The Conversation](https://theconversation.com/unrest-in-bangladesh-is-revealing-the-bias-at-the-heart-of-googles-search-engine-249131))
— which cuts both ways: English pages may rank more easily, but Bangla pages face thinner competition.

**→ Recommendation: do not plan the content strategy on these blog figures. Spend one hour in Google
Keyword Planner (region=Bangladesh, language=Bengali *and* English) pulling real volumes for the three
script variants of your top 30 head terms before committing.**

### 8.2 High-intent query patterns

**Treat all of these as hypotheses to validate in Keyword Planner, not as verified volumes** — no
tool-level volume data for BD toy terms is publicly retrievable.

**English / Banglish-friendly (highest commercial intent):**
- `toy shop in dhaka` · `toy shop near me` · `baby toys price in bangladesh` · `kids toy bd` · `toy store bd`
- `remote control car price in bd` · `rc car price in bangladesh` · `remote control helicopter price in bd`
- `lego bangladesh price` · `lego price in bd` · `hot wheels price in bangladesh` · `barbie doll price in bd`
- `educational toys bangladesh` · `stem toys bd` · `montessori toys bangladesh`
- `baby walker price in bangladesh` · `tricycle price in bd` · `kids bicycle price in bangladesh`
- `birthday gift for 3 year old bangladesh` · `toys for 1 year old bd`
- `online toy shop bangladesh cash on delivery` · `toy shop dhanmondi` / `uttara` / `mirpur` / `chittagong`

**Bangla script:**
`খেলনা` · `বাচ্চাদের খেলনা` · `শিশুদের খেলনা` · `খেলনার দাম` · `বাচ্চাদের খেলনার দাম` · `খেলনা গাড়ি` ·
`রিমোট কন্ট্রোল গাড়ি দাম` · `খেলনা গাড়ির দাম বাংলাদেশ` · `অনলাইনে খেলনা কিনুন` · `ঢাকায় খেলনার দোকান` ·
`খেলনা পাইকারি বাজার ঢাকা` · `শিক্ষামূলক খেলনা` · `পুতুল দাম` · `বাচ্চাদের সাইকেল দাম` ·
`১ বছরের বাচ্চার খেলনা` · `২ বছরের বাচ্চার জন্য খেলনা`

**Banglish (Latin-script Bangla) — the most under-served tier:**
`khelna` · `baccader khelna` · `bachchader khelna dam` · `khelna gari` · `khelna dam koto` ·
`baccader khelna online` · `khelnar dokan dhaka` · `remote control gari dam` ·
`3 bochorer baccar khelna` · `birthday gift baccader jonno`

**Pattern rules to encode in the URL/title architecture:**
1. `[product] price in bangladesh` / `price in bd` — the dominant BD commercial modifier
2. `[product] bd` suffix form
3. `[brand] bangladesh price`
4. `[category] + [age]` — the gifting market
5. `[category] + [Dhaka area]`
6. **Every one of the above in all three scripts**

Mitigate transliteration variance with on-page FAQ/glossary blocks of common Banglish spellings, plus
hreflang `en` and `bn-BD` ([KuiperZ](https://kuiperz.io/blog/local-seo-in-bangladesh-a-field-guide-to-ranks-maps-citations-and-bengali-keyword-nuances/)).

### 8.3 Competitors (verified URLs)

**Could NOT verify, across several searches: "Toy Palace" and "Kidstoy BD" — no such Bangladeshi store
found. No Pickaboo toy category surfaced either, and Bhalo.com did not surface. Treat all four as
unconfirmed.**

**Marketplaces — the real SERP competition on head terms:**

| Competitor | URL | Verified detail |
|---|---|---|
| Daraz Bangladesh | [daraz.com.bd](https://www.daraz.com.bd/) · [toys-games](https://www.daraz.com.bd/toys-games/) | Largest local marketplace, registered as a normal local company. Subcats: Blaster Guns & Slingshots, Kids Bikes, Tricycles, Scooters |
| Othoba.com (PRAN-RFL) | [othoba.com/toys-games](https://othoba.com/toys-games) · [kids-toys](https://othoba.com/kids-toys) | **12,000–14,000+ toy SKUs — the deepest catalogue found** |
| RFL (manufacturer-direct) | [rflbd.com/category/toys](https://rflbd.com/category/toys) · [rfleshop.com](https://rfleshop.com/toys-in-bangladesh) | Domestic maker; **likely the price floor on plastic toys** |
| Rokomari | [Town Store](https://www.rokomari.com/brand/10436/town-store) · [Playtime Toys](https://www.rokomari.com/brand/14355/playtime-toys) | Puzzles/cubes/flash cards ৳200–1,000. **Runs Bangla-script URLs (`/প্লেটাইম-টয়েস`) — direct evidence Bangla slugs rank in BD** |
| Bikroy (classifieds) | [bikroy.com/toys/school](https://bikroy.com/toys/school) | Long-tail used/cheap |

**Toy specialists — the direct competitive set:**

| Store | URL | Verified detail |
|---|---|---|
| SK Toy | [sktoy.com.bd](https://www.sktoy.com.bd/) | 1,200+ toys; Mohammadpur, Dhaka-1207; bKash/Nagad/COD; free delivery over ৳3,000; "3-day easy return" |
| ToyTopiya | [toytopiya.com](https://toytopiya.com/) | Educational/STEM; Merul Badda, Dhaka 1212; 1–3 days Dhaka, 3–5 outside; COD/bKash/Nagad/Rocket/cards |
| Toy For Joy BD | [toyforjoybd.com](https://toyforjoybd.com/) | Dakshin Khan, Dhaka; 50+ categories |
| KiddyLand | [kiddylandbd.com](https://kiddylandbd.com/) | Toy shop + kids zone |
| Toy House BD | [toyhouseltd.com](https://toyhouseltd.com/) | RC cars, toy guns, educational; Dhaka showroom |
| Bangla Toys | [banglatoys.com](https://www.banglatoys.com/) | Claims first BD online toy store, launched 10 Feb 2015 |
| Toy Shop BD | [FB @toybdofficial](https://www.facebook.com/toybdofficial/) | Online + physical, Dhaka |
| Paklubi | [paklubi.com](https://paklubi.com/) | Toys + baby products |
| Also named by TBS | Chalkpencil.com, Lokkhisona.com, Books for Kids, Tos Fun, Ayaat's, Goofi World; offline Sundora Kids Banani ([TBS](https://www.tbsnews.net/node/694286)) | |

**Key competitive finding — and the clearest strategic opening in this whole report.** On the two
specialists inspected in detail (SK Toy, ToyTopiya), **neither displays a trade licence, DBID/UBID or
BIN**, neither has substantive Bangla content (SK Toy's only Bangla is a delivery banner), and return
policies are thin or link-only. So **the §7.10 disclosure checklist is simultaneously a legal duty, a
trust differentiator in a market with 58,000+ e-commerce complaints filed
([Sonali News](https://www.sonalinews.com/economy/news/275082)), and unique crawlable Bangla content
rivals do not have.** Compliance and SEO point the same direction here, which is rare.

### 8.4 F-commerce — the dominant acquisition channel

| Metric | Figure | Source |
|---|---|---|
| Facebook business pages in BD | **500,000+** (e-CAB estimate) | [TBS](https://www.tbsnews.net/economy/facebook-restriction-keeps-f-commerce-entrepreneurs-bay-905416) |
| Pages run by F-commerce entrepreneurs | **200,000–250,000** (e-CAB executive director) | same |
| Of those making **regular** sales | **~60,000** | same |
| Looser 2026 estimates | 300,000+ pages; 200–250k active sellers; reported annual sales >BDT 1,000 crore | [inside-bangladesh (Apr 2026)](https://inside-bangladesh.com/facebook-businesses-bangladesh-micro-economy/); [SSRN 2026](https://papers.ssrn.com/sol3/papers.cfm?abstract_id=7539959) |
| F-commerce share | >BDT 1,000cr of ~BDT 7,000cr e-commerce (older) | [TBS](https://www.tbsnews.net/thoughts/f-commerce-double-edged-sword-207256) |
| Framing | "F-commerce is the new shopping mall" | [Dhaka Tribune](https://www.dhakatribune.com/business/281034/f-commerce-is-the-new-shopping-mall); [DHL BD](https://www.dhl.com/discover/en-bd/e-commerce-advice/e-commerce-trends/guide-to-f-commerce-in-bangladesh) |

**Meta's own reach** (DataReportal, *Digital 2026: Bangladesh*, published 8 Nov 2025): Facebook ad reach
**64.0m** (36.3% of population), Messenger **35.0m** (19.9%), against **82.8m internet users** at 47.0%
penetration ([DataReportal](https://datareportal.com/reports/digital-2026-bangladesh)).
**Facebook reaches ~77% of the online population — the structural reason F-commerce dominates.**

**What this implies for a store that wants organic search traffic:**

1. **Long-tail SEO competition is genuinely low.** Hundreds of thousands of rivals publish to a *feed*,
   not to the indexable web. The wall exists only on head terms, and **it is 4–5 sites
   (Daraz/Othoba/RFL/Rokomari), not 300,000.**
2. **But absolute organic volume is also low.** The dominant journey is Facebook discovery → Messenger
   → COD. **SEO is a *capture* channel for already-formed intent** (price checks, "where to buy X"),
   **not a demand-creation channel.** Plan it as a compounding, high-margin *secondary* channel while
   Facebook/Messenger carries launch volume: low traffic, unusually high intent. **Do not budget SEO as
   the primary launch channel.**
3. **Bangla content is the main unexploited lever** — simultaneously a legal requirement (Bengali T&Cs,
   §7.10 #1) and the thinnest-competition keyword tier. **Rokomari's Bangla slugs prove it ranks.**
4. **Structured data is near-free differentiation**, because Facebook sellers emit none: `Product` +
   `Offer` (priceCurrency BDT, availability), `AggregateRating`/`Review` (remember reviews may not be
   deleted, §7.10 #13), `BreadcrumbList`, `LocalBusiness` with the physical address, `FAQPage`,
   `Organization` with trade licence/DBID in the footer. **Price/availability rich results are what win
   the "price in bd" query class.**
5. **Own the catalogue as a hedge** — Facebook access restrictions have previously stranded F-commerce
   sellers ([TBS](https://www.tbsnews.net/economy/facebook-restriction-keeps-f-commerce-entrepreneurs-bay-905416)).
   Separately, FY2026-27 cut **VAT on digital advertising 15% → 5%**
   ([bdnews24](https://bdnews24.com/budget2026-27/af480897762f)), making paid Facebook **cheaper right now** —
   so the rational play is paid social for volume *plus* organic as the compounding hedge.
6. Market tailwind: BD B2C e-commerce ~Tk 56,870cr (2021), ~17.61%/yr, forecast ~Tk 1.5 lakh crore by
   2026 (ResearchAndMarkets via [TBS](https://www.tbsnews.net/economy/bangladesh-e-commerce-sales-more-double-2026-research-497134))
   — **a 2022 forecast, not an outcome.**

### 8.5 Mobile vs desktop and connection speed

**The two standard sources flatly contradict each other.** Both are given rather than the convenient one.

| Source | Metric | BD figure |
|---|---|---|
| **StatCounter** (share of **page views**) | Desktop/Mobile/Tablet | **Oct 2026: Desktop 56.04% / Mobile 43.60% / Tablet 0.35%** ([link](https://gs.statcounter.com/platform-market-share/desktop-mobile-tablet/bangladesh)). Other views in the same index gave ~71.85% desktop / 28.15% mobile ([2025](https://gs.statcounter.com/platform-market-share/desktop-mobile-tablet/bangladesh/2025)) — **StatCounter's BD series is internally unstable** |
| **Cloudflare-based** (share of **HTTP requests**) | Mobile | **Q3 2026: 71.09% mobile**; 75.45% mobile human traffic; BD ranked **#1 most mobile-first** ([TechnologyChecker](https://technologychecker.io/blog/mobile-internet-usage-by-country)) |
| **BTRC subscribers** | Mobile internet users | ~**119.06m** of ~132.8m total (early 2024); 135.94m subscriptions Jun 2026 ([Daily Star/BTRC](https://www.thedailystar.net/supplements/anniversary-supplement-2025/towards-equality/news/the-digital-evolution-challenges-and-opportunities-3832886)) |
| StatCounter mobile OS | Android | **92.72%** (Oct 2026) ([link](https://gs.statcounter.com/os-market-share/mobile/bangladesh)) |

**Why they differ:** StatCounter counts page views on tracker-carrying sites; Cloudflare counts HTTP
requests including non-human traffic.

**Resolution: ~90% of BD subscriptions are mobile and Android, and Cloudflare/BTRC both point to a
strongly mobile-first consumer — so build mobile-first, low-end-Android-first. But do NOT quote
"mobile is 75% of BD web traffic" as a StatCounter fact; StatCounter says the opposite.** Let your own
GA4 arbitrate once live.

**Speed** (Ookla via DataReportal, end-2025): median mobile download **36.84 Mbps**, fixed
**60.26 Mbps** ([DataReportal](https://datareportal.com/reports/digital-2026-bangladesh)).
**The medians are fine; the tail is the constraint** (budget Androids, data cost, congested cells).
Engineering targets: WebP/AVIF for an image-heavy catalogue, lazy loading, **LCP < 2.5s on mid-range
Android over 4G**, minimal JS, and **subset your Bangla webfonts — they are large and will block
render.** That last point matters doubly here, because Bangla content is the SEO strategy (§8.4 #3).

---

## Unverified / needs confirmation by the business owner

Ordered by how much damage a wrong assumption would do.

### Tier 1 — could change the business case or the launch date

1. **BSTI certification pathway for imported toys.** Toys came under mandatory BSTI standards via
   R.O. 232/233-Act/2026 (gazetted 23 Jun 2026, effective 23 Aug 2026 per SGS). **Unresolved: whether
   an importer needs a CM licence or consignment-wise port clearance, the lead time, the per-consignment
   cost, and exactly which SKUs are in scope.** Confirm with **BSTI and a licensed C&F agent before
   placing a first order.** This is the single highest-impact unknown in the plan.
2. **Landed cost of imported toys.** NBR's Chapter 95 schedule could not be machine-read; the 25% CD
   figure comes from its search index and may predate FY2026-27. The circulating "~58% total tax
   incidence" figure is third-party and ~9 months old. **Get a written landed-cost calculation per HS
   line from the C&F agent.**
3. **COD return / RTO rate.** No Bangladesh-specific figure exists from any primary source. The 15–30%
   planning range is an inference from India comparables plus local "safe success rate" heuristics.
   **If RTO economics are load-bearing, the only way to get real numbers is to ship a few hundred
   parcels and measure.**
4. **COD share of orders.** The 85–90% planning assumption rests on **one named quote in one
   newspaper** (Apr 2025). Bangladesh Bank's 2025 Payment Systems Report contains no COD data at all
   (verified by full-text search). The widely-cited ">90%" is *user preference* from an undated e-CAB
   claim whose underlying report could not be located.
5. **VAT rate and registration threshold.** Which code applies to online retail of **physical toys** —
   7.5% trading stage vs 15% standard vs the 15% "commission" line that likely does **not** apply — and
   the threshold (**Tk 50 lakh vs Tk 3 crore; 2026 sources flatly conflict**). **Accountant, against
   the Finance Act text.**
6. **Steadfast's return-charge policy.** Its own two pages contradict each other: Terms say *"Steadfast
   does not charge for return parcels"*; the Business page says *"Return charges apply separately."*
   At 15–30% RTO this line may exceed any forward-freight saving. **Get it in writing before signing.**

### Tier 2 — payment & gateway commercials

7. **SSLCommerz settlement timeline.** Not published anywhere official. Secondary sources give
   same-day, T+1–T+2, and 5–7 business days. **Get it in writing.** Also confirm whether the
   **BDT 25,500 setup fee is one-time or recurring** — the pricing page does not say.
8. **bKash PGW rate card, settlement, and PGW/API onboarding checklist.** No official source found for
   any of the three. The circulating 1.85% may actually be bKash's *cash-out* charge.
9. **bKash IPN signature verification procedure.** bKash asserts notifications are signed and supplies
   SNS-style `Signature`/`SigningCertURL`, but documents **no algorithm, no covered-field list and no
   certificate-validation steps**; its sample payloads reuse one signature across three examples.
   Also undocumented: how to acknowledge the subscription confirmation. **Confirm with bKash before
   relying on IPN.** (Mitigation already in the design: make Execute + Query Payment authoritative.)
10. **aamarPay and ShurjoPay pricing, settlement, and onboarding requirements.** Neither publishes any.
11. **Nagad everything** — no public developer portal, API docs, rate card or onboarding checklist
    exists. The only merchant entry point is a CAPTCHA'd lead form. The RSA/`sensitiveData` flow
    described here is reconstructed from community code; padding, key formats and field ordering are
    unverified.
12. **SSLCommerz `verify_sign_sha2`.** A SHA-256 variant appears in sample payloads but its
    construction is undocumented. (The MD5 scheme *is* verified, from SSLCommerz's own PHP library.)
13. **ShurjoPay token lifetime** — prose says 15 minutes, the sample response says `expires_in: 3600`.
    Also its `sp_code` table (1000 vs 200 for success) and whether a documented IPN/webhook exists.

### Tier 3 — legal text and identifiers

14. **The current consolidated text of the Digital Commerce Operation Guidelines, and whether any
    সংশোধনী exists at all.** Only the 4 July 2021 gazette was found; **no amendment gazette could be
    located**, contrary to the brief's assumption. The gazette PDF is in legacy Bijoy encoding and needs
    a Bijoy→Unicode conversion or a human reader — **worth doing, as it is the only primary source and
    would settle items 15 and 16.**
15. **Refund deadline: 7 days or 10 days?** (Prothom Alo headline vs law-firm summaries.)
16. **Advance-payment trigger: 48h or 72h?** Three sources say 48, one says 72. And whether a store
    shipping from its own stock may take 100% prepayment **outside** an escrow arrangement.
17. **Exactly which identifiers must appear in the website footer** — trade licence / DBID / UBID /
    TIN / BIN / PRA. Sources give four different answers. (Cheapest safe action: display all of them.)
18. **Whether a trade licence is mandatory for DBID** — the official portal marks it "if any", a 2022
    Dhaka Tribune report says mandatory.
19. **Whether a "Payment and Settlement Systems Act 2024" exists** and governs gateways/escrow — cited
    by one blog only.

### Tier 4 — technical details to confirm during the build

20. **Pathao's actual Dhaka COD rate** — its FAQ says 0.5%, every rate-table row says 1%.
    *Resolvable in code* via `/merchant/price-plan`'s `cod_percentage`.
21. **Pathao's auto-address feature** — the Bengali blog post says city/zone/area can be omitted and
    Pathao will resolve the address string, but this conflicts with those fields being documented as
    required, and the post is ~467 days old.
22. **Whether Pathao's `/city-list` or `/countries/1/city-list` is deprecated** — the community Postman
    collection uses the former, Pathao's own current plugin uses the latter.
23. **Pathao's live city/zone counts.** The 64 cities / 1,258 zones figure is a **third-party cached
    snapshot**, not Pathao's API. Mirror the real lists before relying on any count.
24. **Steadfast field max-lengths** (invoice 100, `recipient_address` 250 or 490 — third-party SDKs
    disagree) and the **extended bulk endpoint** `/create_order/bulk-order/extended`, which is absent
    from the official collection.
25. **RedX's entire rate card and COD policy** — no public page exists (route enumeration confirms it).
    Its auth header and parcel field names are third-party only, though the host and paths were probed
    and confirmed live. **Register a merchant account before ruling RedX in or out.**
26. **Paperfly's rates** for sub-area, delivery-centre, inter-city Dhaka and outside-Dhaka inter-city —
    the tabs exist but contain no data server-side.
27. **Whether BTRC formally adopted the Dec-2025 06-series IoT recommendation**, and any primary BTRC
    numbering-plan document (btrc.gov.bd has been rebuilt; the relevant pages 404).
28. **bulksmsbd.net's published pricing and official API docs** — entirely login-gated. The endpoint
    and parameter list given here comes from open-source wrappers. **Do not treat bulksmsbd.com / .co /
    .xyz prices as bulksmsbd.net's — they are different companies.**
29. **MIM SMS's own API reference** (JS-gated), **REVE SMS's API style** (no public docs), and
    **SSL Wireless / Infobip Bangladesh price lists** (quote-only).
30. **Masking / sender-ID approval fees** — not one provider publishes a figure.
31. **A primary BTRC document** for the Tk 0.19–0.23 SMS floor price, the NDNC registry, and the
    aggregator enlistment list. All three are second-hand. **Verify your chosen SMS vendor's current
    BTRC enlistment yourself.**
32. **Meta's display-name and business-verification review timelines** — Meta publishes neither.
33. **When WhatsApp's authentication-international band applies to your traffic.** Bangladesh is on
    Meta's `authIntlMarkets` list, and that rate (~$0.096 ≈ ৳11.71) is **26× the domestic
    authentication rate**. Confirm with Meta or your BSP **before putting OTP on WhatsApp.**
34. **Real Bangla vs English vs Banglish search volumes for toy terms.** No public data exists. The
    "70% search in Banglish/Bangla" figure is uncited folklore. **Spend an hour in Google Keyword
    Planner before committing to a content plan.**
35. **"Toy Palace", "Kidstoy BD", a Pickaboo toy category, and Bhalo.com** — named in the brief, but
    **no such Bangladeshi toy stores could be found.** Treat as unconfirmed.
36. **Mobile vs desktop share.** StatCounter (Oct 2026: 56% desktop) and Cloudflare (Q3 2026: 71%
    mobile) flatly contradict each other, and StatCounter's BD series is internally unstable.
    **Let your own GA4 arbitrate once live** — but build mobile-first regardless, since ~90% of BD
    subscriptions are mobile and Android.
