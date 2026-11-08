# Agent 2 — Competitor Research: BD Kids' Toy E-commerce

**Research date:** 2026-11-07
**Method:** Raw HTTP fetches via `curl` (preserves platform fingerprints that markdown conversion destroys), plus authenticated-session cart/checkout walkthroughs. Every claim below is tied to markup actually observed.

### Evidence conventions

| Label | Meaning |
|---|---|
| **Yes** | Observed in fetched markup / rendered text |
| **No (verified absent)** | Looked for it specifically on the relevant page and it was not there |
| **Not verified** | Did not or could not test — treat as unknown, not as absent |

### Pages fetched per site

| Site | Home | PLP | PDP | Cart | Checkout | robots | sitemap | Blog |
|---|---|---|---|---|---|---|---|---|
| toyhousebd.com | Yes | Yes (2) | Yes | Yes (+session) | Yes (+session) | Yes | Yes | n/a — none exists |
| babybazarbd.com | Yes | Yes (2) | Yes | Yes (+session) | Yes (+session) | Yes | Yes | Yes |
| edutoyzone.com | Yes | Yes (2) | Yes (2) | Yes (+session) | Yes (+session) | Yes | Yes | Yes |
| kidszone.com | Yes | n/a | n/a | n/a | n/a | Fails | Error page | n/a |
| daraz.com.bd | Yes | Yes (2, via `?ajax=true`) | Yes (+ module manifest) | Blocked by robots | Blocked by robots | Yes | Yes | n/a |
| othoba.com | Yes | Yes (5) | Yes | Login-gated | Login-gated | Yes | **0 bytes** | n/a |
| chaldal.com | Yes | Yes (empty JS shell) | n/a | n/a | n/a | Yes | n/a | n/a |

Checkout pages were inspected **with a real cart session** (item added via cookie jar) — otherwise all three stores redirect to an empty-cart page and the real fields, shipping rates and payment methods stay hidden. No orders were placed.

---

## 1. toyhousebd.com — Toy House BD

> Resolves to `https://www.toyhousebd.com/`. **This is a real but barely-stocked BD toy store — effectively a soft-launch / demo-stage site.**

### A. Platform detection

WordPress + WooCommerce, confirmed by `<meta name="generator">` tags in the homepage source:

```html
<meta name="generator" content="WordPress 7.1.4" />
<meta name="generator" content="WooCommerce 10.9.4" />
<meta name="generator" content="Elementor 4.0.0; features: e_font_icon_svg, ..." />
```

Marker counts on the homepage: `wp-content` × 332, `wp-includes` × 14, `woocommerce` × 110. Product pages are built with **ShopEngine** (Elementor WooCommerce widget builder) — `shopengine` × 169 on the PDP, with widgets such as `data-widget_type="shopengine-product-title.default"` and `shopengine-product-stock.default`. Wishlist is the **WishSuite** plugin (`wishsuite` × 74). Footer credit: "Design and developed by Kreative Studio". No Shopify, Laravel, Next.js or local-SaaS markers present.

### B. UX & site structure

**Catalogue size (the headline fact):** the product sitemap `wp-sitemap-posts-product-1.xml` contains **12 product URLs**, and `wp-sitemap-taxonomies-product_cat-1.xml` contains **7 categories**. Sidebar category counts on the PLP confirm it: Educational (4), Creative & Art (2), Other (2), Action Figures (1), Remote Control Car (1), Teddy & Dolls (1), Vehicle Toys (1). The `/shop/` page itself renders exactly **12 distinct `data-product_id` values** — so 12 is the entire catalogue, not a paginated first page.

**Homepage section order:** utility bar (Login/Register, Shopping Bag) → category nav → hero slider ("A trusted online shop" / "Shop now") → 4 trust badges (*Flat-rate Delivery, Support 24/7, Secure Payment, Cash on Delivery*) → "NEW COLLECTION" product grid (11 products) → "Shop by Catagory" [sic] → about blurb → footer. Side-drawer cart.

**Navigation:** flat, one level, 11 labels — Baby & Toddler, Board Games, Vehicle Toys, Blocks play set, Creative & Art, Educational, Action Figures, Outdoor & Sports, Remote Control Car, Teddy & Dolls, Others. **Only 7 of these 11 exist as real categories**; Baby & Toddler, Board Games, Blocks play set and Outdoor & Sports are absent from the category sitemap, so those menu items lead nowhere useful. Category depth: 1 (no sub-categories).

**PLP:** 3-column grid, `Showing all 4 results` on /product-category/education/. Sorting = stock WooCommerce 5 options (Default sorting, popularity, latest, price low→high, price high→low). Sidebar: search box, category list with counts, a **price Min/Max filter** (`widget_price_filter`, `price_slider`), product tag cloud. **No brand, age or gender filter** (verified absent — no `pa_age`, `pa_brand` or `layered_nav` attribute widgets in markup).

**PDP** (`/shop/spelling-game/`): breadcrumb-ish category + tags line, title, `In Stock` badge, price, quantity input, Add to Cart, Category, Tags, a **Description** tab, and a "Recently Viewed Products" block (rendering "No recently viewed products to display"). Gallery infrastructure is present (`woocommerce-product-gallery`, `photoswipe` × 14, `easyzoom` × 4, `lightbox` × 7) so zoom/lightbox works, but this product carries a single image. **No reviews, no related/upsell products, no SKU, no additional-information tab, no variants, no age guidance, no delivery/returns info on the PDP** (all verified absent). Product description body is the complete string: *"1 pen is added"*.

**Search:** plain WordPress search form. No AJAX/autocomplete markers found (verified absent).

**Cart / checkout flow:** a **3-step** checkout (step indicator reads `Cart → Information → Payment`, with "Continue to payment" / "Return to cart" / "Return to information" / "Place order" controls) — a plugin-driven multistep checkout, themed via `--moderncart-*` CSS variables.

Guest checkout: **Yes** (optional "Create an account?" checkbox; an "Already have an account? Log in" panel sits above).

Fields actually present (`name="..."` attributes): `billing_email`\*, `billing_first_name`\*, `billing_last_name`\*, `billing_country`\* (Bangladesh), `billing_address_1`\*, `billing_address_2` (optional), `billing_city`\* (Town/City), `billing_state`\* (**District** — a `<select>` with all 64 BD districts: Bagerhat → Thakurgaon), `billing_postcode` (optional), `order_comments` (optional), plus a full "Ship to a different address?" mirror set.

> **No phone number field exists.** `billing_phone` appears exactly once in the checkout HTML, and only inside the WooCommerce address-i18n JS selector config (`"phone":"#billing_phone_field, #shipping_phone_field"`) — there is no corresponding input. For a COD-only store served by phone-dependent BD couriers, this is the single most damaging defect found on any site in this study.

Payment: **Cash on Delivery only** — the one gateway input is `id="payment_method_cod"` under the heading "Choose a Payment Gateway" ("Pay with cash upon delivery."). No bKash, Nagad, card or aggregator (verified absent).

Delivery charges: **none computed.** No `shipping_method`, no `flat_rate`, no `free_shipping`, no shipping-total row. Order totals read Subtotal ৳350.00 → Total ৳350.00 with no shipping line — despite the homepage badge promising "Flat-rate Delivery". No Dhaka / outside-Dhaka distinction (verified absent).

Coupon field: **Yes** (on checkout: "Coupon code" + Apply).

### C. E-commerce features

| Feature | Status | Evidence |
|---|---|---|
| Product variations | No (verified absent) | `variations_form` = 0; 12 simple products |
| Wishlist | Yes | WishSuite plugin; `/wishlist/` page; `add_to_wishlist` on PDP |
| Compare | Yes | `compare` × 34 |
| Reviews + ratings | No (verified absent) | `Reviews` = 0, `comment_container` = 0, no rating markup on PDP |
| Flash sale / countdown | No (verified absent) | `countdown` hits are the theme class `ht-product-countdown-fill` only |
| Coupon field | Yes | Checkout coupon form |
| Order tracking page | No (verified absent) | Not in the 21-URL page sitemap |
| Stock visibility | Yes (binary) | `In Stock` badge; no quantity shown |
| Payment methods listed | COD only | `payment_method_cod` |
| Related products | No (verified absent) | `products-related` = 0, `upsell` = 0 |
| Recently viewed | Yes (empty) | "Recently Viewed Products" block renders a no-items message |
| Bundles | No (verified absent) | No bundle/combo products or widgets |
| Blog | No (verified absent) | No posts sitemap, no blog route |

### D. SEO

- **URLs:** products at `/shop/<slug>/`, categories at `/product-category/<slug>/` — clean, but `/shop/` as the product base is unusual and collides conceptually with the `/shop/` listing page (which is also a real page).
- **PDP title:** `Spelling game – Toy House Bd`. Thin; no category, age or intent keywords.
- **Meta description: absent** on the PDP (verified absent).
- **JSON-LD:** only `"@type": "BreadcrumbList"` (Home → Shop → Educational → Spelling game). **No `Product` schema, no `Offer`, no price/availability markup** — ShopEngine's custom PDP template bypasses WooCommerce's built-in structured data. No rich results possible.
- **Open Graph:** partial — only `og:title` and `og:image`. No `og:description`, `og:type` or `og:url` (verified absent).
- **Breadcrumbs:** present as schema and as a category/tags line.
- **Sitemap:** WordPress core sitemaps (`wp-sitemap.xml`), 8 children. Exposes `wp-sitemap-users-1.xml` (author enumeration) and template post types (`elementskit_content`, `elementskit_template`, `shopengine-template`) that should not be indexable.
- **robots.txt:** standard WooCommerce disallows; correctly points to `wp-sitemap.xml`.
- **Bangla:** `lang="en-US"`; no Bangla copy anywhere on the pages fetched (verified absent). Only the ৳ symbol is localised.
- **Analytics/pixels: none at all.** No `G-`, no `GTM-`, no `fbq`, no `gtag`, no GA, no Meta Pixel (verified absent across homepage, PLP, PDP, checkout). The store cannot measure or retarget anything.

### E. Bangladesh localisation

- BDT formatting: `৳ 1,500.00` (symbol before, space, 2 decimals).
- Address + phone in footer: *Jamaj Road, Joar Shahara, Dhaka* / `01712101357` / `info@toyhousebd.com`.
- COD: offered (only method).
- District dropdown: full 64-district select at checkout.
- **Customer phone collection: none** (see above).
- WhatsApp / Messenger button: No (verified absent).
- Named courier: No (verified absent).
- Delivery charge policy: advertised as "Flat-rate Delivery" but **not implemented or stated anywhere**.
- Bangla content: No (verified absent).

