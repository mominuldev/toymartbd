# Toy Mart BD — Admin Feature & Screen Checklist

**Date:** 2026-11-11 · **Status:** Working checklist for the Figma admin pass (pages `04 Admin Components`, `07 Admin — Desktop`)
**Inputs:** `04-admin-spec.md` (24 composites, 8 screens), `UI-UX-DESIGN-SPEC.md` §4–5 (58 admin screens), owner brief of 2026-11-11 (CMS/page builder, SEO, tracking, couriers, fraud, AI inbox, POS, loyalty, multi-branch).

## 0. Visual direction (owner decision 2026-11-11 — "Calm pro + brand")

The admin is a working tool, not a shop window. It keeps the storefront's colour tokens, cream canvas (`color/bg/canvas`) and the coral→pink gradient primary button, but drops the playful shapes:

| Thing | Storefront | Admin |
|---|---|---|
| Control radius | `radius/full` (pill) | `radius/sm` (10) |
| Chips, table pills, mono fields | pill | `radius/xs` (6) |
| Cards, panels, tables | `radius/sm`–`lg` | `radius/md` (16) |
| Modals, drawers | `radius/xl` | `radius/lg` (20) |
| UI type | Fredoka display + Hanken body | **Hanken Grotesk everywhere**; Fredoka only for page titles and the wordmark |
| Numbers | Fredoka prices | Hanken `Admin/num*`, right-aligned, `৳` glyph set in Fredoka (Hanken has no `৳`) |
| Mono | — | JetBrains Mono (SKU, consignment, slug, ledger ref) |

Radius tokens moved one step rounder in the storefront pass (`sm` 10, `md` 16, `lg` 20), so the admin uses one step *down* from the names written in `04-admin-spec.md`. The pixel values the spec intended (10 / 6 / 14–16 / 20) are what ship.

Rules that carry over unchanged from `04-admin-spec.md` Part 0: status = text + icon shape + colour; `color/feedback/*` never carries 12–14px text; money right-aligned with true minus; Dhaka time everywhere; skeletons at final size; cursor pagination (no page numbers).

## 1. Navigation (information architecture)

Every feature below has exactly one home. Sidebar groups, in order:

| Group | Item | Sub-pages (tabs or second-level) |
|---|---|---|
| — | **Overview** | Dashboard |
| — | **Inbox** | All · Messenger · WhatsApp · Live chat · AI-handled · Unassigned |
| SELL | **Orders** | Board (status tabs) · Create order · Drafts · Verification queue |
| SELL | **POS** | Register · Sessions · Cash drawer |
| SELL | **Returns** | Returns · Refunds · Exchanges |
| SELL | **Customers** | List · Segments · Loyalty & store credit |
| CATALOG | **Products** | Products · Categories · Brands · Attributes · Reviews · Import/export |
| CATALOG | **Inventory** | Stock overview · Movements ledger · Adjustments · Transfers · Warehouses & branches |
| CATALOG | **Purchasing** | Purchase orders · Suppliers · Receive stock |
| DELIVER | **Shipments** | Booking queue · Submitted · In transit · Failed bookings · Delivery charges · Location mapping · COD settlements · Courier performance |
| DELIVER | **Fraud & risk** | Verification queue · Phone lookup · Blacklist & watchlist · Risk rules |
| GROW | **Marketing** | Discounts & coupons · Automatic promotions · Flash sales · Abandoned carts · Campaigns (WhatsApp/SMS) |
| GROW | **Analytics** | Sales · Visitors & funnel · Products · Courier performance · Live view |
| GROW | **SEO** | Overview & health · Redirects · Sitemap & robots · Schema · Search Console |
| STORE | **Online store** | Pages & page builder · Homepage · Navigation (header/footer/menus) · Blog · Media library · Theme |
| MONEY | **Finance** | P&L · Daily reports · Expenses · COD receivables · Supplier payments · Profitability |
| SETTINGS | **Staff & roles** | Users · Roles & permissions · Activity log |
| SETTINGS | **Automations** | Workflows · Notification templates · Notification settings |
| SETTINGS | **Integrations** | Couriers · Payments · Tracking & pixels · Messaging · AI assistant · Search Console |
| SETTINGS | **Settings** | Store · Branches · Checkout · Taxes & invoices · Domains · Languages |

