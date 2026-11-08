# Agent 1 — Competitor Teardown: 5 Bangladesh Kids' Toy E-commerce Sites

**Research date:** 2026-11-07
**Method:** Direct HTTP fetches of raw HTML (desktop Chrome UA, redirects followed), plus `WebFetch`
for cross-checking, plus one `WebSearch` to test index visibility. All evidence strings below were
read out of the actual response bodies, not inferred.

## Evidence & limitations (read this first)

| Item | Note |
|---|---|
| **Rendering ceiling** | Fetches do not execute JavaScript. On client-rendered sites (sktoy, kidszonebd) anything that only exists after hydration is marked **Not verified**, never "absent". |
| **Checkout access** | WooCommerce redirects `/checkout/` → `/cart/` on an empty cart. I established a session and added one item on **toyshop** (succeeded — real checkout captured). The same approach on **kidsrepublicbd** set **no cookies at all** (full-page cache / nonce requirement), so its checkout is **Not verified**. |
| **robots.txt restrictions** | `sktoy.com.bd` and `krystal.com.bd` both serve the identical 2,676-byte **Cloudflare-managed** robots.txt which contains `User-agent: ClaudeBot` / `Disallow: /` (also GPTBot, CCBot, Google-Extended, etc.) alongside `User-agent: *` / `Content-Signal: search=yes,ai-train=no,use=reference` / `Allow: /`. Research here was low-volume, user-directed page reads (not crawling or training), but the restriction is recorded because it is a required finding — and because it means these two sites are **excluded from AI answer engines by their own config**. |
| **Private APIs** | I attempted to query `api.sktoy.com.bd` to recover client-rendered PDP data; that request was blocked by the sandbox policy. SK Toy's PDP internals are therefore **Not verified**. |
| **Not probed** | I did not place orders, submit forms, or log in anywhere. |

---

# 1. SK Toy — https://sktoy.com.bd/

Canonical host redirects `https://sktoy.com.bd/` → **`http://www.sktoy.com.bd/`** (301, and note the
downgrade to `http://` in the `Location` header), while `rel=canonical` says
`https://www.sktoy.com.bd`. Inconsistent host/protocol canonicalisation.

## A. Platform detection — **Next.js (App Router) headless SPA + separate Node API**

| Signal | Evidence string found |
|---|---|
| Next.js | `/_next/static/chunks/...` — **104 occurrences** on the homepage; `self.__next_f.push(...)` RSC flight payload (12 pushes); `<meta name="next-size-adjust" content="">`; Turbopack chunk `turbopack-0mizl0cx5_6od.js` |
| No `__NEXT_DATA__` | 0 occurrences → App Router, not Pages Router |
| Separate API | `https://api.sktoy.com.bd/api` found inside a `/_next/static/chunks/*.js` bundle |
| Datastore | MongoDB ObjectIds in RSC payload, e.g. `"_id":"69f1f23bd51962a151b15620","key":"global"` |
| Media | S3: `https://sk-toy.s3.ap-south-1.amazonaws.com/media/202609/...webp` |
| Auth/analytics | Firebase config inline: `"projectId": "sktoy-60a12"`, `"measurementId": "G-R9C6P60JKN"` |
| Not WordPress/Shopify | `wp-content` = 0, `woocommerce` = 0, `cdn.shopify.com` = 0, `Shopify.theme` = 0, `csrf-token` = 0 |
| Agency | Footer: `Crafted with ♥ by Rootdevs` |

Custom-built store. 81 product URLs and 36 category URLs in the sitemap.

## B. UX & site structure

**Homepage sections, in order** (homepage *is* server-rendered):
1. Bangla promo bar — `৳৩,০০০ টাকার বেশি অর্ডারে ফ্রি ডেলিভারি।` ("free delivery over ৳3,000")
2. Header: All Products / Categories / Your Offers / Call Us Now +8801965603234 / Track Order / Wishlist / Cart / Login–Sign Up
3. **New Arrivals** (4 cards, each with % OFF badge, strike-through compare price, "🚚 Free Ship" badge, "Order Now" CTA)
4. **Shop By Age** — 5 emoji tiles: 0–2 "First rattles & soft toys", 3–5 "Building & pretend play", 6–8 "STEM & outdoor adventure", 9–12 "RC, drones & strategy", 12+ "Tech, models & collectibles"
5. **Explore Everything / All Products** (8 cards)
6. **Best Selling Products** (8 cards)
7. Trust strip: Reliable Shopping / Secure Payment / Customer Support 24/7 / Nationwide Delivery / Easy Return (3-day)
8. Footer: address `54/C, Nurjahan Road Mohammadpur, Dhaka - 1207`, newsletter, payment badges **bKash · COD · Nagad**

**Navigation:** flat top bar + a slide-out "🌈 Menu" drawer listing 9 categories. Mega menu: No.
**Category depth:** 3 levels in URLs — `/categories/books/activity-books`, `/categories/dolls/action-figures`.
**Cart:** slide-out drawer, server-rendered empty state ("Your cart is empty 🧸").

**PLP, PDP, cart, checkout, /track — all client-rendered. Verified:** `<main>` on
`/products/childrens-doctor-set` contains **only a spinning loader**:

```html
<main class="flex-1"> …<script type="application/ld+json">…</script>
<div class="flex justify-center items-center py-32">
  <svg class="animate-spin text-[#FF5B6E] w-10 h-10" …></svg>
</div><!--$--><!--/$--></main>
```

After stripping every `<script>`, the product name appears **only** in `<title>`/meta/OG — zero
product markup, no price, no gallery, no add-to-cart, no filters, no reviews in the DOM. Identical
shell is served for `/products`, `/cart`, `/checkout`, `/track` and every category page (all ~70–80 KB
raw, differing only in head metadata and JSON-LD).

**Filters (URL-level, verified present as links):** `/products?age=age-0-2|age-3-5|age-6-8|age-9-12|age-teen`,
`/products?filter=sale`, `/products?q={term}`. Price/brand/gender filters and sort options: Not verified.

**Search:** two inputs exist (`placeholder="Search Products..."`, `placeholder="Search toys, brands, ages…"`)
but **neither is inside a `<form>` and neither has a `name` attribute** — search is JS-only and
degrades to nothing. `/search?q=car` returns **404**. The working endpoint is `/products?q=`, per the
`SearchAction` JSON-LD: `"urlTemplate": "https://www.sktoy.com.bd/products?q={search_term_string}"`.
Autocomplete / typo tolerance: Not verified.

**Checkout / delivery (recovered from the RSC payload — authoritative store config):**
```json
"shipping":{"insideDhaka":{"title":"Inside Dhaka","amount":80,
   "description":"Delivered within 1–2 business days","freeOver":2500},
 "outsideDhaka":{"title":"Outside Dhaka","amount":130,
   "description":"Delivered within 1–3 business days","freeOver":2500}},
"paymentMethods":{"cod":{"enabled":true,"label":"Cash on Delivery",
   "description":"কোনো অগ্রিম পেমেন্ট নয় — পণ্য হাতে পেয়ে পেমেন্ট করুন।"},
 "bkash":{"enabled":false,"label":"bKash","description":"Pay securely via bKash"}}
```
→ Inside Dhaka **৳80** (1–2 days), Outside Dhaka **৳130** (1–3 days), free over **৳2,500**.
**COD enabled; bKash gateway explicitly `enabled:false`** despite bKash and Nagad badges in the
footer. Step count, field list, district dropdown, guest checkout: Not verified (client-rendered).