### F. Weaknesses (observed)

1. **No phone field at checkout** while being COD-only — orders cannot realistically be fulfilled by BD couriers.
2. **No shipping cost is ever calculated or disclosed**; "Flat-rate Delivery" is an empty promise and the total equals the subtotal.
3. **12 products total.** The site looks like a store but cannot function as one.
4. **4 of 11 menu categories have no products** behind them.
5. **No Product JSON-LD and no meta descriptions** — invisible to shopping-rich results.
6. **Zero analytics or pixel** — no measurement, no retargeting, no ad optimisation.
7. **Unconfigured theme-demo pages left live and in the sitemap:** `/shop-2/`, `/cart-2/`, `/checkout-2/`, `/my-account-2/`, `/lets-keep-in-touch/`, `/lets-keep-in-touch-2/`, `/lets-keep-in-touch-3/`, `/manage-profile/`, `/manage-profile-2/`, `/manage-profile-3/`, `/sample/`, `/wishsuite/`. Duplicate cart/checkout routes are an SEO and support hazard.
8. Product copy is placeholder-grade ("1 pen is added") — no dimensions, materials, age range or safety note on a children's product.
9. No reviews, no related products, no delivery info on the PDP — nothing that converts.
10. `lang="en-US"` with no Bangla, on a store whose own footer address is in Dhaka.

---

## 2. babybazarbd.com — Baby Bazar BD

> A substantial, genuinely trading Chattogram-based mother-and-baby retailer whose toy range is one department among many. **The widest catalogue and the strongest merchandising taxonomy of the three — undermined by neglect and content hygiene problems.**

### A. Platform detection

WordPress + WooCommerce with the **Woodmart** theme and **WPBakery** builder:

```html
<meta name="generator" content="WordPress 7.1.4" />
<meta name="generator" content="WooCommerce 11.2.2" />
<meta name="generator" content="Powered by WPBakery Page Builder - drag and drop page builder for WordPress." />
```

`wp-content` × 893, `woocommerce` × 543. Theme assets load from `/wp-content/themes/woodmart/...`. SEO by **Yoast** (`sitemap_index.xml` with `wordpress-seo/css/main-sitemap.xsl`; "START YOAST BLOCK" in robots.txt). Security layer: `imunify-bot-check`. Wishlist/compare are Woodmart-native (`wd-action-btn` × 326).

### B. UX & site structure

**Catalogue size:** `product-sitemap.xml` holds **666 product URLs**; `product_cat-sitemap.xml` holds **80 categories**. `lastmod` on both is **2026-11-07** (today) — actively maintained.

**Category depth: 3 levels**, and the taxonomy is the site's real asset:

- `shop-by-age/` → `babies`, `1-year-olds` … `10-year-olds` (plus Teen, 11/12 Year Olds in the menu), and a separate `birth-to-6-months`
- `skills/` → `fine-motor-skills`, `gross-motor-skills`, `sensory-processing`, `eye-hand-coordination`, `motor-planning`, `visual-perception`, `social-skills`, `bilateral-integration`, `transition-skills`, `academic-skills`
- `special-needs/`, `developmental-goals/`
- `toys/` → 20 children (lego, hotwheels, funskool, wooden-toys, educational-toys, dolls, guns-blasters, play-tents, science, …)
- `baby-care/`, `baby-food/`, `feeding/`, `moms-care/`, `clothes-footwear/`, `lifestyle/`

A **skill/therapy-goal axis is a differentiator no competitor here matches** — it addresses occupational-therapy and special-needs buyers directly.

**Homepage section order:** cookie notice → header with mega-menu → hero with **Bangla h1** (`সর্বোত্তম পণ্যের সর্বোচ্চ সেবা দিয়ে মা ও শিশুর জীবন অতুলনীয় করাই আমাদের উদ্দেশ্য`) → **Shop By Category** (Bangla tiles: `শিশুদের খাদ্য`, `শিশুদের খেলা ধুলা`, `শিশুদের বুদ্ধি বিকাশ`, `শিশুদের মানসিক বিকাশ`, `শিশুদের যত্ন`) → **Shop By Skill** (10 therapeutic-goal icons, sub-head "Click on an icon below for products designed to achieve specific therapeutic goals") → second h1 "Happy things for happy kids on a Happy Planet" → **Our Products** → footer trust row.

> **"Our Products" dumps 80 products onto the homepage in alphabetical order** (`03 Rotate 360 Degree Bicycle…`, `12 Colors Soft Super Light Air Dry Clay…`, then a long run of `Baby …` / `Xierbao` SKUs). No featured, bestseller or new-in curation. This is why the homepage ships **578 KB of HTML**, and why the first thing a toy shopper sees is baby cotton buds and nail clippers.

**Primary nav (mega-menu):** Shop · Categories · Tika Reminder · Hello Doctor · Blog · About Us · Install Our App · Wishlist · Compare · Login/Register. Value-added services (vaccination reminder, child-specialist consult, mobile app on both App Store and Google Play) are a real moat.

**PLP:** `Showing 1–24 of 201 results` on /product-category/toys/educational-toys/, 24 per page, paginated. Sorting = stock WooCommerce 5 options. Sale badges (`-7%`, `-14%`) and strike-through pricing render on cards.

> **No filters at all.** No price widget, no brand, age or gender facet (`widget_price_filter` = 0, `price_slider` = 0; the only `layered_nav` hits are theme CSS). A "show sidebar" toggle button exists in the markup but its own inline CSS sets `.wd-show-sidebar-btn{display:none}` and no widget renders. Age is reachable only by navigating to a separate category — it cannot be combined with anything else. **For a 666-product catalogue this is the most consequential UX gap on the site.**

**PDP** (`/product/monkey-balance-educational-math-game/`): discount badge `-7%`, "Click to enlarge" gallery zoom, title, strike-through `৳700.00` → `৳650.00` with "Original price was / Current price is" accessible text, quantity stepper, Add to cart, **Compare**, **Add to wishlist**, categories, a **Description** tab, and **Related products** (8 cards with Quick view).

Verified absent on the PDP: **reviews** (the tab list contains only `description_tab` — `<ul class="wd-nav wd-nav-tabs tabs wc-tabs">` has a single `<li class="description_tab">`; all `star-rating` / `woocommerce-product-rating` hits are theme CSS), **stock status of any kind** (no `In stock` / `Out of stock` string, no `<p class="stock">`), **age guidance field**, **SKU display**, **variants** (`variations_form` = 0), **delivery info**. The quantity input has `min="1"` and **no `max`** — a shopper can order any quantity of an item whose stock is never shown.

**Search:** AJAX search with autocomplete present (`wd-search` × 29, `ajax_search`, `autocomplete`).

**Cart / checkout flow:** classic **single-page** WooCommerce checkout. Guest checkout: **Yes**. Social login offered (Facebook, Google).

Fields: `billing_first_name`\*, `billing_last_name`\*, `billing_company` (optional), `billing_country`\*, `billing_address_1`\*, `billing_address_2` (optional), `billing_city`\*, `billing_state`\* (**District** select), `billing_postcode` (optional), **`billing_phone`\***, `billing_email`\*, `order_comments`, plus a full shipping mirror including `shipping_phone`\*.

Shipping: a single **`Flat rate: ৳130.00`** line (`flat_rate` present). Totals observed: Subtotal ৳650.00 + Shipment ৳130.00 = **Total ৳780.00**. **No Dhaka / outside-Dhaka differentiation observed** — one national rate. Footer promises "Free Delivery — For Order over 3000 Tk*".

Payment: **COD only** — `payment_method_cod` is the sole gateway. bKash and Nagad appear **only inside the Terms text** ("Customers who paid with bKash, Nagad, card, or other electronic methods are eligible for a refund") — i.e. the policy describes payment methods the checkout does not offer.

Coupon: **Yes** ("Have a coupon? Click here to enter your code").

> The checkout page inlines the **entire Terms & Conditions document** (sections 1–8+, thousands of words on eligibility, copyright, cookies, promotional activity) directly into the order form, inflating it to **325 KB**. On a mobile connection this sits between the shopper and the Place Order button.

### C. E-commerce features

| Feature | Status | Evidence |
|---|---|---|
| Product variations | Yes | Verified on `/product/baby-girl-pre-walker-shoes/`: `variations_form`, `attribute_pa_baby-size`, "Baby First Walker Size → Choose an option / 1 - 11 CM / Clear". The toy PDP sampled was a simple product |
| Wishlist | Yes | Woodmart wishlist; `/wishlist/` page |
| Compare | Yes | `/compare/` page; Compare on PDP + cards |
| Reviews + ratings | No (verified absent) | PDP tab list contains only `description_tab` |
| Flash sale / countdown | No (verified absent) | Widget config carries `"sale_countdown":"0"` |
| Coupon field | Yes | Cart + checkout |
| Order tracking page | Yes (broken page, see F) | `/track-order/` with `name="orderid"` + `name="order_email"` |
| Stock visibility | No (verified absent) | No stock string on PDP; qty input has no `max` |
| Payment methods listed | COD only | `payment_method_cod` |
| Related products | Yes | 8 related cards on PDP |
| Recently viewed | No (verified absent) | "Recently viewed" renders on neither the PDP nor the PLP; the single `recently-viewed` hit is theme CSS |
| Bundles | No (verified absent) | `wd-fbt` / `frequently` = 0 on both PDPs sampled; theme ships the CSS but no bundle renders |
| Blog | Partial | `/blog/` returns HTTP 200 but renders the site default title and no post list |
| Mobile app | Yes | App Store + Google Play buttons in footer |
| Vaccination reminder ("Tika Reminder") | Yes | `/tika-reminder/`, `/reminder/`, `/download-tikadate/` |
| Doctor consult ("Hello Doctor") | Yes | `/child-specialist/` |

### D. SEO

