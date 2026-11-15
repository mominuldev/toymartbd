# Storefront cache revalidation contract

Laravel tells the Next.js storefront which cached pages are stale after a write. The storefront
renders catalog pages with ISR and cache tags; this call drops exactly the affected tags.

## Request (Laravel → Next.js)

```
POST {STOREFRONT_URL}/api/revalidate
Content-Type: application/json
X-Revalidate-Timestamp: 1762871234            (unix seconds, set by the sender)
X-Revalidate-Signature: sha256=<hex>          (HMAC-SHA256 over "<timestamp>.<raw body>")

{"tags": ["brands", "brand:lego"]}
```

- The secret is `STOREFRONT_REVALIDATE_SECRET`, identical on both sides. It never reaches a browser.
- The signature covers the timestamp and the raw body bytes exactly as sent.

## Receiver rules (Next.js route handler)

1. Read the raw body **before** parsing. Recompute the HMAC and compare in constant time.
   A missing or invalid signature is a **401** — nothing is parsed or revalidated.
2. Reject a timestamp more than **300 seconds** from now (either direction) with **401** (replay guard).
3. Body must be `{"tags": string[]}`, 1–50 tags, each matching `^[a-z0-9:_-]{1,100}$`. Otherwise **400**.
4. Call `revalidateTag(tag)` for each tag, then answer **200** `{"revalidated": [...tags]}`.

## Sender rules (Laravel)

- Sent from a **queued job after the database commit** (CLAUDE.md rule 4), never inside a transaction.
- Retries with backoff on network errors and 5xx. A 4xx is a bug and is not retried.
- If `STOREFRONT_URL` or the secret is empty (local without the storefront), the job logs at debug level and does nothing.

## Tag vocabulary