## 2. Feature checklist by module

Legend — **S** = full desktop screen drawn on page 07 this pass · **C** = covered as a component / drawer / modal on page 04 or inside another screen · **—** = documented, not drawn (same pattern as a drawn screen).

### 2.1 Overview & analytics
- [S] Dashboard: KPI cards with deltas + sparklines, status strip, sales trend, category donut, best sellers, low stock, needs-attention stack, activity
- [S] Analytics: sales, visitors, sessions, conversion funnel (view → cart → checkout → order → delivered), traffic sources (Facebook/Google/direct/WhatsApp), device split, top landing pages, live visitors
- [C] Date range picker (Dhaka presets + compare), chart cards (line / bar / stacked / donut), stat card states (loading, no comparison)
- [—] Product performance, courier performance (use Analytics layout)

### 2.2 Orders
- [S] Order board: status tabs with counts, saved views, filters, anomaly switch, bulk actions, triad status badge, risk column
- [S] Order detail: three-track stepper, items, payment & COD, shipment timeline, risk card, customer, notes/activity, actions (confirm, hold, edit, cancel, invoice)
- [S] Create order (phone/Facebook orders): phone lookup first, product picker, address cascade (division→district→upazila→area + landmark), delivery charge, advance payment, source channel
- [C] Order edit after placement, invoice/packing slip preview, cancel ConfirmDialog (typed order number)
- [S] Returns, refunds & exchanges: RMA list, reason, inspection result, restock, refund method (bKash/Nagad/bank/store credit), exchange creates linked order

### 2.3 Courier & delivery (Steadfast + Pathao)
- [S] Booking queue: ready-to-ship list, provider picker with charge preview, single + bulk booking, `submitted` vs `booked` states, unmapped-zone blocking
- [S] Shipments & tracking: list with live status, detail with ShipmentTrackingTimeline (webhook/poll/manual source), divergence banner, partial delivery
- [S] Courier settings & delivery charges: provider cards (connected / unverified / no credentials), rate cards (inside Dhaka / suburb / outside, per-kg step — Steadfast 1 kg, Pathao 2 kg), free-delivery threshold, location mapping table, webhook health
- [S] COD settlements: courier statements vs expected, per-consignment matching, variance
- [—] Courier performance report (delivery rate, avg days, return rate by provider × district) — drawn as an Analytics tab
- [C] Failed bookings retry (queue tab), return-to-sender handling (Returns screen)

### 2.4 Fraud detection & verification
- [S] Verification queue: orders auto-held for review, reasons (first order over threshold, repeat cancels, duplicate phone/address, blacklisted), call outcome logging, confirm / keep on hold — **never auto-cancel**
- [S] Phone risk lookup: RiskScoreCard with our score and courier-verified record as separate, timestamped panels; order history; pinned notes
- [S] Blacklist & watchlist: phone/address/device entries, reason, who, when, expiry; typed confirmation for blacklist
- [C] Duplicate-order detector (same phone or address within 24 h) as a banner on order detail + a queue reason
- [—] Risk rules (weights, thresholds) — settings form pattern

### 2.5 Customer communication & AI support
- [S] Unified inbox: Messenger + WhatsApp + live chat in one list, AI-handled vs needs-human, conversation with order context side panel (order status, tracking, risk), quick replies, product cards, "hand over to human", assign
- [S] AI assistant settings: on/off per channel, knowledge sources (FAQ, policies, catalogue), answerable intents (order status, delivery charge, stock, return policy), handover rules, tone (Bangla/English/Banglish), test console, conversation analytics
- [C] Chat bubble, conversation row, AI-suggestion card, handover banner, channel badge
- [—] Campaigns (WhatsApp utility templates; no OTP on WhatsApp — rule 32b)