- **URLs:** `/product/<slug>/`, `/product-category/<parent>/<child>/` — correct and hierarchical.
- **PDP title:** `Monkey Balance Educational Math Game - Baby Bazar BD`. Decent.
- **Meta description:** present and product-specific (Yoast), drawn from the description.
- **JSON-LD — the best of the three WooCommerce implementations.** Graph 1 (Yoast): `"@type": "WebPage"`, `"ReadAction"`, `"ImageObject"`, `"BreadcrumbList"`, `"WebSite"`, `"SearchAction"`, `"EntryPoint"`, `"PropertyValueSpecification"`, `"Organization"`. Graph 2 (WooCommerce): `"@type": "Product"` with `sku: 12787`, and `"@type": "Offer"` carrying `price: 650.00`, `priceCurrency: BDT`, `availability: https://schema.org/InStock`, plus two `"UnitPriceSpecification"` nodes (650.00 current / 700.00 list). No `aggregateRating` (consistent with reviews being off).
- **Open Graph:** complete — `og:locale`, `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:image` (+ width/height/type), `article:publisher` → `facebook.com/babybazarbdctg`. Flaw: `og:type` is `article` on a product page.
- **Breadcrumbs:** schema present but shallow — Home → Shop → Product, skipping the category despite a 3-level taxonomy.
- **Sitemap:** Yoast index with 7 children. `portfolio-sitemap.xml` (`lastmod` 2017) and `project-cat-sitemap.xml` are legacy cruft from a prior site.
- **Bangla:** `lang="en-US"`, but **real Bangla content is present** — Bangla h1, Bangla category tiles, 28 Bangla text tokens on the homepage. The `lang` attribute misdeclares it.
- **Analytics/pixels:** **Meta Pixel present** — `connect.facebook.net`/`fbevents` loaded, `fbq('init', this._pixelId, …)`, with `pixelId: "229240508811973"` in the WooCommerce-Facebook config. **No GA4, no GTM** (verified absent). TikTok appears only as a social profile link (`tiktok.com/@babybazarbd.com`), not a pixel.

### E. Bangladesh localisation

- BDT formatting: `৳ 650.00`.
- Bangla content: **Yes** — hero h1, category tiles, footer strings.
- Phone collection: **Yes**, `billing_phone` required (and `shipping_phone`).
- Support phone: `+8801847309400`. Registered office: *120, Momin Road, Jamal Khan, Chattogram*.
- **Named couriers: Yes — two of them.** The footer "Shipping System:" row carries `alt="Pathao Courier"` (`rrr.png`) and `alt="e courier"` (`qqq.png`) — Pathao and eCourier.
- Advertised payment: the "Payment System:" logo is `alt="Cash on Delivery"` (`22.png`) — i.e. the footer honestly advertises COD only, matching the checkout. The bKash/Nagad mismatch is confined to the Terms text.
- Trust signals: **DBID NO: 460396301** (Digital Business Identity), **ECAB membership** (`alt="ECAB"` — e-Commerce Association of Bangladesh), App Store + Play Store badges.
- Delivery policy: flat ৳130 at checkout; "Free Delivery for Order over 3000 Tk"; Terms state **12-hour express delivery to Chattogram metro** and **3–5 working days regular**.
- WhatsApp / Messenger button: **No (verified absent)** — `whatsapp`, `Messenger`, `fb-customer-chat` and `m.me/` all return zero hits. Only a plain Facebook page link.
- COD: Yes (only method).

### F. Weaknesses (observed)

1. **Zero filtering on a 666-product catalogue.** No price, brand, age or gender facet; the sidebar is CSS-hidden and empty. Age is navigable but not filterable or combinable.
2. **Homepage is an 80-item alphabetical catalogue dump** (578 KB), opening with cotton buds and nail clippers rather than toys or offers.
3. **SEO-spam pages are live and in the sitemap** on a children's store — verified HTTP 200 with these exact titles:
   - `What Is an Online Slot Machine? - Baby Bazar BD` → `/what-is-an-online-slot-machine/`
   - `Cheap Essays - How to Find Cheap Essay Writers - Baby Bazar BD` → `/cheap-essays-how-to-find-cheap-essay-writers/`
   - `/essay-writing-how-to-pick-the-right-essay-topic/`
   A brand-safety and ranking liability, almost certainly residue of a content injection.
4. **`/track-order/` is functional but wrapped in unedited theme demo content:** lorem-ipsum steps (`STEP 1 — VESTIBULUM CUBILIA`, `Etiam proin phasellus a hac sociis condimentum…`), a **New York address — "50 East 52nd Street, Brooklyn, NY 10022"**, support hours "Monday to Friday from 09:00 to 21:00 **UTC +2**", and a WordPress error string "Create your first navigation menu here and add it to the 'Main menu' location."
5. **No stock status anywhere on the PDP** — not on the simple toy product, and not even on a variable product with a size selector (`In stock` / `Out of stock` return zero hits on both) — combined with no `max` on the quantity input. Overselling is structurally invited.
6. **No reviews or ratings** on 666 products; the only tab is Description. No social proof, no `aggregateRating`.
7. **Checkout inlines the entire Terms & Conditions** (325 KB page) above the order button.
8. Payment reality vs policy mismatch: Terms promise bKash/Nagad/card refunds; checkout offers **COD only**.
9. **No Dhaka / outside-Dhaka delivery pricing** — one ৳130 national flat rate from a Chattogram base.
10. **No GA4/GTM** — only a Meta Pixel; no funnel measurement.
11. Other live cruft: `/test/`, `/reminder-test/`, `/download-tikadate/`, 2017 portfolio sitemaps. Two `<h1>` elements on the homepage. Footer trust logos named `22.png`, `qqq.png`, `rrr.png`.
12. `lang="en-US"` despite substantial Bangla content — hurts language targeting.

---

## 3. edutoyzone.com — EduToyZone

> **The strongest competitor by a wide margin, and the only one built specifically as an educational-toy store for Bangladeshi parents.** Treat this as the benchmark to beat.

### A. Platform detection

**nopCommerce** (ASP.NET Core) — not WordPress, not Shopify:

```html
<meta name="generator" content="nopCommerce" />
```

Corroborated by `__RequestVerificationToken` (ASP.NET antiforgery) × 2, `Nop.` × 4, footer "Powered by nopCommerce", nopCommerce route shapes in robots.txt (`/cart/estimateshipping`, `/catalog/searchtermautocomplete`, `/addproducttocart/catalog/*`, `/compareproducts/add/*`, `/backinstocksubscribe/*`, `/setproductreviewhelpfulness`, `/country/getstatesbycountryid/`), WebP image pipeline (`/images/thumbs/0007470_..._510.webp`), and a verified AJAX add-to-cart at `POST /addproducttocart/details/4446/1`.

> **This site is not a stock theme — it carries purpose-built custom plugins**, which is the clearest signal of real engineering investment in the competitor set. Script paths observed: `/Plugins/ETZ.Plugin.Misc.ProductDiscovery/Scripts/product-card-age.js` (the age-badge / age-discovery system), `/Plugins/ETZ.Plugin.Misc.OneCspCheckout/Contents/js/public.buynow.js` (the custom one-page checkout and Buy-now flow, with endpoints `/OneCspCheckout/ApplyDiscountCoupon`, `/OneCspCheckout/ApplyGiftCard`), `/Plugins/Widgets.SmartProductCarousel/Scripts/etz-product-video.js` (product video), and `/Themes/Emporium/Content/scripts/etz-category-filters.js` (the faceted filters). Theme base: `Emporium`.

### B. UX & site structure

**Catalogue size:** `sitemap.xml` holds **510 URLs**.

**URL structure: completely flat** — every product and category sits at the root (`/wooden-shape-sorting-box`, `/brain-development-toys`, `/2-year-plus`). Short and shareable; no hierarchy signal for crawlers.

**Homepage section order** (a deliberately designed toy-store IA):

1. Trust strip — *Nationwide Delivery · Cash on Delivery · Free Delivery ৳1999+ · Customer Support*
2. Announcement bar — "Free delivery on orders over 1999tk · Cash on delivery nationwide"
3. h1 **"Educational & Learning Toys in Bangladesh"** + Bangla tagline (`খেলনা শুধু খেল…`)
4. **"Start with Your Child's Age"** — age-first entry point
5. **"Parents' Favorites"** — 8 curated products
6. **"More Savings in a Bundle"** — 6 combo/bundle products
7. **"Real Kids. Real Play."** — social proof / UGC
8. **"New at EduToyZone"** — 8 products
9. **"Visit Our Office and Shop In Person"** — offline trust
10. Bangla explainer sections
11. **"Parents Often Ask"** — 6-question FAQ accordion
12. Bilingual footer (কাস্টমার সার্ভিস / জনপ্রিয় ক্যাটাগরি / যোগাযোগ)

**Navigation: 3 levels, organised by shopper intent** rather than by product type:

- **Shop by Age** → Babies (*3-6 months, 6-12 months*) · Toddlers (*1 Year +, 2 Year +*) · Pre-school (*3 Year +, 4 Year +*) · Older Kids (*5 Year +, 6 Year +*) · Family (*Family Toys*), with an inline nudge "Not sure where to start? Find the right toys for your child's age"
- **Shop by Skill** → Developmental Skills (*Fine Motor, Hand-Eye Coordination, Matching & Sorting, Problem Solving*) · Early Learning (*Alphabet & Letters, Numbers & Counting, Colors & Shapes, Language & Literacy*) · Play & Creativity (*Creative Play, Pretend & Role Play, Sensory Play, Building & Construction*) · Popular Ways to Play (*STEM Activities, Puzzles, Family Games, Books & Activities*)
- **Education & Books** → 9 children · **STEM Learning** · **New Arrivals** · **Combo Offer** · All Categories

**PLP** (`/brain-development-toys`, 9 pages) — **the only site in this study with real merchandising filters**, all with live result counts:

| Facet | Options observed |
|---|---|
| Filter by price | Range `140৳ – 8,990৳`; bands *Under 500৳*, *500৳–1,000৳*, *1,000৳–1,500৳*, *Above 1,500৳* |
| **Recommended age** | *Age 6 months+ (2)*, *Age 1 years+ (1)*, *Age 1.5 years+ (38)*, *Age 2 years+ (13)*, *Age 3 years+ (96)*, *Age 4 years+ (8)*, *Age 5 years+ (4)* |
| **Learning goal** | *Fine Motor (21)*, *Problem Solving (18)*, *Creativity (15)*, *Memory & Focus (6)*, *Numbers & Math (2)* |
| Availability | *In stock only* |

With *Clear all* / *Reset filters* and an `Apply → Show <n> Products` footer. Sorting: **Featured, Best selling, Newest, Price: Low to High, Price: High to Low** (`data-etz-sort`). Infinite scroll enabled (`data-etz-infinite-scroll="true"`). **No brand or gender facet** (verified absent). Note the filters are client-side (`data-etz-*`), so they act on loaded products rather than re-querying the server.

**PDP** (`/wooden-shape-sorting-box`) — the most complete product page found:

- **`Age 2 years+` badge above the title**
- `In stock` status · Video indicator
- Bangla marketing description with emoji
- `1,050.00৳` with strike-through `1,190.00৳` and **`Save 140৳ (12%)`**
- Payment/delivery line: *"Cash on delivery or advance pay · Free delivery on eligible orders over 3000৳"*
- **Add to cart** + **Buy now** (express)
- *"Need help before ordering?"* → **WhatsApp** and **Messenger**
- Trust triplet: *Nationwide delivery* / *Cash on delivery — Pay when you receive* / *Parcel issue support — Wrong, damaged or missing items*
- Social share across 6 networks
- Tabs: **Description · Specifications · Delivery & Returns · FAQ**
  - Specifications → `Age Group: 3 Year +, 4 Year +, 5 Year +`
  - **Delivery & Returns** (quoted): *"Nationwide delivery available — inside Dhaka 1–2 days, outside Dhaka 2–4 days. Delivery charge is calculated by your location at checkout. Free delivery on eligible orders over 3000৳. Cash on delivery, or pay in advance via bKash/Nagad if you prefer. Wrong, damaged or missing items are replaced — inform us within 24 hours with an unboxing photo. Returns for change of mind are not accepted. You can also collect from our Mirpur 2 office, 10:00 AM – 8:00 PM."*
  - **Per-product FAQ**: *Is cash on delivery available? / How long does delivery take? / Is this safe for my child? / Can I see it before buying?*
- *"Have a question? Ask us on WhatsApp"*
- Sticky repeat of price + Add to cart + Buy now
- **"You may also like"** — related products with `% OFF` badges, video markers, `Out of stock` labels and **`View Options`** for variant products
- **Recently viewed products**

**Variants confirmed** on `/magnetic-lacing-and-fishing-box-toy-set`: `Please Choose a variation *` → *Vegetables, Vehicles, Fruits, Dessert, Tasty Food*, with per-variation pricing (`570.00৳` / `650.00৳`, `Save 80৳ (12%)`) and per-variant stock.

**Reviews confirmed**: `<div class="product-rating-box" title="1 review(s)">` with a filled star bar. Review infrastructure is live (nopCommerce `setproductreviewhelpfulness` in robots.txt) though volume is very low.

**Search:** autocomplete via `/catalog/searchtermautocomplete`, plus a curated **"Popular searches"** panel — *Wooden puzzle, Magnetic fishing, Stacking blocks, Colour pencils, Memory game, Busy board*.

**Cart:** free-delivery progress — **"Add 949৳ more to get free delivery"** / "Free over 1,999৳"; `= 1,050.00৳` line totals; Remove / Update / Clear; *"Delivery charge is added at checkout. Cash on delivery available."*; **Discount codes and Vouchers** field; **Estimate shipping**; Bangla order summary (`মোট মূল্য`, `ডেলিভারি চার্জ: Calculated during checkout`, `Tax: 0.00৳`, `সর্বমোট`); CTA `অর্ডার কনফার্ম করুন →`. Bangla add-to-cart confirmation: *"প্রোডাক্টটি কার্ট এ যোগ হয়েছে, অর্ডার কনফার্ম করুন CLICK HERE"*.

**Checkout — single page, guest, phone-first, bilingual.** Heading `Checkout / অর্ডার সম্পন্ন করুন`, "🔒 Secure Checkout", "☎ Need help? 01804-194044".

Fields in order: **`Mobile Number *`** (first field), `Your Name (আপনার নাম)`, **`District (জেলা)`** select, `Full Address (পূর্ন ঠিকানা) *`, then a collapsed *"Email বা Order Note যোগ করুন"* revealing `Email (Optional)` and `Order Note (Optional)`. Form names: `ShippingNewAddress.PhoneNumber`, `.FirstName`, `.StateProvinceId`, `.Address1`, `.Address2`, `.Email`, `.CountryId` (Bangladesh only), plus `ShippingOption` and `paymentmethod`. **Email is optional; phone is mandatory** — correctly inverted for the BD market. No last-name field.

**The district dropdown is delivery-aware, not administrative** — 36+ options that break Dhaka into billable subcities: `Dhaka`, `Ashulia(Dhaka subcity)`, `Dhamrai(Dhaka subcity)`, `Dohar(Dhaka subcity)`, `Hemayetpur(Dhaka subcity)`, `Keraniganj(Dhaka subcity)`, `Nawabganj(Dhaka subcity)`, `Savar(Dhaka subcity)`, then the rest of the country.

**Shipping — 3-tier zone pricing, the only real zone model found:**

```
Delivery charge will be calculated after selecting your district.
জেলা নির্বাচন করলে ডেলিভারি চার্জ দেখা যাবে।
  Inside Dhaka      70.0৳
  Dhaka Subcity    100.0৳
  Outside Dhaka    120.0৳
```

Payment: **Cash On Delivery(COD)** is the only method rendered at checkout.

Totals (Bangla labels): `মোট মূল্য: 1,050.00৳` · `ডেলিভারি চার্জ: (Inside Dhaka) 70.00৳` · `Tax: 0.00৳` · `সর্বমোট: 1,120.00৳`, CTA `Confirm ( — 1,120.00৳`. Coupon prompt `কুপন কোড আছে?`. Reassurance ticks: *Cash on Delivery / Nationwide delivery / Customer support available*, plus *"অর্ডার করতে সমস্যা হচ্ছে? WhatsApp 01804-194044"*.

**Guest order tracking:** `/findorder` — **"Find Your Order"** taking `Order ID` + **"Last 4 digits of Phone"**. Phone-based lookup, consistent with email being optional. (`/track-order`, `/order/tracking` and `/orderdetails` all 404; `/findorder` and `/order/history` are the real routes.)

### C. E-commerce features

| Feature | Status | Evidence |
|---|---|---|
| Product variations | Yes | "Please Choose a variation" + 5 options, per-variant price & stock |
| Wishlist | Yes | `wishlistMenuLinkSelector`; nopCommerce wishlist |
| Compare | Yes | `/compareproducts/add/*` in robots.txt |
| Reviews + ratings | Yes (very low volume) | `product-rating-box title="1 review(s)"` |
| Flash sale / countdown | No (verified absent) | No countdown markers on homepage/PLP/PDP |
| Coupon / voucher field | Yes | Cart + checkout (`discountcouponcode`, `applydiscountcouponcode`) |
| Order tracking page | Yes | `/findorder` — Order ID + last 4 phone digits |
| Stock visibility | Yes | `In stock` / `Out of stock`, per variant; "In stock only" filter; back-in-stock subscribe route |
| Payment methods listed | COD at checkout; bKash/Nagad advance described in policy | `Cash On Delivery(COD)`; Delivery & Returns tab |
| Related products | Yes | "You may also like" |
| Recently viewed | Yes | "Recently viewed products" |
| **Bundles** | **Yes** | "More Savings in a Bundle"; `/combo-offer`; combo SKUs |
| Age filter | Yes | `data-etz-age-option` with counts |
| Learning-goal filter | Yes | `data-etz-goal-option` with counts |
| Free-delivery progress bar | Yes | "Add 949৳ more to get free delivery" |
| Buy-now express | Yes | "Buy now" beside Add to cart |
| WhatsApp + Messenger | Yes | Header, PDP, checkout |
| Product video | Yes | "Video" markers on PDP + cards |
| Blog | Page exists, **empty** | `/blog` HTTP 200 with RSS link, no posts rendered |

### D. SEO — strongest of the four

- **URLs:** flat root-level slugs; readable but hierarchy-free.
- **Homepage title:** `Educational & Learning Toys in Bangladesh | EduToyZone – Educational & Learning Toys for Kids` (geo + category intent, though the brand suffix duplicates itself).
- **Homepage meta description:** *"Shop educational and learning toys in Bangladesh for babies, toddlers and growing kids. Explore wooden toys, puzzles, STEM toys, activity books and creative play products at EduToyZone."*
- **PDP title:** `Wooden Shape and Color Sorting Box | EduToyZone – …`; **PDP meta description is in Bangla**.
- **JSON-LD:**
  - Homepage: `"@type": "Organization"`, `"WebSite"`, and a **`"FAQPage"`** whose questions are Bangla customer concerns — `কোন বয়সের জন্য কোন খেলনা উপযুক্ত?`, `Cash on Delivery আছে?`, `ঢাকার বাইরে delivery হয়?`, `Wrong বা damaged product পেলে কী করবো?`, `অফিসে এসে product দেখা যাবে?`, `Product selection-এর জন্য help পাওয়া যাবে?`
  - PDP: `"@type": "Product"` (`sku: 1800009`) + `"Offer"` (`price: 1050.00`, `priceCurrency: BDT`, `availability: https://schema.org/InStock`), `"BreadcrumbList"` (**Brain Development Toys → product**, i.e. category-aware), and a **second per-product `"FAQPage"`**.
- **Open Graph:** on the **PDP** yes — `og:type` correctly `product`, plus `og:title`, `og:description` (Bangla), `og:image`, `og:image:url`, `og:url`, `og:site_name`. **On the homepage: none at all** (verified absent) — inconsistent, and the homepage is the most-shared URL.
- **Twitter cards:** No (verified absent).
- **robots.txt:** the most carefully written of the four — 20+ targeted disallows for cart/customer/compare/AJAX routes, correct `Sitemap:` and `Host:` directives.
- **Bangla:** `lang="en"` but **deeply bilingual in practice** — Bangla meta descriptions, product copy, FAQ schema, cart/checkout labels, footer navigation, add-to-cart toasts. Natural Banglish register ("Delivery charge এলাকা অনুযায়ী checkout-এ দেখানো হয়"). The `lang="en"` declaration understates it.
- **Analytics/pixels — full stack, the only complete one:** `GTM-W7DGWV43` (Google Tag Manager), `G-ZSFE8WM8TS` (GA4), Meta Pixel `1071219174683903` (`fbq("init", …)`), plus `googletagmanager`, `gtag`, `analytics.js`, reCAPTCHA.

### E. Bangladesh localisation — best in class

- BDT formatting: **`1,050.00৳`** (symbol *after* the number — the local convention; both WooCommerce competitors use `৳ 1,050.00`).
- Bangla content: extensive and purposeful (see above).
- Phone collection: **mandatory and first**; email optional.
- **3-tier delivery pricing** with Dhaka-subcity granularity: 70৳ / 100৳ / 120৳.
- Stated SLAs: **inside Dhaka 1–2 days, outside Dhaka 2–4 days**, "after the order is confirmed over phone" (phone confirmation is the norm in BD).
- COD nationwide, pay the courier on receipt; bKash/Nagad advance offered in policy copy.
- Returns: **24-hour window with unboxing photo** for wrong/damaged/missing; **no change-of-mind returns** — stated plainly rather than hidden.
- **WhatsApp + Messenger** in header, on the PDP, and at checkout; support number `01804-194044`.
- Physical pickup: *House No: 68, Road No: 19/1, Rupnagar R/A, Mirpur 2, Dhaka*, open `প্রতিদিন সকাল ১০টা – রাত ৮টা` (daily 10 AM–8 PM).
- Guest order lookup by phone.
- Named courier: **No (verified absent)** — "courier-এর মাধ্যমে" generically; no Pathao/Steadfast/RedX branding.