| Tag | Meaning |
|---|---|
| `brands` | any brand list |
| `brand:{slug}` | one brand page |
| `categories` | the category tree (mega menu, `GET /categories/tree`) and any category list; sent on every category create, edit, move or archive |
| `category:{slug}` | one category page. Sent for the changed category, its old slug after a slug change, and — after a move, rename or slug change — every descendant, whose breadcrumbs changed with it. Category slugs are ≤ 91 characters so the tag fits the 100-character limit |
| `products` | any product list or grid (listings, home rails, search results); sent on every product create, edit, variant/price change or archive, and on every stock change (`StockLevelChanged`: cards carry `in_stock`, listings an availability facet). Also sent alone by an attribute edit, a value rename and a value reorder (`AttributeController` / `AttributeValueController`, after the Action commits): product pages name their variant axes and options in attribute/value order, and every product page carries `products` (listing facets do not use attributes). Attribute/value create and delete send nothing — only an unused one can be deleted |
| `product:{slug}` | one product page; after a slug change the old slug is sent too; also on every stock change of one of its variants. Product slugs are ≤ 92 characters so the tag fits the 100-character limit |
| `flash-sale` | the `/flash-sale` page and any flash-sale rail (`GET /flash-sales`). Sent with `products` and each sale product's `product:{slug}` on every promotion save/deactivate and at each start/end boundary (`toymart:promotions:boundaries`, every minute) — P7 |
| `content:home` | the CMS homepage (`GET /storefront/content/home`). Sent on every publish and revert of the homepage, and by `toymart:content:revalidate-scheduled` (every minute) when a scheduled block of the live version starts or ends (docs/api/cms-blocks.md) |
| `delivery` | delivery reference data — `GET /delivery-rates`, `/districts`, `/districts/{id}/upazilas` (P4-STO-06); the PDP delivery caption and the `/delivery-charge` page read it. Sent through `DeliveryReferenceDataChanged` → `RevalidateDeliveryData` (queued, after commit) by: a rate-card save that changes a zone base charge, the included weight or the per-kg step (`UpdateCourierRateCard`; COD fee and return charge are what the courier bills us and send nothing); a courier switched on/off or made default — the default prices every quote (`UpdateCourierProviderStatus`, only when a flag actually changes); every free-delivery threshold save (`UpdateFreeDeliveryThreshold`, `PUT /settings/delivery`); a Delivery areas & timing save that changes the cut-off, ETA days, metro/suburb districts, not-serviced upazilas or the COD advance rule (`UpdateDeliveryAreaSettings`, `PUT /settings/delivery-areas`, reason `delivery_areas`; an unchanged save sends nothing). Courier credentials and courier-location mapping send nothing (neither is read by these endpoints). `delivery.non_working_weekdays` and `delivery.holidays` have no admin editor yet — whatever editor writes them must dispatch `DeliveryReferenceDataChanged`. `GET /delivery-quote` is never cached |
| `content:blog` | every blog/guide list: `/blog`, `/guides`, blog category/tag/author pages and the home-page blog teaser (`GET /blog/posts`, `/blog/teaser`, `/blog/categories…`). Sent on every post save/delete, every blog category/tag/author edit, and by the scheduled-publish sweep (`toymart:blog:publish-due`, every minute) when a scheduled post goes live |
| `content:post:{id}` | one blog post or buying-guide page (`GET /blog/posts/{slug}`). Id-based, so a slug change needs no old-slug tag. Sent with `content:blog` for the saved post, every post of an edited or deleted term, and each post the sweep publishes. The preview route (`/blog/preview/{token}`) is never cached |
| `seo` | any SEO document fetch (`GET /storefront/seo/{type}/{slug}`); sent on every SEO panel save, with the page's `{type}:{slug}` (`page:x`, `blog_post:x`, …) — docs/api/seo.md. A static page's SEO document is tagged `seo` + `page:{slug}`; **every static-page save sends `page:{slug}` too** (and the old slug's after a slug change), and a page SEO-panel save also sends `content:pages` + `content:page:{slug}` — so a write to either the page or its SEO reaches both the body and the `<head>` (`StoreContentTags::pageAll`) |
| `redirects` | the redirect map the proxy loads; sent on every slug-history write and every manual redirect change |
| `sitemaps` | the sitemap index and files; sent after every sitemap build |
| `robots` | robots.txt; sent on every robots.txt save |
| `content:pages` | every static-page fetch (`GET /pages` list and each `GET /pages/{slug}`); sent on every static-page save, archive or SEO save (page form or SEO panel) |
| `content:page:{slug}` | one static page (`/{slug}` at the site root, including `/about` for the CMS `about` page); always sent together with `page:{slug}` (the page's SEO document); after a slug change the old slug's pair is sent too; also sent when the page's sections are published or reverted and at their schedule boundaries (docs/api/cms-blocks.md), by a page SEO-panel save and by `toymart:content:revalidate-scheduled` (every minute) when a scheduled page's publish time passes. Page slugs are ≤ 94 characters so the tag fits |
| `content:menus` | the published header/footer/mobile menus (`GET /menus`, read by every page's chrome); sent on every menu save and every static-page change (menu links resolve a page's slug and hide a page that is not live) |
| `content:banners` | every banner fetch (`GET /banners?placement=…`: announcement bar, category-page banner, cart/checkout info strip, popup); sent on every banner save/delete and by `toymart:content:revalidate-scheduled` when a banner's start or end passes. The storefront also caps these fetches at 300 s |
| `content:testimonials` | published testimonials (`GET /testimonials`, the homepage testimonials block); sent on every testimonial save, delete or reorder |
| `store-info` | `GET /storefront/store-info` — the shop's contact and legal details (hotline, WhatsApp, Messenger Page, email, address, trade licence, BIN) from Admin → Settings → Store → Business. Sent by `BusinessDetailsController` and `InvoiceSettingsController` on every save (queued, after commit) |
| `tracking-config` | `GET /storefront/tracking/config` (public GA4/Meta/GTM ids, consent mode, purchase trigger). Sent by `UpdateTrackingSettings` on every admin Tracking save that changes something (`RevalidateTrackingConfig`) — docs/api/tracking.md §1.4 |

New tags are added here first, then on both sides.
