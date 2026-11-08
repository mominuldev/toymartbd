# Agent 3 — AppByte benchmark + Pathao & Steadfast courier API reference

Research date: **2026-11-07**
Target: design an equivalent-or-better admin system for **Toy Mart BD** (Bangladesh toy e-commerce).

---

## 0. Critical framing — read this first

**`appbyte.net` is not an "AppByte" product site. It is a single-page Bengali marketing landing page for a product called "EcomByte", published by "appByte Technologies Ltd".**

Two hard facts that shape everything below:

1. **There are no sub-pages.** I probed `robots.txt`, `sitemap.xml`, `/features`, `/pricing`, `/docs`, `/blog`, `/demo`, `/integrations`, `/changelog`, `/help`, `/about`, `/api-docs`, `/login`, `/register`, `/app`, `/dashboard`. **Every single path returns HTTP 200 with byte-identical content (634,483 bytes)** — the same SPA shell. There is no features page, no pricing page, no module docs, no blog, no help centre, no API docs, and no public demo instance. The footer *lists* those link labels (`ফিচার`, `প্রাইসিং`, `ইন্টিগ্রেশন`, `মোবাইল অ্যাপ`, `চেঞ্জলগ`, `হেল্প সেন্টার`, `সেলার একাডেমি`, `ব্লগ`, `ট্রেড লাইসেন্স গাইড`, `বিকাশ মার্চেন্ট গাইড`, `API ডকস`, `আমাদের সম্পর্কে`, `ক্যারিয়ার`, `পার্টনার`, `প্রেস`, `যোগাযোগ`, `সেবার শর্তাবলি`, `গোপনীয়তা নীতি`, `রিফান্ড নীতি`, `ডেটা নিরাপত্তা`) but in the source these are **plain strings in a `links:[...]` array with no `href`** — they are decorative, non-functional labels. The only real links in the whole site are `#top`, `#pricing`, `#faq`, `#landing-pages` and `https://wa.me/8801891614300`.

2. **`WebFetch` returns nothing** because the page is 100% client-rendered React. I recovered the content by downloading the raw HTML (all JS is inlined into `index.html`) and extracting the string literals, FAQ array, pricing array and feature-comparison array directly from the bundle. **Everything in Section 1 is therefore verbatim marketing copy from the production bundle** — which is strong evidence of *what is claimed*, but it is **all first-party marketing copy**. There is **zero independent verification** of any of it (see Section 7).

Contact / identity data from the bundle footer:
- Company: `© 2026 appByte Technologies Ltd`
- Product: `EcomByte` · tagline `শুধুমাত্র বাংলাদেশের জন্য তৈরি ই‑কমার্স সলিউশন।` ("an e-commerce solution built only for Bangladesh")
- Phone / WhatsApp: `01891-614300` → `https://wa.me/8801891614300`
- Email: `business.appbyte@gmail.com` (a Gmail address, not a company domain)
- Socials: `facebook.com/profile.php?id=61581379350465` (a numeric-ID profile, i.e. a very new page), `instagram.com/appbytebd`, `youtube.com/@appByte-bd`
- Demo video: YouTube ID `f9HlOycwihw`, embedded as `https://www.youtube.com/embed/f9HlOycwihw?autoplay=1&rel=0&modestbranding=1&playsinline=1`. Title confirmed: **"কিভাবে আমাদের Ecombyte ওয়েবসাইট নিজেই সেটআপ করবেন? 🚀"** ("How to set up our Ecombyte website yourself").

**Maturity read:** numeric-ID Facebook page, Gmail business address, no docs/blog/changelog/help-centre despite linking to them, and a one-page site. This is an **early-stage / pre-launch-grade product**, not an established incumbent. Treat its feature list as a *roadmap/claim set*, not as proven shipped software.

---

## 1. AppByte (EcomByte) — Confirmed capabilities

All evidence URL = `https://appbyte.net/` (the inlined JS bundle at that URL). Where a claim came from the FAQ array I note `FAQ Qn`; from the pricing feature-comparison array, `pricing table`; from the feature grid, `feature grid`; from an interactive demo widget's own data, `demo widget`.