### 2.6 Catalog
- [S] Products list: thumbnail, name, SKU, variants, stock, price, status, channel visibility; bulk edit; import/export
- [S] Product edit: General, Media (ImageUploader with EN + বাং alt), Pricing & cost, Variants (matrix), Inventory per warehouse, SEO panel, Related products (upsell/cross-sell caps), BSTI certificate field
- [S] Categories: tree with drag reorder + category edit panel (banner, SEO, filters shown)
- [—] Brands, attributes, attribute values, reviews moderation (list/edit patterns)

### 2.7 Inventory, purchasing, branches
- [S] Inventory overview: per-warehouse on hand / reserved / available, low & out of stock, valuation at cost
- [S] Stock movement ledger: append-only, reconciliation strip
- [S] Purchase orders: list + PO detail with receive-stock flow, supplier balance
- [C] Stock adjustment form, stock transfer between branches/warehouses
- [S] Branches & warehouses (inside Settings): Dhaka HQ warehouse, Chattogram branch, pickup points; courier pickup address per branch

### 2.8 Customers, CRM & loyalty
- [S] Customers list: segments (new, repeat, VIP, at-risk, blacklisted), LTV, orders, delivery success rate, last order
- [S] Customer profile: summary, orders, addresses, conversations, risk, notes, loyalty points, store credit ledger, tags
- [S] Loyalty & store credit: earn rules (৳100 = 1 point), redemption, tiers, expiry, credit ledger
- [S] Abandoned carts: recoverable carts, contact channel, automated reminder sequence, recovered revenue

### 2.9 Marketing & promotions
- [S] Discounts & coupons: list + coupon builder (amount/percent/free delivery/BOGO, min spend, limits, eligibility, schedule), live summary card
- [—] Automatic promotions, flash sales (same builder with schedule + countdown)

### 2.10 CMS & online store
- [S] Page builder (live visual editor): section list (drag reorder), canvas with desktop/mobile toggle, section settings panel, schedule & publish, version history
- [S] Pages & navigation: page list (home, landing pages, custom pages) + menu editor (header, footer, mobile) with nested drag items
- [S] Blog editor: rich text, featured image, categories, SEO panel, schedule
- [C] Media library picker, block library

### 2.11 SEO
- [S] SEO center: site health score, issues list (missing meta, missing Bangla alt, broken links, duplicate titles), Search Console metrics (clicks, impressions, CTR, position, top queries), sitemap status, robots.txt editor
- [S] Redirect manager: 301/302 list, hits, source (auto from slug change / manual), bulk import, loop detection
- [C] Per-entity SeoPanel (meta, SERP preview, OG preview, canonical, robots, schema), SlugField redirect warning
- [—] Schema validator

### 2.12 Analytics & tracking
- [S] Tracking & pixels: GA4, GTM, Meta Pixel + Conversions API (server-side), TikTok pixel, custom scripts (head/body), event map (view_item, add_to_cart, begin_checkout, purchase on **delivery** vs on order), consent, diagnostics/last-event health

### 2.13 Finance
- [S] P&L statement (five blocks, cash panel, waterfall, reconciliation)
- [S] Expenses: list by category (ads, rent, salaries, packaging, utilities), receipts, recurring, per-branch
- [C] COD receivables (Finance tab = COD settlements screen), product/order profitability tables
- [—] Daily reports (P&L layout by day)

### 2.14 POS & offline sales
- [S] POS register: product grid with barcode search, cart, customer phone lookup, discounts, split payment (cash / bKash / Nagad / card), receipt print, branch + cash drawer session

### 2.15 Payments
- [S] Payment methods: COD (advance rules for high-risk/outside Dhaka), bKash (tokenized checkout), Nagad, Rocket, SSLCommerz (cards), bank transfer; per-method fees, test/live mode, webhook status, settlement schedule