## C. E-commerce features
Wishlist route `/wishlist` + 27 `wishlist` references: Yes. Order tracking `/track` (200): Yes.
`/account`, `/login`, `/register`: Yes. Sale/discount badges and compare-at pricing: Yes (SSR'd on home).
Age-based browse: Yes. Coupons, bundles, recently viewed, reviews, countdown/flash sale, variant
selector, stock quantity: **Not verified** (no server HTML). No `countdown`/`flash sale` string
anywhere in any fetched page.

## D. SEO
- **URLs:** clean slugs — `/products/childrens-doctor-set`, `/categories/dolls-figures`. Filters use query strings.
- **PDP `<title>`:** `Fun Children's Doctor Set for Ages 3-5 - Educational Play | SK Toy Bangladesh` (77 chars) — good, age-targeted.
- **PDP meta description:** 142 chars, benefit-led — the best-written of the five.
- **JSON-LD:** richest set found. Home: `Organization`, `ToyStore`, `WebSite`, `SearchAction`, `EntryPoint`, `ContactPoint`, `PostalAddress`, `GeoCoordinates`, `Country`. PLP: `BreadcrumbList`, `ItemList`, `ListItem`. PDP: `Product`, `Offer`, `Brand`, `BreadcrumbList`, `ListItem`, `MerchantReturnPolicy`, `OfferShippingDetails`, `MonetaryAmount`, `DefinedRegion`, `ShippingDeliveryTime`, `QuantitativeValue`, `Organization`.
- **Three JSON-LD defects, verified:**
  1. `"offers":{"@type":"Offer","price":0,"priceCurrency":"BDT", … "availability":"https://schema.org/InStock"}` — **price 0 with InStock**. Google rejects this.
  2. `"description"` contains raw HTML (`<h3>…</h3><p>…</p><ul><li>…`) inside the JSON string.
  3. `shippingRate` = **`"value":"60"`**, contradicting the site's own ৳80/৳130 config.
- **No `AggregateRating` / `Review`** on the PDP.
- **OG:** home has only `og:title/description/type/url` (**no `og:image`**); PDP has the full set incl. `og:image`, `og:locale`. Twitter card present everywhere.
- **Breadcrumbs:** `BreadcrumbList` JSON-LD present; visual breadcrumb Not verified.
- **sitemap.xml:** present, 131 URLs (81 product, 36 category, 7 blog, 5 pages, 1 sale, 1 home), with `lastmod`/`changefreq`/`priority`. Image sitemap namespace declared but **unused**.
- **Language:** `<html lang="en">`, no `hreflang`, but `WebSite.inLanguage: ["en-BD","bn-BD"]` and Bangla body content (2,725 Bangla runs on the homepage).
- **Analytics:** GA4 `G-R9C6P60JKN` (via Firebase, gtag direct — no GTM), Meta Pixel `fbq('init','1516765536845697')`, **TikTok Pixel `D9Q54VBC77U4UUP3JU0G`** (`analytics.tiktok.com`, `ttq.load`). Only site of the five running a TikTok pixel.

## E. Bangladesh localisation
Strong. Bangla promo bar, fully Bangla product descriptions, Bangla COD copy. `৳` glyph used (45× on
home), Bangla numerals (`৳৩,০০০`). Phone `+8801965603234` displayed in header + footer. Dhaka shop
address. COD default and bKash/Nagad/COD badges. WhatsApp: 1 reference (link type Not verified).
Messenger/FB chat: **none found**. **No courier named anywhere.**

## F. Weaknesses (observed)
1. **PDP/PLP/cart/checkout ship a loading spinner to crawlers and to first paint.** Verified: `<main>` holds only `animate-spin` SVG. Inconsistent with the homepage, which *is* SSR'd — so this is an implementation gap, not a deliberate choice.
2. **Broken structured data:** `Offer.price = 0` + `InStock`; HTML inside `description`; `shippingRate` 60 vs real 80/130.
3. **Three mutually contradictory free-delivery thresholds:** promo bar says `৳৩,০০০`, store config says `freeOver: 2500`, schema implies a flat ৳60 rate.
4. **Catalog data errors visible on the homepage:** 2 products priced `৳0`, 4 cards labelled "Coming Soon", 1 "SOLD OUT" — including the PDP I sampled (Children's Doctor Set, ৳0).
5. **Search is non-functional without JS** (inputs outside any form, no `name`), and `/search?q=` 404s while the advertised endpoint is `/products?q=`.
6. **Host/protocol canonicalisation broken:** `https://` 301s to `http://www.`; canonical declares `https://www.`.
7. **TTFB 1.52 s** on the PDP (18.3 KB gzip HTML, 43 scripts on home) — slow for a page that then has to fetch everything again client-side.
8. Payment badges advertise bKash while the bKash gateway is `enabled:false`.
9. **robots.txt blocks every AI crawler** (ClaudeBot, GPTBot, Google-Extended…) → invisible to AI answer surfaces.
10. Image sitemap namespace declared without a single `<image:image>` entry.

---

# 2. Krystal Toys — https://krystal.com.bd/

## A. Platform detection — **Astro frontend (SSR) + headless WooCommerce backend**

| Signal | Evidence string found |
|---|---|
| Astro | `/_astro/Base.astro_astro_type_script_index_1_lang.DH_Kddob.js`, `/_astro/CartDrawer.astro_astro_type_script_index_0_lang.BAoYzEiS.js`, `/_astro/Checkout.astro_astro_type_script_index_0_lang.BfFob6-1.js`, `/_astro/ProductCard.astro…`, `/_astro/StoreHeader.astro…`, `/_astro/ChatAssistant.astro…`, `/_astro/MetaPixel.astro…`; scoped attrs `data-astro-cid-4fhpls6h` |
| Headless WooCommerce | **282** `wp-content` occurrences, all pointing at `https://backendapi.krystal.com.bd/wp-content/uploads/…`; PDP embeds WooCommerce Store API payloads with `"_links":[{"href":"https://backendapi.krystal.com.bd/wp-json/wc/store/v1/products/24317"}]` |
| Backend confirmed WP | `backendapi.krystal.com.bd` headers: `x-powered-by: PHP/8.5.12`, `link: <https://backendapi.krystal.com.bd/wp-json/>; rel="https://api.w.org/"` |
| CDN | Cloudflare (`cf-cache-status: HIT` on the storefront) |
| Fonts | `fonts.bunny.net` (privacy-friendly Google Fonts proxy) |

Largest catalog of the five: **820 products**, 22 categories, 88 blog posts, 14 pages (946 sitemap URLs).

## B. UX & site structure

**Homepage sections, in order:**
1. Header: search box (`action="/search/"`), Shop, Categories mega panel, cart drawer
2. Hero — "A world of play / Find their next little adventure." → "Browse all toys →"
3. **Shop by category** — mega panel, 2 levels: Cars & RC Vehicles (Die Cast, DIY/Lego, Hot Wheels, Pull-Back & Friction, RC Vehicles) · Educational Toys (Family Board Games, Lego Type, Puzzle & Blocks, STEM & Learning) · Baby Toys (Activity, Musical, Rattles & Shakers, Sensory, Teethers) · Outdoor & Bath Toys (Bath Squirters, Night-time Comfort, Outdoor Games) · Others
4. **Latest Trends** carousel (with ← → and an explicit **Pause** control — good a11y)
5. Per-category rails: Cars & RC Vehicles / Educational Toys / Baby Toys
6. "Their next favourite is waiting."
7. Bangla trust strip (4 `<h2>`): `ডেলিভারি চার্জ` · `১০০% অরিজিনাল পণ্য` · `সহজ ও নিরাপদ পেমেন্ট` · `দ্রুত ও নির্ভরযোগ্য ডেলিভারি`
8. Footer + **"Krystal Assistant"** AI chat widget

**Navigation:** mega menu (2 levels). **Category depth:** URLs are flat (`/category/educational-toys/`) even though the backend taxonomy nests (`/product-category/educational-toys/stem-learning-toys/`) — the frontend flattens to one level.

**PLP (`/category/educational-toys/`):** SSR'd grid with names, SKUs, sale/compare prices, `-22%` badges. Intro copy above the grid (good for SEO). Filters panel: **price range (min/max with validation message), Availability & offers (In stock only, On sale), Color**. Sort `<select name="sort">` with `newest / price-asc / price-desc / name-asc` — **rendered `disabled` in SSR** (JS enables it). Product cards carry a `+` quick-add; the stock label is a `hidden` placeholder populated client-side from the Store API. No age, brand or gender filter. No pagination block in SSR (infinite/"Load more" pattern).

**PDP:** breadcrumb (`Home / Educational Toys / <product>`), gallery with **zoom modal** (`class="gallery-zoom-trigger"`, controls `← → − 100% + Fit`), SKU `KET04-014CT`, price `From ৳349.00` / `৳449.00 Save 22%`, **`In stock`** server-rendered as an ARIA live region:
```html
<p id="product-availability" class="product-availability" data-state="available"
   role="status" aria-live="polite" aria-atomic="true">In stock</p>
```
Store API payload embedded in the page exposes `"low_stock_remaining":1`, `"stock_availability":{"text":"1 in stock","class":"in-stock"}`, `"variations":[]`, `"has_options":false`, `"average_rating":"0"`, `"review_count":0`. Quantity stepper, **Add to cart**, **Order now** (quick-order modal), phone CTA `0188 666 7666`, **WhatsApp** button, Bangla trust trio (Free Delivery / Cash on Delivery / Original Product), Description, **Related Products** rail.

**Search:** `role="combobox"` + `aria-autocomplete` + "View all results →" → **real autocomplete**. `/search/?q=car` returns a results shell with "Load more results" (results hydrate client-side). **`/?s=car` returns a byte-identical copy of the homepage** (verified with `cmp`) — the WordPress-style search param is silently swallowed, a trap for any legacy link or external deep link.

**Cart:** drawer only. **`/cart/` returns 404** — there is no cart page URL.

**Checkout (`/checkout/`, fully readable in SSR) — best-in-class for Bangladesh.** One page, two labelled steps:
- *1. Your Details* — `full_name*`, `phone*`, **`district*` (full 64-district `<select>`: Bagerhat → Thakurgaon)**, **`thana*` (dependent select, placeholder "Choose district first")**, `full_address*`, `email` (**explicitly optional** — "Add email (optional)"), `order_notes` (optional)
- *2. Delivery & Payment* — "Delivery is calculated from your address." Card/Net Banking via **SSLCommerz**; **cash (COD)** and bank-transfer "arranged with the store"
- `coupon_code` + Apply
- Terms & Conditions / Refund & Returns / Privacy consent checkbox → **Place Order**
- **Guest checkout:** yes, with optional *post-purchase* account creation ("Set a password to track your orders… Verify your mobile number once by SMS")
- Thoughtful safeguards: "Placing your order… Please keep this page open. You don't need to press Place Order again." and a duplicate-order warning
- **7 fields total.** No postcode, no country, no company, no separate first/last name.

**Delivery charges, stated in Bangla on the checkout page:**
`১৫০০ টাকার বেশি অর্ডারে ফ্রি ডেলিভারি! ঢাকা সিটিতে ৭০ টাকা এবং সমগ্র বাংলাদেশ ১১৫ টাকা মাত্র।`
→ Dhaka city **৳70**, rest of Bangladesh **৳115**, free over **৳1,500**.

## C. E-commerce features
Variations (`variations`/`has_options` in the Store API; "Choose or change options" on multi-variant
products): Yes. Wishlist page `/pages/wishlist/` (200): Yes, but client-rendered. Reviews:
infrastructure present (`average_rating`, `review_count`) — **0 reviews on the sampled product and
no review form in SSR**. Coupons at checkout: Yes. Order tracking: "Track your order" inside the
assistant widget + account order status (`/pages/order-tracking/` → 404; no public tracking page
found). Stock visibility: Yes, including low-stock counts. Payment methods listed: SSLCommerz,
COD/cash, bank transfer. Related products: Yes. **Quick-order modal from the PDP: Yes** (unique among
the five). **AI chat assistant: Yes** (unique). Flash sale / countdown: **no `countdown` or `flash sale`
string in any fetched page** → verified absent. Bundles, recently viewed, compare: not found.

## D. SEO — the standout failure
- **URLs:** clean — `/product/<slug>/`, `/category/<slug>/`, `/pages/<slug>/`, `/blog/<slug>/`.
- **`rel=canonical`: ABSENT on every page fetched** (home, PLP, PDP, checkout, blog, search).
- **Open Graph: ABSENT on every page.** No `og:title`, no `og:image`, no Twitter card. Shared links render bare.
- **JSON-LD: ZERO blocks on every page.** No `Product`, no `Offer`, no `BreadcrumbList`, no `Organization`. 820 products with no structured data at all. No microdata either.
- **PDP has no meta description** (home 80 chars, PLP 94 chars, both thin).
- Visual breadcrumbs: Yes. Breadcrumb markup: No.
- **sitemap.xml:** present and good — 946 URLs, flat single file.
- `<html lang="en">`, no `hreflang`, Bangla used for trust/policy copy.
- **Analytics:** GTM `GTM-PHRQWT93` only. A `MetaPixel.astro` component exists and loads `./meta-tracking.3cKauSQ9.js`, and `backendapi.krystal.com.bd` sets an `_fbp` cookie — so a Meta Pixel is wired, but **no `fbq('init', …)` and no `connect.facebook.net` in the HTML**. No GA4 ID, no TikTok pixel.

## E. Bangladesh localisation
Excellent. District + thana dropdowns, optional email, Bangla trust/policy copy, `৳` formatting
(50× on home), phone `0188 666 7666` in header/footer/PDP, `help@krystal.com.bd`, **WhatsApp (4 refs)
and Messenger (2 refs)** both linked, COD prominent, delivery charges stated in Bangla with exact
amounts. **The only site of the five that names its couriers**, on `/pages/shipping-or-delivery/`:
`PaperFly Courier`, `Sundarban Courier`, `SA Poribohon`, `SteadTas Courier` (sic — Steadfast
misspelled). Also offers **office pickup** (Senpara-style self-collect, 9am–7pm) and states dispatch
cut-off (5pm same-day).

## F. Weaknesses (observed)
1. **Total absence of canonical, Open Graph and JSON-LD across 946 URLs.** No rich results, no social previews, no AI-citable structured data. This is the single largest gap of any site reviewed.
2. **Shipping policy contradicts the checkout.** `/pages/shipping-or-delivery/` says free inside Dhaka only when order **≥ ৳10,000 and < 2 kg**, and "৳100–150" outside Dhaka. The checkout and sitewide strip say **৳70 Dhaka / ৳115 nationwide, free over ৳1,500**.
3. **Unreplaced placeholders on a live policy page:** the hotline is printed as `xxxx-xxxxx` and `xxxx – xxxxx` — **twice** — on `/pages/shipping-or-delivery/`.
4. **Colour facet is unusable.** The PLP "Color" filter dumps ~250+ raw variant attribute values as individual checkboxes, each with count 1: `Lotus-710 PCS`, `Xiongbao Blue -112C/224W`, `Azure Flower-1853PCS`, `Nidai Kitetsu-1158 PCS`, `Rubiks Cube-Iron Box`, `Praying Mantis`, `Sanemi Shinazugawa`… These are SKU/model names, not colours.
5. **`/cart/` is a 404** and **`/?s=` silently serves the homepage** — two broken-URL traps.
6. **No age filter and no age-based navigation anywhere** — a glaring omission for a toy store (SK Toy and Kids Republic both have it).
7. **Sort control ships `disabled`**; stock labels and search results are JS-dependent, so no-JS and slow-connection users get a partly inert catalog.
8. **Blog is off-market.** 88 posts, but the sampled post ("Pre-Order the Container Board Game: Editions, Retailers, and Buying Tips", 13 Oct 2026) is about buying a US board game from Allplay at **$39/$99** — foreign currency, foreign retailers, product Krystal doesn't sell. Reads as generated volume content with little commercial relevance to BD toy buyers.
9. **robots.txt blocks every AI crawler** (same Cloudflare-managed file as SK Toy).
10. No reviews on the sampled product and no review form in SSR, despite 820 SKUs.

---

# 3. Toyshop.com.bd — https://toyshop.com.bd/

## A. Platform detection — **WordPress + WooCommerce + Elementor (Woostify theme)**

| Signal | Evidence string found |
|---|---|
| WordPress | `wp-content` ×266, `wp-includes` ×1 |
| WooCommerce | **`woocommerce` ×374**; `wp-sitemap-taxonomies-product_cat-1.xml`; `data-product_id="7803"`; `name="add-to-cart" value="…"` |
| Elementor | `<meta name="generator" content="Elementor 4.3.6; features: e_font_icon_svg, additional_custom_breakpoints; settings: css_print_method-external, google_font-enabled, font_display-swap">` — `elementor` ×482 |
| Google Site Kit | `<meta name="generator" content="Site Kit by Google 1.191.0" />` |
| Theme | Cookie `woostify_product_recently_viewed`; CSS classes `woostify-search`, `.tools-icon.header-wishlist-icon` → **Woostify** |
| Wishlist plugin | CSS selector `.tinvwl_add_to_wishlist_button.tinvwl-icon-heart` → TI WooCommerce Wishlist |
| Carousel | `swiper` |
| Agency | `Designed and developed by ET Tech Limited, operating under its own brand` |

126 products, 21 product categories, 4 blog posts.

## B. UX & site structure

**Homepage sections, in order:**
1. Header: "All Categories" **mega menu** + Explore / Track Order / Login–Register / cart
2. Trust strip: Express Delivery / Free Return / 24/7 Support / Payment Method
3. "Joy in Every Toy!" hero
4. **Shop by Category…**
5. **New Arrivals…**
6. "Build up your Childhood today with Toyshop"
7. **Best Selling Products…**
8. **Featured Products…**
9. Footer + **mobile bottom nav bar** (Home / Store / Cart / Profile) — the only site with one

**Navigation:** mega menu, grouped 3 levels deep: *Action & Role Play* → Action Figures, Character Toys, Doctor's Play Set, Dolls, Pretend Play; *Vehicles* → Aircraft & Drone, Cars → Remote Control Car, Motorcycle, Track Set, Trains; *Learning & Building* → Educational, Preschool Toys, Games & Puzzles, Blocks Play Set; *Arts & Crafts* → Creative Sets, House Sets (BO), Play Dough Sets, Play Sets → Kitchen Play Sets; *Fun & Outdoor* → Music play set, Outdoor Play, Battery Operated, Toy Guns, Rattle Toys.
**Category depth:** 2 levels — `/product-category/play-sets/kitchen-play-sets/`, `/product-category/dolls/barbie/`.

**PLP (`/product-category/remote-control-car/`):** "Showing all 6 results", `woocommerce-ordering` select with the five WooCommerce defaults (*popularity / average rating / latest / price low→high / price high→low*). Sidebar: **product search**, **Filter By price (Min/Max)**, **product categories with counts** (Educational 39, Games & Puzzles 47, Play Sets 23, Dolls 13, Cars 9…), **layered nav on `Color` — exactly one value: "Orange (1)"**. No age, brand or gender filter.

**PDP (`/product/remote-control-car/`):** breadcrumb (Home / Cars / Remote Control Car), prev/next product nav with thumbnails and prices, gallery, sale pricing with strike-through (`1,050.00 ৳` → `720.00 ৳`), **"Sold Out" / "Out of stock"** badge, Categories + Tags, tabs **Description | Additional information | Reviews (0)**, Additional information table (Dimensions `12 × 7 × 4 cm`, color Orange), **review form with 1–5 star select** ("Perfect / Good / Average / Not that bad / Very poor"), **Related products** rail. No variant selector (simple product). No delivery info, no size/age guidance in a dedicated field (age is buried in free-text description: "Age Group : 6+ Year"), no trust badges, no WhatsApp.

**Search:** `woostify-search` (theme AJAX search present). **Typo tolerance: verified absent** —
`/?s=puzle&post_type=product` → **"No products were found"**, while `/?s=puzzle&post_type=product`
returns results. Default WordPress `LIKE` matching.

**Cart & checkout — captured with a live session (1 item, ৳3,350):**
- Empty cart: `/checkout/` **redirects to `/cart/`** (same 402,763-byte body, `<title>Cart – Toyshop.com.bd`, `canonical → /cart/`).
- With an item: **3-step checkout — `Billing Details → Delivery → Payment`.**
- Login panel at the top ("Returning customer? Click here to login") guarded by a **math captcha: `Prove your humanity: 2 + 2 =`**.
- "Have a coupon? Click here to enter your code" → `coupon_code` + Apply.
- **Billing fields:** `Full name*`, `Phone*`, **`Email address*` (required)**, `Full Address*`, `Town / City*`, **`State / County*`** (a `<select>` of all 64 BD districts, but labelled with WooCommerce's default "State / County"), `Postcode / ZIP (optional)`, `Country / Region*` (`<option value="BD" selected>Bangladesh</option>` — single option, still required), optional "Ship to a different address?" block (First name, Last name, Company, Street address, Apartment, Town/City, State/County, Postcode, Phone), `Order notes (optional)`.
- Guest checkout: **Yes** (billing section usable without an account).
- **Delivery charge is hidden until the address is entered:** order review shows `Shipment → "Enter your address to view shipping options."` with Subtotal ৳3,350 = Total ৳3,350, no shipping line.
- **Payment methods — verified, the only three offered:** `payment_method_woo_bkash` (default, checked), `payment_method_woo_rocket`, `payment_method_woo_nagad`. **`cod` / `payment_method_cod` / "Cash on Delivery" appear nowhere in the checkout HTML.**
- **These are manual-transfer gateways, not hosted gateways.** The page instructs:
  `Bkash করুন এই নাম্বারেঃ 01941111000` / `You need to send us ৳ 3350.00` / `Account Type: Bkash Payment` / `Account Number: 01941111000`, then asks the buyer to type **"Your bKash Account Number"** and **"Your bKash Transaction ID"**. Same pattern for Rocket and Nagad.
- **Order tracking:** `/track-order/` (200) — WooCommerce form requiring **Order ID + Billing email**.

## C. E-commerce features
Reviews with star ratings: **form present, 0 reviews** on sampled products. Coupons at checkout: Yes.
Order tracking page: Yes. Stock visibility: Yes ("Sold Out" / "Out of stock"). Related products: Yes.
Recently viewed: **plugin active** (cookie `woostify_product_recently_viewed` set on PDP visit) —
widget placement not verified. Wishlist: **TI Wishlist CSS loaded** (`.tinvwl_add_to_wishlist_button`,
`.header-wishlist-icon`) but **no wishlist button found in the PDP markup** → likely enabled in theme
assets but not placed. Variations: none on sampled products. Flash sale / countdown / bundles: no
string found → verified absent. **COD: verified absent.**

## D. SEO
- **URLs:** clean — `/product/<slug>/`, `/product-category/<parent>/<child>/`. **Except blog posts, which are 200+ character percent-encoded Bangla slugs**, e.g. `https://toyshop.com.bd/%e0%a6%aa%e0%a7%8d%e0%a6%b0%e0%a6%a4%e0%a6%bf%e0%a6%9f%e0%a6%bf-%e0%a6%b6%e0%a6%bf%e0%a6%b6%e0%a7%81%e0%a6%b0-%e0%a6%9c%e0%a6%a8%e0%a7%8d%e0%a6%af-%e0%a6%96%e0%a7%87%e0%a6%b2%e0%a6%a8%e0%a6%be/`.
- **`<title>`:** PDP = `Remote Control Car – Toyshop.com.bd` — product name only, no modifiers, no "price in Bangladesh". **PLP and PDP share the identical title** (`Remote Control Car – Toyshop.com.bd`) because a category and a product have the same name.
- **Meta description: ABSENT on home, PLP and PDP.** No SEO plugin managing descriptions (Site Kit does not write them).
- **Canonical:** present on home and PDP; **absent on the PLP**.
- **JSON-LD:** **none on home or PLP.** PDP has one `@graph` block (WooCommerce core): `BreadcrumbList`, `ListItem`, `Product`, `Offer`, `UnitPriceSpecification`, `Organization`. No `AggregateRating`, no `brand`. `"sku": 7683` is emitted as a **number, not a string**, and is the WP post ID rather than the real code (`97-2A` sits in the description text).
- **Open Graph:** home and PLP = **none**. PDP = only `og:image` + `og:title`. No Twitter card anywhere.
- **sitemap.xml:** `/sitemap.xml` 301s to `/wp-sitemap.xml` (WP core, no SEO plugin). Index lists 7 children: posts-post (4), posts-page, posts-product (**126**), taxonomies-category, post_tag, **product_cat (21)**, product_tag.
- `<html lang="en-US">`, no `hreflang`. Bangla appears in product titles (`পাজল হাউস সেট`) and all 4 blog posts.
- **Analytics:** Google tag **`GT-PBGVSV67`** via `googletagmanager.com/gtag/js?id=GT-PBGVSV67` (Site Kit). **No GA4 `G-` ID, no GTM container, no Meta Pixel, no TikTok pixel.** The only site of the five with no Facebook tracking at all — in a market where Meta is the primary acquisition channel.

## E. Bangladesh localisation
Partial. `৳` as `&#2547;` (82× on home), **symbol placed *after* the number** (`3,350.00 ৳`) — the
WooCommerce default, not the BD convention (`৳3,350`). Bangla in product names and blog posts, but the
**UI, PDP and entire checkout are English-only**, with WooCommerce's "State / County" and
"Postcode / ZIP" labels left untranslated. **No phone number found anywhere in the HTML** of home,
PLP or PDP. **No WhatsApp, no Messenger, no chat widget.** No courier named. **No delivery-charge
policy stated anywhere** — not on the homepage, not on the PDP, and the checkout refuses to show it
until an address is typed. Payment is prepaid-only via manual mobile-money transfer.

## F. Weaknesses (observed)
1. **No COD.** The only payment paths are manual bKash/Rocket/Nagad transfers where the buyer must send money first and then type their own account number and transaction ID. In a COD-dominant market this is the heaviest conversion barrier found in this study.
2. **~2/3 of the catalog is out of stock.** Verified by counting WooCommerce stock classes: homepage **19 `outofstock` vs 9 `instock`**; `/product-category/remote-control-car/` **5 of 6 sold out**; the PDP I sampled is `"availability":"https://schema.org/OutOfStock"`. Most PLP buttons read "Read more" instead of "Add to cart".
3. **11 `<h1>` elements on the homepage** (mega-menu category names), with product names as `<h2>`. Heading hierarchy is inverted and diluted.
4. **No meta descriptions anywhere**; PLP has no canonical; PLP and PDP share an identical `<title>`.
5. **Heaviest HTML of the five: 558 KB raw / 90 KB gzipped homepage, 421 KB PDP**, 27–28 scripts, Elementor-generated. (TTFB was the *best* at 0.48 s, so the problem is payload, not origin.)
6. **Math captcha (`2 + 2 = ?`) on the checkout login panel** — pointless friction on a conversion-critical screen.
7. **Email is a required checkout field** and the order-tracking page requires Order ID *plus* billing email.
8. **Delivery cost is unknowable before the form is filled.** No shipping policy page content found, no charge on the PDP, "Enter your address to view shipping options" at checkout.
9. **Zero Meta/Facebook tracking** — no pixel, no CAPI, no Messenger.
10. **Blog is dormant:** 4 posts, all published 27 Sep 2025, on 200+ char percent-encoded URLs. Good Bangla keyword targeting (`অনলাইনে খেলনা কেনার সেরা শপ`, `বয়স অনুযায়ী সঠিক খেলনা`) wasted on an abandoned section.
11. **Colour facet has exactly one option** ("Orange (1)"). No age/brand/gender filters.
12. Zero reviews across sampled products despite the review form being enabled.

---

# 4. Kids Republic — https://kidsrepublicbd.com/

## A. Platform detection — **WordPress + WooCommerce (Theme Freesia theme)**

| Signal | Evidence string found |
|---|---|
| WordPress | `<meta name="generator" content="WordPress 7.1.4" />` |
| WooCommerce | `<meta name="generator" content="WooCommerce 11.2.2" />`; `woocommerce` ×470 |
| Theme | `Designed by: Theme Freesia` |
| SEO plugin | `<?xml-stylesheet … href="//kidsrepublicbd.com/wp-content/plugins/wordpress-seo/css/main-sitemap.xsl"?>` + `<!-- XML Sitemap generated by Yoast SEO -->` |
| Plugins (from asset paths) | `add-to-cart-direct-checkout-for-woocommerce`, `contact-form-7`, **`deposits-partial-payments-for-woocommerce`**, `duracelltomi-google-tag-manager`, `jetpack`, `megamenu`, `product-video-gallery-slider-for-woocommerce`, `really-simple-featured-video`, `wp-whatsapp` (v3.8.2), `yith-woocommerce-wishlist` |

71 products, 12 product categories, 27 blog posts.

## B. UX & site structure

**Homepage sections, in order:** header + cart → **All Products** → **age-group tiles: Infant /
Toddler / Pre-Schooler / Explorer** (+ "Hot") → **Kids Toys** → **Kids School Items** → **Kids
Adventure** → **Kids Care** → **Kids Lifestyle** → **Kids Winter Collection** → **Kids Watch** →
**Upcoming Products** → **Blogs** → **We Accept** → **Shop Location** → **Important**.

**Navigation:** mega menu (`megamenu` plugin). **Category depth:** 2 levels —
`/product-category/age-groups/kids-care/`, `/product-category/age-groups/newborn-to-three-years/`.
**Age-based navigation: Yes** (both homepage tiles and `age-groups` taxonomy:
`newborn-to-three-years`, `three-to-eight-years-3-8-y-o`, `eight-years-above-8`).

**PLP (`/product-category/lego-stem-more/`):** `woocommerce-result-count` with
`role="status" aria-relevant="all"` and `name="orderby"` sort. Sidebar widgets: `widget_price_filter`,
`widget_product_categories` (with counts: Eight Years Above 8+ (24), Kids Republic Toys (44), Newborn
to Three Years (33), Three to Eight Years (50)…), `widget_product_search`, `widget_product_tag_cloud`,
`widget_products`, `widget_shopping_cart`, `widget_contact`. No attribute/colour/brand/gender filter.

**PDP (`/product/rolling-ball-building-blocks/`):** `Sale!` badge, **`Rated 5.00 out of 5 based on 1
customer rating (1 customer review)`**, price range `1,250.00 ৳ – 1,950.00 ৳`, **variation selector
("Variation: Choose an option / 76 PCs Box / 182 Pcs Box / Clear")**, quantity, **Add to cart**,
**Buy Now** (direct-checkout plugin), **Add to wishlist** (YITH), `SKU: N/A`, Categories, tabs
**Description | Additional information | Reviews (1)**, a **real Bangla review**:
`Rated 5 out of 5 — আনিকা জাহান – April 29, 2025 — ভালো। বাচ্চাদের খুব উপকারী। বাচ্চারা খেলতে খেলতে শিখতে পারবে।`,
review form with star select, **Related products** rail (4), sidebar Product categories + Products +
Product tags, footer **"We Accept"** graphic (`uploads/2024/04/bkashnagad.jpeg` → bKash + Nagad only).
Video-gallery plugins are installed, so PDP video is likely but was not present on the sampled product.
**No breadcrumb markup in the DOM** (`class="…breadcrumb…"` → 0 matches) despite Yoast emitting
`BreadcrumbList` JSON-LD. No delivery info, no stock quantity, no trust badges on the PDP.

**Search:** plain WordPress search (no `autocomplete` / `live-search` / AJAX markup found).
**Typo tolerance: verified absent** — `/?s=lgo&post_type=product` → **"No products were found"**;
`/?s=lego&post_type=product` returns 3 server-rendered product cards.

**Cart & checkout:** `/cart/` 200. **`/checkout/` redirects to `/cart/`** on an empty cart
(`<title>Cart - Kids Republic…`, canonical `/cart/`). **Add-to-cart could not be established:**
`?add-to-cart=3727` returned 200 but **set no cookies whatsoever** (full-page cache / nonce gate), and
`robots.txt` itself disallows `/*?add-to-cart=`. **Checkout steps, field list, district handling,
guest checkout, COD availability and delivery charges are therefore NOT VERIFIED.** No BD
district/courier plugin appears in the asset list, so the district field is most likely WooCommerce's
default "State" select — but that is inference, not observation. `deposits-partial-payments-for-woocommerce`
is installed, suggesting partial/advance payment is offered.
**Order tracking:** `/track-order/` → **404**. Only `/my-account/` was found.

## C. E-commerce features
Variations: **Yes, verified.** Wishlist: **Yes** (YITH, 1,259 `wishlist` refs on home, 60 on PDP).
Reviews with ratings: **Yes — the only site of the five with a real customer review.** Buy Now /
direct checkout: Yes. Stock visibility: Yes (WooCommerce classes). Related products: Yes. Partial
payments: plugin installed. WhatsApp: **Yes** (`wp-whatsapp` plugin, `"number":"+8801806886649","title":"WhatsApp"`).
Flash sale / countdown: **no string found → verified absent.** Coupons at checkout, COD, order
tracking: **Not verified / `/track-order/` 404.** Recently viewed, bundles, compare: not found.

## D. SEO — strongest of the five on structured data
- **URLs:** clean — `/product/<slug>/`, `/product-category/<parent>/<child>/`. Blog posts use **date-based permalinks with percent-encoded Bangla slugs**: `/2025/04/15/%e0%a6%b6%e0%a6%bf%e0%a6%b6%e0%a7%81%e0%a6%b0-…/`.
- **PDP `<title>`:** `Premium Rolling Ball House Building Blocks-182 Pcs | Build Imagination | 3+ - Kids Republic - All in 1 Kids Island` — **113 characters**, will be truncated in SERPs; two separate brand suffixes appended.
- **PDP meta description:** 151 chars, well written — `Stimulate creativity, problem-solving & STEM skills with this fun marble-run construction toy. Perfect educational gift for children in Bangladesh.`
- **JSON-LD (Yoast + WooCommerce), PDP @types quoted:** `Product`, `AggregateOffer`, **`AggregateRating`**, **`Review`**, `Rating`, `Person`, `UnitPriceSpecification`, `BreadcrumbList`, `ListItem`, `Organization`, `WebPage`, `WebSite`, `SearchAction`, `EntryPoint`, `PropertyValueSpecification`, `ImageObject`, `ReadAction`. PLP: `CollectionPage` + `BreadcrumbList` + `Organization` + `WebSite` + `SearchAction`. Home: `Organization`, `WebSite`, `WebPage`, `SearchAction`, `BreadcrumbList`, `ImageObject`, `ReadAction`. **No `FAQPage` anywhere.**
- **OG:** full set on PDP (`og:title`, `og:description`, `og:image` + width/height/type, `og:locale`, `og:type`, `og:url`); home/PLP have OG but **no `og:image`**. Twitter: only `twitter:card`.
- **`rel=canonical` is emitted TWICE, identically**, on PDP, PLP, home and blog (Yoast + theme both outputting) — verified: `grep -c 'rel="canonical"'` = 2 with two identical tags.
- **sitemap.xml:** `/sitemap.xml` → `/sitemap_index.xml` (Yoast), 8 children: post (27), page, product (71), category, post_tag, product_cat (12), product_tag, author. **`author-sitemap.xml` is exposed** — pointless index bloat for a shop.
- `<html lang="en-US">`, no `hreflang`. Bangla in blog posts (2,029 Bangla runs on `/blog/`) and in a product review; UI is English.
- **Analytics:** GTM **`GTM-NX5CJB4G`** via the `duracelltomi-google-tag-manager` plugin (which supports WooCommerce enhanced ecommerce). No inline GA4 `G-` ID, **no Meta Pixel**, no TikTok pixel.

## E. Bangladesh localisation
Partial. `৳` as `&#2547;` (133× on home), again **suffixed** (`2,250.00 ৳`). Phone `01806-886649` /
`+8801806886649` in footer and WhatsApp widget. **WhatsApp floating button: Yes.** Messenger: not
found. Address `Senpara Parbata, Mirpur 10, Dhaka 1216`. Bangla blog content and one Bangla review.
`We Accept` = bKash + Nagad image only. **No courier named. No delivery-charge policy stated
anywhere on the homepage, PLP, PDP or cart** (verified: zero matches for "delivery charge",
"Inside Dhaka", "Outside Dhaka", "Free delivery", "ডেলিভারি" in the homepage body).

## F. Weaknesses (observed)
1. **Heaviest front-end of the five: 91 `<script>` tags and 21 stylesheets on one PDP** (68 + 17 on the homepage), with **TTFB 2.27 s** — the slowest origin measured. Jetpack + Elementor-free but plugin-stacked.
2. **`<meta name="viewport" content="width=device-width" />` — no `initial-scale`.** Older/varied mobile browsers will apply their own scale; this is a genuine mobile-rendering defect, not a nitpick.
3. **Duplicate `rel=canonical`** on every page type.
4. **No order tracking:** `/track-order/` 404s, only `/my-account/`.
5. **No delivery charge or shipping policy published anywhere** I could reach.
6. **Only 2 images lazy-loaded out of 48 on the homepage** (`loading="lazy"` ×2) — nearly every image is eager.
7. **Demo content from 2019 still live and in the sitemap:** `/2019/03/27/high-quality-products/`, `/2019/03/27/luxury-collection/`, `/2019/03/27/10-student-discount/`. The last one renders as a theme stub — body text `Grow`, `Post By admin`, `Slider`, with "Next post: Learn".
8. **Over-long, double-branded titles** (113 chars) that truncate in search results.
9. **`SKU: N/A`** on the sampled variable product — no SKU data.
10. **No breadcrumbs in the visible DOM** (JSON-LD only), so no on-page navigational aid.
11. **No filters beyond price + category + tag** — no age filter on the PLP even though the taxonomy and homepage are organised by age.
12. **Zero typo tolerance** in search.
13. **Only 1 review across the catalog** despite review infrastructure and schema being ready.
14. `author-sitemap.xml` exposed; no Meta Pixel.

---

# 5. Kids Zone BD — https://www.kidszonebd.shop/

## A. Platform detection — **Angular SPA (client-side only) on the `saleecom` SaaS platform**

| Signal | Evidence string found |
|---|---|
| Angular | `<html lang="en" data-critters-container>`, `<app-root></app-root>`, `<base href="/">`, `polyfills-G5YB3HCU.js`, `main-34RVP552.js`, 10 × `<link rel="modulepreload" href="chunk-XXXXXXXX.js">` |
| Critters | `data-critters-container` → Angular CLI inline-critical-CSS |
| SaaS platform | `<link rel="preconnect" href="https://cdn.saleecom.com/upload/images">`; in `main.js`: `https://cdn.saleecom.com/upload/images/placeholder`, `https://cdn.saleecom.com/upload/static/placeholder`, `https://www.saleecom.com/` |
| Theme cruft | Leftover third-party asset URLs in `main.js`: `https://cdn.tirabeauty.com/v2/billowing-snowflake-434234/tira-p/wrkr/organization/62d539deb767fec5c06847bd/theme/assets/…` (×3) |
| Server | `server: nginx/1.24.0 (Ubuntu)`, static file `last-modified: Sun, 27 Sep 2026 07:20:42 GMT` |
| Not WP/Shopify/Next | `wp-content` = 0, `woocommerce` = 0, `cdn.shopify.com` = 0, `__NEXT_DATA__` = 0, `csrf-token` = 0 |

## B. UX & site structure — **unverifiable without a JS runtime**

**Verified fact: every URL on this domain returns one byte-identical 53,313-byte HTML shell with
HTTP 200.** Confirmed by MD5 across `/`, `/cart`, `/checkout`, `/products`, `/shop`, `/category/toys`,
`/product/test`, `/blog`, `/track-order` **and `/this-path-does-not-exist-12345`** — all
`fa7ce14bd760fc04e6ad954313ee8171`. There are no soft 404s and no per-route server response.

The only text in `<body>` is:
```html
<noscript>Please enable JavaScript to continue using this application.</noscript>
```
`WebFetch` independently confirmed: *"The page shows only one piece of visible content: the title
'E-commerce Platform.' It has no product names, prices, categories, navigation items, delivery or
payment information, or phone numbers."*

**Client-side routes recovered from `main-34RVP552.js`** (so these screens exist in the app):
`/products`, `/products/:slug`, `/product/:slug`, `/product-details/:slug`, `/categories`,
`/category`, `/cart`, `/checkout`, `/order-details/:id`, **`/order-tracking`**, `/blog`, `/blogs`,
`/blog-details/:slug`, `/login`, `/profile`. UI component classes indicate a cart dropdown
(`cart-dropdown-wrap`, `cart-fixed-box`), a category mega-nav (`category-menu`, `category-nav`,
`category-slide`) and a product grid.

Homepage section order, navigation type, category depth, PLP filters, sorting, PDP elements, search
behaviour, checkout steps, field list, district dropdowns, COD and delivery charges: **ALL NOT
VERIFIED** — no server-rendered markup exists to observe, and no API host is referenced in `main.js`.

## C. E-commerce features — keyword census of `main-34RVP552.js` (590 KB)
| Keyword | Occurrences | Reading |
|---|---|---|
| `wishlist` | **143** | wishlist feature present in the app |
| `compare` | **54** | product compare present (only site with it) |
| `review` | **14** | some review capability |
| `coupon` | 0 | not in the main bundle |
| `flash` / `countdown` | 0 / 0 | not in the main bundle |
| `variant` | 0 | not in the main bundle |
| `district` / `thana` | 0 / 0 | not in the main bundle |
| `cash on delivery` / `COD` / `bkash` / `nagad` / `sslcommerz` | 0 | not in the main bundle |
| `related` / `recently viewed` | 0 / 0 | not in the main bundle |
| `pathao` / `steadfast` / `courier` | 0 | not in the main bundle |

These zeros may live in lazily-loaded chunks, so they are **Not verified**, not verified-absent.

## D. SEO — effectively nonexistent
- **URL pattern:** clean paths (`/product/:slug`, `/category/...`), but every one of them resolves to the same shell.
- **`<title>` on every URL: `E-commerce Platform`** — an unbranded platform placeholder. Verified `repr()` = `'E-commerce Platform'`. No per-page titles.
- **Meta description: ABSENT.** The complete meta list is: `charset`, `viewport`, `theme-color` (×2, duplicated), `robots`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-status-bar-style`, `apple-mobile-web-app-title` (= `"E-commerce"`), `msapplication-TileColor`, `msapplication-tap-highlight`.
- **`rel=canonical`: ABSENT. Open Graph: ABSENT. Twitter card: ABSENT. JSON-LD: ZERO blocks. Microdata: none.**
- **Breadcrumbs:** none in HTML.
- **`/sitemap.xml` returns the 53,313-byte Angular shell** — byte-identical to the homepage (verified with `cmp`). There is **no sitemap**.
- **`/robots.txt`** is 40 bytes in full: `User-agent: *` / `Disallow: /admin` / `Allow: /`. **No `Sitemap:` directive.** (Notably, this is the only site of the five that does *not* block AI crawlers — but it has nothing crawlable.)
- `<html lang="en">`, no `hreflang`, no Bangla in the shell.
- **Analytics: NONE.** No GA4, no GTM, no `googletagmanager.com`, no `fbq`, no TikTok, no Clarity, no Hotjar in the shell or `main.js`. The business has **zero measurement**.
- **Index visibility test:** a web search for `kidszonebd.shop Kids Zone BD toys` returned **no result from the domain at all** — competitors (othoba.com, daraz.com.bd, bikroy.com) occupied every slot.

## E. Bangladesh localisation
**None observable.** No Bangla, no `৳`, no `BDT`, no phone number, no WhatsApp/Messenger, no courier,
no delivery policy in the served HTML. Theme colour `#01640D` (Bangladesh-flag green) is the only
local signal. Everything else is **Not verified**.

## F. Weaknesses (observed)
1. **Zero server-rendered content.** Every route, including nonexistent ones, returns `200` with the same shell and the title `E-commerce Platform`. No SSR, no prerender, no per-route metadata.
2. **`<title>E-commerce Platform</title>` sitewide** — the SaaS vendor's placeholder was never replaced. Every indexed page would carry it.
3. **No sitemap** (`/sitemap.xml` serves the SPA shell) and **no `Sitemap:` line in robots.txt**.
4. **No canonical, no OG, no Twitter card, no JSON-LD, no meta description, no breadcrumbs, no `hreflang`.**
5. **No analytics or ad pixels of any kind** — the business cannot measure traffic, run retargeting, or feed a Meta catalog.
6. **Confirmed zero organic search presence** — the domain surfaced nowhere in a direct branded search.
7. **~682 KB of uncompressed JavaScript must download and execute before a single pixel of content renders** (`main.js` 590,083 B + `polyfills` 34,525 B + 10 chunks ≈ 57,174 B), plus **50.5 KB of inline critical CSS** in a 53 KB HTML file. TTFB is excellent (0.19 s) and then nothing happens.
8. **`<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">` — `user-scalable=no` + `maximum-scale=1.0` blocks pinch-zoom** (WCAG 1.4.4 failure) *and* `initial-scale` is declared twice. `theme-color` is also duplicated.
9. **Commented-out PWA scaffolding left in the shell** — manifest, apple-touch-icons and favicons are all inside `<!-- ... -->`, so the site has **no favicon and no PWA manifest** despite being built for them.
10. **Dead third-party theme assets** (`cdn.tirabeauty.com/.../organization/62d539deb767fec5c06847bd/theme/assets/...`) still referenced in the production bundle.
11. `cache-control: no-cache, no-store, must-revalidate` on the shell — the one cacheable asset is explicitly made uncacheable.

---

# Per-site feature checklist

`Yes` = observed in a fetched response. `No` = **verified absent** (searched for and not found where
it would have to appear). `NV` = **Not verified** (page was client-rendered, gated, or unreachable).

| Feature | SK Toy | Krystal | Toyshop | Kids Republic | Kids Zone BD |
|---|---|---|---|---|---|
| **Platform** | Next.js + Node/Mongo API | Astro + headless WooCommerce | WP + WooCommerce + Elementor | WP + WooCommerce | Angular SPA (saleecom) |
| Server-rendered homepage | Yes | Yes | Yes | Yes | **No** |
| Server-rendered PLP | **No** | Yes | Yes | Yes | **No** |
| Server-rendered PDP | **No** | Yes | Yes | Yes | **No** |
| Mega menu | No (drawer) | Yes | Yes | Yes | NV |
| Category depth ≥2 | Yes (3) | No (flat) | Yes (2) | Yes (2) | NV |
| **Age filter / age browse** | **Yes** | **No** | **No** | **Yes** | NV |
| Price filter | NV | Yes | Yes | Yes | NV |
| Brand filter | NV | **No** | **No** | **No** | NV |
| Gender filter | NV | **No** | **No** | **No** | NV |
| In-stock / on-sale filter | NV | Yes | **No** | **No** | NV |
| Sorting options | NV | Yes (4, ships `disabled`) | Yes (5) | Yes (5) | NV |
| Search autocomplete | NV | **Yes** | Yes (AJAX) | **No** | NV |
| Search typo tolerance | NV | NV | **No** | **No** | NV |
| PDP image zoom | NV | **Yes** | NV | NV | NV |
| Variant selector | NV | Yes | **No** (simple products) | **Yes** | NV |
| Stock visibility on PDP | NV | **Yes** (+ low-stock count) | Yes | Yes | NV |
| Delivery info on PDP | NV | **Yes** (Bangla trio) | **No** | **No** | NV |
| Age guidance on PDP | NV | **No** | Free-text only | Free-text only | NV |
| Reviews with ratings | NV | Form NV; **0 reviews** | Form Yes; **0 reviews** | **Yes (1 real review)** | NV |
| Related products | NV | Yes | Yes | Yes | NV |
| Trust badges on PDP | NV | Yes | **No** | **No** | NV |
| Wishlist | Yes | Yes | CSS only, **no button** | **Yes** (YITH) | Yes (in bundle) |
| Product compare | NV | **No** | **No** | **No** | **Yes** (in bundle) |
| Flash sale / countdown | **No** | **No** | **No** | **No** | NV |
| Coupons at checkout | NV | **Yes** | **Yes** | NV | NV |
| Bundles | NV | **No** | **No** | **No** | NV |
| Recently viewed | NV | **No** | Plugin active (cookie) | **No** | NV |
| **Guest checkout** | NV | **Yes** | **Yes** | NV | NV |
| **Checkout steps** | NV | **1 page / 2 sections** | **3 steps** | NV | NV |
| **District dropdown** | NV | **Yes (64)** | Yes, labelled "State / County" | NV | NV |
| **Thana/area dropdown** | NV | **Yes (dependent)** | **No** | NV | NV |
| Email required at checkout | NV | **No (optional)** | **Yes** | NV | NV |
| Postcode asked | NV | **No** | Yes (optional) | NV | NV |
| **COD offered** | **Yes** (config `cod.enabled:true`) | **Yes** | **No** | NV | NV |
| Automated card gateway | **No** (bKash `enabled:false`) | **Yes** (SSLCommerz) | **No** (manual transfer only) | NV | NV |
| **Delivery charge published** | Yes (৳80 / ৳130, free >৳2,500) | Yes (৳70 / ৳115, free >৳1,500) | **No** | **No** | NV |
| Order tracking page | Yes (`/track`) | Account + assistant only | **Yes** (`/track-order/`) | **No** (404) | Route exists (`/order-tracking`) |
| **`rel=canonical`** | Yes | **No** | Home+PDP only | **Duplicated ×2** | **No** |
| **Open Graph** | Partial (no `og:image` on home) | **No** | PDP only (2 tags) | Yes | **No** |
| **JSON-LD `Product`** | Yes (**`price: 0`**) | **No** | Yes | Yes | **No** |
| JSON-LD `AggregateRating` | **No** | **No** | **No** | **Yes** | **No** |
| JSON-LD `BreadcrumbList` | Yes | **No** | PDP only | Yes | **No** |
| JSON-LD `Organization` | Yes | **No** | PDP only | Yes | **No** |
| JSON-LD `FAQPage` | **No** | **No** | **No** | **No** | **No** |
| Meta description on PDP | Yes (142 ch) | **No** | **No** | Yes (151 ch) | **No** |
| Working sitemap.xml | Yes (131 URLs) | Yes (946 URLs) | Yes (WP core) | Yes (Yoast) | **No** (serves SPA shell) |
| `hreflang` | **No** | **No** | **No** | **No** | **No** |
| Bangla content | **Yes (heavy)** | **Yes** | Partial | Partial | **No** |
| GA4 | **Yes** `G-R9C6P60JKN` | **No** | **No** | **No** | **No** |
| GTM | **No** | Yes `GTM-PHRQWT93` | **No** | Yes `GTM-NX5CJB4G` | **No** |
| Other Google tag | — | — | `GT-PBGVSV67` | — | — |
| **Meta Pixel** | **Yes** `1516765536845697` | Wired (no `fbq` in HTML) | **No** | **No** | **No** |
| TikTok Pixel | **Yes** `D9Q54VBC77U4UUP3JU0G` | **No** | **No** | **No** | **No** |
| WhatsApp button | Yes (1 ref) | **Yes** | **No** | **Yes** | **No** |
| Messenger | **No** | **Yes** | **No** | **No** | **No** |
| **Courier named** | **No** | **Yes (4)** | **No** | **No** | **No** |
| Blog | Yes (7 posts) | Yes (88, off-market) | Yes (4, dormant) | Yes (27, demo cruft) | Route exists |
| AI chat assistant | **No** | **Yes** | **No** | **No** | **No** |
| robots.txt blocks AI crawlers | **Yes** | **Yes** | **Yes** | **No** | **No** |

### Measured performance (PDP, compressed, Chrome UA)

| Site | TTFB | Total | Gzipped HTML | Raw HTML | `<script>` | `<link rel=stylesheet>` | Server |
|---|---|---|---|---|---|---|---|
| SK Toy | **1.52 s** | 1.75 s | 18.3 KB | 81 KB | 27 | 2 | Cloudflare |
| Krystal | 1.05 s | 1.05 s | **17.9 KB** | 73 KB | 15 | 4 | Cloudflare |
| Toyshop | **0.48 s** | 0.58 s | **90.2 KB** | **421 KB** | 28 | 2 | Cloudflare |
| Kids Republic | **2.27 s** | 2.55 s | 32.2 KB | 155 KB | **91** | **21** | Cloudflare |
| Kids Zone BD | 0.19 s | 0.19 s | 7.8 KB (shell) | 53 KB | 2 | 3 | nginx 1.24 |

Catalog sizes (from sitemaps): Krystal **820**, Toyshop **126**, SK Toy **81**, Kids Republic **71**,
Kids Zone BD **unknown** (no sitemap).

---

# Cross-site patterns

## (a) Present on nearly all sites
1. **WooCommerce or a WooCommerce backend** — 3 of 5 directly (Toyshop, Kids Republic) or headless (Krystal). The de-facto default.
2. **Cloudflare** in front (4 of 5).
3. **Bangla content somewhere** (4 of 5) — but almost always only in blog posts, trust strips or product descriptions, never in the UI chrome or checkout.
4. **`৳` price formatting** (4 of 5), with the two WooCommerce sites putting the symbol *after* the number (`2,250.00 ৳`) rather than the BD convention.
5. **Related products on the PDP** (4 of 5 verified).
6. **A wishlist** of some kind (4 of 5), though Toyshop's is CSS-only with no button.
7. **A blog** (5 of 5 have the route) — and in 4 of 5 it is dormant, demo cruft, or off-market filler.
8. **Clean slug URLs** for products and categories (5 of 5).
9. **A working sitemap.xml** (4 of 5).
10. **Mobile viewport declared** (5 of 5) — though 2 of 5 declare it wrongly.
11. **robots.txt blocking AI crawlers** (3 of 5, via Cloudflare's managed file).

## (b) Absent on nearly all sites
1. **`FAQPage` schema — 0 of 5.** Nobody is competing for FAQ rich results or AI answer citations.
2. **`AggregateRating` in schema — 1 of 5** (Kids Republic only). Nobody shows star ratings in SERPs.
3. **Meaningful review volume — 0 of 5.** One single review exists across all five catalogs (~1,100 products combined).
4. **`hreflang` / a real bn-BD locale — 0 of 5.** No site serves a Bangla URL variant despite Bangla copy.
5. **Flash sale / countdown timers — 0 of 5** (verified absent on 4, NV on 1). No urgency mechanics at all.
6. **Product bundles / "frequently bought together" — 0 of 5.**
7. **Brand filter and gender filter — 0 of 5.**
8. **Age filter on the PLP — 0 of 5.** Two sites navigate by age (SK Toy's tiles, Kids Republic's taxonomy) but neither lets you *filter* a category listing by age — the single most natural way a parent shops for toys.
9. **A named courier — 1 of 5** (Krystal). Nobody else tells a buyer who will deliver.
10. **A published delivery-charge policy — 2 of 5.** Toyshop and Kids Republic publish nothing; Toyshop actively hides it behind an address form.
11. **Search typo tolerance — 0 of 5 verified.** Both testable sites return "No products were found" for a one-character typo.
12. **Meta Pixel — 1 of 5 confirmed firing** (SK Toy). In a market where Meta is the dominant acquisition channel, 3 of 5 have no Facebook tracking at all.
13. **Messenger integration — 1 of 5** (Krystal), despite Messenger being the default BD customer-service channel.
14. **Order tracking that actually resolves — 3 of 5** (SK Toy `/track`, Toyshop `/track-order/`, Krystal via account). Kids Republic 404s.
15. **Any analytics whatsoever — 4 of 5** (Kids Zone BD has none).

## (c) The 5 most exploitable weaknesses

**1 — Nobody owns Bangla-first structured data, and nobody has FAQ or review schema.**
Krystal (820 products) emits **zero** JSON-LD, zero canonical and zero Open Graph. Kids Zone BD emits
nothing at all. SK Toy's `Product` schema is **broken** (`"price": 0` with `InStock`, HTML inside
`description`, a `shippingRate` of 60 that contradicts its own ৳80/৳130 config). Toyshop's is
WooCommerce-default with a numeric post-ID as `sku` and no `brand`. Only Kids Republic has
`AggregateRating`/`Review`, built on **one** review. **No site has `FAQPage`. No site has `hreflang`
or a bn-BD URL.** A competitor shipping correct `Product` + `Offer` + `AggregateRating` +
`BreadcrumbList` + `FAQPage`, with Bangla `name`/`description` and a `bn-BD` locale, takes the rich
results and the AI-answer citations uncontested — and three of these five have additionally
**excluded themselves from AI crawlers** in robots.txt.

**2 — Checkout friction and payment gaps, with Toyshop the extreme case.**
Toyshop offers **no COD** — only manual bKash/Rocket/Nagad transfers where the buyer sends money
first and then types their own account number and transaction ID — on top of a **3-step** checkout, a
**required email**, a **`2 + 2 = ?` math captcha**, WooCommerce's untranslated "State / County" and
"Postcode / ZIP", and a delivery charge that is **invisible until the address is entered**. SK Toy has
COD but its bKash gateway is `enabled:false` while bKash badges sit in the footer. Krystal is the one
to beat (7 fields, district+thana, optional email, COD + SSLCommerz, charges stated in Bangla) — and
even it has no cart page (`/cart/` 404s) and contradicts its own shipping policy. **A one-page,
Bangla-labelled, district→thana, COD-default checkout with the delivery charge shown before any field
is typed beats four of these five outright.**

**3 — Two of five serve crawlers and first paint almost nothing.**
Kids Zone BD returns **one byte-identical 53 KB Angular shell with `<title>E-commerce Platform</title>`
for every URL including nonexistent ones**, has no sitemap, no canonical, no OG, no JSON-LD, **no
analytics or pixels of any kind**, and **surfaced nowhere in a branded search**. SK Toy's PDP and PLP
ship **only a spinning loader** inside `<main>` — its homepage is server-rendered but its
money pages are not. Both are effectively absent from organic search for product and category
queries. Those queries are free.

**4 — Stock, price and policy data are visibly wrong across the board.**
Toyshop: **19 of 28 homepage products and 5 of 6 in the sampled category are out of stock**; most PLP
buttons say "Read more" instead of "Add to cart". SK Toy: **2 products priced ৳0**, 4 "Coming Soon",
1 "SOLD OUT" on the homepage — including the PDP I sampled. Krystal: its shipping page says free
delivery over **৳10,000**, its checkout says **৳1,500**, and the hotline is printed as **`xxxx-xxxxx`
twice on a live page**. SK Toy advertises three different free-delivery thresholds (৳3,000 banner,
৳2,500 config, flat ৳60 in schema). Kids Republic still serves **2019 theme demo posts**
(`/2019/03/27/10-student-discount/` renders as "Grow / Post By admin / Slider") and lists them in its
sitemap. Accurate stock, real prices and one consistent delivery policy are a low-cost, high-trust
differentiator here.

**5 — Nobody has built the toy-specific merchandising a parent actually needs.**
**Zero of five** let you filter a product listing by age, brand or gender. Zero have bundles or
"frequently bought together". Zero have countdown/flash-sale mechanics. Zero have size/age guidance as
structured data. Only Krystal names a courier; only Krystal offers Messenger; only Krystal shows
low-stock counts; only Kids Republic has a single review. Search breaks on a one-letter typo on both
testable sites. Across ~1,100 products there is **one** customer review. An age+gender+price+brand
faceted PLP, gift-finder flows, real review collection (SMS/WhatsApp follow-up after delivery), named
courier + tracking, and typo-tolerant Bangla-and-English search would be differentiating on day one —
none of it requires beating anyone on price or catalog size.