| Module | Capability | Evidence URL | Wording seen (verbatim Bengali + gloss) |
|---|---|---|---|
| **Order management** | Order statuses pipeline | appbyte.net (demo widget) | Status chips array: `ড্রাফট` (Draft), `কনফার্মড` (Confirmed), `প্যাকড` (Packed), `কুরিয়ারে` (With courier), `ডেলিভারড` (Delivered). A 4-step progress bar: `অর্ডার কনফার্ম` → `প্যাকড` → `কুরিয়ারে` → `ডেলিভারড`, labelled `ডেলিভারির ধাপ` ("delivery steps") |
| Order management | Canonical status slugs | appbyte.net (FAQ Q18) | "অর্ডারের জন্য একাধিক স্ট্যাটাস রয়েছে, যেমন **pending, confirmed, in_transit, delivered, returned ও cancelled**" — note these are given as literal English snake_case slugs |
| Order management | Return/cancel handling adjusts stock + accounts | appbyte.net (FAQ Q18) | "অর্ডার রিটার্ন হলে স্ট্যাটাস আপডেট করে স্টক ও হিসাব ম্যানেজ করা যায়" (on return, update status to manage stock and accounts) |
| Order management | Order **sources** tracked | appbyte.net (FAQ Q11) | "প্রতিটি অর্ডারের সোর্স ট্র্যাক করা যায় — যেমন **ওয়েবসাইট, ল্যান্ডিং পেজ, Facebook, TikTok, ফোন বা অন্যান্য সোর্স**" (website, landing page, Facebook, TikTok, **phone**, other) |
| Order management | Traffic attribution retained 90 days | appbyte.net (FAQ Q11) | "Traffic Attribution-এর মাধ্যমে **fbclid, ttclid, gclid এবং UTM** প্যারামিটারও **৯০ দিন** পর্যন্ত সংরক্ষিত থাকে" |
| Order management | Manual/phone order entry with phone-number autofill | appbyte.net (pricing table) | "**কাস্টমারের নাম্বার টাইপ করলেই ডেটা অটো-ফিল।**" (type the customer's number and data auto-fills) — all 3 plans |
| Order management | Offline-capable order entry (mobile app) | appbyte.net (feature grid) | `অফলাইন-ফ্রেন্ডলি অর্ডার এন্ট্রি` — "নেট না থাকলেও অর্ডার নিন, অনলাইন হলেই নিজে থেকেই সিঙ্ক হবে" (take orders offline, auto-syncs when online) |
| Order management | Unlimited incomplete orders | appbyte.net (pricing table) | "**Unlimited Incomplete অর্ডার**" — all 3 plans |
| Order management | Incomplete-order (abandoned cart) recovery via WhatsApp/SMS | appbyte.net (demo widget) | Channel toggle `রিমাইন্ডার কোন চ্যানেলে যাবে` with options **WhatsApp / SMS**; scripted messages e.g. "আপনার অর্ডারে ১টি জামদানি শাড়ি রয়ে গেছে। এখন অর্ডার করলে ডেলিভারি ফ্রি।"; KPI tiles `এই মাসে ফিরে পাওয়া বিক্রি` (sales recovered this month) |
| Order management | Round-robin order assignment to staff | appbyte.net (pricing table) | "**অর্ডারসমূহ Active Employee দের মধ্যে সমানভাবে ভাগ হবে**" (orders split equally among active employees) — all 3 plans |
| Order management | Min/max order quantity per product | appbyte.net (feature grid) | `মিনিমাম/ম্যাক্সিমাম কোয়ান্টিটি কন্ট্রোল` — "প্রতিটি পণ্যে সর্বনিম্ন ও সর্বোচ্চ অর্ডার লিমিট" |
| Order management | Customer-facing order tracking, no login | appbyte.net (FAQ Q13; feature grid) | "প্রতিটি অর্ডারের জন্য একটি **সিকিউর ট্র্যাকিং লিংক** তৈরি হয়। কাস্টমার **লগইন ছাড়াই** লিংকে গিয়ে ... ডেলিভারি স্ট্যাটাস দেখতে পারবে" |
| **Inventory / stock** | Per-product stock, SKU, variants | appbyte.net (FAQ Q17) | "প্রতিটি পণ্যের **স্টক, SKU এবং ভ্যারিয়েন্ট যেমন রঙ/সাইজ** ড্যাশবোর্ডে ম্যানেজ করা যায়" |
| Inventory / stock | Auto stock decrement on order | appbyte.net (FAQ Q17) | "**অর্ডার হলে স্টক অটো কমে যায়**" |
| Inventory / stock | Low-stock alerts (dashboard + SMS) | appbyte.net (FAQ Q17; feature grid) | `লো-স্টক অ্যালার্ট` — "পণ্য ফুরিয়ে যাওয়ার আগেই ড্যাশবোর্ড আর **SMS**-এ সতর্কবার্তা পাবেন" |
| Inventory / stock | Bulk stock update | appbyte.net (FAQ Q17) | "**বাল্ক আপডেট** করা যায়" |
| Inventory / stock | Real-time stock count incl. returns & restock | appbyte.net (feature grid) | `রিয়েল-টাইম স্টক কাউন্ট` — "প্রতিটি **বিক্রি, রিটার্ন আর রিস্টক** সাথেসাথেই হিসাবে বসে — খাতা মেলানোর দরকার নেই" |
| Inventory / stock | Multi-warehouse / multi-branch stock | appbyte.net (feature grid) | `মাল্টি-ওয়্যারহাউস ও শাখা` — "ঢাকা, চট্টগ্রাম বা যেকোনো শাখার স্টক একসাথে — কোন পণ্য কোথায়, এক নজরে" |
| Inventory / stock | Variant **and batch** tracking | appbyte.net (feature grid) | `ভ্যারিয়েন্ট ও ব্যাচ ট্র্যাকিং` — "সাইজ, কালার বা **ব্যাচ** আলাদা করে ট্র্যাক করুন" |
| Inventory / stock | **Dead-stock and stock-valuation reports** | appbyte.net (feature grid) | `ডেড স্টক ও ভ্যালুয়েশন রিপোর্ট` — "কোন পণ্যে টাকা আটকে আছে, কোনটা দ্রুত ঘুরছে — রিপোর্ট দেখে সিদ্ধান্ত নিন" |
| Inventory / stock | Dashboard stock summary | appbyte.net (dashboard section) | "স্টকে কতটি পণ্য রয়েছে, কতটি পণ্য বিক্রি হয়েছে, এবং কতটি পণ্যের স্টক লো রয়েছে, সবই জানতে পারবেন একই ড্যাশবোর্ডে" |
| **Courier integration** | **Exactly 4 couriers integrated: Steadfast, Pathao, RedX, Carrybee** | appbyte.net (FAQ Q7, Q8; `Ui` + `dd` arrays) | FAQ Q7: "**Steadfast, Pathao, RedX ও Carrybee — চারটি কুরিয়ারই ইন্টিগ্রেটেড।**" Code arrays confirm exactly 4: `Ui=[{value:"Steadfast",slug:"steadfast",prefix:"SF"},{value:"Pathao",slug:"pathao",prefix:"PT"},{value:"RedX",slug:"redx",prefix:"RX"},{value:"Carrybee",slug:"carrybee",prefix:"CB"}]`. **No eCourier, Paperfly, SA Paribahan, Sundarban or Delivery Tiger anywhere in the bundle** (grepped — zero hits) |
| Courier integration | Consignment prefixes | appbyte.net (`Ui` array) | Per-courier `prefix`: `SF`, `PT`, `RX`, `CB` — used in the demo as `#<prefix><n>`; booking confirmation copy `"<Courier>-এ বুক হয়েছে · #<id>"` ("booked at \<courier\> · #id") |
| Courier integration | Select orders in dashboard → send straight to courier (bulk) | appbyte.net (FAQ Q7) | "ড্যাশবোর্ড থেকে **অর্ডার সিলেক্ট করে সরাসরি কুরিয়ারে পাঠানো যায়**" |
| Courier integration | One-click / auto booking on confirm | appbyte.net (pricing table; carousel) | "**অর্ডার কনফার্ম করলেই অটো কুরিয়ার সেন্ড।**" (confirm the order and it auto-sends to courier) — all 3 plans. Carousel headline `ওয়ান-ক্লিক কুরিয়ার ইন্টিগ্রেশন` |
| Courier integration | **Status auto-sync** | appbyte.net (FAQ Q7) | "**স্ট্যাটাস অটো সিঙ্ক হয়**" |
| Courier integration | Auto-update of courier Return & Paid panels | appbyte.net (pricing table) | "**কুরিয়ারের Return ও Paid প্যানেলে অটো আপডেট।**" — all 3 plans |
| Courier integration | Return-parcel receiving workflow | appbyte.net (pricing table) | "**Return Received** রিটার্ন পার্সেল কুরিয়ার থেকে বুঝে নিন" (receive the returned parcel back from the courier) |
| Courier integration | Bulk status update by uploading courier invoice `.xlsx` | appbyte.net (FAQ Q19) | "**বাল্ক কুরিয়ার ইনভয়েস (.xlsx) আপলোড করে একসাথে স্ট্যাটাস আপডেট** করার সুবিধাও আছে" |
| Courier integration | Zone-based delivery charge for all 64 districts | appbyte.net (FAQ Q7; feature grid) | "**৬৪ জেলার জন্য জোনভিত্তিক ডেলিভারি চার্জ সেট করা যায়**"; feature grid `ডেলিভারি চার্জ ম্যানেজমেন্ট` — "একবার সেট করলেই অর্ডারে অটো বসে যায়" |
| Courier integration | Courier webhooks exist | appbyte.net (FAQ Q28) | "Public API এবং **কুরিয়ার ওয়েবহুক** আছে" |
| **Fake / fraud order** | Product name: **"Fake Order Guard"** | appbyte.net (FAQ Q3) | "**Fake Order Guard** দিয়ে ডুপ্লিকেট বা ঝুঁকিপূর্ণ অর্ডার শনাক্ত ও ব্লক করা যায়" |
| Fake / fraud order | **Claims 7 couriers' data for auto-blocking** | appbyte.net (pricing table) | "**৭ টি কুরিয়ার ডেটা দিয়ে ফেক কাস্টমার অটো ব্লক।**" ("auto-block fake customers using 7 couriers' data") — all 3 plans. ⚠️ **This contradicts FAQ Q7's "4 couriers" and the 4-entry code array.** The live UI badge is generated as `` `${co(Ui.length)}টি কুরিয়ার রিপোর্ট` `` — i.e. it renders **"4 courier reports"**, computed from `Ui.length` = 4 |
| Fake / fraud order | Check is **by phone number**, returns per-courier signals | appbyte.net (demo widget `Mx`) | Risk panel titled `ঝুঁকি বিশ্লেষণ` (risk analysis), subtitle `ক্রেতা: <name>`, badge `4টি কুরিয়ার রিপোর্ট`. Each signal row is **rendered next to a courier logo** (`Fd({courier:Ui[G%Ui.length]})`), i.e. each signal is attributed to Steadfast/Pathao/RedX/Carrybee |
| Fake / fraud order | Signals used — **courier delivery history** | appbyte.net (demo widget `Mx`) | Safe buyer: "৯টি পার্সেল, সব ডেলিভারড" (9 parcels, all delivered, w=96), "৭টি পার্সেল, ১টি রিটার্ন" (w=90), "নম্বরটি ভেরিফায়েড, ২ বছর পুরনো" (number verified, 2 years old, w=94), "গড় ডেলিভারি সময় ৩৬ ঘণ্টা" (avg delivery 36h, w=88) |
| Fake / fraud order | Signals used — **medium risk** | appbyte.net (demo widget `Mx.mid`) | "৫টি পার্সেলের ২টি রিটার্ন" (2 of 5 parcels returned, w=52), "কোনো অর্ডার হিস্ট্রি নেই" (no order history, w=62), "নম্বরটি মাত্র ৩ দিনের পুরনো" (number only 3 days old, w=58), "ডেলিভারি ঠিকানা যাচাই হয়নি" (address unverified, w=44) |
| Fake / fraud order | Signals used — **high risk** | appbyte.net (demo widget `Mx.bad`) | "গত ৩০ দিনে ৩টি রিটার্ন" (3 returns in last 30 days, w=95), "৩টি COD পেমেন্ট নেওয়া হয়নি" (3 COD payments not taken, w=91), "**একই ঠিকানায় ৪টি আলাদা নাম**" (4 different names at the same address, w=88), "ভেরিফিকেশন কলে সাড়া দেননি" (did not answer verification call, w=84) |
| Fake / fraud order | **Success-ratio gauge + 3 risk tiers + explicit 70% threshold** | appbyte.net (demo widget `Mx`, `Xb`) | Buyer-type tabs `ক্রেতার ধরন`: `নিরাপদ` (safe) / `মাঝারি ঝুঁকি` (medium risk) / `ফ্রড` (fraud). Tiers carry a `success` % gauge — **ok: 94%, mid: 56%, bad: 7%** — with `label`/`action`/`cta`: ok → `নিরাপদ` / `অটো কনফার্ম হবে` / `এক ক্লিকে কনফার্ম করুন`; mid → `সতর্ক` / `ফোনে যাচাই করুন` / `কল দিয়ে যাচাই করুন`; bad → `ঝুঁকিপূর্ণ` / `অর্ডার আটকে দিন` / `অর্ডার আটকে দিন`. Tone chips `পরিষ্কার`/`যাচাই`/`ঝুঁকি`. **Explicit rule stated in copy: "স্কোর ৭০% ছাড়ালে অর্ডার নিজে থেকেই আটকে যায়, আর নিরাপদ ক্রেতা অটো কনফার্ম হয়।"** ("if the score exceeds 70% the order is held automatically, and safe buyers are auto-confirmed") |
| Fake / fraud order | Second fraud widget: 0–100 risk score + 3 checks | appbyte.net (demo widget `Ax`) | Safe customers get checks `["নম্বর ভেরিফাইড","ঠিকানা যাচাই","অর্ডার হিস্ট্রি"]` with `risk:12` / `risk:8`; fraud customer gets `["নম্বর মেলেনি","ঠিকানা অস্পষ্ট","ডুপ্লিকেট নম্বর"]` with `risk:78, safe:false`, area `অজানা ঠিকানা`, phone `০১৩০০-০০০০০০`. Verdict strings: `অর্ডার নিরাপদ — এগিয়ে যান` / `সন্দেহজনক — ম্যানুয়াল রিভিউ`. The scan animation iterates the 4 courier logos (`dd` array) — explicitly framed as querying each courier |
| Fake / fraud order | Customer block / unblock + courier success-ratio monitoring | appbyte.net (FAQ Q3) | "একই নাম/নম্বরে বারবার অর্ডার, ঝুঁকিপূর্ণ কাস্টমার, **কাস্টমার ব্লক/আনব্লক** এবং **কুরিয়ার সাকসেস রেশিও মনিটরিং**য়ের সুবিধা আছে" |
| Fake / fraud order | Duplicate order block | appbyte.net (carousel; hero) | `ডুপ্লিকেট অর্ডার ব্লক`, `ফেক অর্ডার ব্লক`, `অ্যাডভান্সড ফ্রড চেকিং সিস্টেম` |
| Fake / fraud order | Custom order-gap duration to stop repeat fake orders | appbyte.net (pricing table) | "**বারবার ফেইক অর্ডার আটকাতে কাস্টম অর্ডার গ্যাপ ডিউরেশন।**" — Pro + Enterprise only (**not** Basic) |
| Fake / fraud order | Smart IP blocking vs spam/bot hits | appbyte.net (pricing table) | "**স্প্যামিং ও বট হিট ঠেকাতে স্মার্ট IP ব্লকিং সিস্টেম।**" — all 3 plans |
| Fake / fraud order | OTP verification to guarantee real customers | appbyte.net (pricing table) | "**OTP ভেরিফিকেশনের মাধ্যমে ১০০% রিয়েল কাস্টমার যাচাই।**" — Pro + Enterprise only |
| Fake / fraud order | "Blocked today" counter | appbyte.net (demo widget) | `আজ আটকানো ফেক অর্ডার <n>টি` (n fake orders blocked today); order-card chips `ব্লকড ✕` / `কনফার্মড ✓`, `সব যাচাই সম্পন্ন ✓`, `রোবট স্ক্যান চলছে…` |
| **Accounting / P&L** | Gross Sales + Net Profit auto report | appbyte.net (pricing table) | "**Gross Sales ও Net Profit এর অটো রিপোর্ট।**" — all 3 plans |
| Accounting / P&L | Ads + courier cost accounting | appbyte.net (pricing table) | "**Ads ও কুরিয়ার খরচের নিখুঁত হিসাব।**" (precise accounting of ad and courier costs) — all 3 plans |
| Accounting / P&L | **Projected profit from in-transit parcels** | appbyte.net (pricing table) | "**ট্রানজিটে থাকা পার্সেল থেকে সম্ভাব্য লাভের হিসাব।**" (calculation of likely profit from parcels in transit) — all 3 plans |
| Accounting / P&L | Expenses + transactions module | appbyte.net (FAQ Q22) | "এছাড়া **একাউন্টিং সেকশনে খরচ ও ট্রানজেকশন ট্র্যাক** করা যায়" |
| Accounting / P&L | Profit/loss headline feature | appbyte.net (pricing plan Basic) | `📊 লাভ-লসের হিসাব` (profit-loss accounting) |
| **COD reconciliation** | Per-order COD status + per-courier amounts due | appbyte.net (FAQ Q9) | "প্রতিটি অর্ডারের **COD স্ট্যাটাস** এবং **কোন কুরিয়ারে কত টাকা আসছে** তা ড্যাশবোর্ডে দেখা যায়। রিপোর্ট **Excel-এ এক্সপোর্ট** করে হিসাবও রাখা যায়" |
| COD reconciliation | COD amount is a first-class order field | appbyte.net (demo widget `LT`) | Booking card fields: `প্রাপক` (recipient), `মোবাইল`, `ঠিকানা`, `পার্সেল`, **`COD অ্যামাউন্ট` `৳৪,৮০০`** (marked `strong:!0`); plus a `COD মোট` (COD total) aggregate |
| **Invoice / label print** | Invoice + courier barcode print | appbyte.net (FAQ Q19) | "অর্ডারের **ইনভয়েস এবং কুরিয়ার বারকোড প্রিন্ট** করা যায়" |
| Invoice / label print | **10+ templates incl. sticker print** | appbyte.net (pricing table) | "**ইনভয়েস স্টিকার প্রিন্ট সহ ১০+ রেডি টেমপ্লেট।**" (10+ ready templates including invoice sticker print) — all 3 plans |
| Invoice / label print | **3 invoices per page for fast packing** | appbyte.net (pricing table) | "**দ্রুত প্যাকিংয়ের জন্য এক পেজে ৩টি ইনভয়েস প্রিন্ট।**" — all 3 plans |
| Invoice / label print | Auto invoice generation + PDF | appbyte.net (feature grid) | `অটো ইনভয়েস` — "প্রতিটি অর্ডারের ইনভয়েস অটো তৈরি — এক ক্লিকে **প্রিন্ট বা PDF ডাউনলোড**" |
| Invoice / label print | Invoices in Bengali | appbyte.net (FAQ Q27) | "ল্যান্ডিং পেজ, স্টোরফ্রন্ট, SMS এবং **ইনভয়েস** — সবই **বাংলায়**" |
| **Customer / CRM** | OTP phone login for customers | appbyte.net (FAQ Q14) | "কাস্টমার **মোবাইল নম্বরে OTP** দিয়ে লগইন করতে পারবে। OTP **বাংলা SMS**-এ যায়" |
| Customer / CRM | Customer history lookup + autofill by number | appbyte.net (pricing table; demo widget) | "কাস্টমারের নাম্বার টাইপ করলেই ডেটা অটো-ফিল।"; demo check `অর্ডার হিস্ট্রি` |
| Customer / CRM | One-click WhatsApp message to customer | appbyte.net (pricing table) | "**এক ক্লিকেই কাস্টমারের হোয়াটসঅ্যাপে মেসেজ।**" — all 3 plans |
| Customer / CRM | Multi-channel chat widgets | appbyte.net (feature grid) | `মাল্টিপল চ্যাট উইজেট` — "**WhatsApp, Messenger ও লাইভ চ্যাট**" |
| Customer / CRM | Product reviews + 5-star ratings with moderation | appbyte.net (FAQ Q21; feature grid) | "কাস্টমার পণ্যে রিভিউ ও ৫-স্টার রেটিং দিতে পারবে। আপনি ড্যাশবোর্ড থেকে রিভিউ **মডারেট করে পাবলিশ বা আড়াল** করতে পারবেন" |
| **Analytics / reporting** | Sales, order, product, courier, COD reports + Excel export | appbyte.net (FAQ Q22) | "**সেলস, অর্ডার, পণ্য, কুরিয়ার ও COD রিপোর্ট** দেখা যায় এবং **Excel-এ এক্সপোর্ট** করা যায়" |
| Analytics / reporting | Unified admin dashboard | appbyte.net (feature grid) | `পাওয়ারফুল অ্যাডমিন ড্যাশবোর্ড` — "**সেলস, স্টক, কুরিয়ার আর খরচ** — পুরো ব্যবসার ছবি একটাই শক্তিশালী ড্যাশবোর্ডে" |
| Analytics / reporting | Product page-view analytics | appbyte.net (feature grid) | `স্ট্র্যাটেজিক প্রোডাক্ট পেজ ভিউ` — "কোন পণ্য কতবার দেখা হচ্ছে — ভিউ ডেটা দেখে বুঝুন আগামী সেরা বিক্রি কী" |
| Analytics / reporting | "Advance Business Report" is a **paid differentiator** | appbyte.net (pricing array) | Field `advanceBusinessReport`: Basic = `নেই` (absent), Pro = `রয়েছে` (present), Enterprise = `রয়েছে` |
| Analytics / reporting | GTM + Google Analytics | appbyte.net (feature grid) | `গুগল ট্যাগ ম্যানেজার ও অ্যানালাইটিক` |
| **Product management** | Unlimited products & orders | appbyte.net (pricing table + highlights) | "**Unlimited প্রোডাক্ট আপলোড**", "♾️ আনলিমিটেড প্রোডাক্ট ও অর্ডার"; highlights: `অর্ডার প্রসেস` = `আনলিমিটেড`, `এক্সট্রা অর্ডার চার্জ` = `নেই` (no extra per-order charge), `অতিরিক্ত মাসিক চার্জ` = `নেই` |
| Product management | Variants (colour/size), SKU | appbyte.net (FAQ Q17) | as above |
| Product management | Coupons & vouchers engine | appbyte.net (FAQ Q20; feature grid) | "**পারসেন্টেজ বা ফিক্সড ডিসকাউন্ট, কুপন কোড, মেয়াদ এবং ব্যবহারের সীমা** সেট করা যায়" |
| Product management | Group / bundle product checkout | appbyte.net (feature grid) | `গ্রুপ প্রোডাক্ট চেকআউট` — "একাধিক পণ্য বান্ডল করে অর্ডার" |
| Product management | **B2B quotation manager** | appbyte.net (feature grid) | `প্রোডাক্ট কোটেশন ম্যানেজার` — "বাল্ক অর্ডারের জন্য কোটেশন তৈরি, পাঠান আর ট্র্যাক করুন — **B2B বিক্রি সহজ**" |
| Product management | Free data migration from Excel/CSV | appbyte.net (FAQ Q24) | "**ফ্রি ডেটা মাইগ্রেশন** সাপোর্ট আছে। Excel/CSV ফাইলে পণ্যের তথ্য থাকলে আমাদের টিম সেটা মাইগ্রেট করতে সাহায্য করবে" |
| **Multi-user / RBAC** | 3 named roles + per-module permissions | appbyte.net (FAQ Q23; feature grid) | "**অ্যাডমিন, ম্যানেজার ও এমপ্লয়ি** — আলাদা **রোল ও পারমিশন** সেট করা যায়। প্রত্যেক স্টাফকে **নির্দিষ্ট মডিউলের অ্যাক্সেস** দেওয়া সম্ভব" |
| Multi-user / RBAC | Unlimited employees on every plan | appbyte.net (pricing highlights) | `এমপ্লয়ী ম্যানেজমেন্ট` = `আনলিমিটেড` |
| **Notifications** | Bengali SMS: OTP, order confirm, status update, bulk marketing | appbyte.net (FAQ Q15) | "**বাংলা SMS** সাপোর্ট আছে। কাস্টমার লগইনের **OTP**, **অর্ডার কনফার্মেশন**, **স্ট্যাটাস আপডেট** এবং **বাল্ক মার্কেটিং SMS** পাঠানো যায়। **SMS টেমপ্লেট, লগ ও রিচার্জ** ড্যাশবোর্ড থেকেই ম্যানেজ করা যায়" |
| Notifications | Single or bulk SMS to any number | appbyte.net (pricing table) | "**যেকোনো মোবাইল নাম্বারে সিঙ্গেল বা বাল্ক SMS সেন্ড।**" — Pro + Enterprise only |
| Notifications | Secure OTP + SMS retargeting | appbyte.net (pricing table) | "**নিরাপদ OTP এবং SMS রিটার্গেটিং।**" — Pro + Enterprise only |
| Notifications | In-app bell + **browser push (VAPID)** | appbyte.net (FAQ Q16) | "SMS-এর পাশাপাশি **ইন-অ্যাপ বেল নোটিফিকেশন** এবং **ব্রাউজার পুশ নোটিফিকেশন (VAPID)** আছে। **নতুন অর্ডার, স্ট্যাটাস পরিবর্তন বা স্টক কমে গেলে** নোটিফিকেশন পাওয়া যায়" |
| Notifications | WhatsApp one-click + Messenger widget | appbyte.net (pricing table; feature grid) | as above. Hero copy: "এক ক্লিকেই ... কিংবা ... মেসেজ পাঠাতে পারবেন।" |
| **Payments** | Advance payment via bKash / Nagad / Rocket with proof verification | appbyte.net (FAQ Q10; feature grid) | "কাস্টমার **bKash, Nagad বা Rocket**-এ টাকা পাঠিয়ে **প্রুফ জমা** দিতে পারে। আপনি ড্যাশবোর্ড থেকে **যাচাই করে অর্ডার কনফার্ম** করতে পারবেন। পাশাপাশি **COD**-ও চালু রাখা যায়। নিজের **মার্চেন্ট অ্যাকাউন্ট** থাকলে সেটিও যুক্ত করা যাবে" |
| Payments | Payment gateway integration | appbyte.net (feature grid) | `পেমেন্ট গেটওয়ে ইন্টিগ্রেশন` — "জনপ্রিয় পেমেন্ট গেটওয়ে যুক্ত করে অনলাইনেই টাকা নিন — **COD-নির্ভরতা কমান**" |
| **Marketing / ads** | Meta Pixel + Conversions API (server-side) + TikTok Events API | appbyte.net (FAQ Q4) | "**Meta Pixel + Conversions API (server-side)** এবং **TikTok Events API** সাপোর্ট আছে। অর্ডার কনফার্ম হলে **Purchase/PlaceAnOrder** ইভেন্ট অটো পাঠানো যায়" |
| Marketing / ads | Free pixel setup; CAPI is paid-tier | appbyte.net (pricing table) | "ফেসবুক ও টিকটক পিক্সেল সম্পূর্ণ ফ্রি সেটআপ।" (all plans) vs "**FB & Tik Tok Conversion API সেটআপ ফ্রি**" (Pro + Enterprise only) |
| Marketing / ads | Real-time FB catalog + feed link | appbyte.net (pricing table) | "**রিয়েল-টাইম FB Catalog আপডেট ও Feed লিংক**" — Pro + Enterprise only |
| Marketing / ads | Block-based landing page builder with direct order form | appbyte.net (FAQ Q12; pricing table) | "**ব্লক-ভিত্তিক ল্যান্ডিং পেজ বিল্ডার** আছে। **Hero, Section** সহ বিভিন্ন ব্লক ও ভ্যারিয়েন্ট বেছে নিয়ে ... **সরাসরি সেখান থেকেই অর্ডার নেওয়া যায়**"; "ল্যান্ডিং পেজের জন্য রেডিমেড টেমপ্লেট সুবিধা।" |
| Marketing / ads | Ad-blocker-proof server-side tracking | appbyte.net (hero) | `আনলিমিটেড সার্ভার-সাইড ট্র্যাকিং`, `অ্যাড-ব্লকার প্রুফ`, `<n> ইভেন্ট ট্র্যাক হয়েছে` |
| **Storefront / SEO** | Auto JSON-LD, Open Graph, canonical, sitemap | appbyte.net (FAQ Q26; feature grid) | "SEO-এর জন্য **JSON-LD, Open Graph ট্যাগ, canonical URL এবং sitemap** অটো জেনারেট হয়" |
| Storefront / SEO | SEO sitemap / Google indexing | appbyte.net (pricing table) | "SEO Sitemap Google Indexing (গুগল র‍্যাংকিং)" — all 3 plans |
| Storefront / SEO | Theme / layout manager, no code | appbyte.net (feature grid; pricing table) | `ডায়নামিক থিম লেআউট ম্যানেজার` — "কোড ছাড়াই **হেডার, ফুটার ও সেকশনের** সাজ বদলান"; "ওয়েবসাইটের হেডার ও কালার থিম কাস্টমাইজেবল।" |
| Storefront / SEO | Bengali-first with English toggle | appbyte.net (FAQ Q27) | "প্রয়োজন হলে **ইংরেজি ল্যাঙ্গুয়েজ টগল**ও আছে" |
| Storefront / SEO | Custom `.com` / `.com.bd` domain | appbyte.net (FAQ Q25) | "নিজের **.com বা .com.bd** ডোমেইন যুক্ত করা যায়" |
| Storefront / SEO | Free cloud hosting + free SSL | appbyte.net (feature grid) | `ক্লাউড হোস্টিং ও এসএসএল সার্টিফিকেট` — "**ফ্রি ক্লাউড হোস্টিং আর ফ্রি SSL**" |
| **Mobile app** | Branded customer app for Android + iOS | appbyte.net (feature grid) | `সব Android ও iOS ফোনে` — "একই ব্র্যান্ড, একটাই অ্যাপ"; `আল্ট্রা-ফাস্ট অ্যাপ`; `এক ট্যাপে সহজ অর্ডার` |
| **API** | Public API + webhooks, **Scale plan only** | appbyte.net (FAQ Q28) | "**Public API** এবং কুরিয়ার ওয়েবহুক আছে। **Scale প্ল্যানে** API অ্যাক্সেস ও ওয়েবহুক সাপোর্ট পাওয়া যায়, যার মাধ্যমে **ERP, অ্যাকাউন্টিং সফটওয়্যার** বা নিজের সিস্টেমের সাথে ইন্টিগ্রেশন করা যায়" — ⚠️ "Scale" plan **does not exist** in the pricing array (plans are Basic/Pro/Enterprise); stale copy |
| **Support** | WhatsApp/phone support only | appbyte.net (FAQ Q29) | "আমাদের **WhatsApp**-এ মেসেজ দিবেন কিংবা **কল** করবেন" — no ticketing, email SLA or chat channel named |

### 1.1 Pricing model (confirmed, verbatim from the `Ft.plans` array)

Header: `আপনার জন্য সঠিক প্ল্যান বেছে নিন` · subtitle "হোস্টিং, আপডেট ও সাপোর্ট সবই অন্তর্ভুক্ত" · period suffix `/বছর` (**per YEAR**) · note: **`প্যাকেজ ১ ও প্যাকেজ ২-এর মূল্য পুরো এক বছরের জন্য। মূল্যে ভ্যাট অন্তর্ভুক্ত।`** ("the price of packages 1 and 2 is for a full year; VAT included").

| Plan | Price (BDT) | Setup fee | Advance Business Report | Positioning | Selling points |
|---|---|---|---|---|---|
| **বেসিক** (Basic) | **৭০০০** = 7,000 /yr | `নেই` (none) | `নেই` (no) | `ছোট বিক্রেতা এবং নতুনদের জন্য আদর্শ` (small sellers & beginners) | ⚡ সুপারফাস্ট স্পিড · ♾️ আনলিমিটেড প্রোডাক্ট ও অর্ডার · 🛡️ **ফ্রড কাস্টমার চেক** · 📊 লাভ-লসের হিসাব · 🎧 বেসিক সাপোর্ট · 💾 ৫ জিবি হোস্টিং (১ বছর মেয়াদী) |
| **প্রো** (Pro) — `জনপ্রিয়` / most popular | **১২,০০০** = 12,000 /yr | `নেই` (none) | `রয়েছে` (yes) | `প্রফেশনাল ব্যবসার জন্য আদর্শ` | 🚀 আল্ট্রা-ফাস্ট স্পিড · 🟢 ওয়েবসাইট কখনো ডাউন হবে না · 📈 কাস্টমার বেশি হলেও সাইট স্লো হবে না · 👑 প্রায়োরিটি সাপোর্ট · 🗄️ ১০ জিবি হোস্টিং · 🌐 নিজের ডোমেইন হোস্টিংয়ে সেটআপ |
| **এন্টারপ্রাইজ** (Enterprise) | **৩০,০০০** = 30,000 | **৳১,০০০** = 1,000 | `রয়েছে` (yes) | `বড় ব্যবসার জন্য আদর্শ` | 🎨 ফুল কাস্টম ডিজাইন · 🔐 অ্যাডভান্সড সিকিউরিটি সিস্টেম · 👑 প্রিমিয়াম সাপোর্ট · 🛠️ কাস্টম ফিচার · 💯 **১০০% ওনারশিপ** · 🏗️ ফিউচার-প্রুফ আর্কিটেকচার |

**Model read-across:**
- **Annual prepay, not monthly SaaS.** BDT 7,000/yr ≈ **583/mo**; 12,000/yr ≈ **1,000/mo**. Hosting (5 GB / 10 GB) is bundled and expires with the year.
- **No commission on sales** (FAQ Q6: "EcomByte বিক্রির ওপর কোনো কমিশন নেয় না"), **no per-order charge**, **no extra monthly charge**, **unlimited employees** on all tiers. `custom:"আলোচনার সাপেক্ষে"` ("subject to negotiation") exists as a price label.
- Every CTA is `যোগাযোগ করুন` ("contact us") → WhatsApp. **There is no self-serve signup, no free trial, and no card checkout.** This is a high-touch, WhatsApp-sales motion.
- **Paywalled behind Pro:** FB/TikTok Conversions API, real-time FB catalog + feed, single/bulk SMS, secure OTP + SMS retargeting, OTP customer verification, custom order-gap duration, unlimited visitors/bandwidth, Advance Business Report. **Note the commercial tell: OTP verification and SMS — the two strongest anti-fake-order tools — are deliberately withheld from the cheapest tier.**

### 1.2 Confirmed third-party / identity evidence

| Fact | Source |
|---|---|
| "Appbyte" is a custom software/web/app development agency; "At App Byte, we help businesses thrive with custom software, web, and app development." Listed project size $1,000+, hourly rate $300+, **one** client review (5.0), for an Android gardening-service app | https://clutch.co/profile/appbyte |
| Demo video exists: "কিভাবে আমাদের Ecombyte ওয়েবসাইট নিজেই সেটআপ করবেন? 🚀" | https://www.youtube.com/watch?v=f9HlOycwihw |
| A GitHub org/repo `appbyte-bd/product-ecombyte` is indexed by search engines but returns **HTTP 404 / "Not Found"** from the GitHub API — private or deleted. No README obtainable | https://github.com/appbyte-bd/product-ecombyte (404 via api.github.com) |

---

## 2. AppByte — Inferred / unverified

Everything here is **NOT confirmed**. It is either a reasonable inference from the copy, or a capability the copy implies without stating.

**Inferences from AppByte's own wording (plausible but unstated):**
- **Bulk order actions beyond courier booking.** Confirmed: bulk courier send, bulk `.xlsx` status update, bulk SMS, bulk stock update. **Inferred:** bulk status change, bulk print, bulk assign, bulk tag/delete from a multi-select order grid. Never stated.
- **An order-list grid with filters/search.** A dashboard is confirmed; column-level filtering, saved views, inline editing, and date-range filters are **inferred** — the site never shows or describes the order list UI.
- **Purchases / suppliers / purchase orders / landed cost.** ⚠️ **Nothing in the bundle mentions suppliers, purchase orders, GRN, or cost-of-goods entry.** "ডেড স্টক ও ভ্যালুয়েশন রিপোর্ট" implies a cost basis must exist somewhere, and "লাভ-লসের হিসাব" / "Net Profit" implies COGS, so **some** cost field almost certainly exists — but the *purchase/supplier module* is **entirely unverified and may not exist**.
- **Stock adjustments with reasons** (damage, loss, audit correction) and **stock-movement ledger**. Implied by "রিয়েল-টাইম স্টক কাউন্ট" but never stated.
- **Inventory valuation method** (FIFO / weighted average / last cost). A "ভ্যালুয়েশন রিপোর্ট" is claimed; the **method is unknown**.
- **Which courier the fraud check actually queries, and how.** See Section 3.2 — AppByte claims "7 couriers' data" in the pricing table while its own code and FAQ say 4. **The actual data source is not disclosed anywhere on the site.** My inference (strongly supported by the category evidence in 3.2, but *not* confirmed for AppByte) is that it logs into courier **merchant portals** with the merchant's own credentials and calls undocumented internal endpoints.
- **Whether there is a shared cross-merchant blacklist.** AppByte's copy mentions "কাস্টমার ব্লক/আনব্লক" (per-merchant block list) and courier data. It does **not** claim a cross-merchant shared blacklist. Competitors do (see 3.2). **Unverified for AppByte.**
- **Consignment ID storage/display.** The demo shows `"<Courier>-এ বুক হয়েছে · #<id>"` and prefixes SF/PT/RX/CB, so consignment IDs are evidently captured. Whether they are searchable, exportable, or linked to courier tracking pages is **unverified**.
- **Price/delivery-charge calculation via courier API.** AppByte states merchant-defined zone-based charges for 64 districts — i.e. **a local rate table**. Whether it calls Pathao's price-calculation endpoint is **unverified** (the copy implies it does not).
- **Partial delivery / partial return handling.** Pathao and Steadfast both support partial delivery. AppByte's status list (`pending, confirmed, in_transit, delivered, returned, cancelled`) has **no partial state**. Likely a genuine gap.
- **Multi-currency, VAT/Mushak compliance, BIN-compliant invoices.** Only "মূল্যে ভ্যাট অন্তর্ভুক্ত" (VAT in *their* price). No claim of generating NBR/Mushak-6.3 compliant invoices.
- **Audit log / activity history, 2FA for staff, IP allowlisting for admins.** Not mentioned.
- **Data export / account portability** beyond Excel report export. Enterprise claims "১০০% ওনারশিপ" — meaning unclear (source code? data?). **Unverified.**

**Things I could not verify at all because the artefacts do not exist:**
- No pricing page, features page, module docs, changelog, API reference, help centre, seller academy, blog, or ToS/privacy/refund/data-security policy — **despite the footer linking to all of them.**
- No public demo tenant, no sandbox, no screenshots of the actual admin UI (every "screenshot" on the site is a hand-coded React mock with hard-coded fake data: `করিম উদ্দিন`, `০১৭১২-৩৪৫৬৭৮`, `জামদানি শাড়ি`, etc.).
- No customer count, no named reference customers, no uptime/SLA figures. The "client websites" gallery loads images by index (`ক্লায়েন্ট ${i+1} এর ছবি`) with no client names.
- No independent review, comparison article, or merchant testimonial found anywhere.

---

## 3. Category-standard capabilities across BD order-management SaaS

Verified against named competitors in the same market segment. This is the baseline Toy Mart BD must meet to be credible.

### 3.1 The canonical BD feature set

Primary reference: **Easy Order Manager** (`https://easyordermanager.com.bd/`), a WooCommerce-based BD competitor that publishes its full feature list and prices — **25 named features**, quoted exactly:

> "All-in-One Order Dashboard" (spreadsheet-style order table) · "Inline Table Editing" · **"Bulk Actions"** (update status, assign staff, book couriers, or send SMS to many orders at once) · **"Advanced Filtering"** (by product, category, status, zone, or date) · "Courier Integrations" · **"Urgent Order Tracking"** (parcels stuck in transit too long) · **"Delivery Charge Alert"** (Telegram alert when couriers add unexpected delivery fees) · **"AI Return Loss Minimizer"** (redispatch returned parcels to new customers) · **"Courier Delivery Rate Checker"** · "Fraud Customer Blocker" (blocks phone numbers **and devices**) · "OTP Verification — No More Fake Orders" · "Duplicate Order Prevention" · **"Get Only High Rate Customers"** (limit COD to customers with good delivery rates) · **"AI Auto Call Order Confirmation"** · "Incomplete Order Recovery" · "SMS Notifications" · "Customer Order Tracking" · **"Telegram Order Notifications"** · "Invoice & Print" (branded, barcodes, batch) · "Profit/Loss Calculation" (per-order profit after cost, ad spend, courier charges) · "Inventory Management" · "Team Management" · **"Custom Order Statuses"** · "CSV Export" · "Meta Server Side Tracking"

Couriers: **Pathao, Steadfast, Carrybee, RedX** + "other couriers added regularly".
Pricing: **৳390/month** or **৳9,900 one-time lifetime**, one website per licence, unlimited orders on both.

**The category-standard module list (present in essentially every BD product):**
1. Spreadsheet-style order dashboard with advanced filters + inline edit + **bulk actions**
2. Manual/phone order entry with **phone-number autofill from history**
3. Order source attribution (website / landing page / FB / TikTok / phone)
4. Custom + canonical order statuses, incl. an explicit **incomplete/abandoned** bucket
5. **Multi-courier one-click and bulk booking** — the near-universal set is **Pathao + Steadfast + RedX + Carrybee** (Paperfly and eCourier appear less often; SA Paribahan/Sundarban essentially never in SaaS)
6. Courier **status auto-sync** back into the order record + consignment/tracking ID capture
7. **Courier fraud / delivery-rate check by phone number** (see 3.2) — the signature BD feature
8. Phone + **device** blocking, duplicate-order prevention, order-gap throttling, IP blocking
9. **OTP verification at checkout**
10. **COD gating by delivery success rate** (require advance payment from low-rate customers) — a notable pattern AppByte does **not** claim
11. Incomplete-order recovery via SMS/WhatsApp
12. Bengali SMS (OTP, confirmation, status, bulk marketing) with template + log + balance recharge
13. Invoice + **barcode/sticker label** printing, batch/multi-up print
14. Per-order **profit/loss after product cost, ad spend, and courier charge**
15. Inventory with low-stock alerts
16. Role-based team management with per-order staff assignment
17. CSV/Excel export
18. **Meta Pixel + server-side Conversions API** (and increasingly TikTok Events API)
19. Customer-facing order tracking link

**Category-standard features AppByte does NOT claim** (worth stealing):
- **COD gating by success rate** — auto-force advance payment for risky buyers (Easy Order Manager: "Get Only High Rate Customers")
- **AI auto-call order confirmation** (customer presses 1 to confirm) — a very strong BD fake-order filter and a real labour saver vs. manual calls
- **Telegram notifications** — a de-facto BD ops channel; AppByte offers SMS/push/WhatsApp but not Telegram
- **Delivery-charge anomaly alerts** — catching couriers silently adding fees
- **"Urgent order tracking"** — a stuck-in-transit SLA queue
- **Return-loss minimiser** — re-dispatch returned stock to a new buyer
- **Custom order statuses** — AppByte's status set looks fixed
- **Device fingerprint blocking** — AppByte blocks phone + IP, not device

### 3.2 The fake-order / "customer check" mechanism — how it ACTUALLY works

This is the single most important architectural finding, and it is **confirmed from source code**, not marketing.

**Most BD fraud checkers work by logging into the courier's own merchant portal with the merchant's credentials and calling undocumented internal endpoints.** ⚠️ **Important refinement from Section 6:** this is true for Pathao, RedX, Carrybee and Paperfly, but **Steadfast is the exception — it exposes a real, officially-documented, API-key-authenticated fraud-check endpoint** (`GET /fraud_check/{phone}` is in Steadfast's own published Postman collection; `GET /fraud_check/score/{phone}` is the newer one). So BD SaaS products claiming "courier API fraud checks" are telling the truth **for Steadfast**, and are scraping merchant portals for the rest. Plan for two different integration classes.

Confirmed from `https://github.com/AbiruzzamanMolla/Fraud-Checker-BD-Courier-Laravel` (a Laravel package; "A fraud detection tool for e-commerce platforms to analyze customer order behavior across Steadfast, RedX, Pathao, Paperfly, and Carrybee couriers"). Credentials required are **merchant portal logins, not API keys**: `PATHAO_USER`/`PATHAO_PASSWORD`, `STEADFAST_USER`/`STEADFAST_PASSWORD`, `REDX_PHONE`/`REDX_PASSWORD`, `PAPERFLY_USER`/`PAPERFLY_PASSWORD`, `CARRYBEE_PHONE`/`CARRYBEE_PASSWORD`. Input is validated against `^01[3-9][0-9]{8}$`. The README credits "S. Ahmad with discovering the Steadfast and Pathao API endpoints" — i.e. these are **reverse-engineered**.

Exact endpoints, read from the package source (`src/Services/*.php` on branch `main`, raw.githubusercontent.com):

| Courier | Auth flow | Fraud-check endpoint | Response fields parsed |
|---|---|---|---|
| **Pathao** | `POST https://merchant.pathao.com/api/v1/login` with `{username, password}` → `access_token`; then `Authorization: Bearer <token>` | **`POST https://merchant.pathao.com/api/v1/user/success`** body `{phone}` | `data.customer.successful_delivery`, `data.customer.total_delivery`. Package derives `cancel = total − success`, `success_ratio = success/total*100` |
| **Steadfast** | `GET https://steadfast.com.bd/login` → regex out `<input type="hidden" name="_token" value="...">`; `POST https://steadfast.com.bd/login` (form: `_token`, `email`, `password`) → **session cookies**; then logs out via `POST https://steadfast.com.bd/logout` | **`GET https://steadfast.com.bd/user/frauds/check/{phoneNumber}`** (cookie-authenticated) | `total_delivered`, `total_cancelled`. Derives `total`, `success_ratio` |
| **RedX** | `POST https://api.redx.com.bd/v4/auth/login` → `data.accessToken` (cached; `Authorization: Bearer`) | **`GET https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{phone}`** | success/return rate |
| **Carrybee** | `GET https://merchant.carrybee.com/api/auth/csrf` → `POST https://merchant.carrybee.com/api/auth/callback/login` → `GET https://merchant.carrybee.com/api/auth/session` → `{accessToken, businessId}` (NextAuth-style) | **`GET https://api-merchant.carrybee.com/api/v2/businesses/{businessId}/fraud-check/{phone}`** | fraud-check payload |
| **Paperfly** | base `https://go-app.paperfly.com.bd/merchant/api/react`, login → `token` (cached ~55 min) | (same base, token-authenticated) | — |

**The normalised output shape the whole category converged on** (per courier, then aggregated):
- per courier: `success`, `cancel`, `total`, `success_ratio`
- aggregate: `total_success`, `total_cancel`, `total_deliveries`, `success_ratio`, `cancel_ratio`

Also confirmed: `https://packagist.org/packages/shahariar-ahmad/courier-fraud-checker-bd` (Pathao + Steadfast only), and a large crop of free hosted checkers — `fraudchecker.smartposbd.com`, `bizscal.com/tools/courier-fraud-checker/`, `fraudshield.bd`, `fraudbd.com`, `kothbel.com/fraud-checker`, `ecomsolvebd.com/courier-fraud-checker`, `ordercheckbd.com`, `fraudcheck.mohasagorit.solutions`, `fraudchecker.link`, `onecodesoft.com/fraudchecker`.

**How it is presented to merchants** (confirmed, `https://smartposbd.com/en/fraud-checker`): "Verify customer delivery track record and COD return risks across Steadfast, Pathao & RedX **100% free**", with "live delivery ratio & scam report **directly from couriers database**". Its sample "Safe Buyer Profile" shows an **overall delivery ratio (93.5%, labelled "Excellent")**, then per-courier breakdown — Steadfast "15 delivered, 1 return", Pathao "customer rating Excellent, 4.8", RedX "10 delivered, 0 returns" — plus **"Merchant fraud reports: none"**. It claims both "Real-time API sync active" and **"11,700+ Cached Parcel Records"** without explaining the relationship. Bizscal frames the decision: check "before you ship", and if the success ratio is low, **call the customer or request an advance**.

**Three distinct data sources are used in the category** — important to keep separate:
1. **Courier merchant-portal delivery history per phone number** (the dominant source; reverse-engineered, per above). **This is what AppByte's "৭ টি কুরিয়ার ডেটা" almost certainly means.**
2. **Crowdsourced cross-merchant blacklist.** Confirmed at SmartPOS: "Access incidents reported by other online shops for **fake orders, refusals, and COD scammers**", surfaced as "Merchant fraud reports". FraudShield similarly claims "blacklisted customers" and "return fraud".
3. **Internal/tenant-local history** — the merchant's own repeat-order, same-address-different-name, and block-list signals. This is where AppByte's "একই ঠিকানায় ৪টি আলাদা নাম" (4 names at one address) signal must come from; no courier exposes that.

**Commercial patterns:** fraud checking is widely **free** as a lead magnet (SmartPOS "Free Forever For Merchants", Bizscal "free checks every day without signing up", Mohasagor IT free with a JSON API and a claimed "10,000+ merchant users"), with **paid API tiers** (FraudShield offers an API + Shopify/WordPress/Google Sheets integrations and bulk number search; EcomSolveBD limits free to **3 checks/day** and charges for **automatic checks on incoming orders**; onecodesoft offers a "developer REST API"). **The monetisable unit is automatic checking on every inbound order, not the manual lookup.**

### 3.3 Category pricing benchmark (what the BD market actually pays)

| Product | Price | Source |
|---|---|---|
| **AppByte / EcomByte** | **৳7,000/yr** (Basic) · **৳12,000/yr** (Pro) · **৳30,000 + ৳1,000 setup** (Enterprise). VAT incl. Hosting bundled. No commission, no per-order fee | appbyte.net |
| **Easy Order Manager** | **৳390/month** or **৳9,900 one-time lifetime**, 1 site, unlimited orders | easyordermanager.com.bd |
| **Bonik AI** | **৳590/month** bundling fraud check + orders + invoices + courier labels | bonikai.app (via search; not directly fetched) |
| Standalone fraud checkers | Free tier standard; paid "Pro API" tiers | smartposbd, bizscal, ecomsolvebd, onecodesoft |

**Read:** the market clears at roughly **৳400–1,000/month**, or **৳7,000–12,000/year** prepaid. Lifetime one-time deals (৳9,900) exist and are attractive to BD SMEs. **A lifetime/one-time option and WhatsApp-based sales are both normal here.** Self-serve card checkout is not.

---

## 4. What AppByte appears NOT to do (opportunities for Toy Mart BD)

Ordered by how exploitable each gap is. "Not claimed" = absent from the entire bundle; given the copy is exhaustive (31-row feature table + 29-item FAQ + 29-card feature grid), absence is meaningful — though not proof.

**A. Hard gaps in inventory/purchasing — the biggest one**
1. **No supplier / purchase-order module at all.** Zero mentions of suppliers, purchases, POs, GRN, or supplier payables. For a toy business importing/wholesaling SKUs this is disqualifying. **Build: suppliers, purchase orders, goods receipt, per-receipt unit cost, supplier ledger/payables.**
2. **No stated inventory valuation method and no stock-movement ledger.** A "valuation report" is claimed but FIFO/weighted-average is undefined, and there's no immutable stock-movement log with reasons. **Build: a real movement ledger (sale, return, restock, purchase receipt, adjustment w/ reason, transfer) + explicit weighted-average costing.**
3. **No stock-adjustment/audit workflow** (damage, loss, stocktake variance). Critical for toys (breakage, missing parts).

**B. Order-ops gaps**
4. **No partial delivery / partial return state.** Status set is `pending, confirmed, in_transit, delivered, returned, cancelled` — yet **both Pathao and Steadfast support partial delivery** (see Sections 5–6). An order partially delivered will be mis-booked and mis-costed. **Build partial states and partial COD reconciliation from day one.**
5. **Fixed status set, no custom statuses.** Competitors ship "Custom Order Statuses".
6. **No stated bulk operations beyond courier-send / SMS / xlsx-status-import.** No bulk status change, bulk print, bulk assign, bulk tag. **Build a true multi-select order grid.**
7. **No "stuck in transit" / SLA queue** ("Urgent Order Tracking" at a competitor). Easy win, high ops value.
8. **No return-loss recovery** (re-dispatching returned stock to a new buyer).
9. **No delivery-charge anomaly alerting** — catching couriers adding unexpected fees.

**C. Fraud gaps — and the credibility problem**
10. **Internally inconsistent fraud claim: "৭ টি কুরিয়ার" (7 couriers) in the pricing table vs "চারটি কুরিয়ারই" (4 couriers) in the FAQ, and the code renders `Ui.length` = 4.** The 7-courier claim appears unsupported by its own product. **Opportunity: state your courier coverage honestly and name each one.**
11. **No COD gating by delivery success rate.** The single highest-leverage BD anti-fraud control (auto-require advance payment below an X% success ratio). AppByte scores risk but only *holds* the order; it doesn't convert a risky COD into a prepaid order. **Build this.**
12. **No AI/auto call confirmation.** Competitors ship it; it's the strongest practical fake-order filter in BD.
13. **No device fingerprint blocking** (phone + IP only).
14. **No cross-merchant / crowdsourced fraud reports.** Competitors surface "incidents reported by other online shops". AppByte is courier-data + own-history only.
15. **OTP verification and SMS are paywalled out of the ৳7,000 tier** — the cheapest plan is sold with "ফ্রড কাস্টমার চেক" but without OTP or SMS, its two best defences. **Opportunity: make anti-fraud complete at every tier (for an in-house system this is free anyway).**
16. **No fraud-check caching/quota strategy disclosed.** Since the underlying data comes from scraped merchant portals (3.2), this is fragile and rate-limited. **Build: cache per phone number with TTL, degrade gracefully, never block order intake on a fraud-check timeout, and queue checks asynchronously.**

**D. Accounting gaps**
17. **No NBR/Mushak-compliant VAT invoicing, no BIN field, no VAT return support.** Only "VAT included" in their own pricing. A competitor (Bizmation) explicitly markets BD VAT/tax-compliant invoices and filing reports — so this is a recognised market need.
18. **No double-entry accounting, chart of accounts, bank/mobile-wallet reconciliation, or supplier payables.** "খরচ ও ট্রানজেকশন" (expenses & transactions) is a flat log.
19. **COD reconciliation appears to be a report, not a workflow.** No stated courier-payout/settlement matching: receive a courier payment advice, match it to consignments, flag shortfalls, post the courier charge and COD fee. **Build settlement matching — this is where BD merchants actually lose money.**

**E. Platform / trust gaps**
20. **No self-serve signup, no free trial, no public demo, no pricing page.** Every CTA is "contact us" → WhatsApp.
21. **No documentation, API reference, changelog, help centre, or seller academy — despite the footer advertising all of them.** No ToS, privacy policy, refund policy, or data-security page either, though all four are listed. **For an internal build this matters as a warning: don't trust the maturity signal.**
22. **No audit log, no 2FA, no admin IP allowlist, no granular permission matrix published.** Only three fixed roles (admin/manager/employee).
23. **Public API is restricted to a "Scale" plan that does not exist** in the pricing array — stale copy.
24. **No SLA, uptime figure, backup/restore or data-export guarantee.** Claims "ওয়েবসাইট কখনো ডাউন হবে না" ("the website will never go down") with nothing behind it.
25. **No named customers, no customer count, no independent review anywhere.** The client gallery is unnamed; every UI "screenshot" is a hand-coded mock with fake data.
26. **Hosting is bundled and expires** (5/10 GB, "১ বছর মেয়াদী"), creating renewal lock-in — and 5 GB is small for an image-heavy toy catalogue.

**F. Toy-retail-specific needs no BD product in this category addresses**
27. **Age grading / age-range filters**, safety certification fields (CE/EN71/ASTM), choking-hazard warnings — regulatory and merchandising metadata for toys.
28. **Brand / licence / character taxonomy** (e.g. Lego, Hot Wheels, Marvel) and set/series relationships.
29. **Bundle/gift-set BOM** — AppByte has "group product checkout" but no kit/BOM that decrements component stock.
30. **Seasonality and gifting workflows** — Eid/Puja/birthday peaks, gift wrap, gift message, scheduled delivery date.
31. **Battery/accessory-included flags, dimensional weight** for courier charge accuracy on bulky-but-light toys (a real BD courier cost trap).
32. **Serial/batch recall traceability** — AppByte claims batch tracking; tie it to a recall workflow.

---

## 5. Pathao Courier API reference

### 5.0 Documentation accessibility — be blunt

**The official Pathao Merchant API docs are login-gated and JS-only.** `https://merchant.pathao.com/courier/developer-api` returns a ~6.4 KB Vue/Vite SPA shell (HTTP 200) whose `<noscript>` reads *"We're sorry but Merchant Panel doesn't work properly without JavaScript enabled."* `/courier/developer-api/api-docs` is a client-side route of that same SPA, not a separate document. **`https://developer.pathao.com/` does not exist** (DNS `ENOTFOUND`). Wayback returned HTTP 429, so no archived official snapshot was obtainable.

Consequently, **no field-level fact below comes from a directly citable official doc page.** Evidence tiers used:

| Tier | Source | Weight |
|---|---|---|
| **A** | **Pathao's own GitHub org** — `github.com/pathao-eng/courier-woocommerce-plugin` (`pathao-bridge.php`, `plugin-api.php`) | **First-party source code — strongest public evidence** |
| **B** | Live HTTP probes against both API hosts | Direct observation |
| **C** | Verbatim scrape of the gated official docs, committed as `pathaodocs.md` + `pathaowebhook.md` in `github.com/shahria7k/pathao-courier` | Third-party copy, demonstrably a real logged-in DOM dump |
| **D** | Postman collection — `github.com/abusufianrubel/pathao-courier-api-postman-collection` | Confirms HTTP verbs |
| **E** | `github.com/codeboxrcodehub/pathao-courier` README | Enum corroboration |

Tier C is trustworthy because it still contains the scraping merchant's own panel values (`Client ID 4openn5e7A`, masked secret), their own callback URL, and leftover UI chrome including a typo ("ickup Failed") — a real DOM dump, not a hand-written guess. Every structural claim in Tier C that Tier A can check, Tier A confirms.

⚠️ **No authenticated round-trip was performed.** All *response* JSON shapes below are documented (Tier C), not observed. Request paths, verbs, auth header format and webhook semantics are Tier A/B/D verified. See Section 7 for the blocked action.

### 5.1 Base URLs — VERIFIED (Tier A + B)

| Env | Base URL |
|---|---|
| **Sandbox / staging** | `https://courier-api-sandbox.pathao.com` |
| **Production / live** | `https://api-hermes.pathao.com` |

Tier A, exact line (`pathao-bridge.php:7`):
```php
return ($environment === 'staging') ? 'https://courier-api-sandbox.pathao.com' : 'https://api-hermes.pathao.com';
```
Tier B: both hostnames resolve and serve the live gateway. Unauthenticated `GET https://courier-api-sandbox.pathao.com/aladdin/api/v1/city-list` → HTTP 400:
```json
{"message":"Bad Request","type":"error","error":{"source":"verify-auth-token","description":"bearer token missing"}}
```
**All paths are prefixed `/aladdin/api/v1`.** Note: `courier-api.pathao.com` appears in some third-party summaries — **unverified, treat as wrong/legacy**; both first-party sources use `api-hermes.pathao.com`.

### 5.2 Auth model

**Password grant** — path VERIFIED (B+C+D), body/response Tier C.
`POST /aladdin/api/v1/issue-token`, `Content-Type: application/json`:
```json
{ "client_id": "...", "client_secret": "...", "grant_type": "password",
  "username": "{your_email}", "password": "{your_password}" }
```
All five documented **Required**. Tier B live proof: posting deliberately bad credentials returns HTTP 400 `{"message":"The user credentials were incorrect","type":"error","code":400}` — a credential-specific error, confirming both the path and the password-grant handler.

Success (HTTP 200):
```json
{ "token_type": "Bearer", "expires_in": 432000,
  "access_token": "...", "refresh_token": "..." }
```
**`expires_in` = 432000 s = 5 days.** `token_type` documented as *"always be Bearer"*.

**Refresh grant** (Tier C) — same path:
```json
{ "client_id": "...", "client_secret": "...",
  "grant_type": "refresh_token", "refresh_token": "..." }
```
(The official curl sample for this is literally invalid JSON — missing comma + trailing comma. Fix on copy.)

**Authenticated call headers** — VERIFIED (Tier A):
```
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json
```
Tier A also sends a non-documented `source: woocommerce` header on order create. Optional/informational.

⚠️ **Undocumented alternative auth — VERIFIED (Tier A), absent from the docs.** Pathao's *own current plugin does not use `issue-token`*. It uses `POST /aladdin/api/v1/external/login` (`pathao-bridge.php:25`) with body **only** `{ "client_id": "...", "client_secret": "..." }`, consuming `access_token`/`refresh_token`/`expires_in`. **This is a 2-legged client-credentials flow needing no username/password** — likely newer, and it would remove password storage from our integration. Evidence is first-party-code-only; **test before relying on it.**

**Published sandbox credentials (Tier C):** `client_id: 7N1aMJQbWm`, `client_secret: wRcaibZkUdSNz2EI9ZyuXLlNrnAv0TdPUPXMnD39`, `username: test@pathao.com`, `password: lovePathao`. Shared public test account — do not create junk orders on it.

### 5.3 Endpoints — paths VERIFIED (A/C/D), verbs VERIFIED (Tier D)

| Operation | Method | Path |
|---|---|---|
| Issue / refresh token | POST | `/aladdin/api/v1/issue-token` |
| **Create order (single)** | POST | `/aladdin/api/v1/orders` |
| **Create orders (bulk)** | POST | `/aladdin/api/v1/orders/bulk` |
| **Price calculation** | POST | `/aladdin/api/v1/merchant/price-plan` |
| Store list | GET | `/aladdin/api/v1/stores` |
| Create store | POST | `/aladdin/api/v1/stores` |
| City list | GET | `/aladdin/api/v1/city-list` |
| Zone list (by city) | GET | `/aladdin/api/v1/cities/{city_id}/zone-list` |
| Area list (by zone) | GET | `/aladdin/api/v1/zones/{zone_id}/area-list` |
| Order info / tracking | GET | `/aladdin/api/v1/orders/{consignment_id}/info` |

Tier A independently confirms `orders`, `orders/bulk`, `stores`, `cities/{id}/zone-list`, `zones/{id}/area-list` at identical paths.

**Extra endpoints only in Pathao's own plugin (Tier A, undocumented):** `GET /aladdin/api/v1/countries/1/city-list` (country-scoped, `1` = Bangladesh — **Pathao's plugin uses this, not `/city-list`**), `GET /aladdin/api/v1/zones/list` (flat), `GET /aladdin/api/v1/areas/list` (flat), `GET /aladdin/api/v1/user/short-info` (merchant profile).

**NOT verified:** `POST /orders/{consignmentId}/cancel` — surfaced only in one third-party summary; absent from the docs scrape, the Postman collection, and Pathao's own plugin. **Do not rely on it.** Note a probing limitation: Pathao's auth middleware runs *before* routing, so **every** path — including a deliberately bogus one — returns the same 400 `bearer token missing`. Unauthenticated probing cannot distinguish real from fake routes on this API.

### 5.4 Create order — fields (Tier C tables, Tier A field names)

`POST /aladdin/api/v1/orders`

| Field | Type | Req | Notes |
|---|---|---|---|
| `store_id` | integer | **Yes** | Sets pickup location |
| `merchant_order_id` | string | No | Our own order reference |
| `recipient_name` | string | **Yes** | 3–100 chars |
| `recipient_phone` | string | **Yes** | exactly 11 chars |
| `recipient_secondary_phone` | string | No | 11 chars |
| `recipient_address` | string | **Yes** | 10–220 chars |
| `recipient_city` | integer | No | Auto-resolved from address if omitted |
| `recipient_zone` | integer | No | Auto-resolved |
| `recipient_area` | integer | No | Auto-resolved |
| `delivery_type` | integer | **Yes** | **48** = Normal, **12** = On Demand |
| `item_type` | integer | **Yes** | **1** = Document, **2** = Parcel |
| `special_instruction` | string | No | |
| `item_quantity` | integer | **Yes** | |
| `item_weight` | float | **Yes** | **min 0.5 kg, max 10 kg** |
| `item_description` | string | No | |
| `amount_to_collect` | integer | **Yes** | COD amount; **0** for non-COD |

**Critical gotcha:** for `recipient_city`/`zone`/`area` the docs say verbatim *"This is an optional parameter, so do not send a null value."* **Omit the key entirely — never send `null`.** Tier A enforces exactly this with `if (!empty(...))` guards in `makeDto()`, confirming the rule is real.

Enums 48/12 and 1/2 confirmed by Tier A (`plugin-api.php:392-393` hardcodes `'delivery_type' => 48, 'item_type' => 2`), Tier C and Tier E.

**`item_weight` type is inconsistent in the official docs** — create-order sends string `"0.5"`, price-plan sends number `0.5`, the field table says `float`. Tier A casts to `(float)` for both. **Send a JSON number.**

Success (HTTP 200, Tier C):
```json
{ "message": "Order Created Successfully", "type": "success", "code": 200,
  "data": { "consignment_id": "...", "merchant_order_id": "...",
            "order_status": "Pending", "delivery_fee": 80 } }
```
⚠️ Tier A contradicts the 200 — `pathao-bridge.php` comments `// status code 201 means created` and treats `>= 300` as failure. **Accept any 2xx; do not equality-check 200.**

**Bulk:** `POST /aladdin/api/v1/orders/bulk`, body `{ "orders": [ <order object>, ... ] }`, same object schema (Tier A confirms via `pt_hms_create_new_order_bulk` building `['orders' => $order_data]`). Success is **HTTP 202 (async)**:
```json
{ "message": "Your bulk order creation request is accepted,<br> please wait some time to complete order creation.",
  "type": "success", "code": 202, "data": true }
```
**No consignment IDs are returned — they must be learned via webhook or polling.** No documented max batch size found.

**Price plan:** `POST /aladdin/api/v1/merchant/price-plan` — required `store_id`, `item_type`, `delivery_type`, `item_weight` (float), `recipient_city`, `recipient_zone` (**no** `recipient_area`):
```json
{ "message":"price","type":"success","code":200,
  "data":{ "price":80,"discount":0,"promo_discount":0,"plan_id":69,
           "cod_enabled":1,"cod_percentage":0.01,"additional_charge":0,"final_price":80 } }
```
(`cod_enabled` appears in the sample but is missing from the docs' own response field table.)

**Create store:** `POST /aladdin/api/v1/stores` — `name` (3–50, req), `contact_name` (3–50, req), `contact_number` (11, req), `secondary_contact` (opt), `otp_number` (opt — this store's order OTPs go here), `address` (**15–120**, req), `city_id`/`zone_id`/`area_id` (int, all req). Returns `{"message":"Store created successfully, Please wait one hour for approval.", ..., "data":{"store_name":"Demo Store"}}` — **note the ~1 hour approval delay and that no `store_id` is returned.**

**Store list:** `GET /aladdin/api/v1/stores` → Laravel-style paginator; `data.data[]` of `{store_id, store_name, store_address, is_active, city_id, zone_id, hub_id, is_default_store, is_default_return_store}` plus `total, current_page, per_page (1000), total_in_page, last_page, path, to, from, last_page_url, first_page_url`; `?page=N`.

**Locations (all GET, all double-nested `data.data[]`):**
- `city-list` → `{city_id, city_name}` (e.g. 1 Dhaka, 2 Chittagong, 4 Rajshahi — note 3 is absent from the sample)
- `cities/{city_id}/zone-list` → `{zone_id, zone_name}`
- `zones/{zone_id}/area-list` → `{area_id, area_name, home_delivery_available, pickup_available}`

**Order info:** `GET /aladdin/api/v1/orders/{consignment_id}/info` →
```json
{ "message":"Order info","type":"success","code":200,
  "data":{ "consignment_id":"...","merchant_order_id":"...","order_status":"Pending",
           "order_status_slug":"Pending","updated_at":"2024-11-20 15:11:40","invoice_id":null } }
```
**This is a status snapshot, not a tracking history — there is no event-timeline endpoint.** A timeline must be accumulated from webhooks.

### 5.5 Webhooks — VERIFIED (Tier A first-party + Tier C)

Configured in-panel: supply a **Callback URL** and a **Secret**. Pathao POSTs JSON.

**Inbound headers:** `X-PATHAO-Signature` = **the literal secret you configured**, plus `Content-Type: application/json`.

⚠️ **The signature is NOT an HMAC — it is a plain shared-secret equality check.** Tier A proves it (`plugin-api.php:414-427`):
```php
if (isset($_SERVER['HTTP_X_PATHAO_SIGNATURE'])) {
    $client_secret = $_SERVER['HTTP_X_PATHAO_SIGNATURE'];
    $secret = get_option('pt_hms_settings')['webhook_secret'] ?? null;
    if (!$secret || $client_secret !== $secret) { return false; }
    return true;
}
return false;
```
No payload hashing, no timestamp. **Compare in constant time and require TLS — the secret is replayable.**

⚠️ **Required response contract — the part integrations fail.** Our endpoint **must echo a fixed, hardcoded UUID** on every response:
```
X-Pathao-Merchant-Webhook-Integration-Secret: f3992ecc-59da-4cbe-a049-a13da2018d51
```
This is **not** our secret — it is a global constant identical for all merchants. Verified in **Pathao's own repo** (`plugin-api.php:483`) and independently in `shahria7k/pathao-courier` `src/constants.ts` as `DEFAULT_WEBHOOK_INTEGRATION_SECRET`; Tier C repeats it in all 21 event sections. Highest-confidence non-obvious fact here (first-party + third-party + docs all agree).

Also **handle the `event: "webhook_integration"` handshake ping** — Tier A short-circuits it and returns 202 before any order lookup (`plugin-api.php:435`). Without it, panel verification fails. Tier A returns **202** for normal events too.

**Delivery requirements (Tier C, stated per event):** reachable; resolved within **≤3 redirections**; valid SSL if HTTPS; respond within **10 seconds**; must return the integration-secret header.

**Payload base (all events):** `event`, `updated_at` (`"2024-12-27 23:49:43"` — Asia/Dhaka naive), `timestamp` (ISO-8601 UTC, `"2024-12-27T17:49:43+00:00"`). The two differ by +6 h; **`timestamp` is the authoritative instant.** Order events add `consignment_id`, `merchant_order_id` (optional), `store_id` (integer).

```json
{ "consignment_id": "DL121224VS8TTJ", "merchant_order_id": "TS-123",
  "updated_at": "2024-12-27 23:49:43", "timestamp": "2024-12-27T17:49:43+00:00",
  "store_id": 130820, "event": "order.created", "delivery_fee": 83.46 }
```

**Event names — there are NO numeric event codes.** Wire values are **dotted lowercase strings**. The underscore forms (`Pickup_Requested` etc.) are *not* wire values — they are the plugin's internal display statuses. Tier A's `$orderEventsStatusMap` (`plugin-api.php:16-36`) is authoritative for both:

| Panel label | Wire `event` | Extra fields | Plugin status string |
|---|---|---|---|
| Order Created | `order.created` | `delivery_fee` | `Order_Created` |
| Order Updated | `order.updated` | `delivery_fee` | `Order_Updated` |
| Pickup Requested | `order.pickup-requested` | `delivery_fee` | `Pickup_Requested` |
| Assigned For Pickup | `order.assigned-for-pickup` | — | `Assigned_for_Pickup` |
| Pickup | `order.picked` | — | `Picked` |
| Pickup Failed | `order.pickup-failed` | — | `Pickup_Failed` |
| Pickup Cancelled | `order.pickup-cancelled` | — | `Pickup_Cancelled` |
| At the Sorting Hub | `order.at-the-sorting-hub` | — | `At_the_Sorting_HUB` |
| In Transit | `order.in-transit` | — | `In_Transit` |
| Received at Last Mile Hub | `order.received-at-last-mile-hub` | — | `Received_at_Last_Mile_HUB` |
| Assigned for Delivery | `order.assigned-for-delivery` | — | `Assigned_for_Delivery` |
| **Delivered** | `order.delivered` | **`collected_amount`** | `Delivered` |
| **Partial Delivery** | `order.partial-delivery` | **`collected_amount`**, `reason?` | `Partial_Delivery` |
| Return | `order.returned` | `reason?` | `Return` |
| Delivery Failed | `order.delivery-failed` | `reason?` | `Delivery_Failed` |
| On Hold | `order.on-hold` | `reason?` | `On_Hold` |
| **Payment Invoice** | `order.paid` | **`invoice_id`** (e.g. `"121224IBW19790"`) | `Payment_Invoice` |
| Paid Return | `order.paid-return` | `collected_amount`, `reason?` | `paid_return` |
| Exchange | `order.exchanged` | `collected_amount`, `reason?` | `exchange` |
| Store Created | `store.created` | store shape | *(unmapped)* |
| Store Updated | `store.updated` | store shape | *(unmapped)* |
| *(handshake)* | `webhook_integration` | — | *(short-circuit 202)* |

**Casing traps:** `At_the_Sorting_HUB` and `Received_at_Last_Mile_HUB` use **HUB** uppercase; `paid_return`/`exchange` are lowercase and inconsistent. **`order.paid` → `Payment_Invoice` is the non-obvious mapping** and is the COD-settlement event (carries `invoice_id`). `reason` is documented *"This field might not be present in some cases"* — always optional. Per-event field presence corroborated by `src/types/webhook.ts` discriminated unions matching Tier C samples exactly.

Store events use a different shape:
```json
{ "store_id": 1, "store_name": "Test Store", "store_address": "Test store address",
  "is_active": 1, "event": "store.created",
  "updated_at": "2024-12-27 23:55:34", "timestamp": "2024-12-27T17:55:34+00:00" }
```

### 5.6 Error envelope (Tier B observed)

Success: `{message, type:"success", code, data}`. Errors: `{message, type:"error", code}`, or with nested detail:
```json
{"message":"Bad Request","type":"error","error":{"source":"verify-auth-token","description":"bearer token missing"}}
```
⚠️ **Auth failures return HTTP 400, not 401** — observed live twice. **Do not branch on 401 for token refresh; inspect `error.source == "verify-auth-token"`.**

### 5.7 Implementation notes for Toy Mart BD

1. Cache the token (5-day `expires_in`); refresh on `error.source == "verify-auth-token"`, **not** on HTTP 401.
2. Evaluate `POST /aladdin/api/v1/external/login` to avoid storing a merchant password — but test first (first-party-code evidence only).
3. Omit `recipient_city`/`zone`/`area` keys rather than sending `null`; let Pathao geocode `recipient_address`.
4. Location lists are **double-nested**: `response.data.data[]`.
5. Webhook endpoint must return the hardcoded `f3992ecc-...` header on **every** response, handle `webhook_integration`, and reply within 10 s — **enqueue and return immediately**, never process inline.
6. Bulk create is fire-and-forget (202, no IDs) — webhooks are mandatory for reconciliation.
7. **Model partial delivery from day one** — `order.partial-delivery` carries `collected_amount`, so COD reconciliation must handle a collected amount ≠ ordered amount.

**Pathao source URLs used:**
- https://github.com/pathao-eng/courier-woocommerce-plugin (first-party; `pathao-bridge.php`, `plugin-api.php`)
- https://github.com/shahria7k/pathao-courier (`pathaodocs.md`, `pathaowebhook.md` = official docs scrape; `src/constants.ts`, `src/types/webhook.ts`, `src/webhooks/verifier.ts`)
- https://github.com/abusufianrubel/pathao-courier-api-postman-collection
- https://github.com/codeboxrcodehub/pathao-courier (README, enum corroboration)
- https://merchant.pathao.com/courier/developer-api (JS-gated SPA shell — no readable docs)

---

## 6. Steadfast Courier API reference

### 6.0 Documentation accessibility — be blunt

**Steadfast's own public developer docs are NOT on their website.** `https://steadfast.com.bd/` has no developer/API/docs link at all (nav is Home/Track/Rates/Coverage/Business/About/Contact). The in-panel guide at `https://steadfast.com.bd/user/api` and `/user/api/guide` is **login-gated** — fetching it returns the "Merchant sign-in" page. The authoritative guide is distributed to merchants as a Google Doc / PDF "API Guide".

**However, two genuinely official public artifacts exist, plus live verification was possible:**

| Tier | Source | Authority |
|---|---|---|
| **T1 — Official public Postman doc** | `https://documenter.getpostman.com/view/26211192/2sAYJ1mhpk` — collection **"SteadFast API Documentation"**, `publishedId: 2sAYJ1mhpk`, `owner: 26211192`, `publishDate: 2024-12-26`, description is an `<img>` of base64 `U3RlYWRGYXN0IExvZ28gRmluYWwucG5n` → "SteadFast Logo Final.png". Real account data inside (`user_id: 37722`), responses dated to 2026-09. **Machine-readable JSON (618 KB, HTTP 200): `https://documenter.gw.postman.com/api/collections/26211192/2sAYJ1mhpk?segregateAuth=true&versionTag=latest`** | **Steadfast's own published collection** |
| **T1 — Official Laravel package** | `github.com/steadfast-it/SteadFast-Courier-Laravel-Package` (the `steadfast-it` org) — `README.md`, `src/SteadfastCourier.php`, `config/steadfast-courier.php` | Vendor-published SDK |
| **T2 — LIVE PROBES (2026-11-07)** | Production API hit unauthenticated; status codes prove route existence: **404 = absent, 401 = exists + needs creds, 405 = exists but POST-only** | Strongest evidence of what exists *today* |
| **T3 — Verbatim paste of the official guide** | `github.com/MdSalman2022/Ecommerce-site-backend` → `steadfastapi.txt` (duplicated at `github.com/PKTonmoy/e-com2` → `Steadfast Courier Limited.txt`) | Unmistakably a copy-paste of Steadfast's own doc: retains the original TOC, masked credential placeholders `***************`, and the editorial note *"Yellow colour marked parameters are added newly."* **All exact constraint wording below comes from here.** |
| **T3 — Recreation of a newer official PDF** | `github.com/mehedijaman/shopnow` → `docs/steadfast-courier-api.md`, headed *"Recreated Developer Documentation — Based on the supplied SteadFast API Guide PDF"* | Newer guide generation; documents `/ping`, extended bulk, pickups, fraud scoring, rate limits. Corroborated by live probes |
| **T3 — Webhook spec recreations** | `github.com/TeamRSBDigital/STEADFAST_API` → `STEADFAST_WEBHOOK.md`; `github.com/inversemaha/SteadFastWebhookForFunnelliner` → `README.md` | Two independent authors describing the same contract |
| **T4 — UNRELIABLE, DO NOT TRUST** | `github.com/refatbd/bangladesh-api-docs/docs/steadfast/*` and `https://steadfast-python.readthedocs.io/` | See 6.9 — endpoint *names* check out, **example payloads are fabricated** |

### 6.1 Base URL — CONFIRMED

**`https://portal.packzy.com/api/v1`**

- T3 official guide: `Base Url: https://portal.packzy.com/api/v1`
- T1 official Postman: all 13 requests use `https://portal.packzy.com/api/v1/...`
- T1 official package `config/steadfast-courier.php`: `'base_url' => env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1')`
- T2 live: `dig portal.packzy.com` → `54.251.188.242, 54.255.160.246, 52.220.63.73`; `GET /api/v1/ping` → `200 {"status":200,"message":"pong","service":"steadfast-api","version":"v1","time":"2026-11-07T23:34:44+06:00"}`

⚠️ **Trap: `portal.steadfast.com.bd` is DEAD.** The official package's *README* tells you to set `STEADFAST_BASE_URL="https://portal.steadfast.com.bd/api/v1"` — but its own *config default* is `portal.packzy.com`, and **`dig portal.steadfast.com.bd` returns no DNS records at all.** Several repos in the wild are hardcoded to this broken host. Also broken: `manoar/laravel-steadfast` on Packagist uses `api.steadfast.com` — no DNS.

⚠️ **There is NO sandbox / staging environment.** No official source mentions one. `sandbox.packzy.com` → no DNS. `api.steadfast.com` → no DNS. The `steadfast-python` readthedocs SDK advertises `https://sandbox.api.steadfast.io` — but **`steadfast.io` is Steadfast Networks, an unrelated US hosting company**, and those IPs are AWS parked/wildcard addresses. **Treat the sandbox claim as fabricated.** For testing: use `/ping` for connectivity, `/get_balance` for credential validation, and create real orders with `cod_amount: 0` then cancel immediately.

### 6.2 Authentication

Three headers on every request **except `/ping`**:
```
Api-Key: <your api key>
Secret-Key: <your secret key>
Content-Type: application/json
```
- T3 official guide verbatim table: `Api-Key` / String / "API Key provided by Steadfast Courier Ltd."; `Secret-Key` / String / "Secret Key provided by Steadfast Courier Ltd."; `Content-Type` / String / `application/json`. Plus: *"Authentication parameters are required to be added at the header part of each request."*
- T1 official package `src/SteadfastCourier.php` — exact literals on all 6 methods:
```php
Http::withHeaders([
    'Api-Key'      => $this->apiKey,
    'Secret-Key'   => $this->secretKey,
    'Content-Type' => 'application/json',
])
```
- T1 official Postman uses lowercase `api-key` / `secret-key` — HTTP headers are case-insensitive, both work. Adding `Accept: application/json` is harmless and done by some SDKs.

**No OAuth, no bearer token, no login step, no token refresh.** Both static keys go on every call. This is dramatically simpler than Pathao.

⚠️ In the official Postman collection the header *values* are literally `"Your API Key Here"` **including the double quotes** — a doc-authoring artifact. Don't send quotes.

**Failure shape (live-verified):** `HTTP 401` + `{"status":401,"message":"Unauthorized Access (missing API credentials)"}`

**Getting credentials:** merchant panel → `https://steadfast.com.bd/user/api` (login required), or contact Steadfast support. Keys are scoped per business/profile; multiple keys per profile are supported.

### 6.3 Create order — `POST /create_order`

**Live-verified:** `GET /create_order` → **HTTP 405 Method Not Allowed** (route exists, POST-only).

Exact fields and constraints (T3 official guide, **verbatim**):

| Name | Type | Req | Description (verbatim) | Example |
|---|---|---|---|---|
| `invoice` | string | **required** | "Must be Unique and can be alpha-numeric including hyphens and underscores." | `12366`, `Aa12-das4`, `a_sdfd-wq` |
| `recipient_name` | string | **required** | "Within 100 characters." | `John Smith` |
| `recipient_phone` | string | **required** | "Must be 11 Digits Phone number" | `01234567890` |
| `alternative_phone` | string | optional | "Must be 11 Digits Phone number" | |
| `recipient_email` | string | optional | — | |
| `recipient_address` | string | **required** | "Recipient's address within 250 characters." | `Fla# A1, House# 17/1, Road# 3/A, Dhanmondi, Dhaka-1209` |
| `cod_amount` | numeric | **required** | "Cash on delivery amount in BDT **including all charges**. Can't be less than 0." | `1060` |
| `note` | string | optional | "Delivery instructions or other notes." | `Deliver within 3 PM` |
| `item_description` | string | optional | "Items name and other information" | |
| `total_lot` | numeric | optional | "Total Lot of items" | |
| `delivery_type` | numeric | optional | "**0 = for home delivery, 1 = for Point Delivery/Steadfast Hub Pick Up**" | `0`/`1` |

`item_description`, `total_lot`, `delivery_type` are the three marked *"added newly"* in the guide.

⚠️ **Two conflicting sets of max lengths.** The **older guide** (verbatim above) says name ≤100, phone = 11 digits, address ≤250 as *validation*. The **newer guide PDF** (T3 shopnow, corroborated by `AH-Raihan/laravel-steadfast-courier`) says these are **truncation limits, not rejections**: `invoice` 100, `recipient_name` 100, `recipient_phone` **40**, `recipient_address` **490**, `note` **480**, `item_description` 255, `total_lot` defaults to 1, `cod_amount` max 1,000,000.

**Reality check from T1 official Postman:** the Create Order example sends `"recipient_phone": "+8801309055966"` (14 chars, not 11 digits) and the 200 response echoes it unchanged. **So >11-digit and `+88`-prefixed phones are accepted in practice.** Safest: send exactly `01XXXXXXXXX` (11 digits), cap name/invoice at 100, address at 250.

⚠️ **Character stripping.** Before storing name/address/note the gateway **replaces `{` `}` `;` `<` `>` with a space** (some SDKs also strip `$`). **Text you send may not come back byte-identical** — do not rely on round-trip equality.

Request example (T1 official Postman, verbatim):
```json
{
    "invoice": "13151523",
    "recipient_name": "Badrul Hasan Sajib",
    "recipient_phone": "+8801309055966",
    "recipient_address": "Dhanmondi, Zigatola",
    "recipient_email": "badrul.sajib@gmail.com",
    "alternative_phone": "01309055966",
    "item_description": "iPhone 16pro Max 512GB",
    "cod_amount": "3010",
    "note": "অবশ্যই কাস্টমারের বাড়িতে..."
}
```
(`cod_amount` sent as a **string** `"3010"` in the official example, returned as number `3010`.)

Success — **HTTP 200**:
```json
{
    "status": 200,
    "message": "Consignment has been created successfully.",
    "consignment": {
        "consignment_id": 1424107,
        "invoice": "Aa12-das4",
        "tracking_code": "15BAEB8A",
        "recipient_name": "John Smith",
        "recipient_phone": "01234567890",
        "recipient_address": "Fla# A1,House# 17/1, Road# 3/A, Dhanmondi,Dhaka-1209",
        "cod_amount": 1060,
        "status": "in_review",
        "note": "Deliver within 3PM",
        "created_at": "2021-03-21T07:05:31.000000Z",
        "updated_at": "2021-03-21T07:05:31.000000Z"
    }
}
```
T1 official Postman's 200 is identical in shape (`consignment_id: 131822154`, `tracking_code: "7DB724A65D4"`, `status: "in_review"`).

- `consignment_id` — **integer**
- `tracking_code` — **string, uppercase hex, VARIABLE LENGTH.** Observed real values: `15BAEB8A` (8), `B025A038` (8), `5491DE4A4` (9), `7DB724A65D4` (11), `E942C9CBFFFFBEF` (15). **Do not assume 8 chars.** (The `ST-9845210` form seen in T4 sources is fabricated.)
- `consignment.status` at creation is always `in_review`.
- `tracking_link` (`https://steadfast.com.bd/tl/<slug>`) appears **only** in newer-PDF recreations, **not** in any official sample. **Unverified.**

### 6.4 Bulk create — `POST /create_order/bulk-order`

**Live-verified:** `GET` → **HTTP 405** (exists, POST-only). **Max items: 500** — *"Maximum 500 items are allowed"* (T3 official guide, verbatim).

⚠️ **Request shape is the #1 gotcha — three variants exist in the wild.**

**Variant A (RECOMMENDED — official guide + official SDK).** T3 guide's verbatim input table: `data` / Json / require / *"Maximum 500 items are allowed. **Json encoded array**"*. The guide's own PHP sample calls `json_encode($data)` first, and the **official Laravel package** does exactly this (`src/SteadfastCourier.php`):
```php
])->post($this->baseUrl.'/create_order/bulk-order', ['data' => json_encode($data)]);
```
→ wire body is `{"data":"[{\"invoice\":\"...\"},...]"}` — **`data` is a JSON-encoded STRING, not a nested array.**

**Variant B** — `{"data": [ {...}, {...} ]}` (nested array). Used by several third-party SDKs and the shopnow PDF recreation.

**Variant C** — a **bare top-level JSON array** `[{...},{...}]` with no wrapper. **This is what the official Postman collection's "Create Bulk Order" raw body literally contains** (5 order objects, no `data` key).

**Use Variant A.** The API appears lenient, but A is the only form attested by both the guide text and the vendor SDK.

Response (T1 official Postman, envelope form):
```json
{
    "status": 200,
    "message": "We have a response for you.",
    "data": [
        { "invoice": "SFC-1234j56", "recipient_name": "Badrul Hasan Sajib",
          "recipient_phone": "+8801309055966", "recipient_address": "Dhanmondi, Zigatola",
          "cod_amount": "3010", "status": null, "note": "...",
          "consignment_id": null, "tracking_code": null,
          "error": "[\"THIS_INVOICE_ALREADY_EXISTS\"]" }
    ]
}
```
⚠️ **`error` is a JSON string containing a JSON array — double-decode it.**

T3 official guide (older, bare-array form) success entry has `"consignment_id":11543968,"tracking_code":"B025A038","status":"success"`; failures have `"consignment_id": null, "tracking_code": null, "status": "error"`.

**Per-item `status` is `"success"`, `"error"`, or `null`. HTTP is 200 even when individual items fail — ALWAYS iterate every entry.** Results stay in request order.

**Bulk validation error codes** (T3 shopnow, newer PDF): `INVOICE_IS_REQUIRED`, `INVOICE_MUST_BE_ALPHA_NUM_DASH_UNDERSCORE`, `INVOICE_LENGTH_SHOULD_BE_LESS_THAN_100_CHARS`, `THIS_INVOICE_ALREADY_EXISTS`, `RECEIVER_NAME_IS_REQUIRED`, `RECEIVER_NAME_LENGTH_SHOULD_BE_LESS_THAN_100_CHARS`, `RECEIVER_PHONE_IS_REQUIRED`, `RECEIVER_PHONE_LENGTH_SHOULD_BE_LESS_THAN_40_CHARS`, `RECEIVER_ADDRESS_IS_REQUIRED`, `RECEIVER_ADDRESS_LENGTH_SHOULD_BE_LESS_THAN_500_CHARS`, `RECEIVER_NOTE_LENGTH_SHOULD_BE_LESS_THAN_500_CHARS`, `COD_AMOUNT_ERROR`. (`THIS_INVOICE_ALREADY_EXISTS` independently confirmed in the official Postman response above.)

**`POST /create_order/bulk-order/extended` — EXISTS** (live-verified: `GET` → HTTP 405). Same `data` array, max 500, but returns **human-readable per-field validation sentences** instead of the code constants, e.g. `{"status":200,"message":"Processed bulk order entries.","data":[{"invoice":"ORD-10232","status":"error","error":["The recipient phone format is invalid."],"consignment_id":null,"tracking_code":null}]}`. **Not in the official Postman collection** (zero grep hits for "extended"). **Prefer it for new integrations.**

### 6.5 Status-check endpoints

All live-verified as existing (`401 Unauthorized Access (missing API credentials)` unauthenticated, vs `404` for a bogus path):

| Method | Path | Attestation |
|---|---|---|
| `GET` | `/status_by_cid/{id}` | T3 guide verbatim; T1 Postman `GET .../status_by_cid/98339475`; official SDK |
| `GET` | `/status_by_invoice/{invoice}` | T3 guide verbatim; official SDK. **Not in official Postman** |
| `GET` | `/status_by_trackingcode/{trackingCode}` | T3 guide verbatim; official SDK. **Not in official Postman** |
| `GET` | `/status_with_return_status_by_cid/{consignment_id}` | **Not in T3 guide or T1 Postman.** Live-verified to exist; used by 15+ production repos |
| `GET` | `/trackings_by_invoice/{invoice}` | T1 Postman `GET .../trackings_by_invoice/INV0EEBA2A` |

Response for the three simple ones:
```json
{ "status": 200, "delivery_status": "in_review" }
```

**`delivery_status` values — T3 official guide, VERBATIM table:**

| Value | Description (verbatim) |
|---|---|
| `pending` | Consignment is not delivered or cancelled yet. |
| `delivered_approval_pending` | Consignment is delivered but waiting for admin approval. |
| `partial_delivered_approval_pending` | Consignment is delivered partially and waiting for admin approval. |
| `cancelled_approval_pending` | Consignment is cancelled and waiting for admin approval. |
| `unknown_approval_pending` | Unknown Pending status. Need contact with the support team. |
| `delivered` | Consignment is delivered and balance added. |
| `partial_delivered` | Consignment is partially delivered and balance added. |
| `cancelled` | Consignment is cancelled and balance updated. |
| `hold` | Consignment is held. |
| `in_review` | Order is placed and waiting to be reviewed. |
| `unknown` | Unknown status. Need contact with the support team. |

⚠️ **Correction to a commonly-cited list:** the real suffix is **`_approval_pending`**, not `_approval`. Identical list appears in the official package README.

Extras / traps:
- `exceptional` ("Lost, damaged, or otherwise outside the normal path") appears **only** in the newer PDF recreation. Handle defensively.
- `status_by_trackingcode` may return **`"in review"` with a space** rather than `in_review`. **Normalize whitespace and underscores.**
- **`in_transit` is NOT a documented Steadfast value** — it is fabricated in T4 sources.
- `status_by_invoice`: if the same invoice was booked more than once, it answers for the **most recent**.
- **Status responses are cached 60 seconds.** Polling faster returns the same value and burns rate limit.

**Return-progress statuses — from `/status_with_return_status_by_cid` only:** `partial_delivered_return_processing`, `partial_delivered_return_rider_assigned`, `partial_delivered_return_received`, `cancelled_return_processing`, `cancelled_return_rider_assigned`, `cancelled_return_received`. Shape: `{"status":200,"delivery_status":"cancelled_return_rider_assigned"}`. (A separate `return_status` key is claimed by T4 — unverified and conflicting.)

**`/trackings_by_invoice/{invoice}`** (T1 official Postman, real data):
```json
{ "status": 200,
  "tracking": [
    { "id": 1138890547, "consignment_id": 170577767, "sender_id": null,
      "tracking_type": 1, "icon": null,
      "text": "Consignment created by Sender(API).",
      "user_type": null, "user_id": 37722, "deliveryman_id": null,
      "admin_id": null, "updated_by": null, "confirm_status": 0,
      "status": 1, "hide_sender": 0,
      "created_at": "2025-09-04T09:46:19.000000Z",
      "updated_at": "2025-09-04T09:46:19.000000Z" } ] }
```
Observed `tracking_type`: `1` (created by sender/API), `10` (status updated), `6` (field changed). Human-readable text is in `text`. **This is Steadfast's event-timeline endpoint — Pathao has no equivalent.**

### 6.6 Current balance — `GET /get_balance`

Live-verified: exists, `401` unauthenticated. T3 official guide verbatim: *"5. Checking Current Balance — Path: /get_balance — Method: GET"*.
```json
{ "status": 200, "current_balance": 0 }
```
T1 official Postman 200 example: `{"status": 200, "current_balance": -10}` — **can be negative.** Bare number, BDT.

Semantics: delivered COD **less** delivery charges **less** a **1% collection charge**. Also the recommended authenticated-connectivity probe. ⚠️ One third-party doc shows `{"balance": 12500}` — **wrong key**; the official key is `current_balance`.

### 6.7 Webhooks

**Steadfast does have webhooks, but they are NOT in the public Postman collection** (618 KB grepped: zero occurrences of "webhook" or "notification_type") and **not in the older official guide text**. The spec ships with the merchant panel / newer PDF. Configured in the merchant panel — the official package README: *"SteadFast Webhook wants `Callback Url` and `Auth Token(Bearer)`"*.

**Transport:** **`POST`**, `Content-Type: application/json`, HTTPS endpoint required.

**Auth: `Authorization: Bearer <your-auth-token>`** — the token *you* configure in the panel. **CONFIRMED from the official package** (`steadfast-it/.../README.md`):
```php
$token = $request->header('Authorization');
if ($token !== 'Bearer '. config('steadfast-courier.webhook_bearer_token')) {
    return response()->json(['error' => 'Unauthorized'], 401);
}
```
with `config/steadfast-courier.php`: `"webhook_bearer_token" => env('STEADFAST_BEARER_TOKEN', 'your-generated-token')`.

**Additional headers — corroborated by TWO independent third parties, NOT by any official source:**
- `X-Signature` — **HMAC-SHA256 of the RAW request body, keyed with the webhook auth token.** Verify timing-safe; compute from raw bytes, never re-encoded JSON:
```php
$expected = hash_hmac('sha256', file_get_contents('php://input'), $token);
hash_equals($expected, $_SERVER['HTTP_X_SIGNATURE'] ?? '');
```
- `Idempotency-Key` — same value on retries; store and dedupe.
- `User-Agent: Steadfast-Webhook/1.0`
- **Retries:** on `5xx` or unreachable → ~30 s then ~2 min, **3 attempts total**. A `4xx` is **not** retried. Return any `2xx` to ack.

**Verdict:** treat `Authorization: Bearer` as **confirmed** (official SDK). Treat `X-Signature` / `Idempotency-Key` / retry timings as **strongly-attested-but-unofficial** — implement defensively (verify if present, don't hard-fail if absent) until a real request is observed.

**`notification_type` values (9 events)**, identical in two independent sources:
`delivery_status`, `tracking_update`, `consignment_update`, `return_list_accepted`, `payment_request`, `cancel_request`, `return_request`, `pickup_request`, `user_update`

**Payload — `delivery_status`** (identical JSON in two independent sources):
```json
{
    "notification_type": "delivery_status",
    "consignment_id": 12345,
    "invoice": "INV-67890",
    "cod_amount": 1500.00,
    "status": "delivered",
    "delivery_charge": 100.00,
    "tracking_message": "Package delivered successfully",
    "updated_at": "2025-03-02 12:45:30"
}
```
⚠️ **The status field is `status`, NOT `delivery_status` — `delivery_status` is the *event name*.** Documented `status` values for this event: `pending`, `delivered`, `partial_delivered`, `cancelled`, `unknown`.

**Payload — `tracking_update`:**
```json
{ "notification_type": "tracking_update", "consignment_id": 12345, "invoice": "INV-67890",
  "tracking_message": "Package arrived at sorting center", "updated_at": "2025-03-02 13:15:00" }
```

**Minimum required fields — OFFICIAL** (`steadfast-it/.../README.md` `validatePayload()`):
```php
$properties = ['consignment_id', 'invoice', 'status', 'cod_amount', 'updated_at'];
```
This officially confirms `consignment_id`, `invoice`, `status`, `cod_amount`, `updated_at`.

⚠️ A T4 source shows `{"event": "parcel_status_updated", ...}` — **no other source uses `event` or that value; treat as fabricated. Use `notification_type`.**

**Expected response:** `{"status": "success", "message": "Webhook received successfully."}` with HTTP 2xx. Any 2xx is fine.

### 6.8 Fraud / delivery-success-ratio check — **OFFICIAL AND REAL**

Two endpoints, **both live-verified** (`401` unauthenticated → routes exist). **This is the single most important finding for our fraud module.**

**(a) `GET /fraud_check/{phone}` — legacy, IN THE OFFICIAL POSTMAN COLLECTION.** T1 request named **"Fraud Check"**: `GET https://portal.packzy.com/api/v1/fraud_check/{phone}`, headers `api-key` + `secret-key`. **Official 200 response:**
```json
{
    "total_parcels": 24,
    "total_delivered": 9,
    "total_cancelled": 15,
    "total_fraud_reports": []
}
```
⚠️ **No `status` wrapper on this one.** Note `total_fraud_reports` is an array — i.e. **Steadfast itself maintains merchant-submitted fraud reports.**

**(b) `GET /fraud_check/score/{phone}` — newer scoring endpoint.** Live-verified to exist. Documented in `AH-Raihan/laravel-steadfast-courier/src/SteadfastClient.php` (`checkFraud()`) and T3 shopnow §10:
```json
{
  "status": 200,
  "phone": "01712345678",
  "delivery_ratio": 92,
  "cancellation_ratio": 7,
  "volume_band": "high",
  "total_reports": 0,
  "fraud_categories": {},
  "score": null,
  "level": null,
  "reasons": [],
  "scoring_disabled": true,
  "doubtful_reports": false
}
```
⚠️ **`score` and `level` currently return `null` and `scoring_disabled` is `true` — Steadfast's composite scoring is switched off.** Use `delivery_ratio` / `cancellation_ratio` (whole percentages of *finished* parcels; **partial-delivered counts as delivered**; both `null` when nothing has finished), `volume_band` (`none` | `low` 1–5 | `medium` 6–20 | `high` 21–200 | `very_high` 200+), `total_reports`, `fraud_categories`.

**Rate limit is tied to your own parcel volume: roughly (4 × busiest day's parcels in the last week) + 10 checks.** ⚠️ **The older count endpoint `/fraud_check/{phone}` is being deprecated and reportedly stops returning counts on 27 September 2026** (third-party-sourced date — verify with Steadfast).

**Corroboration this is real and used:** Steadfast's own merchant sign-in page markets a **"Delivery Risk Check"** in the dashboard, and multiple independent production repos call these endpoints (`rzzisan/bsol`, `akidmahmud/amadere-monorepo`, `click2itbd/e-Commerce`, `naeemBro17/naeems`).

⚠️ T4's version of this response (`success_ratio: "91.67%"`, `risk_level: "low"`, `merchant_reports`, `delivered_parcels`, `cancelled_parcels`) matches **neither** attested shape — **fabricated field names, do not use.**

### 6.9 Full endpoint surface (live-probed 2026-11-07)

404 = absent · 401 = exists + auth required · 405 = exists + POST-only

| Method | Path | Live probe | In official Postman? | In official guide text? |
|---|---|---|---|---|
| GET | `/ping` | **200, no auth** | no | no |
| POST | `/create_order` | 405 on GET | **yes** | **yes** |
| POST | `/create_order/bulk-order` | 405 on GET | **yes** | **yes** |
| POST | `/create_order/bulk-order/extended` | 405 on GET | no | no |
| GET | `/status_by_cid/{id}` | 401 | **yes** | **yes** |
| GET | `/status_by_invoice/{invoice}` | 401 | no | **yes** |
| GET | `/status_by_trackingcode/{code}` | 401 | no | **yes** |
| GET | `/status_with_return_status_by_cid/{id}` | not probed (15+ repos use it) | no | no |
| GET | `/trackings_by_invoice/{invoice}` | not probed | **yes** | no |
| GET | `/get_balance` | 401 | **yes** | **yes** |
| POST | `/create_pickup_request` | not probed | **yes** | no |
| POST | `/create_return_request` | 405 on GET | **yes** | **yes** |
| GET | `/get_return_request/{id}` | not probed | **yes** | **yes** |
| GET | `/get_return_requests` (`?page=N`) | not probed | **yes** | **yes** |
| GET | `/payments` (`?page=N`) | not probed | **yes** | **yes** |
| GET | `/payments/{payment_id}` | not probed | **yes** | **yes** |
| GET | `/police_stations` | 401 | **yes** | **yes** |
| GET | `/fraud_check/{phone}` | 401 | **yes** | no |
| GET | `/fraud_check/score/{phone}` | 401 | no | no |
| — | `/returns`, `/cities`, `/zones`, `/delivery_areas` | **404 — DO NOT EXIST** | | |

`/ping` (no auth, live-verified): `{"status":200,"message":"pong","service":"steadfast-api","version":"v1","time":"2026-11-07T23:34:44+06:00"}` — ISO-8601 with Asia/Dhaka `+06:00`. **A successful ping does NOT validate credentials — use `/get_balance` for that.**

Other official shapes worth knowing (all T1 official Postman, real data):
- **`POST /create_pickup_request`** → **201**; body `{address_id, police_station_id, address, contact_number, note, estim_qty}`; response `{"message":"Pickup request created successfully.","data":{id, user_id, user_address_id, police_station_id, pickup_location, contact_number, note, estim_qty, req_status, created_at}}`
- **`POST /create_return_request`** → **201**; body `{"consignment_id": "..."}` **or** `{"invoice": "..."}` **or** `{"tracking_code": "..."}`, plus optional `reason`; response is the bare return-request object `{id, user_id, consignment_id, consignment:{...}, reason, status, created_at, updated_at}`. **Return statuses: `pending`, `approved`, `processing`, `completed`, `cancelled`** (T3 guide verbatim).
- **`GET /get_return_requests`** → Laravel paginator: `{data:[...], links:{first,last,prev,next}, meta:{current_page,from,last_page,links,path,per_page:10,to,total}}`
- **`GET /payments`** → `{"status":1,"alertClass":"success","message":"Fetched successfully!","payments":[{payment_id:"SFC-23618228", amount, method:"Cash"|"Rocket", due_bills, paid_bills, charges, total, status_label:"ready"|"paid", created_at, ready_at, paid_at}]}`. ⚠️ **`status` is `1` here, not `200`, and the key is `alertClass` with a capital C.** **This is the COD settlement feed — the basis for real COD reconciliation.**
- **`GET /payments/{payment_id}`** → `{status, alertClass, message, payment:{..., consignments:[ {full consignment objects} ]}}`. The `SFC-` prefix is stripped to digits. **This gives payment-advice → consignment matching, exactly what AppByte appears to lack (gap #19).**
- **`GET /police_stations`** → `{"status":"success","data":[{id:66, name:"Bagerhat", policestations:[{id:127, name:"Bagerhat sadar", hub_id, district_id, ps_type, big_parcel, post_code, address, search_tags, phone, admin_id, updated_by, status, created_at, updated_at}]}]}` — **districts with nested `policestations`, and `status` is the string `"success"`, not `200`.** `police_station_id` for pickup requests comes from the inner `id`. (A flat `{id, thana, district, division}` shape in T4 is fabricated.)

⚠️ **Steadfast has NO price/delivery-charge calculation endpoint** (`/delivery_charge` not probed but `/cities`, `/zones`, `/delivery_areas` are confirmed 404). Unlike Pathao's `/merchant/price-plan`, charges must come from Steadfast's published rate card or our own zone table. **Also: Steadfast has no city/zone/area ID hierarchy at all — addresses are free text.** This is a major architectural asymmetry with Pathao.

### 6.10 Rate limits & HTTP codes (newer-PDF sourced, not independently verifiable)

- **1,000 req/min** general; **6,000 req/min** for booking → `429`.
- ⚠️ **10 auth failures from one IP/key within 5 minutes → 60-minute lockout, even with correct keys afterwards. NEVER retry a 401 in a loop.**
- Codes: `200` (incl. per-item failures in bulk), `201` (created: pickup/return), `400`, `401`, `403`, `409` (e.g. `PICKUP_REQUEST_EXISTS`), `422` (e.g. return request on an already-delivered parcel), `429`, `500`.
- **On a `500`/timeout, retrying with the SAME `invoice` is safe** — duplicate invoices are refused, which prevents double booking. **Make `invoice` our idempotency key.**

**Steadfast source URLs used:**
- https://documenter.getpostman.com/view/26211192/2sAYJ1mhpk (**official** published collection) — JSON: `https://documenter.gw.postman.com/api/collections/26211192/2sAYJ1mhpk?segregateAuth=true&versionTag=latest`
- https://github.com/steadfast-it/SteadFast-Courier-Laravel-Package (**official** vendor SDK)
- https://steadfast.com.bd/ and https://steadfast.com.bd/user/api (login-gated; no public docs)
- https://github.com/MdSalman2022/Ecommerce-site-backend → `steadfastapi.txt` (verbatim paste of the official guide)
- https://github.com/PKTonmoy/e-com2 → `Steadfast Courier Limited.txt` (duplicate of the above)
- https://github.com/mehedijaman/shopnow → `docs/steadfast-courier-api.md` (recreation of newer official PDF)
- https://github.com/TeamRSBDigital/STEADFAST_API → `STEADFAST_WEBHOOK.md`
- https://github.com/inversemaha/SteadFastWebhookForFunnelliner → `README.md`
- https://github.com/AH-Raihan/laravel-steadfast-courier → `src/SteadfastClient.php`, `README.md`
- **Do NOT cite:** https://github.com/refatbd/bangladesh-api-docs, https://steadfast-python.readthedocs.io/

### 6.11 Pathao vs Steadfast — architectural asymmetries to design around

| Concern | Pathao | Steadfast |
|---|---|---|
| Auth | OAuth-ish token, 5-day expiry, refresh needed; **400 not 401** on auth failure | Two static headers, no expiry, **401** on failure |
| Sandbox | **Yes** (`courier-api-sandbox.pathao.com`) with published test creds | **None at all** |
| Address model | **Structured** city → zone → area IDs (lookup endpoints) | **Free text only**; no city/zone/area IDs |
| Price calc | **Yes** — `POST /merchant/price-plan` | **None** — use rate card / own table |
| Bulk create | **202 async, NO IDs returned** — webhook-dependent | **200 sync, per-item IDs returned** |
| Idempotency | `merchant_order_id` (not enforced) | **`invoice` is unique-enforced** — true idempotency key |
| Tracking timeline | **None** — snapshot only; accumulate webhooks | **Yes** — `/trackings_by_invoice` event list |
| COD settlement | `order.paid` webhook with `invoice_id` | **`/payments` + `/payments/{id}`** with nested consignments |
| Fraud check | **No public endpoint** — portal scraping only | **Official** `/fraud_check/{phone}` + `/fraud_check/score/{phone}` |
| Webhook auth | Plain shared secret in `X-PATHAO-Signature` (**not** HMAC) + **must echo a hardcoded global UUID** | `Authorization: Bearer` (official) + probable HMAC `X-Signature` |
| Partial delivery | `order.partial-delivery` + `collected_amount` | `partial_delivered` + `partial_delivered_*` return states |
| Weight limits | **0.5–10 kg enforced** | Not documented |

**Design implication:** build a courier-adapter abstraction where **address resolution, price calculation and idempotency are per-adapter concerns**, not shared. Do not assume a common "city/zone/area" model — Steadfast has none. Store the raw courier response per booking so later schema drift is recoverable.

---

## 7. Research limitations

**Be clear about what is and isn't verified.**

### 7.1 AppByte / EcomByte
1. **The site is a single-page, 100% client-rendered React app with no sub-pages whatsoever.** `WebFetch` returns nothing usable. Every one of the 16 paths I probed (`robots.txt`, `sitemap.xml`, `features`, `pricing`, `docs`, `blog`, `demo`, `integrations`, `changelog`, `help`, `about`, `api-docs`, `login`, `register`, `app`, `dashboard`) returned the **same 634,483-byte SPA shell**. There is **no features page, pricing page, module documentation, blog, help centre, seller academy, API docs, changelog, legal pages, or public demo tenant** — despite the footer advertising all of them as links (they are `href`-less strings).
2. **All AppByte capability claims in Section 1 come from one source: the marketing copy inside that bundle.** I extracted them from the production JS (string literals, the 29-item FAQ array, the 3-plan pricing array, the 31-row feature-comparison array, the 29-card feature grid, and the interactive demo widgets' hard-coded data). This is reliable evidence of **what AppByte claims**, and nothing more.
3. **Zero independent verification of any AppByte capability.** No review article, comparison piece, merchant testimonial, forum thread, or case study exists. Searches in English and Bengali returned nothing about AppByte/EcomByte. The only third-party datapoint is a Clutch profile for "Appbyte" as a dev agency with **one** client review (an unrelated Android gardening app).
4. **Every "screenshot" on the site is a hand-coded React mock with hard-coded fake data** (`করিম উদ্দিন`, `০১৭১২-৩৪৫৬৭৮`, `জামদানি শাড়ি`, `risk:78`). **I have not seen the actual admin UI.** Feature claims like "bulk actions" or "order grid filters" cannot be visually confirmed.
5. **No access to the running product.** No self-serve signup, no trial, no demo tenant, no screenshots of real screens. Could not test anything.
6. **`github.com/appbyte-bd/product-ecombyte` is indexed by search engines but returns HTTP 404 from the GitHub API** — private or deleted. No README obtainable.
7. **AppByte's Facebook page and YouTube channel could not be read** — both are JS-gated and returned no post or video content. Only one video title was recoverable (`f9HlOycwihw`). **No Facebook posts or YouTube descriptions were verifiable**, contrary to what the brief hoped for.
8. **Internal contradictions in AppByte's own copy** mean some claims cannot be trusted even as claims: "7 couriers" (pricing table) vs "4 couriers" (FAQ + code); a "Scale plan" (FAQ Q28) that does not exist in the pricing array.
9. **The fraud-check data source is never disclosed by AppByte.** My account of *how* it must work (Section 3.2) is **inferred from the category**, not from AppByte. Treat it as a strong hypothesis.
10. **Nothing about AppByte's purchase/supplier/accounting internals could be verified** — absence from exhaustive marketing copy is suggestive but not proof.

### 7.2 Competitors / category
11. Competitor data is **vendor marketing copy**, not independently verified. Easy Order Manager's feature list and prices come from its own site. Bonik AI's ৳590/mo came from a search summary, **not a direct page fetch** — treat as soft.
12. **"Ordena" and "BanglaCommerce" could not be found** as BD order-management products. "EasyOrder" results were mostly a European food-ordering product; the BD match is **Easy Order Manager** (`easyordermanager.com.bd`). ShopUp and Sheba were not researched in depth — they are adjacent (B2B supply / services marketplace) rather than direct order-management SaaS.
13. The reverse-engineered courier endpoints in Section 3.2 are **real and read from source code**, but they are **undocumented, unsupported, and can break or be blocked without notice.** They also require storing the merchant's **courier portal password**, which is a meaningful security and ToS liability. **Do not treat them as an API.**

### 7.3 Pathao
14. **Official Pathao docs are login-gated and JS-only.** `merchant.pathao.com/courier/developer-api` serves only an SPA shell; `developer.pathao.com` **does not exist** (DNS failure); Wayback was rate-limited (429). **No field-level fact is citable to an official doc page.** The strongest evidence is Pathao's **own GitHub plugin source** (`pathao-eng/courier-woocommerce-plugin`), supplemented by a demonstrably-genuine third-party scrape of the gated docs.
15. **No authenticated Pathao round-trip was performed. All response JSON shapes are documented, not observed.** Paths, verbs, auth header format and webhook semantics are verified; response bodies are not.
16. **Unauthenticated route probing cannot validate Pathao paths** — its auth middleware runs before routing, so every path (including deliberately bogus ones) returns the same `400 bearer token missing`. Hence `POST /orders/{id}/cancel` remains **unverified — do not rely on it**.
17. The undocumented `POST /aladdin/api/v1/external/login` client-credentials flow is **first-party-source-code evidence only**. Promising, but **must be tested**.
18. No documented **max batch size** for Pathao bulk create was found.

### 7.4 Steadfast
19. **Official Steadfast docs are login-gated** — there is no public developer page on `steadfast.com.bd`. The only *public official* artifacts are its **published Postman collection** and the **`steadfast-it` Laravel package**. The exact constraint wording comes from a **verbatim third-party paste** of Steadfast's own guide (authentic-looking — original TOC, masked keys, "Yellow colour marked parameters are added newly" — but still a copy). **Get the current PDF from Steadfast support or the merchant panel before going live.**
20. **The official Postman collection is incomplete/stale** — missing `/ping`, `/status_by_invoice`, `/status_by_trackingcode`, `/status_with_return_status_by_cid`, extended bulk, `/fraud_check/score`, the `delivery_type` and `total_lot` inputs, and **webhooks entirely**. Its bulk-order body also **contradicts the guide** (bare array vs `data` field).
21. **Not independently verifiable** (all from third-party recreations of the newer official PDF, consistent across 2–3 authors but not from Steadfast directly): the 1,000/6,000 req/min limits, the **60-minute auth lockout**, the 60-second status cache, the exact character-stripping set, the 500-item bulk cap *in practice*, `tracking_link`, the `exceptional` status, the `X-Signature`/`Idempotency-Key`/retry behaviour, the `/fraud_check/score` response body, and the **27 September 2026 deprecation date** for `/fraud_check/{phone}`.
22. **No authenticated Steadfast calls were made** — only unauthenticated probes using status codes (404/401/405) to prove route existence. Response bodies are documented, not observed. Several endpoints (`/trackings_by_invoice`, `/payments`, `/get_return_requests`, `/create_pickup_request`) were **not probed at all**.
23. **Two named third-party sources are partly fabricated and must not be cited:** `refatbd/bangladesh-api-docs` (endpoint *paths* are right, **example payloads invented** — `tracking_code:"ST-9845210"`, `delivery_status:"in_transit"`, `{"event":"parcel_status_updated"}`, flat `police_stations`, `success_ratio`/`risk_level`) and `steadfast-python.readthedocs.io` (**invents a sandbox URL** on an unrelated company's domain).

### 7.5 Blocked actions — surfacing for the user's decision
24. Both courier research agents reported that **the environment's permission system blocked an action**, and asked that it be run instead. **I did not run either, and I did not alter any permission settings.** Flagging them so you can decide:
    - **Pathao:** issuing a sandbox access token (using the test credentials Pathao itself publishes in its docs) to observe real response JSON rather than documented shapes. Would require allowing an outbound `POST` to `courier-api-sandbox.pathao.com`.
    - **Steadfast:** a looped unauthenticated endpoint sweep against `portal.packzy.com` to existence-confirm the remaining paths. This was blocked as scanning behaviour.
    Neither is necessary for the architecture work; both would upgrade "documented" to "observed". **The better path is to obtain real merchant credentials for both couriers and verify against live sandbox/production once, rather than probing.**

### 7.6 Net confidence summary

| Area | Confidence | Why |
|---|---|---|
| AppByte's **claimed** feature set | **High** | Extracted verbatim from the production bundle |
| AppByte's **actual shipped** feature set | **Very low** | No demo, no screenshots, no reviews, no docs, internally contradictory copy |
| AppByte pricing | **High** | Verbatim from the pricing data array |
| Category-standard feature set | **High** | Multiple independent vendors agree |
| BD fraud-check **mechanism** | **High** | Read from working source code + two vendor pages |
| AppByte's **specific** fraud data source | **Low (inferred)** | Never disclosed by AppByte |
| Pathao paths / verbs / auth shape / webhook semantics | **High** | First-party plugin source + live probes + Postman |
| Pathao response bodies | **Medium** | Documented via a genuine docs scrape; never observed |
| Steadfast base URL / auth / core endpoints | **High** | Official Postman + official SDK + live probes |
| Steadfast fraud-check endpoints | **High** | In Steadfast's own published collection; live-probed |
| Steadfast webhooks | **Medium** | Bearer auth official; signature/retry details third-party only |
| Steadfast rate limits & newer-PDF details | **Low–Medium** | Third-party recreations only |