### 2.16 Staff, automation, integrations, settings
- [S] Staff & roles: users list + PermissionMatrix
- [S] Automations & notifications: workflow list (order confirmed → WhatsApp utility message; high risk → hold + alert; low stock → email; abandoned cart → reminder), template editor, channel costs
- [S] Integrations hub: couriers, payments, messaging, marketing/tracking, AI, accounting, with status
- [S] Store settings: business info, trade licence/BIN, branches & warehouses, invoice, checkout rules, languages
- [S] Activity log: global audit feed with sensitive-change diff
- [S] Sign in (email/phone + password, 2FA)

## 3. Component checklist (page `04 Admin Components`)

| # | Section | Components |
|---|---|---|
| 00 | Admin type & icons | `Admin/*` text styles · ~90 Phosphor admin icons |
| 01 | Controls | AdminButton · AdminIconButton · AdminInput · AdminSelect · SearchField · SegmentedControl · AdminTab · StatusChip · FilterChip · Tag · KeyValue |
| 02 | Shell | SidebarItem · Sidebar (expanded / collapsed) · Topbar · ContentHeader · AppShell |
| 03 | Data display | StatCard · ChartCard (line / bar / donut / stacked) · DataTable parts (toolbar, header cell, row, footer, states) · BulkActionBar · DateRangePicker · Pager |
| 04 | Orders & delivery | OrderStatusBadge (triad) · OrderStatusStepper · CourierBookingPanel · ShipmentTrackingTimeline · CourierProviderCard |
| 05 | Risk | RiskScoreCard (two panels) · CustomerPhoneLookup · RiskChip |
| 06 | Forms & content | FormSection · FormActions · ImageUploader · VariantMatrixEditor · SlugField · SeoPanel · RichTextToolbar |
| 07 | Communication | ConversationRow · ChatBubble (customer / agent / AI / system) · ChannelBadge · AISuggestion · HandoverBanner |
| 08 | Feedback & overlays | ConfirmDialog · AdminEmptyState · ActivityFeed · AdminToast · PermissionMatrix · IntegrationCard |

## 4. States every screen covers

Loading (skeleton at final size) · Empty (`no-data-yet`, `all-clear`) · No results (echo filters) · Error (request id + retry) · Success (inline saved bar, not a toast) · Confirmation (ConfirmDialog, typed for destructive) · Permission denied. A dedicated "States" board on page 07 shows them on the order board.

## 5. Bangladesh specifics used in mock data

`৳` with thousands commas · phones `01712 046 318` (013–019 only, masked `01712•••318` in lists) · Dhaka time `08 Nov 2026, 2:30 pm` · couriers Steadfast + Pathao (Steadfast unverified, no merchant account yet) · payments COD, bKash, Nagad, Rocket, SSLCommerz · divisions/districts (Dhaka, Chattogram, Khulna, Sylhet, Rajshahi) · delivery ৳70 inside Dhaka / ৳100 suburb / ৳130 outside, free over ৳3,000 (placeholders to confirm) · BSTI certificate on products · trade licence + BIN on invoices.

## 6. Build log

**2026-11-11 — first full pass complete.**

Page `04 Admin Components` (root `180:18`) — 9 sections, ~45 components/sets: Admin text styles (16), 115 Phosphor admin icons, AdminButton `184:242`, AdminIconButton `184:267`, AdminInput `184:338`, AdminSelect `184:369`, SearchField `185:28`, StatusChip `185:79`, Tag `185:92`, SegmentedControl `185:110`, AdminTab `185:125`, AdminField `185:151`, SidebarItem `187:91`, Sidebar `187:473`, Topbar `187:474`, ContentHeader `187:530`, StatCard `189:360`, ChartCard Line/Bar/Stacked/Donut/Empty/Loading `189:361…189:540`, DataTable example + states `192:532…192:950`, BulkActionBar `193:740`, DateRangePicker `193:741/749`, OrderStatusBadge `190:384`, RiskPill `190:405`, PaymentChip `190:441`, OrderStatusStepper `194:683`, ShipmentTrackingTimeline `194:835`, CourierProviderCard `194:1014`, CourierBookingPanel `196:743`, RiskScoreCard Full/Compact `197:858/995`, CustomerPhoneLookup `197:1057`, FormSection `198:901`, FormActions `198:1085`, SlugField `198:1086`, SeoPanel `198:1123`, ImageUploader `201:1035`, VariantMatrixEditor `201:1201`, RichTextToolbar `201:1570`, PageBuilderSection `201:1716`, ChannelBadge `202:1374`, ConversationRow `202:1454`, ChatBubble `202:1487`, AISuggestion `202:1488`, HandoverBanner `202:1529`, ChatComposer `202:1560`, ConfirmDialog `203:1387`, AdminEmptyState `203:1531`, ActivityFeed `203:1532`, PermissionMatrix `203:1610`, IntegrationCard `203:2257`, AdminToast `203:2315`.