### F. Weaknesses (observed)

1. **Free-delivery threshold contradicts itself three ways, sometimes on the same page.** The PDP header strip says `Free Delivery ৳1999+` and "Free delivery on orders over 1999tk" while the product block and Delivery & Returns tab say **"Free delivery on eligible orders over 3000৳"**; the cart computes against **1,999৳** ("Add 949৳ more" on a 1,050৳ cart); the homepage also shows **`৳2,000+`** and "Free delivery over ৳1,999". A shopper cannot tell what qualifies.
2. **Age guidance contradicts itself on the PDP** — badge reads `Age 2 years+`, Specifications reads `Age Group: 3 Year +, 4 Year +, 5 Year +`. On a toy store, age is the primary buying signal.
3. **No Open Graph on the homepage** — the most-shared link renders without a title/image card on Facebook and Messenger, the dominant BD channels.
4. **Blog exists but is empty** — `/blog` and `/blog` RSS are live with zero posts; top-of-funnel content is forfeited.
5. **Reviews are live but near-empty** (1 review on the sampled related product); no `aggregateRating` on the main PDP.
6. Payment reality vs copy: the Delivery & Returns tab offers "advance via bKash/Nagad", but checkout renders **COD only**.
7. **Filters are client-side and not URL-addressable.** `etz-category-filters.js` (7 KB) contains **zero `pushState` and zero `replaceState`** calls; it toggles `hidden` on already-rendered cards and AJAX-loads more for infinite scroll. So a filtered view (e.g. "Age 3 years+ & Fine Motor") has no shareable link and nothing for Google to index — a wasted long-tail SEO opportunity on the site's single best feature. Pagination *is* addressable (`?pagenumber=2` returns a genuinely different page), and the native nopCommerce `?specificationids=` parameter appears to be ignored by this custom layer.
8. **Flat URL namespace** — all 510 products and categories share the root. One slug collision away from trouble, and no hierarchy for crawlers.
9. `lang="en"` on a substantially Bangla site; `maximum-scale=2.0` on the viewport mildly constrains accessibility zoom.
10. PDP meta description contains a raw `</br>` tag, which leaks into search snippets.
11. No brand or gender filter; no flash-sale/countdown mechanic.

---

## 4. kidszone.com — NOT a Bangladesh toy store

> **Verified: this is not a store and not Bangladeshi. It is a parked domain serving an ad landing page.** It should be removed from the competitor set.

**Evidence.** `https://kidszone.com/` returns HTTP 200 with **27 KB** of HTML containing **no `<title>`, no products, no navigation, no prices, no commerce markup of any kind**. There is no `wp-content`, no `cdn.shopify.com`, no `csrf-token`, no `__NEXT_DATA__` — no platform at all.

The entire page body is (a) a **consentmanager.net GDPR/CCPA consent-management script** (`cmp_cdid = "40679ccc92462"`, `cmp_host = "d.delivery.consentmanager.net"`, `cmp_cdn = "cdn.consentmanager.net"`) supporting 32 European languages, (b) a "Do Not Sell or Share My Personal Information" link driven by US state privacy regulation keys (`USVCDPA`, `USCPA`, `USUCPA`, …), and (c) **one single tracking pixel** — the only external resource on the page:

```
https://l.cdn-fileserver.com/bping.php?cc=BD&...&vgd_rpth=%2Fola&vgd_cage=37
  &vgd_cdv=O3746&vgd_setup=c21&prid=8PR11258V&crid=126431565&wsip=170763333
  &requrl=https%3A%2F%2Fkidszone.com&mspa=0&gdpr=0&vgd_wlstp=0&...
```

The `bping.php` + `vgd_*` + `prid`/`crid` parameter signature is domain-monetisation / parked-ad infrastructure. Supporting findings: **`/robots.txt` fails outright** (`curl: (35) Recv failure: Connection reset by peer`), and **`/sitemap.xml` returns an HTML error page**, not XML: *"Error. Page cannot be displayed. Please contact your service provider for more details."* The GDPR-global default plus European language list plus US state-privacy handling point at a US/EU monetisation operator; the `cc=BD` parameter merely reflects the geolocation of the requesting IP, not the site's market.

**Conclusion:** no platform, no catalogue, no checkout, no Bangla, no BDT, no BD business identity. Sections A–F are not applicable. Any competitive brief listing kidszone.com as a Bangladeshi toy retailer is mistaken.

---

## Cross-site comparison

### Feature matrix

| Feature | Toy House BD | Baby Bazar BD | EduToyZone |
|---|---|---|---|
| Platform | WooCommerce + Elementor/ShopEngine | WooCommerce + Woodmart | **nopCommerce + custom front-end** |
| Products in sitemap | **12** | **666** | **510 URLs** |
| Category depth | 1 | 3 | 3 |
| Shop by age navigation | No (verified absent) | Yes (categories) | **Yes (categories + filter)** |
| Shop by skill/goal | No (verified absent) | **Yes (10 therapy goals)** | **Yes (16 skills + filter)** |
| Price filter | Yes (min/max) | **No (verified absent)** | Yes (4 bands) |
| **Age filter** | No (verified absent) | No (verified absent) | **Yes (7, with counts)** |
| Brand filter | No (verified absent) | No (verified absent) | No (verified absent) |
| Gender filter | No (verified absent) | No (verified absent) | No (verified absent) |
| Sort options | 5 | 5 | 5 (incl. Best selling) |
| Search autocomplete | No (verified absent) | Yes | **Yes + popular searches** |
| PDP gallery zoom | Yes | Yes | Yes |
| Product video | No (verified absent) | Not verified | **Yes** |
| Variants | No (verified absent) | Yes (attribute dropdown) | **Yes (priced per variant)** |
| Stock status on PDP | Yes (binary) | **No (verified absent, incl. variable products)** | **Yes (per variant)** |
| Out-of-stock label on PLP | Not verified (no OOS item in sample) | Not verified (all 25 sampled were in stock) | **Yes** (`Out of stock` on cards) |
| Discount badge on PLP | No (verified absent) | Yes (`onsale` × 8) | **Yes** (`% OFF` × 44) |
| Age guidance on PDP | No (verified absent) | No (verified absent) | **Yes (badge + specs)** — but self-contradictory |
| Reviews + ratings | No (verified absent) | No (verified absent) | Yes (very sparse) |
| Related products | No (verified absent) | Yes (8) | Yes |
| Recently viewed | Yes (empty state) | No (verified absent) | Yes |
| Bundles / combos | No (verified absent) | No (verified absent) | **Yes** |
| Wishlist | Yes | Yes | Yes |
| Compare | Yes | Yes | Yes |
| Flash sale / countdown | No (verified absent) | No (disabled: `sale_countdown:0`) | No (verified absent) |
| Coupon field | Yes | Yes | Yes (cart + checkout) |
| Free-delivery progress | No (verified absent) | No (verified absent) | **Yes** |
| Guest checkout | Yes | Yes | **Yes (phone-first)** |
| Checkout steps | 3 | 1 | 1 |
| **Phone collected** | **No (verified absent)** | Yes (required) | **Yes (required, first field)** |
| Email required | Yes | Yes | **No (optional)** |
| District dropdown | Yes (64) | Yes | **Yes (36+, Dhaka subcities)** |
| Dhaka vs outside pricing | **None computed** | No (flat ৳130) | **Yes — 70 / 100 / 120৳** |
| COD | Yes (only) | Yes (only) | Yes (only at checkout) |
| Order tracking | No (verified absent) | Yes (page full of demo content) | **Yes (`/findorder`, phone-based)** |
| Named courier | No (verified absent) | **Yes (Pathao + eCourier)** | No (verified absent) |
| Industry trust badge | No (verified absent) | **Yes (ECAB + DBID)** | No (verified absent) |
| WhatsApp / Messenger | No (verified absent) | No (verified absent) | **Yes (both, 3 places)** |
| Bangla content | No (verified absent) | Yes | **Yes (extensive)** |
| Product JSON-LD | **No (verified absent)** | Yes (+ Offer, BDT) | **Yes (+ Offer + FAQPage ×2)** |
| Meta description on PDP | No (verified absent) | Yes | Yes (Bangla) |
| Open Graph | Partial (2 tags) | Yes (complete) | PDP yes / **homepage none** |
| GA4 / GTM | **None** | **None** | **GTM-W7DGWV43 + G-ZSFE8WM8TS** |
| Meta Pixel | **None** | Yes (`229240508811973`) | Yes (`1071219174683903`) |
| Blog | No (verified absent) | Page exists, no posts | Page exists, **no posts** |
| Mobile app | No (verified absent) | **Yes (iOS + Android)** | No (verified absent) |

### Delivery-charge models observed

| Site | Model | Charges |
|---|---|---|
| Toy House BD | **None implemented** | Total = subtotal; no shipping row |
| Baby Bazar BD | Single national flat rate | ৳130; free over ৳3,000 (footer claim) |
| EduToyZone | **3-tier zone** | Inside Dhaka ৳70 · Dhaka Subcity ৳100 · Outside Dhaka ৳120; free threshold stated inconsistently (1,999 / 2,000 / 3,000) |

### Universal gaps across all three toy stores

These are unserved by every niche competitor measured, and are the clearest openings:

1. **Nobody offers a gender filter, and nobody offers a brand filter.**
2. **Nobody runs a flash sale or countdown mechanic** (Baby Bazar has the capability and explicitly disabled it).
3. **Nobody has meaningful review volume.** Two of three have reviews switched off entirely; the third has ~1 review per product at best. No `aggregateRating` anywhere, so no star ratings in search results for any competitor.
4. **Nobody has a working blog.** Two have empty blog routes; one has none. All parenting/gift/age-guide search intent is uncontested.
5. **Nobody lists a digital payment gateway at checkout.** All three are COD-only in practice, even where policy copy promises bKash/Nagad.
6. **Only one names its couriers** (Baby Bazar → Pathao + eCourier); shipment-level tracking is absent everywhere, and only Baby Bazar displays an industry credential (ECAB, DBID).
7. **No gift wrapping, gift messaging, gift finder or gift guide** on any site (verified absent — zero hits for `gift wrap`/`gift message`/`gift finder`/`gift guide` on all three homepages and all three checkouts). The only gift-related things that exist are Baby Bazar's `lifestyle/gift-item/` product *category* and EduToyZone's platform-level gift-card redemption hook (`GiftCard.init("/OneCspCheckout/ApplyGiftCard", …)`, not surfaced as a field in the rendered checkout). A conspicuous omission in a category driven by birthdays and Eid.
8. **No safety/standards or certification messaging** (CE/ASTM, choking-hazard labelling) beyond ad-hoc warning lines inside EduToyZone descriptions.