Page `07 Admin — Desktop` (root "Admin — Desktop", lavender backdrop) — 41 screens at 1440:
- 00 Access & states: Sign in + 2FA `224:17284`, States board `224:17364`
- 01 Overview & analytics: Dashboard `204:9`, Analytics `217:10289`, Tracking & pixels `217:10806`
- 02 Orders & fulfilment: Orders board `205:681`, Order detail `206:1475`, Create order `207:2243`, Courier booking (drawer open) `209:3581`, Shipments `211:3984`, COD settlements `211:4641`, Returns/refunds/exchanges `211:5121`
- 03 Fraud & risk: Verification queue `207:2833`, Phone lookup `209:2968`
- 04 Catalog & inventory: Products `212:4940`, Product edit `212:5610`, Categories `212:7132`, Inventory (multi-branch) `213:6955`, Stock ledger `213:7516`, Purchase orders `213:7971`
- 05 Customers & marketing: Customers `214:7758`, Customer profile `214:8327`, Loyalty & store credit `214:8857`, Discounts & coupon builder `216:8690`, Abandoned carts `216:9197`
- 06 Customer communication: Unified inbox `216:9614`, AI assistant `217:9798`
- 07 Online store & SEO: Page builder `219:10678`, Pages & navigation `219:11313`, Blog editor `219:11852`, SEO center `221:12872`, Redirects `221:13339`
- 08 Money: P&L `221:13802`, Expenses `222:13676`, POS register `222:14159`, Payment methods `222:14528`
- 09 Settings & system: Staff & roles `223:14393`, Automations `223:15784`, Integrations `223:16188`, Store settings & branches `224:16358`, Activity log `224:16873`

**Responsive pass (2026-11-11).** Section `10 · Responsive — 1024` (icon-rail sidebar, 920 content, 3-up stats, 288 rails, wide tables keep columns + scrollbar, charts stacked): Dashboard `226:18450`, Orders board `227:17645`, Order detail `227:18957`, Product edit `227:20298`, Profit & loss `227:21538`, Inbox `227:22081`, Phone lookup `227:22704`, Courier booking `227:23358`, POS `227:24210`. Section `11 · Responsive — 768` (no sidebar, `Topbar / Tablet` `228:1857` with menu button, tables → `OrderCard` `228:1891`, actions docked to the bottom): Dashboard `229:21176` (needs-attention above totals), Orders board `229:21429`, Order detail `229:21746` (vertical stepper), Courier booking sheet `230:21731`, Phone lookup `230:21905`, Product edit `230:22079` (accordion + two-row save bar), Inbox conversation `230:22274`, Navigation drawer `230:22387`, POS portrait `230:22613`. RiskScoreCard panels are now fluid. Bengali digits in Bangla strings were replaced with Western digits (rule 36).

Not drawn yet (pattern exists): brands, attributes, review moderation, stock transfer form, supplier detail, flash-sale builder, campaigns, daily reports, risk rules, 1024/768 breakpoints, dark-mode pass.

Decisions made in this pass that differ from `04-admin-spec.md`: sidebar active item uses `bg/brand-subtle` + brand icon instead of a solid brand fill (calmer, per the "calm pro + brand" choice); the topbar is 60 high, not 56.

Placeholders to confirm with the owner: delivery charges ৳70/৳100/৳130, hotline 09610-123456, trade licence and BIN numbers, bKash merchant number, Nagad fee, loyalty rates, branch addresses.