---

## Marketplace expectations baseline

> **Status of this section:** the two general marketplaces were investigated in parallel as secondary benchmarks. **Daraz Bangladesh** and **Othoba** both yielded substantial data; **Chaldal** was checked and set aside (reason below). Items marked **[NV]** could not be verified because the surface was an empty JS shell or login-gated — they are recorded as unknown, not as absent.
>
> **Method note:** Daraz's category HTML is a pure JS shell, but appending **`?ajax=true`** to any Daraz category URL returns the full server-side JSON — filters, sort bar, 40 product cards and the SEO block. `https://www.daraz.com.bd/toys-games/?ajax=true` → HTTP 200, 182 KB, `content-type: application/json`. All Daraz facet and sort data below came from that. PDP structure came from `var __moduleData__` and `window.__i18n__.en` (518 UI strings), which reveal the real PDP vocabulary even though the populated values arrive by later XHR.

### 5. Daraz Bangladesh — `/toys-games/`

**A. Platform.** Alibaba/Lazada. `<meta name="data-spm" content="a2a0e">`, `<meta name="aplus-cpvdata" content='{"daraz_web_version":"2.0.0"}'>`; listing bundle `//g.alicdn.com/lzd-search-fe/srp-frontend/1.1.35/js/desktop.js`; PDP bundle `//g.lazcdn.com/g/lzdfe/pdp-modules/2.0.9/pc-mod.js`; `window.LZD_RETCODE_PAGENAME = 'lzd-search-desktop-node'`. Backend cluster leaks in the JSON (`"srpName":"LazadaMainSrp"`, `"cluster":"fiber2_my212"`). PDP APIs named in `globalConfig`: `mtop.lazada.detail.getVouchers`, `mtop.lazada.promotion.voucher.spread`.

**B. Structure, facets, sorting.** Flat single-segment category slugs. `sitemap-category-all-1.xml.gz` holds **6,185 category URLs, 118 of them toy-related** (`/toys-games/`, `/blocks-building-toys-games/`, `/kids-dolls/`, `/sorting-stacking-toys/`, `/shop-babies-shape-sorter-toys/`, `/electric-ride-ons/`, …). Hierarchy lives in the breadcrumb (3 levels: `Home → Toys & Games → Blocks & Building Toys`). `/toys-games/` reports `"totalResults":"4080"` at `pageSize: 40`.

**15 facets on `/toys-games/`**, in page order: `Category` · `Brand` · `Service & Promotion` · `Shipped From` · `Price` · `Rating` · `Delivery Option Standard` · `Warranty Type` · `Recommended Gender` · `Warranty Period` · `Interest` · `Character` · `Main Material` · `Featured Characters Brand` · `Color Family`. The deeper `/blocks-building-toys-games/` **gains `Recommended Age`** and `Size`/`Material`.

Option values quoted: `Shipped From` = `Dhaka`, `Chattogram`, `Khulna`, `Rajshahi`, `Sylhet`, `Overseas`, `Local`. `Service & Promotion` = `New Arrival`, `Mall`, `Free Delivery`, `11.11`, `Voucher Max`, `Buy More Save More`, `Coins`, `Fast Delivery`, `Choice`, `Best Price Guaranteed`. `Recommended Gender` = `Other`, `Unisex`, `Kids`, `Boys`, `Girls`, `Men`, `Teen`. `Interest` = `STEM (Science, Engineering, Technology & Math)`, `Collection`, `Transportation`, `Sports`, `Fashion`, `Music`, `Fantasy & Sci-Fi`, `Science`, … (15). `Price` is a free-entry Min/Max box; `Rating` is a star-threshold widget.

> **The exploitable flaw:** `Recommended Age` is a **seller-typed free-text attribute**, so it renders as ~30 overlapping, unsortable buckets — `"3 years"`, `"3 years and above"`, `"2 years and above"`, `"From 3 months +"`, `"From 0 month +"`, `"4 Months"`, `"18 months +"`, `"0-5 Years"`, `"0 - 12 years"`, `"4-10 years"`, `"5-7 years"`, `"6-18 Years"`, `"10 and Up"`, `"14 Years and above"`, `"Other"`… `Warranty Period` is worse: raw database IDs surface as labels (`"26"`, `"42"`, `"112"`, `"46"`) beside `"1 Month"`.

**Sorting: only three options** — `Best Match` (`popularity`, default), `Price low to high`, `Price high to low`. **No newest, no top-rated, no best-selling sort.** Sort bar and filters are sticky (`halfStickyHeader: ["sortBar","preposeFilter"]`).

**Product cards** carry `priceShow ("৳ 834")`, `originalPriceShow`, `discount ("1% Off")`, **`ratingScore ("5.0")` + `review ("1")`**, **`location ("Dhaka")`**, `sellerName`, **`itemSoldCntShow ("10 sold")`**, `inStock`, `isSponsored`/`adFlag`, and 4 description bullets. Badge types present: `FreeshippingAndVoucherMax`, `campaign`, `choice`, `coins` (`"Coins save ৳ 8"`), `lazMall`, `lowestPrice`.

**C. PDP.** URL `/products/{slug}-i{itemId}.html`. Declared modules include `lzd/item-gallery`, `lzd/sku-select`, `lzd/quantity-input` (`quantity.limit = {min:1, max:5}`), `lzd/product-review-summary`, `lzd/review`, `lzd/qna`, `lzd/delivery`, `lzd/warranty`, `lzd/seller`, `lzd/promotion-tags`, `lzd/installment`, `lzd/flash-sale`, `lzd/crazy-deal`, `lzd/combo-list`, `lzd/add-on-service-list`, `lzd/recommendation` (×2 + `seller_recommend`), `lzd/wishlist`, `lzd/pre-order-tag`, and **`lzd/age-restriction`**. Variants are real and gallery-swapping: `skuBase.properties[0] = {name: "Color Family", pid: "30129"}` with 10 image-backed values, and `skuGalleries` keyed per SKU id.

Verified UI vocabulary from `window.__i18n__.en`: `Delivery Options` · `Get by {time}` · `CHANGE` · `This item cannot be delivered to this location, please select another location.` · `Return Policy` · `WARRANTY TYPE` · `{total} Customer Reviews` · `Average Product Rating` · `All images are taken by real users` · `you and {some} people found this helpful` · `Not Helpful` · `Report Abuse` · `Questions about this product` · `ASK QUESTIONS` · `{total} Answered Questions` · `Ask the seller now and their answer will show here.` · `{percent}% Positive Seller Ratings` · `{sellerCount} other sellers from {lowPrice}` · `Only … item(s) left` · `Out of Stock` · `ADD TO WISHLIST`. Also the per-SKU caveat *"Seller info, warranty, return policy, lead time and shipping fee might be different after changing SKU."*

**D. Checkout / COD.** COD confirmed from Daraz's own SEO copy: *"home delivery, **cash on delivery** and installment facility for shoppers across country"*, and in the toys-category footer *"instalment payment options, cash on delivery, Daraz Verified sellers, and free shipping."* Installments: `"Up to {months} months, as low as {amount} per month."` COD exclusion found: *"COD and OTC payments are not supported for Groupbuy"*. Cart API `//cart.daraz.com.bd/cart/api/add`; `"currency":"BDT"`, display `"৳ "`. `robots.txt` disallows `/checkout/`, `/cart/`, `/customer/`, so **guest checkout is [NV]** — though listing pages preload a login/signup popup library and the PDP declares `module_sms-phone-input`, indicating phone auth in-flow. **Dhaka-vs-outside charge differential: [NV].**

**E. Tracking & returns.** **No publicly readable tracking page** — `my.daraz.com.bd/order-tracking/` redirects to a Lazada 404. `/pages/returns-refunds/`, `/payment-options/` and `/customer/order/index/` all return the **identical 149,237-byte generic shell** (md5 `67dd2e38ad79cc0ca32bebf384aabf1d`), and the Help Center (`buyer-helpcenter.daraz.com.bd/s/page`) is an empty SPA whose body text is literally `Help Center`. **Return window in days: [NV].** Account menu does expose `My Orders`, `My Returns & Cancellations`, `My Reviews`, `My Wishlist & Followed Stores` (Bangla: `আমার আদেশ`, `আমার রিটার্ন এবং বাতিলকরণ`).

**F. Vouchers & promotions — the richest mechanics observed anywhere in this study.** Copy-to-clipboard voucher codes (`CODE:` / `COPY` / `COPIED` / `"Paste your code in checkout page."` / `"Collect Voucher Success"`); collectible vouchers via `mtop.lazada.promotion.voucher.spread`; **countdowns both live and pre-launch** (`"Ends in {days} days {time}"`, `"Starts in {days} day(s) {time}"`); a live homepage flash-sale rail in Bangla — `ফ্ল্যাশ সেল এখন বিক্রি চলছে` with `৳ 100 ৳ 200 -50%`, `৳ 99 ৳ 299 -67%`, `৳ 375 ৳ 1,200 -69%`; multi-buy (`Buy 1 get 1 free`, `Buy more and save up to {price}`, `"You will pay {total}. Buy {quantity} and save {discountAmount}"`); combos (`Frequently Bought Together`, `Combo Price:`, `SAVE UP TO {saving}`, `ADD {count} PRODUCTS TO CART`); freebies (`Free Gift`, `Free Sample`, `Add On Service`); loyalty `Coins`; and an app-acquisition voucher (`"Exclusive Voucher for First Order"`). **Free-shipping threshold amount: [NV]** — only a boolean `Free Delivery` facet exists.

**G. SEO.** Category `/{slug}/` with `?page=N`; PDP `/products/{slug}-i{itemId}.html`. JSON-LD: category pages emit `ItemList → ListItem → Product → AggregateRating` (with `ratingValue`/`ratingCount`), but the **PDP emits only `Product` and an *empty* `BreadcrumbList` — no `Offer`, no `AggregateRating`.** Category meta has `og:title`, `og:type: product`, `og:description`, canonical; `hreflang_data: "[]"` — **no hreflang** despite bilingual content. Title/H1/description disagree: `<title>Buy Games & Puzzles at Best Prices in Bangladesh 2024…</title>` (stale "2024" in Nov 2026) against `seoInfo.h1 = "Baby Toys Price In Bangladesh"`. A ~5 KB long-form SEO footer sits under the grid with an `<h2>`, a price table, brand name-drops (including *"Toy House"*) and 14 internal category links; it self-documents the facets: *"Narrow down your options by price range, colour family, baby's recommended age, warranty type, recommended gender, and specific interests."*

**Bangla:** the **entire catalogue layer is localised** — `?lang=bn&ajax=true` returns Bangla facet titles (`সম্পর্কিত বিভাগ`, `ব্র্যান্ড`, `থেকে পাঠানো হয়েছে`, `মূল্য`, `রেটিং`) and Bangla sort labels (`সেরা মিল`, `দাম কম থেকে বেশি`), with an `ভাষা BN / EN` toggle. **But it is visibly machine-translated:** underscores leak (`ওয়ারেন্টি_ধরণ`), one facet is untranslated (`fea_chars_brands`), `Interest` is mistranslated as `সুদ` (financial interest), and homepage nav shows nonsense like `ছাগল` (goat). SEO title/description/H1 stay English under `lang=bn`.

**Analytics:** **no `G-`, no `GTM-`, no `fbq(`, no `gtag(` anywhere.** Daraz runs Alibaba's first-party stack only — `aplus_int.js` beaconed to `sg.mmstat.com`, plus Woodpecker RUM (pid `irmajibm-qikbi6z1`). `robots.txt` (241 B) has **no `Sitemap:` directive**, though `/sitemap.xml` exists (`lastmod 2026-11-07`).

### 6. Othoba — `/kids-toys`

**A. Platform. nopCommerce (ASP.NET Core)** — the same platform as EduToyZone. Conclusive: cookies `.Nop.Customer`, `.Nop.Antiforgery`, `.Nop.Culture=c%3Den-US%7Cuic%3Den-US`, `.Nop.TempData`; 109 references to `/Themes/Othoba/Content/…`; `__RequestVerificationToken`; headers `x-server: Othoba Platform`, `x-miniprofiler-ids`, `server: cloudflare` + `AWSALB` (Cloudflare → AWS ALB → ASP.NET). Search is **Typesense + Algolia Autocomplete**, with the API key base64'd in page source (`search.othoba.com`). Live chat: REVE Chat. Android app `com.othoba.android`.

**B. Structure & facets.** **Depth = 5 levels**, and the toy tree sits oddly under groceries: `Home > FMCG > Mother & Baby > Toys > Indoor Toys` (verified in both the HTML breadcrumb and the PDP `BreadcrumbList`).

> **Assortment is nearly empty.** Counting unique `cataloglist_prductid_(\d+)` in server HTML: `/kids-toys` → **1 product**, `/soft-toy` → **0**, `/education-learning` → **0**, parent `/mother-baby-toys` → **20**. Othoba is **not a real toy assortment competitor**; it is useful only as a UX-convention benchmark.

**Only three facets:** `Brand` · `Vendor` · `Rating`. **No price, age, gender or availability filter** — grepped and absent (`price-range`, `priceFrom`, `SpecificationFilter`, `InStock`). **Sorting: 8 options**, but shipped with raw nopCommerce enum names as the visible text — `Position`, `NameAsc`, `NameDesc`, `PriceAsc`, `PriceDesc`, `NewArrivals`, `Featured`, `Rating`. Page size `Show 40/60/80`. Cards ship **empty and hydrate client-side** (the hydration endpoint `/LoadProductInfoByProductId` is robots-disallowed), but reserve slots for ribbons, discount tags, hover-swap images, wishlist, swatches, sold count, and **both rating value and review count** (`0.0` and `(0)`).

**C. PDP.** Attribute strip `Category / SKU / Brand / Seller`; price `Tk 2,499`; tabs **`Description | Specification | Vendor Info | Customer Reviews (0) | Question & Answer`** (review count in the tab label). Description is **Bangla-first for a toy**, with explicit age guidance `বয়স উপযোগী (Ages 3+)` and developmental-benefit bullets (`বুদ্ধিমত্তা ও মেধার বিকাশ`, `মোটর স্কিল ও হ্যান্ড-আই কোঅর্ডিনেশন`). Q&A is login-gated (`Login to ask a question`).

The **delivery block is the richest element on the page**: `Available Delivery Area: All over the Bangladesh.` · a location selector `Dhaka | Dhaka City North` with `Change` · **`Delivery Time : Inside Dhaka:1-4 Working Days, Outside Dhaka:4-7 Working Days`** · **`Shipping Charge : Tk 60`** · **`Cash on Delivery Available`**. Return block: `Return & Warranty` → **`Change of mind is not applicable`** · `Warranty Not Available`. Seller card: `Positive Seller Ratings 0%` · `Ship On Time 0%` · `Seller Size`.

Notable: an **app-exclusive price dangled on the web PDP** — `App-only price Tk 1,799.28` against the web `Tk 2,499` (~28% off), with an inline QR block. Also `Call For Order : 09613-800800` directly on the PDP.

**D. Checkout.** **No guest checkout**, documented verbatim in `/how-to-shop-on-othoba`: a 4-step checkout beginning *"Enter your Phone Number and click/tap on Proceed"* → *"The system will send an OTP SMS to your Phone"* → *"the system will ask you to choose and set a password"* (skippable). **Phone-first, OTP-verified identity is mandatory.** Payment methods, verbatim: *"Select your preferred Payment Method from the available payment methods: **COD/Visa/Master/Amex or bKash**."* Footer logos add `nagad.png`, `upay.jpg`; `rocket` also appears. **EMI:** *"EMI Offer is available on products priced above or equal to Tk. 5,000"*, and EMI/non-EMI items cannot share a cart. A location gate appears before the cart. Shipping step shows **per-shipment split charges**. Post-order: *"You will get an SMS & Email…"* and *"Our customer support team will contact you for processing the order."* Same/next-day delivery applies (Bangla) **only inside Dhaka city, only for selected vendors, only for orders confirmed before 10:00 AM** (`সকাল ১০:০০ এর আগে নিশ্চিত করা অর্ডার`).

**E. Tracking & returns.** **Tracking means logging in.** The footer `Track My Order` is `href="#"` (dead link), and `/order/history` **302s to `/login?ReturnUrl=%2Forder%2Fhistory`**. Returns policy at `/cancellation-and-returns`, published **bilingually**, with brutally tight terms quoted verbatim:

- *"If you find any item missing, or if anything is damaged, quality problem or wrong product please immediately return the package to your delivery agent. **After you accept the package, we are unable to receive claims for missing, wrong or defective items.**"* → effectively a **zero-day, inspect-at-the-door window**.
- *"The replacement request must be raised within **24 hours** after getting the delivery. (Applicable only for Food and Grocery item)"*
- *"In the event of a return, the **buyer shall be responsible for all transportation and courier charges** incurred."*
- *"**Product(s) cannot be returned once received by the customer.**"*
- Othoba may cancel any order within **48 hours**; stock-out cancel/deliver window **4–25 working days**; refunds **1–10 working days**, less an online gateway transaction fee on customer-cancelled prepaid orders.

Also found: `/same-day-delivery` (labelled "Next/Same day delivery TC") **302s to the homepage** — a broken footer link.

**F. Vouchers.** `Apply Coupon` lives **in the mini-cart**, not checkout. **No countdown timer, no flash-sale module, no collectible-voucher mechanic** — a grep for `voucher` on the PDP returned **0**. A clear capability gap versus Daraz. `Free delivery` appears as a card label but **no free-shipping threshold figure is stated anywhere [NV]**.

**G. SEO.** Flat single-segment category slugs (`/kids-toys`, `/mother-baby-toys`, `/indoor-toys`); PDP `/{long-slug}-{vendor-slug}-{productId}` — **vendor name baked into the product URL**. JSON-LD: `OnlineStore`, `ContactPoint`, a **fully populated `BreadcrumbList`** (4 `ListItem`s), **`Product`** and **`Offer`** including Google Merchant Center feed fields (`sku`, `mpn`, `google_product_category`, `product_type`, `custom_label_0/1`, and `offers` with `priceCurrency: "BDT"`, `price: "2499.00"`, `priceValidUntil: "2026-12-07"`, `itemCondition: NewCondition`, `availability: InStock`, `seller`). **Othoba's PDP structured data is strictly better than Daraz's.** Neither emits `aggregateRating` on the PDP.

Category title/H1/description are **coherent** (unlike Daraz), but the PDP title is malformed (`…Maxora shoponline shop bangladeshothoba.com | Othoba.com` — missing separators). **Open Graph: completely absent** — zero `og:*` or `twitter:*` on category or PDP. **Bangla:** `<html lang="en">`, `content-language: en-US`, no hreflang, **no Bangla UI** — yet Bangla is heavy in *content* (full Bangla returns policy, Bangla-first toy descriptions, Bangla footer labels). **Analytics: `GTM-5L4V35`** (20 occurrences) driving GA4 ecommerce `dataLayer` pushes; no bare `G-` or `fbq(` in server HTML (likely inside GTM). `robots.txt` is 30,477 bytes with **1,050 `Disallow` lines** and **no `Sitemap:` directive** — and **`/sitemap.xml` returns HTTP 200 with 0 bytes**, so Othoba has **no working XML sitemap at all**.

### Chaldal — checked and set aside

`chaldal.com/toys-sports` ("Toys & Sports") exists in a grocery nav beside Baby Care and Stationery, but **the listing is client-rendered and the SSR HTML contains no products** — the body renders the error placeholder *"Sorry! We have encountered a small problem. | Please try refreshing the page"*. React shell (1,191 `react` matches), **zero JSON-LD**. So facets, sorting and PDP structure are **[NV]**. Three signals were still readable: a flat **`Shipping Fee: ৳ 59`** in the cart header, a city gate (`Dhaka | Use my current Location | Change City`), and a first-class language toggle **`EN | বাং`**. Othoba was the better secondary benchmark (server-rendered, readable policies, a real toy taxonomy).

### What Daraz-class UX trains BD shoppers to expect

A shopper arriving at a niche toy store has been conditioned by the above to look for:

1. **Deep faceted filtering with toy-specific attributes** — not just price and brand. Daraz ships 15–16 facets including `Recommended Age`, `Recommended Gender`, `Interest` (with an explicit `STEM` bucket), `Character`, `Main Material`, `Color Family`.
2. **An origin / "Shipped From" signal**, used as a proxy for speed and trust (`Dhaka`…`Overseas`, `Local`).
3. **Rating value *and* review count on every card, before the click.** An unrated card reads as untrustworthy — which means showing `0.0` and `(0)`, as Othoba does, is worse than showing nothing.
4. **Units sold as social proof** (`"10 sold"`).
5. **Scarcity and stock state spelled out** (`Only {n} items left`, `Out of Stock`, per-SKU purchase caps).
6. **Variant pickers that swap the gallery** — image-backed swatches, not text dropdowns.
7. **A delivery ETA computed for *my* address, with a visible location switcher.** A static "3–5 days" string now reads as evasive.
8. **Explicit Dhaka vs outside-Dhaka handling, including a same-day cutoff time** (Othoba: before 10:00 AM, inside Dhaka city, selected vendors only). A single flat figure stated up-front is the norm (`৳60` Othoba, `৳59` Chaldal).
9. **COD as a headline reassurance**, printed in the delivery block rather than buried in policy.
10. **A familiar payment line-up including MFS** — `COD/Visa/Master/Amex or bKash`, plus Nagad/upay/Rocket logos.
11. **EMI on higher tickets** (Othoba: ≥ Tk 5,000) — above most toy price points, but expected on ride-ons and large playsets.
12. **Phone-first, OTP-verified identity, and effectively no guest checkout.** Email-first signup is now the unfamiliar pattern.
13. **Copy-paste voucher codes plus collectible vouchers**, redeemed at checkout.
14. **Countdown-driven flash sales with strikethrough + percent-off badges.**
15. **Bundle and multi-buy vocabulary as standard** — `Buy 1 get 1 free`, `Buy more and save up to {price}`, `Frequently Bought Together`, `Combo Price:`, `Free Gift`.
16. **"Free shipping" treated as a filterable product property** — though *neither marketplace publishes a minimum-spend figure* **[NV]**.
17. **Seller-answered Q&A on the PDP** — for toys, where age-suitability, piece count and safety questions land.
18. **Reviews with photos, helpfulness voting and abuse reporting.**
19. **A per-seller trust panel with hard percentages** (`% Positive Seller Ratings`, `Ship On Time %`). A single-brand store must fill that expected slot with an equivalent credibility block.
20. **Return and warranty terms shown on the PDP itself**, understood to vary per item.
21. **…but an extremely short, inspect-at-the-door return window**, with buyer-paid return freight. Shoppers are primed to expect almost no returns — so a real, stated window is a genuine trust advantage.
22. **"Tracking" meaning *log in*, not enter an order number.** Both marketplaces gate it; neither offers a public lookup.
23. **SMS order confirmation plus a human callback before fulfilment.** Phone is a first-class channel, not a fallback.
24. **Bangla at least in content** — and Daraz's catalogue Bangla is machine-translated and visibly broken.
25. **An app-download nag with an app-only price.** Shoppers now assume the web price is the *worse* price, so a web-equal price is worth saying out loud.
26. **Loyalty currency** (Daraz `Coins`, Chaldal `Egg Club` + Points).
27. **Recommendation rails everywhere**, separately tracked.
28. **A long-form SEO footer under the grid** with internal category links and a price table.

### Where a niche toy store can beat a marketplace

A niche store cannot win on price, breadth, delivery speed or voucher budget. It can win on the things a horizontal marketplace structurally cannot do — and, critically, on things **the three BD niche toy stores are also failing to do**, which is where the real opening sits:

1. **Age and developmental-stage as a first-class axis — the single clearest opening in the whole study.** Daraz *has* a `Recommended Age` facet but it is a seller-typed free-text field rendering ~30 overlapping, unsortable buckets (`"3 years"`, `"3 years and above"`, `"From 3 months +"`, `"0 - 12 years"`, `"4-10 years"`, `"14 Years and above"`, `"Other"`…), and it only appears on *sub*categories, not `/toys-games/` itself. Othoba has **no age filter at all**. Of the niche stores, only EduToyZone has one — and its filters are client-side with no shareable URLs. So **a canonical, sortable, combinable, URL-addressable age-band filter beats every competitor measured, marketplace and specialist alike.** Pair it with Baby Bazar's proven skill/therapy-goal axis (10 goals) and EduToyZone's learning-goal axis (5) and nothing in the market matches it.
2. **Curation and buyer trust in a category where quality is unverifiable.** Daraz toy listings are a flood of third-party SKUs — the dominant brand value on the blocks category is literally `no-brand-1`, and seller trust panels can read `Positive Seller Ratings 0%` / `Ship On Time 0%` (Othoba). A single-merchant store can guarantee age-appropriateness, materials, choking-hazard warnings and genuine stock — and say so. No competitor measured does this systematically.
3. **Expert, consultative help before purchase.** EduToyZone's WhatsApp/Messenger "Need help before ordering?" and Baby Bazar's "Hello Doctor" / child-specialist pages are the right instinct. A marketplace chat can only answer logistics questions; a specialist can answer *"is this right for my child?"*
4. **Gift-occasion merchandising.** Birthdays and Eid drive this category, yet **no competitor offers gift wrapping, a gift message, a gift finder or a price-band gift guide** (verified absent on all three). Baby Bazar has a "Gift Item" category and nothing behind it; EduToyZone has a dormant gift-card hook. Marketplaces do not do occasion merchandising for a single vertical. This is unclaimed.
5. **Content authority.** All three competitors have an empty or non-existent blog. Age guides, developmental-milestone explainers and Bangla parenting content are uncontested — and are the only durable organic channel against marketplace domain authority.
6. **Bundles and play-progression kits.** EduToyZone's "More Savings in a Bundle" / Combo Offer is the single most imitable commercial idea found. Marketplaces cannot assemble cross-SKU developmental kits; a specialist can.
7. **Transparent, zone-accurate delivery pricing shown early.** EduToyZone's 70/100/120৳ with Dhaka-subcity granularity already beats the marketplaces' flat figures (Othoba `Tk 60`, Chaldal `৳59`) and Daraz's unreadable charge policy. The bar to beat is *consistency* — EduToyZone's own free-delivery threshold contradicts itself three ways, and **neither marketplace publishes a free-shipping minimum at all**, so a clearly stated threshold is itself a differentiator.
8. **A real, stated returns window.** This is a bigger opening than it first looks. Othoba's published policy is **inspect-at-the-door with no window whatsoever** (*"After you accept the package, we are unable to receive claims for missing, wrong or defective items"*) **and buyer-paid return freight**; Daraz's return window could not be read at all because its Help Centre is an empty SPA. EduToyZone offers 24 hours with an unboxing photo and no change-of-mind returns. BD shoppers are therefore primed to expect *almost no* returns — which makes a genuine, plainly-worded window a disproportionate trust win.
9. **Public order tracking by order number + phone.** Both marketplaces gate tracking behind login (Othoba's `Track My Order` is a dead `href="#"`; Daraz's tracking URL 404s), and two of three niche stores have no tracking at all. EduToyZone's `/findorder` (Order ID + last 4 phone digits) is the right pattern and is **beatable only on polish, not concept** — so match it and add courier-level status.
10. **Reviews with real volume.** Marketplace shoppers are trained to read a rating *and* a count on every card. **No niche competitor has any reviews worth the name**, and — notably — **neither Daraz nor Othoba emits `aggregateRating` on its PDP either.** Being the only BD toy store earning review stars in search results is both a conversion and an SEO advantage no competitor currently holds.
11. **Checkout built for the actual market, with guest checkout as the differentiator.** EduToyZone's phone-first, email-optional, district-select, single-page COD checkout is the correct local pattern. Crucially, **Othoba mandates phone + OTP + password with no guest checkout**, and Daraz pushes login in-flow — so *frictionless guest COD ordering* is a genuine advantage over the marketplaces, not just parity with the niche stores. The counter-example remains instructive: Toy House BD collects **no phone number at all** while being COD-only.
12. **Honest web pricing.** Othoba dangles an `App-only price Tk 1,799.28` against a web price of `Tk 2,499` (~28% off) and Daraz pushes a first-order app voucher, so shoppers now assume the web price is the *worse* price. Charging the same on web and saying so is cheap and credible.
13. **Hand-written Bangla.** Daraz localises its whole catalogue layer but does it by machine — `Interest` becomes `সুদ` (financial interest), facet labels leak underscores (`ওয়ারেন্টি_ধরণ`), and homepage nav shows `ছাগল` (goat). Othoba has no Bangla UI at all. Hand-written Bangla product, age and safety copy is inexpensive and **beats Daraz's translation outright**.
14. **SEO openings that are simply unclaimed.** Daraz's PDP `Product` schema has **no `offers` and no `aggregateRating`, and its `BreadcrumbList` is empty**; its title/H1/description disagree (`"…Games & Puzzles… 2024"` vs H1 `"Baby Toys Price In Bangladesh"`, in Nov 2026); it emits **no `hreflang`** despite bilingual content. Othoba emits **zero Open Graph tags** and its **`/sitemap.xml` returns 0 bytes** with no `Sitemap:` directive in robots.txt. Complete, correct structured data plus working OG and sitemaps is a low-cost structural edge.
15. **Measurement.** Toy House BD runs zero analytics; Baby Bazar has a Meta Pixel but no GA4/GTM; only EduToyZone has a full GTM + GA4 + Pixel stack. (Daraz is the outlier in the other direction — no GA/GTM/Pixel at all, only Alibaba's first-party `aplus`.) A proper GTM + GA4 + Pixel setup is the normal BD independent-retailer baseline and, unglamorously, a competitive advantage in this set.

---

## Appendix — source artefacts

Raw HTML/JSON captures used for every claim above are retained under this session's scratchpad (session-scoped temporary files, not project files):

- Niche stores — `…/scratchpad/raw/`: `toyhouse-*`, `babybazar-*`/`bb-*`, `edutoy-*`/`ed-*`, `kidszone-*`, including the **session-authenticated cart and checkout captures** `th-check2.html`, `bb-check2.html`, `ed-cart3.html` and `ed-check3.html` (an item was added to each cart via a cookie jar so the real checkout fields, shipping rates and payment methods were visible; no orders were placed).
- Marketplaces — `…/scratchpad/mkt/`: `mkt-daraz-*` (including `mkt-daraz-toys-ajax.json`, `mkt-daraz-toys-bn.json`, `mkt-daraz-pdp-moduledata.json`, `mkt-daraz-i18n.json`, `mkt-daraz-sm-cat2.xml`), `mkt-othoba-*`, `mkt-chaldal-*`.

**Coverage caveat.** The niche-store findings are first-hand and were re-verified where an inference looked shaky (two early guesses — a suspected template-escaping bug on Toy House and a "Chamber of Commerce" logo on Baby Bazar — were checked and corrected; the first was an artefact of the extraction regex, the second is in fact ECAB). The marketplace findings were gathered by a delegated agent using the same raw-fetch method and carry its **[V]**/**[NV]** tags; the **[NV]** items are genuinely unknown, chiefly because Daraz's Help Centre, returns, payment-options and order pages all serve empty SPA shells or one identical 149 KB fallback page.
