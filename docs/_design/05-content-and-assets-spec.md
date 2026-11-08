# 05 — Content & Asset Specification

**Toy Mart BD** · Design Agent 5 · **Date:** 2026-11-08 · **Status:** Ready for native-Bangla review, then Figma entry

Scope: the complete bilingual string set for the MVP storefront, the notification templates with their real SMS cost, the full icon inventory, the eleven illustrated states, and the photography brief. This document contains no Figma writes — the lead owns those. It is the content source of truth: strings go from here into `locales/en.json` and `locales/bn.json` unchanged.

Upstream decisions this document implements: `UI-UX-DESIGN-SPEC.md` §2 (brand direction), §3.4 (iconography and illustration), §4 (component library), §5 (screen inventory); `PRODUCT-REQUIREMENTS.md` §5 (Bangladesh decisions) and §6 items 4, 4a, 4b.

---

## 0. Writing rules that govern every string below

**0.1 Voice.** Warm and plain. Competent, not cheerful. The test: would a shop owner who has sold toys for fifteen years say this to a parent's face? "Delivery in 2–3 days inside Dhaka" passes. "Yay! Your toys are zooming to you!" fails. No exclamation marks except in one place — `order.success` — and even there only one.

**0.2 Bangla register.** আপনি throughout, never তুমি. Imperative verbs in the -উন form (`করুন`, `দেখুন`, `লিখুন`), never -ও. Sentence-final punctuation is the দাড়ি `।`; `?` and `!` stay Latin; the comma stays Latin. No Sanskritised formality (`ক্রয় করুন` → `কিনুন`), and no English word left in Latin script where a Bangladeshi parent would say the Bangla one.

**0.3 Loanwords are allowed — calques are not.** Where Bangladeshi shoppers say the English word, we transliterate it: কার্ট, অর্ডার, ডেলিভারি, রিভিউ, কুপন, ক্যাটাগরি, ক্যাশ অন ডেলিভারি, ব্র্যান্ড, স্টক. Where they say the Bangla word, we use it: খেলনা, দাম, ছাড়, মোট, ঠিকানা, বয়স, পরিমাণ. The failure mode we are explicitly beating is Daraz's dictionary-first machine translation — `Interest` → `সুদ` (financial interest), `Goat` → `ছাগল` in navigation. Banned patterns, for the reviewer's checklist:

| Do not write | Write | Why |
|---|---|---|
| `স্বার্থ` / `সুদ` for "Interest" | `পছন্দ` / `আগ্রহ` | The Daraz bug itself |
| `গাড়ী` | `গাড়ি` | Modern BD orthography |
| `ক্রয় করুন` | `কিনুন` | Register |
| `প্রদান করুন` | `দিন` | Register |
| `অনুগ্রহপূর্বক` | `দয়া করে` (and usually: delete it) | West-Bengal formal |
| `ঝুড়ি` for cart | `কার্ট` | Nobody says ঝুড়ি for an online cart |
| `মূল্য হ্রাস` | `ছাড়` | Register |
| `সন্তান` in UI chrome | `বাচ্চা` | সন্তান is for legal/profile copy only |
| Bengali numerals `০–৯` anywhere transactional | `0–9` | PRD §6 item 4a |

**0.4 Numerals and money.** Western digits (0–9) in both locales, everywhere: prices, phone numbers, quantities, order numbers, dates, ratings, review counts, age ranges. Bengali numerals appear only inside editorial prose (blog posts, About page narrative) and never inside a component. Money renders through one formatter: `৳2,450` — symbol U+09F3, no space, comma thousand separators, no decimals unless non-zero. In **SMS only**, money renders as `BDT 2450`: the `৳` glyph is outside GSM-7 and would force an English SMS into UCS-2, doubling its cost for one character. Dates render `8 Nov 2026` / `8 নভেম্বর 2026`, Asia/Dhaka. Time renders `4:30 PM` / `বিকাল 4:30`.

**0.5 Length.** Bangla runs longer in buttons and labels, and Anek Bangla needs 1.15× line-height. The `notes` column flags every string where Bangla exceeds English by more than 40%, or where the Bangla exceeds the component budget. Budgets, measured at 390px mobile:

| Component | EN budget | BN budget | Overflow behaviour |
|---|---|---|---|
| Primary CTA (full width) | 28 chars | 32 chars | Two lines at 1.15 LH, never truncate |
| Secondary button | 18 chars | 20 chars | Two lines |
| Bottom tab bar label | 9 chars | 8 chars | **Icon + single short word only.** Flagged per label below |
| Badge / pill | 12 chars | 11 chars | Drop to icon-only |
| Filter facet label | 20 chars | 22 chars | Wrap |
| Form field label | 24 chars | 28 chars | Wrap |
| Toast | 60 chars | 66 chars | Two lines max, then shorten |

**0.6 Key naming.** `surface.component.element[.state]`, all lowercase, dot-separated, `snake_case` inside a segment. Placeholders are ICU: `{count}`, `{amount}`, `{district}`, `{days}`, `{name}`, `{n}`, `{max}`, `{code}`. Pluralisation uses ICU `plural` in English; **Bangla has no plural agreement in these strings, so the Bangla form is written once and takes `{count}` directly** — this is a real simplification, not an omission.

**0.7 `[review]` marker.** Where a Bangla term is my best judgement and a native reviewer should confirm or replace it, the `notes` column carries a `[review: <term>]` marker — nine of these, on the terms whose rightness depends on context the reader has in front of them. **§A.18 is the authoritative list and holds all 14 flagged terms**, including five that read fine in isolation but are judgement calls across the whole set. Nothing is blank and nothing says TBD.

---

## Part A — The bilingual UI string set

**Total: 642 UI strings + 74 notification template fields.** Counted row by row in §A.19.

### A.1 Navigation and mega menu (34)

| key | en | bn | notes |
|---|---|---|---|
| `nav.skip_to_content` | Skip to content | মূল অংশে যান | Visually hidden |
| `nav.logo.aria` | Toy Mart BD — home | টয় মার্ট বিডি — হোম | Brand name is never translated |
| `nav.tab.home` | Home | হোম | Tab bar, 4 chars BN — fits |
| `nav.tab.categories` | Categories | ক্যাটাগরি | BN 9 chars at tab budget 8 — **set tab label to `ক্যাটাগরি` at 11px, not 12px** |
| `nav.tab.search` | Search | খুঁজুন | |
| `nav.tab.cart` | Cart | কার্ট | |
| `nav.tab.account` | Account | অ্যাকাউন্ট | BN 10 chars — over tab budget. Use `প্রোফাইল` (8) in the tab bar only; key `nav.tab.account.short` |
| `nav.tab.account.short` | You | প্রোফাইল | Tab bar override |
| `nav.link.all_categories` | All categories | সব ক্যাটাগরি | |
| `nav.link.new_arrivals` | New arrivals | নতুন এসেছে | Not `নতুন আগমন` — that is a calque |
| `nav.link.best_sellers` | Best sellers | বেশি বিক্রি হচ্ছে | BN +64%. Mega-menu row only, never a button |
| `nav.link.shop_by_age` | Shop by age | বয়স অনুযায়ী | |
| `nav.link.offers` | Offers | ছাড় ও অফার | |
| `nav.link.brands` | Brands | ব্র্যান্ড | |
| `nav.link.blog` | Toy guide | খেলনা গাইড | "Blog" reads as empty to a BD parent; "guide" sets the expectation we actually meet |
| `nav.link.track_order` | Track order | অর্ডার ট্র্যাক | BN short form deliberate; full `অর্ডার ট্র্যাক করুন` used on the page itself |
| `nav.link.help` | Help | সহায়তা | |
| `nav.link.wishlist` | Wishlist | পছন্দের তালিকা | BN +80%. Icon-only in the header; full text in account nav. `[review: পছন্দের তালিকা]` — `উইশলিস্ট` is also widely understood |
| `nav.lang.toggle.aria` | Change language | ভাষা বদলান | |
| `nav.lang.to_bn` | বাংলা | বাংলা | Always in the target language, never translated |
| `nav.lang.to_en` | English | English | |
| `nav.cart.aria` | Cart, {count} items | কার্ট, {count}টি পণ্য | |
| `nav.cart.empty.aria` | Cart, empty | কার্ট, খালি | |
| `nav.menu.open` | Open menu | মেনু খুলুন | |
| `nav.menu.close` | Close | বন্ধ করুন | |
| `nav.drawer.title` | Browse | ঘুরে দেখুন | |
| `nav.megamenu.view_all` | All in {category} | {category}-এর সবকিছু | |
| `nav.megamenu.featured` | Picked for you | বাছাই করা | Not `আপনার জন্য নির্বাচিত` — too long and too formal |
| `nav.megamenu.promo.eyebrow` | This week | এই সপ্তাহে | |
| `nav.login_cta` | Log in | লগইন | |
| `nav.account.greeting` | Hello, {name} | {name}, স্বাগতম | Bangla puts the name first — do not reorder the placeholder back |
| `nav.announcement.delivery` | Free delivery over ৳2,000 | ৳2,000-এর বেশি কিনলে ডেলিভারি ফ্রি | BN +44%. Announcement bar scrolls on mobile; acceptable |
| `nav.announcement.cod` | Cash on delivery across Bangladesh | সারা দেশে ক্যাশ অন ডেলিভারি | |
| `nav.breadcrumb.aria` | Breadcrumb | আপনি কোথায় আছেন | BN is descriptive on purpose — "breadcrumb" has no usable Bangla |

### A.2 Search (24)

| key | en | bn | notes |
|---|---|---|---|
| `search.placeholder` | Search toys, brands, age | খেলনা, ব্র্যান্ড বা বয়স লিখুন | BN +46% — fits the 320px input at 15px |
| `search.placeholder.short` | Search toys | খেলনা খুঁজুন | Mobile, under 360px |
| `search.aria.label` | Search | খুঁজুন | |
| `search.submit` | Search | খুঁজুন | |
| `search.clear` | Clear | মুছুন | |
| `search.min_chars` | Type at least 2 letters | কমপক্ষে 2টি অক্ষর লিখুন | |
| `search.loading` | Looking… | খুঁজছি… | |
| `search.recent.title` | Recent searches | আগে যা খুঁজেছেন | |
| `search.recent.clear` | Clear all | সব মুছুন | |
| `search.suggestions.products` | Toys | খেলনা | Group heading |
| `search.suggestions.categories` | Categories | ক্যাটাগরি | |
| `search.suggestions.brands` | Brands | ব্র্যান্ড | |
| `search.suggestions.aria` | {count} suggestions | {count}টি সাজেশন | `aria-live=polite` |
| `search.results.title` | Results for "{query}" | "{query}" — যা পাওয়া গেল | |
| `search.results.count` | {count} toys | {count}টি খেলনা | |
| `search.results.count.one` | 1 toy | 1টি খেলনা | |
| `search.correction.did_you_mean` | Did you mean {suggestion}? | আপনি কি {suggestion} খুঁজছেন? | |
| `search.correction.showing_for` | Showing results for {corrected} | {corrected}-এর ফলাফল দেখানো হচ্ছে | |
| `search.correction.search_instead` | Search for {original} instead | বরং {original} খুঁজুন | |
| `search.banglish.hint` | Bangla or English — both work | বাংলা বা ইংরেজি, দুটোতেই খোঁজা যায় | Shown once, under the first empty focus of the session |
| `search.zero.title` | No toys matched "{query}" | "{query}" দিয়ে কোনো খেলনা পাওয়া গেল না | See §C.4 for the illustrated state |
| `search.zero.body` | Check the spelling, try a shorter word, or browse the category below. | বানান দেখে নিন, ছোট শব্দ দিয়ে খুঁজুন, বা নিচের ক্যাটাগরি দেখুন। | |
| `search.zero.try_category` | Closest category: {category} | কাছাকাছি ক্যাটাগরি: {category} | |
| `search.zero.bestsellers` | Parents are buying these | অভিভাবকেরা এগুলো কিনছেন | Not "popular products" — naming the buyer is the trust signal |

### A.3 Product card (20)

| key | en | bn | notes |
|---|---|---|---|
| `product.card.add_to_cart` | Add to cart | কার্টে নিন | BN is shorter than EN here — good, this is the tightest button on the grid |
| `product.card.added` | In cart | কার্টে আছে | |
| `product.card.view` | View | দেখুন | |
| `product.card.out_of_stock` | Out of stock | স্টকে নেই | |
| `product.card.notify_me` | Notify me | জানিয়ে দিন | |
| `product.card.wishlist.add` | Save to wishlist | পছন্দে রাখুন | `aria-label`; icon-only button |
| `product.card.wishlist.remove` | Remove from wishlist | পছন্দ থেকে সরান | |
| `product.card.badge.sale` | −{percent}% | −{percent}% | Identical in both locales — numeral rule |
| `product.card.badge.new` | New | নতুন | |
| `product.card.badge.bestseller` | Top seller | বেশি বিক্রি | Badge budget forces the short BN form |
| `product.card.badge.low_stock` | Only {count} left | আর {count}টি আছে | |
| `product.card.badge.out_of_stock` | Out of stock | স্টকে নেই | |
| `product.card.badge.free_delivery` | Free delivery | ফ্রি ডেলিভারি | BN 14 chars — over badge budget 11. **Use the truck icon + `ফ্রি` at ≤360px** |
| `product.card.badge.free_delivery.short` | Free | ফ্রি | Narrow-viewport override |
| `product.card.rating.aria` | {rating} out of 5, {count} reviews | 5-এর মধ্যে {rating}, {count}টি রিভিউ | |
| `product.card.rating.none` | No reviews yet | এখনো রিভিউ নেই | |
| `product.card.age` | {min}–{max} yrs | {min}–{max} বছর | En-dash in both; Western numerals |
| `product.card.age.plus` | {min}+ yrs | {min}+ বছর | |
| `product.card.price.was` | was {amount} | আগে ছিল {amount} | `aria` text for the struck price |
| `product.card.price.save` | Save {amount} | {amount} বাঁচল | `[review: বাঁচল]` — `সেভ` is the common spoken form but reads cheap |

### A.4 Product detail (94)

| key | en | bn | notes |
|---|---|---|---|
| `pdp.brand_label` | Brand | ব্র্যান্ড | |
| `pdp.sku_label` | Product code | প্রোডাক্ট কোড | Not `এসকেইউ` — meaningless to a shopper |
| `pdp.rating.count` | {count} reviews | {count}টি রিভিউ | |
| `pdp.rating.write` | Write a review | রিভিউ লিখুন | |
| `pdp.rating.jump` | Read reviews | রিভিউ পড়ুন | Anchor link |
| `pdp.price.current.aria` | Price {amount} | দাম {amount} | |
| `pdp.price.was.aria` | Original price {amount} | আসল দাম {amount} | |
| `pdp.price.discount` | −{percent}% | −{percent}% | |
| `pdp.price.save_note` | You save {amount} | আপনার {amount} সাশ্রয় | `[review: সাশ্রয়]` |
| `pdp.price.inclusive` | Price includes VAT | দামের সঙ্গে ভ্যাট ধরা আছে | |
| `pdp.age.label` | Suitable age | কত বছরের জন্য | Literal BN ("for how many years") reads better than `উপযুক্ত বয়স` |
| `pdp.age.value` | {min}–{max} years | {min}–{max} বছর | |
| `pdp.age.plus` | {min} years and up | {min} বছর থেকে | |
| `pdp.age.under_3_warning` | Small parts. Not for children under 3. | ছোট অংশ আছে। 3 বছরের কম বয়সীদের জন্য নয়। | Legally load-bearing — reviewer must not soften this |
| `pdp.age.guide_link` | How we judge age | বয়স কীভাবে ঠিক করি | |
| `pdp.age.modal.title` | Age guidance | বয়স নির্দেশিকা | |
| `pdp.age.modal.body` | The age range comes from the manufacturer and from the smallest part in the box. When a toy sits between two ranges we show the higher one. | বয়সের সীমা নির্মাতার দেওয়া তথ্য আর বাক্সের সবচেয়ে ছোট অংশটি দেখে ঠিক করা হয়। দুই সীমার মাঝে পড়লে আমরা বড় বয়সটাই দেখাই। | |
| `pdp.stock.in` | In stock | স্টকে আছে | |
| `pdp.stock.low` | Only {count} left | আর {count}টি আছে | |
| `pdp.stock.out` | Out of stock | স্টকে নেই | |
| `pdp.stock.out_note` | We restock this regularly. Leave your number and we will text you. | এটা নিয়মিত আসে। নম্বর দিয়ে রাখুন, স্টকে এলে এসএমএস করব। | Never a dead end — DESIGN-SPEC §6.2 |
| `pdp.stock.preorder` | Pre-order — ships {date} | প্রি-অর্ডার — {date} পাঠানো হবে | |
| `pdp.variant.color.label` | Colour | রঙ | |
| `pdp.variant.size.label` | Size | সাইজ | |
| `pdp.variant.set.label` | Set | সেট | |
| `pdp.variant.select_prompt` | Choose {attribute} | {attribute} বেছে নিন | |
| `pdp.variant.selected.aria` | {attribute}: {value}, selected | {attribute}: {value}, বেছে নেওয়া হয়েছে | |
| `pdp.variant.unavailable` | Out of stock | স্টকে নেই | Disabled, visible, never hidden |
| `pdp.variant.unavailable_reason` | {value} is out of stock in this size | এই সাইজে {value} স্টকে নেই | Shown on tap of a disabled option |
| `pdp.quantity.label` | Quantity | পরিমাণ | |
| `pdp.quantity.increase` | Add one | একটি বাড়ান | `aria-label` |
| `pdp.quantity.decrease` | Remove one | একটি কমান | |
| `pdp.quantity.max_reached` | {max} is all we have right now | এখন আমাদের কাছে {max}টিই আছে | |
| `pdp.delivery.title` | Delivery to your area | আপনার এলাকায় ডেলিভারি | |
| `pdp.delivery.select_district` | District | জেলা | |
| `pdp.delivery.select_district.placeholder` | Choose district | জেলা বেছে নিন | |
| `pdp.delivery.select_upazila` | Upazila / thana | উপজেলা / থানা | |
| `pdp.delivery.select_upazila.placeholder` | Choose upazila | উপজেলা বেছে নিন | |
| `pdp.delivery.check` | Check | দেখুন | |
| `pdp.delivery.loading` | Checking… | দেখছি… | |
| `pdp.delivery.charge` | Delivery charge {amount} | ডেলিভারি চার্জ {amount} | |
| `pdp.delivery.charge.free` | Delivery free | ডেলিভারি ফ্রি | |
| `pdp.delivery.eta.dhaka` | Arrives in 2–3 days inside Dhaka | ঢাকার ভেতরে 2–3 দিনে পৌঁছাবে | The spec's reference sentence |
| `pdp.delivery.eta.outside` | Arrives in {days} days in {district} | {district}-এ {days} দিনে পৌঁছাবে | |
| `pdp.delivery.cod_available` | Cash on delivery available here | এখানে ক্যাশ অন ডেলিভারি চলবে | |
| `pdp.delivery.cod_advance` | This area needs {amount} paid in advance | এই এলাকার জন্য {amount} অগ্রিম দিতে হবে | Stated here, never first revealed at checkout. `[review: অগ্রিম]` |
| `pdp.delivery.remembered` | Showing for {district} | {district}-এর হিসাব দেখানো হচ্ছে | |
| `pdp.delivery.change` | Change area | এলাকা বদলান | |
| `pdp.delivery.unserved.title` | We don't deliver to {upazila} yet | {upazila}-এ আমরা এখনো ডেলিভারি দিই না | The "we don't deliver there" state. Honest, dated, with a way out |
| `pdp.delivery.unserved.body` | Our couriers don't cover this upazila. If someone can receive the parcel at another address, you can still order. Or message us — we sometimes arrange it. | আমাদের কুরিয়ার এই উপজেলায় যায় না। অন্য ঠিকানায় কেউ পার্সেল নিতে পারলে অর্ডার করা যাবে। অথবা আমাদের মেসেজ করুন — মাঝে মাঝে ব্যবস্থা করা যায়। | |
| `pdp.delivery.unserved.cta` | Message us on WhatsApp | হোয়াটসঅ্যাপে মেসেজ দিন | |
| `pdp.delivery.unserved.alt` | Use a different area | অন্য এলাকা দিন | |
| `pdp.delivery.error` | Couldn't check just now. Delivery charge will show at checkout. | এখন দেখা গেল না। চেকআউটে ডেলিভারি চার্জ দেখতে পাবেন। | |
| `pdp.add_to_cart` | Add to cart | কার্টে নিন | |
| `pdp.buy_now` | Buy now | এখনই কিনুন | |
| `pdp.added.toast` | Added to cart | কার্টে যোগ হয়েছে | |
| `pdp.go_to_cart` | Go to cart | কার্টে যান | |
| `pdp.notify.title` | Tell me when it's back | স্টকে এলে জানান | |
| `pdp.notify.placeholder` | 01XXXXXXXXX | 01XXXXXXXXX | Never localise a format mask |
| `pdp.notify.submit` | Notify me | জানিয়ে দিন | |
| `pdp.notify.success` | We'll text you the day it arrives. | যেদিন আসবে সেদিনই এসএমএস করব। | |
| `pdp.wishlist.add` | Save for later | পছন্দে রাখুন | |
| `pdp.wishlist.remove` | Saved | পছন্দে আছে | |
| `pdp.share.label` | Share | শেয়ার করুন | |
| `pdp.share.copied` | Link copied | লিংক কপি হয়েছে | |
| `pdp.tab.description` | About this toy | খেলনাটি সম্পর্কে | |
| `pdp.tab.specifications` | Details | বিস্তারিত | |
| `pdp.tab.age_guidance` | Age & safety | বয়স ও নিরাপত্তা | |
| `pdp.tab.reviews` | Reviews ({count}) | রিভিউ ({count}) | |
| `pdp.tab.delivery_returns` | Delivery & return | ডেলিভারি ও রিটার্ন | |
| `pdp.spec.material` | Material | উপাদান | |
| `pdp.spec.battery` | Batteries | ব্যাটারি | |
| `pdp.spec.battery.included` | Included | সঙ্গে আছে | |
| `pdp.spec.battery.not_included` | Not included | সঙ্গে নেই | |
| `pdp.spec.dimensions` | Size (L×W×H) | মাপ (দৈর্ঘ্য×প্রস্থ×উচ্চতা) | BN +120%. Spec table is two-column and wraps — acceptable |
| `pdp.spec.weight` | Weight | ওজন | |
| `pdp.spec.origin` | Made in | তৈরি হয়েছে | |
| `pdp.spec.warranty` | Warranty | ওয়ারেন্টি | |
| `pdp.spec.box_contents` | In the box | বাক্সে কী আছে | |
| `pdp.spec.safety_note` | Safety note | নিরাপত্তা তথ্য | |
| `pdp.spec.certification` | Certification | সনদ | `[review: সনদ]` — BSTI's own Bangla usage should be matched once confirmed |
| `pdp.safety.bsti` | BSTI certified | বিএসটিআই সনদপ্রাপ্ত | Show only where we hold the document |
| `pdp.gallery.aria` | {product} — image {n} of {total} | {product} — ছবি {n}/{total} | |
| `pdp.gallery.thumbnail.aria` | Show image {n} | {n} নম্বর ছবি দেখুন | |
| `pdp.gallery.zoom` | Tap to zoom | জুম করতে চাপ দিন | |
| `pdp.gallery.video` | Watch video | ভিডিও দেখুন | |
| `pdp.gallery.prev` | Previous image | আগের ছবি | |
| `pdp.gallery.next` | Next image | পরের ছবি | |
| `pdp.gallery.close` | Close | বন্ধ করুন | |
| `pdp.sticky.summary` | {variant} · {amount} | {variant} · {amount} | |
| `pdp.related.title` | Parents also looked at | অভিভাবকেরা এগুলোও দেখেছেন | |
| `pdp.recently_viewed.title` | You looked at these | আপনি এগুলো দেখেছেন | |
| `pdp.fbt.title` | Often bought together | একসঙ্গে প্রায়ই কেনা হয় | |
| `pdp.fbt.add_all` | Add all {count} · {amount} | {count}টিই নিন · {amount} | |

### A.5 Cart (33)

| key | en | bn | notes |
|---|---|---|---|
| `cart.title` | Your cart | আপনার কার্ট | |
| `cart.items.count` | {count} items | {count}টি পণ্য | |
| `cart.items.count.one` | 1 item | 1টি পণ্য | |
| `cart.line.variant` | {attribute}: {value} | {attribute}: {value} | |
| `cart.line.unit_price` | {amount} each | প্রতিটি {amount} | |
| `cart.line.total.aria` | Line total {amount} | এই পণ্যের মোট {amount} | |
| `cart.line.quantity.label` | Quantity | পরিমাণ | |
| `cart.line.remove` | Remove | সরান | |
| `cart.line.remove.confirm` | Remove {product} from the cart? | কার্ট থেকে {product} সরাবেন? | |
| `cart.line.removed.toast` | Removed. | সরানো হয়েছে। | Paired with undo |
| `cart.line.removed.undo` | Undo | ফিরিয়ে আনুন | |
| `cart.line.unavailable` | No longer available | আর পাওয়া যাচ্ছে না | |
| `cart.line.stock_reduced` | Only {max} left — quantity updated | আর {max}টি আছে — পরিমাণ বদলে দেওয়া হয়েছে | |
| `cart.line.price_changed` | Price changed from {old} to {new} | দাম {old} থেকে {new} হয়েছে | Also a validation rule — §A.17 |
| `cart.summary.title` | Order summary | অর্ডারের হিসাব | |
| `cart.summary.subtotal` | Items total | পণ্যের মোট দাম | Not `উপমোট` — that is dictionary Bangla |
| `cart.summary.delivery` | Delivery | ডেলিভারি | |
| `cart.summary.delivery.tbd` | Shown at checkout | চেকআউটে দেখাবে | |
| `cart.summary.delivery.free` | Free | ফ্রি | |
| `cart.summary.discount` | Discount | ছাড় | |
| `cart.summary.total` | Total | সর্বমোট | |
| `cart.summary.savings` | You save {amount} | আপনার {amount} সাশ্রয় | |
| `cart.coupon.toggle` | Have a coupon? | কুপন আছে? | Collapsed by default — DESIGN-SPEC §6.1 |
| `cart.coupon.placeholder` | Enter code | কোড লিখুন | |
| `cart.coupon.apply` | Apply | প্রয়োগ করুন | |
| `cart.coupon.applied` | {code} applied | {code} প্রয়োগ হয়েছে | |
| `cart.coupon.remove` | Remove coupon | কুপন সরান | |
| `cart.checkout_cta` | Checkout · {amount} | অর্ডার করুন · {amount} | |
| `cart.continue_shopping` | Keep shopping | আরও দেখুন | |
| `cart.free_delivery_progress` | Add {amount} more for free delivery | আরও {amount}-এর কিনলে ডেলিভারি ফ্রি | |
| `cart.free_delivery_reached` | Delivery is free on this order | এই অর্ডারে ডেলিভারি ফ্রি | |
| `cart.save_notice` | Your cart stays here for 30 days. | আপনার কার্ট 30 দিন থাকবে। | |
| `cart.cod_note` | You can pay cash when it arrives. | পণ্য হাতে পেয়ে টাকা দিতে পারবেন। | |

### A.6 Checkout (73)

| key | en | bn | notes |
|---|---|---|---|
| `checkout.title` | Checkout | অর্ডার সম্পন্ন করুন | BN +117%. Page title only, never a button |
| `checkout.guest_note` | No account needed. | অ্যাকাউন্ট খোলার দরকার নেই। | Guest checkout is the default |
| `checkout.login_prompt` | Have an account? Log in to use a saved address. | অ্যাকাউন্ট আছে? লগইন করলে সেভ করা ঠিকানা পাবেন। | |
| `checkout.section.contact` | Who is this for | কার জন্য পাঠাব | Literal, warm, and unambiguous — better than "Contact details" |
| `checkout.section.delivery` | Where to deliver | কোথায় পাঠাব | |
| `checkout.section.payment` | How you'll pay | কীভাবে টাকা দেবেন | |
| `checkout.section.review` | Check and confirm | দেখে নিয়ে কনফার্ম করুন | |
| `checkout.field.name.label` | Full name | পুরো নাম | |
| `checkout.field.name.placeholder` | Name of the person receiving | যিনি পণ্য নেবেন তাঁর নাম | |
| `checkout.field.phone.label` | Mobile number | মোবাইল নম্বর | |
| `checkout.field.phone.placeholder` | 01XXXXXXXXX | 01XXXXXXXXX | `inputmode=numeric` |
| `checkout.field.phone.helper` | We call this number to confirm the order. | অর্ডার কনফার্ম করতে এই নম্বরে ফোন করব। | Explains why we ask — reduces drop-off |
| `checkout.field.phone_alt.label` | Another number (optional) | আরেকটি নম্বর (না দিলেও হবে) | |
| `checkout.field.phone_alt.helper` | Useful if the first number is switched off. | প্রথম নম্বর বন্ধ থাকলে কাজে লাগে। | |
| `checkout.field.email.label` | Email (optional) | ইমেইল (না দিলেও হবে) | |
| `checkout.field.email.helper` | For the invoice. We don't send marketing. | ইনভয়েস পাঠাতে। বিজ্ঞাপন পাঠাই না। | |
| `checkout.field.division.label` | Division | বিভাগ | |
| `checkout.field.district.label` | District | জেলা | |
| `checkout.field.upazila.label` | Upazila / thana | উপজেলা / থানা | |
| `checkout.field.area.label` | Area | এলাকা | |
| `checkout.field.select.placeholder` | Choose | বেছে নিন | |
| `checkout.field.select.search` | Type to search | লিখে খুঁজুন | |
| `checkout.field.select.no_match` | No match | কিছু পাওয়া গেল না | |
| `checkout.field.address.label` | Address | ঠিকানা | |
| `checkout.field.address.placeholder` | House and road, flat and floor | বাসা ও রোড, ফ্ল্যাট ও তলা | |
| `checkout.field.landmark.label` | Nearby landmark | কাছের চেনা জায়গা | `[review: চেনা জায়গা]` — field exists because this is how BD addresses work |
| `checkout.field.landmark.placeholder` | Beside which shop, which floor | কোন দোকানের পাশে, কত তলা | |
| `checkout.field.landmark.helper` | This is what stops a failed delivery. | এটাই ডেলিভারি ব্যর্থ হওয়া আটকায়। | |
| `checkout.field.note.label` | Note for us (optional) | আমাদের জন্য কিছু লিখুন (না দিলেও হবে) | |
| `checkout.field.note.placeholder` | Gift wrap, call before 6 PM, anything else | গিফট র‍্যাপ, সন্ধ্যা 6টার আগে ফোন, বা অন্য কিছু | |
| `checkout.address.saved.title` | Saved addresses | সেভ করা ঠিকানা | |
| `checkout.address.use` | Deliver here | এখানে পাঠান | |
| `checkout.address.add_new` | Use a new address | নতুন ঠিকানা দিন | |
| `checkout.address.default_badge` | Default | প্রধান | |
| `checkout.address.edit` | Edit | বদলান | |
| `checkout.delivery.title` | Delivery | ডেলিভারি | |
| `checkout.delivery.home` | Home delivery | বাসায় ডেলিভারি | |
| `checkout.delivery.home.desc` | Courier brings it to your address. | কুরিয়ার আপনার ঠিকানায় পৌঁছে দেবে। | |
| `checkout.delivery.pickup` | Pick up from our shop | দোকান থেকে নিয়ে যাব | |
| `checkout.delivery.pickup.desc` | Ready in 4 hours. No delivery charge. | 4 ঘণ্টায় তৈরি থাকবে। ডেলিভারি চার্জ নেই। | |
| `checkout.delivery.express` | Same-day inside Dhaka | ঢাকার ভেতরে একই দিনে | |
| `checkout.delivery.express.desc` | Order before 1 PM. | দুপুর 1টার আগে অর্ডার করুন। | |
| `checkout.delivery.charge.label` | Delivery charge | ডেলিভারি চার্জ | |
| `checkout.delivery.eta` | Arrives {date_range} | {date_range}-এ পৌঁছাবে | |
| `checkout.payment.title` | Payment | পেমেন্ট | |
| `checkout.payment.cod.name` | Cash on delivery | ক্যাশ অন ডেলিভারি | Listed first, pre-selected |
| `checkout.payment.cod.desc` | Pay the rider when the parcel arrives. | পার্সেল এলে রাইডারকে টাকা দিন। | |
| `checkout.payment.cod.advance_note` | {amount} advance is needed for this area. | এই এলাকার জন্য {amount} অগ্রিম লাগবে। | |
| `checkout.payment.online.name` | Pay now | এখনই পেমেন্ট করুন | |
| `checkout.payment.online.desc` | bKash, Nagad, Rocket, card. | বিকাশ, নগদ, রকেট, কার্ড। | Wallet names in Bangla script — this is how the brands write themselves |
| `checkout.payment.method.bkash` | bKash | বিকাশ | |
| `checkout.payment.method.nagad` | Nagad | নগদ | |
| `checkout.payment.method.rocket` | Rocket | রকেট | |
| `checkout.payment.method.upay` | Upay | উপায় | |
| `checkout.payment.method.card` | Debit / credit card | ডেবিট / ক্রেডিট কার্ড | |
| `checkout.payment.secure_note` | Payment runs on SSLCommerz. We never see your card. | পেমেন্ট হয় SSLCommerz-এ। আপনার কার্ডের তথ্য আমরা দেখি না। | |
| `checkout.review.items` | {count} items | {count}টি পণ্য | |
| `checkout.review.edit_cart` | Edit cart | কার্ট বদলান | |
| `checkout.terms_note` | Placing the order means you accept our return policy. | অর্ডার করলে আপনি আমাদের রিটার্ন নীতি মেনে নিচ্ছেন। | |
| `checkout.place_order` | Place order · {amount} | অর্ডার কনফার্ম করুন · {amount} | BN 24 chars + amount. **Full-width CTA only; verify at 320px** |
| `checkout.placing` | Placing your order… | অর্ডার করা হচ্ছে… | Button stays disabled |
| `checkout.summary.toggle` | Order summary · {amount} | অর্ডারের হিসাব · {amount} | Mobile collapsible top bar |
| `checkout.error.generic` | The order didn't go through. Nothing was charged. Please check the fields marked below. | অর্ডারটি হয়নি। কোনো টাকা কাটা হয়নি। নিচে চিহ্নিত ঘরগুলো দেখে নিন। | Form state survives — DESIGN-SPEC §6.1 |
| `checkout.payment_failed.title` | Payment didn't complete | পেমেন্ট সম্পন্ন হয়নি | |
| `checkout.payment_failed.body` | No money was taken. Your cart is intact. Try again, pick another method, or pay cash on delivery. | কোনো টাকা কাটা হয়নি। আপনার কার্ট ঠিক আছে। আবার চেষ্টা করুন, অন্য মাধ্যম বেছে নিন, বা ক্যাশ অন ডেলিভারিতে নিন। | |
| `checkout.payment_failed.retry` | Try again | আবার চেষ্টা করুন | |
| `checkout.payment_failed.change` | Choose another method | অন্য মাধ্যম বেছে নিন | |
| `checkout.payment_failed.cod` | Pay cash on delivery instead | বরং ক্যাশ অন ডেলিভারিতে নিন | |
| `checkout.register_after.title` | Set a password to see your orders later | পরে অর্ডার দেখতে একটি পাসওয়ার্ড দিন | Offered after the order, pre-filled |
| `checkout.register_after.body` | Your name, number and address are already saved. | আপনার নাম, নম্বর আর ঠিকানা সেভ করা আছে। | |
| `checkout.register_after.password.label` | Password | পাসওয়ার্ড | |
| `checkout.register_after.submit` | Save password | পাসওয়ার্ড সেভ করুন | |
| `checkout.register_after.skip` | Not now | এখন না | |

### A.7 Order confirmation (18)

| key | en | bn | notes |
|---|---|---|---|
| `order.success.title` | Order placed. Thank you! | অর্ডার হয়ে গেছে। ধন্যবাদ! | The one exclamation mark allowed in the whole interface |
| `order.success.order_no.label` | Order number | অর্ডার নম্বর | |
| `order.success.order_no.copy` | Copy | কপি করুন | |
| `order.success.order_no.copied` | Copied | কপি হয়েছে | |
| `order.success.body` | We have your order. Keep the number — you'll need it to track or change anything. | আপনার অর্ডার আমাদের কাছে পৌঁছেছে। নম্বরটি রেখে দিন — ট্র্যাক করতে বা কিছু বদলাতে কাজে লাগবে। | |
| `order.success.call_note` | We will call {phone} within 2 hours on working days to confirm. | কর্মদিবসে 2 ঘণ্টার মধ্যে {phone} নম্বরে ফোন করে কনফার্ম করব। | The single biggest trust line on the page |
| `order.success.eta` | Expected delivery {date_range} | ডেলিভারি হবে {date_range} | |
| `order.success.cod_amount` | Keep {amount} ready for the rider | রাইডারের জন্য {amount} হাতে রাখুন | |
| `order.success.paid_note` | Paid {amount} by {method} | {method}-এ {amount} পরিশোধ হয়েছে | |
| `order.success.items.title` | What you ordered | কী অর্ডার করেছেন | |
| `order.success.address.title` | Delivering to | পাঠানো হবে | |
| `order.success.payment.title` | Payment | পেমেন্ট | |
| `order.success.track_cta` | Track this order | অর্ডার ট্র্যাক করুন | |
| `order.success.continue_cta` | Keep shopping | আরও দেখুন | |
| `order.success.invoice` | Download invoice | ইনভয়েস নামান | |
| `order.success.whatsapp_optin` | Send me updates on WhatsApp | হোয়াটসঅ্যাপে আপডেট পাঠান | Pre-checked; order updates run on WhatsApp utility (PRD §6a) |
| `order.success.whatsapp_note` | Cheaper and clearer than SMS, and you can reply. | এসএমএসের চেয়ে ভালো, আর আপনি উত্তরও দিতে পারবেন। | |
| `order.success.help` | Something wrong? Call 09600-123456 | কিছু ভুল হয়েছে? ফোন করুন 09600-123456 | |

### A.8 Order tracking (16) and the shipment status enum (24)

| key | en | bn | notes |
|---|---|---|---|
| `track.title` | Track your order | অর্ডার ট্র্যাক করুন | |
| `track.body` | Enter the order number and the mobile number you ordered with. | অর্ডার নম্বর আর যে মোবাইল নম্বর দিয়ে অর্ডার করেছেন সেটি লিখুন। | |
| `track.field.order_no.label` | Order number | অর্ডার নম্বর | |
| `track.field.order_no.placeholder` | TM10482 | TM10482 | |
| `track.field.phone.label` | Mobile number | মোবাইল নম্বর | |
| `track.submit` | Show my order | অর্ডার দেখান | |
| `track.not_found.title` | We couldn't find that order | এই অর্ডারটি খুঁজে পাওয়া গেল না | |
| `track.not_found.body` | Check the order number and make sure the mobile number matches the one used to order. | অর্ডার নম্বরটি দেখে নিন, আর যে নম্বর দিয়ে অর্ডার করেছেন সেটিই দিয়েছেন কি না মিলিয়ে নিন। | |
| `track.not_found.cta` | Call us instead | বরং ফোন করুন | |
| `track.timeline.title` | Where your parcel is | আপনার পার্সেল কোথায় | |
| `track.eta` | Expected {date_range} | পৌঁছাবে {date_range} | |
| `track.courier.label` | Courier | কুরিয়ার | |
| `track.consignment.label` | Tracking number | ট্র্যাকিং নম্বর | Not `কনসাইনমেন্ট` in the customer UI — that word belongs in the admin |
| `track.rider_phone.label` | Rider's number | রাইডারের নম্বর | |
| `track.cancel_cta` | Cancel this order | অর্ডার বাতিল করুন | Visible only while status is `pending` |
| `track.cancel.confirm` | Cancel order {order_no}? You won't be charged anything. | {order_no} অর্ডারটি বাতিল করবেন? কোনো টাকা দিতে হবে না। | |

**Shipment status — customer-facing text for the normalised enum.** Each status has a short label (timeline node, order list badge) and a one-line explanation (timeline detail). The internal enum value never appears in the UI.

| key | en | bn | notes |
|---|---|---|---|
| `status.pending.label` | Order received | অর্ডার পেয়েছি | Never "Pending" — to a customer that reads as "nothing is happening" |
| `status.pending.desc` | We have it and will call you to confirm. | আমাদের কাছে আছে, কনফার্ম করতে ফোন করব। | |
| `status.booked.label` | Ready to ship | পাঠানোর জন্য তৈরি | "Booked with courier" is our word, not the customer's |
| `status.booked.desc` | Packed and handed over to {courier} for pickup. | প্যাক করে {courier}-কে দেওয়া হয়েছে, তারা নিয়ে যাবে। | |
| `status.picked_up.label` | Collected by courier | কুরিয়ার নিয়ে গেছে | |
| `status.picked_up.desc` | {courier} has the parcel. | পার্সেলটি এখন {courier}-এর কাছে। | |
| `status.in_transit.label` | On the way | পথে আছে | |
| `status.in_transit.desc` | Moving towards {district}. | {district}-এর দিকে যাচ্ছে। | |
| `status.out_for_delivery.label` | Out for delivery | ডেলিভারির পথে | `ডেলিভারির জন্য বেরিয়েছে` is more natural but 24 chars — too long for a badge. Long form used in the timeline desc |
| `status.out_for_delivery.desc` | The rider is delivering today. Please keep {amount} ready. | রাইডার আজ ডেলিভারি দিতে বেরিয়েছে। {amount} হাতে রাখুন। | |
| `status.delivered.label` | Delivered | পৌঁছে গেছে | `ডেলিভারি সম্পন্ন` is correct but stiff; `পৌঁছে গেছে` is what a parent says |
| `status.delivered.desc` | Received on {date}. 7-day return applies. | {date}-এ হাতে পেয়েছেন। 7 দিনের রিটার্ন সুবিধা আছে। | |
| `status.partially_delivered.label` | Part delivered | কিছু অংশ পৌঁছেছে | `[review: আংশিক ডেলিভারি]` was the alternative; chose the plainer phrase |
| `status.partially_delivered.desc` | {delivered} of {total} items were delivered. We will call you about the rest. | {total}টির মধ্যে {delivered}টি পৌঁছেছে। বাকিটা নিয়ে আমরা ফোন করব। | |
| `status.hold.label` | Paused | আটকে আছে | Honest. Do not write "processing" |
| `status.hold.desc` | The courier couldn't deliver and is holding the parcel. We are sorting it out and will call you. | কুরিয়ার ডেলিভারি দিতে পারেনি, পার্সেলটি তাদের কাছে আছে। আমরা দেখছি এবং ফোন করব। | `[review: আটকে আছে]` — `সাময়িক স্থগিত` is the formal alternative |
| `status.cancelled.label` | Cancelled | বাতিল হয়েছে | |
| `status.cancelled.desc` | This order was cancelled. Nothing is owed. | অর্ডারটি বাতিল হয়েছে। কোনো টাকা দিতে হবে না। | |
| `status.returning.label` | Coming back to us | ফেরত আসছে | |
| `status.returning.desc` | The parcel is on its way back. If this is a mistake, call us today. | পার্সেলটি ফেরত আসছে। ভুল হলে আজই ফোন করুন। | The same-day window is real and worth stating |
| `status.returned.label` | Returned to us | আমাদের কাছে ফিরেছে | |
| `status.returned.desc` | We have the parcel back. Any advance paid is refunded within 7 days. | পার্সেলটি আমাদের কাছে ফিরে এসেছে। অগ্রিম দেওয়া থাকলে 7 দিনের মধ্যে ফেরত পাবেন। | Refund window is pending legal confirmation — PRD §7 item 9c |
| `status.lost.label` | Lost in transit | পথে হারিয়ে গেছে | We say it plainly. Hiding this is how trust dies |
| `status.lost.desc` | The courier cannot find this parcel. We are sending a replacement or refunding you — we will call today. | কুরিয়ার এই পার্সেলটি খুঁজে পাচ্ছে না। আমরা নতুন পাঠাব বা টাকা ফেরত দেব — আজই ফোন করব। | |

### A.9 Account (72)

| key | en | bn | notes |
|---|---|---|---|
| `auth.login.title` | Log in | লগইন করুন | |
| `auth.login.subtitle` | Your mobile number is your account. | আপনার মোবাইল নম্বরই আপনার অ্যাকাউন্ট। | |
| `auth.phone.label` | Mobile number | মোবাইল নম্বর | |
| `auth.phone.placeholder` | 01XXXXXXXXX | 01XXXXXXXXX | |
| `auth.phone.helper` | We'll text a 6-digit code. | 6 সংখ্যার একটি কোড এসএমএস করব। | |
| `auth.continue` | Continue | এগিয়ে যান | |
| `auth.otp.title` | Enter the code | কোডটি লিখুন | |
| `auth.otp.body` | We texted a 6-digit code to {phone}. | {phone} নম্বরে 6 সংখ্যার কোড পাঠিয়েছি। | |
| `auth.otp.input.aria` | Code digit {n} of 6 | কোডের {n} নম্বর সংখ্যা, মোট 6টি | |
| `auth.otp.submit` | Verify | যাচাই করুন | |
| `auth.otp.resend` | Send the code again | আবার কোড পাঠান | |
| `auth.otp.resend_in` | Send again in {seconds}s | {seconds} সেকেন্ড পর আবার পাঠানো যাবে | BN +86%. Helper text line, not a button |
| `auth.otp.change_number` | Change number | নম্বর বদলান | |
| `auth.otp.help` | Code not arriving? Check the number, or call 09600-123456. | কোড আসছে না? নম্বরটি দেখে নিন, বা 09600-123456 নম্বরে ফোন করুন। | |
| `auth.email.toggle` | Log in with email instead | বরং ইমেইল দিয়ে লগইন করুন | |
| `auth.email.label` | Email | ইমেইল | |
| `auth.password.label` | Password | পাসওয়ার্ড | |
| `auth.password.show` | Show password | পাসওয়ার্ড দেখান | |
| `auth.password.hide` | Hide password | পাসওয়ার্ড লুকান | |
| `auth.password.forgot` | Forgot password? | পাসওয়ার্ড ভুলে গেছেন? | |
| `auth.email.submit` | Log in | লগইন করুন | |
| `auth.register.title` | Create an account | অ্যাকাউন্ট খুলুন | |
| `auth.register.name.label` | Your name | আপনার নাম | |
| `auth.register.submit` | Create account | অ্যাকাউন্ট খুলুন | |
| `auth.register.terms` | By continuing you accept our Terms and Privacy Policy. | এগিয়ে গেলে আপনি আমাদের শর্তাবলি ও গোপনীয়তা নীতি মেনে নিচ্ছেন। | |
| `auth.reset.title` | Reset your password | পাসওয়ার্ড বদলান | |
| `auth.reset.body` | Enter your mobile number and we'll text a code. | মোবাইল নম্বর লিখুন, কোড এসএমএস করব। | |
| `auth.reset.submit` | Send code | কোড পাঠান | |
| `auth.reset.sent` | Code sent to {phone}. | {phone} নম্বরে কোড পাঠানো হয়েছে। | |
| `auth.reset.new_password.label` | New password | নতুন পাসওয়ার্ড | |
| `auth.reset.done` | Password changed. You're logged in. | পাসওয়ার্ড বদলে গেছে। আপনি লগইন করা আছেন। | |
| `auth.logout` | Log out | লগআউট | |
| `auth.logout.confirm` | Log out of this device? | এই ডিভাইস থেকে লগআউট করবেন? | |
| `account.dashboard.title` | Your account | আপনার অ্যাকাউন্ট | |
| `account.dashboard.greeting` | Hello, {name} | {name}, স্বাগতম | |
| `account.nav.orders` | Orders | অর্ডার | |
| `account.nav.addresses` | Addresses | ঠিকানা | |
| `account.nav.wishlist` | Wishlist | পছন্দের তালিকা | |
| `account.nav.reviews` | Your reviews | আপনার রিভিউ | |
| `account.nav.profile` | Profile | প্রোফাইল | |
| `account.orders.title` | Your orders | আপনার অর্ডার | |
| `account.orders.placed_on` | Placed {date} | অর্ডার করেছেন {date} | |
| `account.orders.items` | {count} items | {count}টি পণ্য | |
| `account.orders.total` | Total {amount} | সর্বমোট {amount} | |
| `account.orders.view` | See details | বিস্তারিত দেখুন | |
| `account.orders.track` | Track | ট্র্যাক করুন | |
| `account.orders.reorder` | Order again | আবার অর্ডার করুন | |
| `account.orders.invoice` | Invoice | ইনভয়েস | |
| `account.orders.cancel` | Cancel order | অর্ডার বাতিল করুন | |
| `account.order_detail.title` | Order {order_no} | অর্ডার {order_no} | |
| `account.addresses.title` | Your addresses | আপনার ঠিকানা | |
| `account.addresses.add` | Add an address | ঠিকানা যোগ করুন | |
| `account.addresses.edit` | Edit | বদলান | |
| `account.addresses.delete` | Delete | মুছুন | |
| `account.addresses.delete.confirm` | Delete this address? | এই ঠিকানাটি মুছে ফেলবেন? | |
| `account.addresses.set_default` | Make this my main address | এটিকে প্রধান ঠিকানা করুন | |
| `account.addresses.default_badge` | Main | প্রধান | |
| `account.wishlist.title` | Saved toys | পছন্দের খেলনা | Warmer and shorter than `পছন্দের তালিকা` as a page title |
| `account.wishlist.count` | {count} toys saved | {count}টি খেলনা রাখা আছে | |
| `account.wishlist.move_to_cart` | Add to cart | কার্টে নিন | |
| `account.wishlist.remove` | Remove | সরান | |
| `account.wishlist.price_dropped` | Price dropped to {amount} | দাম কমে {amount} হয়েছে | |
| `account.profile.title` | Your details | আপনার তথ্য | |
| `account.profile.name.label` | Name | নাম | |
| `account.profile.phone.label` | Mobile number | মোবাইল নম্বর | |
| `account.profile.phone.note` | To change this, call us — it is your login. | এটি বদলাতে ফোন করুন — এটিই আপনার লগইন। | |
| `account.profile.email.label` | Email (optional) | ইমেইল (না দিলেও হবে) | |
| `account.profile.save` | Save changes | সেভ করুন | |
| `account.profile.saved` | Saved. | সেভ হয়েছে। | |
| `account.delete.title` | Delete your account | অ্যাকাউন্ট মুছে ফেলুন | |
| `account.delete.body` | Your orders stay in our records for accounting, but you will not be able to log in again. | হিসাবের জন্য আপনার অর্ডারের তথ্য আমাদের কাছে থাকবে, কিন্তু আপনি আর লগইন করতে পারবেন না। | |
| `account.delete.confirm` | Type DELETE to confirm | নিশ্চিত করতে DELETE লিখুন | Confirmation token stays Latin in both locales — it must be unambiguous |

### A.10 Category listing, filters and sort (30)

| key | en | bn | notes |
|---|---|---|---|
| `listing.count` | {count} toys | {count}টি খেলনা | |
| `listing.sort.label` | Sort | সাজান | |
| `listing.sort.relevance` | Best match | সবচেয়ে মিলে যায় | |
| `listing.sort.newest` | Newest first | নতুন আগে | |
| `listing.sort.price_asc` | Price: low to high | দাম: কম থেকে বেশি | |
| `listing.sort.price_desc` | Price: high to low | দাম: বেশি থেকে কম | |
| `listing.sort.rating` | Best rated | সবচেয়ে ভালো রেটিং | |
| `listing.sort.bestselling` | Most bought | সবচেয়ে বেশি বিক্রি | |
| `listing.filter.cta` | Filters | ফিল্টার | |
| `listing.filter.cta.count` | Filters ({count}) | ফিল্টার ({count}) | |
| `listing.filter.title` | Narrow it down | বেছে নিন | |
| `listing.filter.age` | Age | বয়স | First facet — DESIGN-SPEC §4 |
| `listing.filter.price` | Price | দাম | |
| `listing.filter.price.min` | From | থেকে | |
| `listing.filter.price.max` | To | পর্যন্ত | |
| `listing.filter.category` | Category | ক্যাটাগরি | |
| `listing.filter.brand` | Brand | ব্র্যান্ড | |
| `listing.filter.gender` | For | কার জন্য | |
| `listing.filter.gender.boys` | Boys | ছেলেদের | |
| `listing.filter.gender.girls` | Girls | মেয়েদের | |
| `listing.filter.gender.all` | Any child | সব বাচ্চার | |
| `listing.filter.rating` | Rating | রেটিং | |
| `listing.filter.availability` | In stock only | শুধু স্টকে আছে যা | |
| `listing.filter.facet_count` | ({count}) | ({count}) | |
| `listing.filter.apply` | Show {count} toys | {count}টি খেলনা দেখুন | |
| `listing.filter.clear` | Clear all | সব মুছুন | |
| `listing.filter.chip.remove` | Remove {facet} | {facet} সরান | |
| `listing.filter.live` | {count} toys match | {count}টি খেলনা মিলেছে | `aria-live=polite` |
| `listing.load_more` | Show more | আরও দেখুন | |
| `listing.page.aria` | Page {n} of {total} | {total}টির মধ্যে {n} নম্বর পাতা | |

### A.11 Reviews (32)

| key | en | bn | notes |
|---|---|---|---|
| `reviews.title` | What parents say | অভিভাবকেরা যা বলছেন | Better than "Customer reviews" — names the actual reader |
| `reviews.count` | {count} reviews | {count}টি রিভিউ | |
| `reviews.average.aria` | Average {rating} out of 5 | 5-এর মধ্যে গড়ে {rating} | |
| `reviews.distribution.row` | {stars} stars — {count} | {stars} স্টার — {count} | |
| `reviews.sort.recent` | Newest | নতুন আগে | |
| `reviews.sort.helpful` | Most helpful | সবচেয়ে কাজের | |
| `reviews.sort.highest` | Highest rating | সবচেয়ে বেশি রেটিং | |
| `reviews.sort.lowest` | Lowest rating | সবচেয়ে কম রেটিং | |
| `reviews.filter.with_photos` | With photos | ছবি আছে যেগুলোতে | |
| `reviews.filter.verified` | Verified buyers | যাঁরা সত্যিই কিনেছেন | `[review: যাঁরা সত্যিই কিনেছেন]` — plainer than `ভেরিফায়েড ক্রেতা`, and the point is credibility |
| `reviews.write.cta` | Write a review | রিভিউ লিখুন | |
| `reviews.form.title` | Review {product} | {product}-এর রিভিউ লিখুন | |
| `reviews.form.rating.label` | Your rating | আপনার রেটিং | |
| `reviews.form.rating.aria` | {n} of 5 stars | 5-এর মধ্যে {n} স্টার | |
| `reviews.form.headline.label` | One-line summary | এক লাইনে বলুন | |
| `reviews.form.headline.placeholder` | Sturdy and my son loves it | মজবুত, আমার ছেলের খুব পছন্দ | Example copy, not instruction |
| `reviews.form.body.label` | Your review | আপনার রিভিউ | |
| `reviews.form.body.placeholder` | Was it the size you expected? Did it last? Would you buy it again? | মাপ যেমন ভেবেছিলেন তেমন হয়েছে? টিকেছে? আবার কিনবেন? | |
| `reviews.form.photo.label` | Add photos (optional) | ছবি দিন (না দিলেও হবে) | |
| `reviews.form.photo.hint` | Up to 3 photos, 5MB each. | সর্বোচ্চ 3টি ছবি, প্রতিটি 5MB পর্যন্ত। | |
| `reviews.form.child_age.label` | Your child's age (optional) | আপনার বাচ্চার বয়স (না দিলেও হবে) | The most useful field on the form for other parents |
| `reviews.form.name.label` | Name shown with the review | রিভিউতে যে নাম দেখাবে | |
| `reviews.form.submit` | Post review | রিভিউ পোস্ট করুন | |
| `reviews.form.submitting` | Posting… | পোস্ট হচ্ছে… | |
| `reviews.form.success.title` | Thanks — your review is in | ধন্যবাদ — রিভিউ জমা হয়েছে | |
| `reviews.form.success.body` | We read every review before it goes live. Yours will show within a day. | প্রতিটি রিভিউ আমরা পড়ে দেখি। আপনার রিভিউ একদিনের মধ্যে দেখা যাবে। | Says moderation happens, without sounding like censorship |
| `reviews.card.verified` | Bought this | এটি কিনেছেন | |
| `reviews.card.child_age` | Child's age {age} | বাচ্চার বয়স {age} | |
| `reviews.card.helpful` | Helpful ({count}) | কাজে লেগেছে ({count}) | |
| `reviews.card.report` | Report | রিপোর্ট করুন | |
| `reviews.card.seller_reply` | Toy Mart BD replied | টয় মার্ট বিডি উত্তর দিয়েছে | |
| `reviews.login_required` | Log in to write a review | রিভিউ লিখতে লগইন করুন | |

### A.12 Footer (34)

| key | en | bn | notes |
|---|---|---|---|
| `footer.about.title` | Toy Mart BD | টয় মার্ট বিডি | |
| `footer.about.body` | A toy shop run by people who answer the phone. Based in Dhaka, delivering across Bangladesh. | এমন একটি খেলনার দোকান, যারা ফোন ধরে। ঢাকায় আমাদের অফিস, ডেলিভারি সারা দেশে। | Positioning against the Facebook-page seller who vanishes |
| `footer.shop.title` | Shop | কিনুন | |
| `footer.shop.all` | All toys | সব খেলনা | |
| `footer.shop.new` | New arrivals | নতুন এসেছে | |
| `footer.shop.offers` | Offers | ছাড় ও অফার | |
| `footer.shop.by_age` | Shop by age | বয়স অনুযায়ী | |
| `footer.shop.brands` | Brands | ব্র্যান্ড | |
| `footer.shop.gift` | Gift ideas | উপহারের আইডিয়া | |
| `footer.help.title` | Help | সহায়তা | |
| `footer.help.track` | Track your order | অর্ডার ট্র্যাক করুন | |
| `footer.help.delivery_checker` | Check delivery charge | ডেলিভারি চার্জ দেখুন | |
| `footer.help.faq` | Common questions | সাধারণ প্রশ্ন | |
| `footer.help.contact` | Contact us | যোগাযোগ করুন | |
| `footer.help.returns` | How returns work | রিটার্ন কীভাবে হয় | |
| `footer.help.size_guide` | Age & size guide | বয়স ও মাপের গাইড | |
| `footer.policies.title` | Policies | নীতিমালা | |
| `footer.contact.title` | Talk to us | কথা বলুন | |
| `footer.contact.phone` | 09600-123456 | 09600-123456 | |
| `footer.contact.hours` | 9 AM – 9 PM, Saturday to Thursday | সকাল 9টা – রাত 9টা, শনি থেকে বৃহস্পতি | |
| `footer.contact.whatsapp` | WhatsApp: 01712-345678 | হোয়াটসঅ্যাপ: 01712-345678 | |
| `footer.contact.email` | hello@toymartbd.com | hello@toymartbd.com | |
| `footer.contact.address` | House 12, Road 7, Dhanmondi, Dhaka 1205 | বাসা 12, রোড 7, ধানমন্ডি, ঢাকা 1205 | A real visitable address is a trust signal; keep it accurate |
| `footer.newsletter.title` | New toys, once a month | মাসে একবার নতুন খেলনার খবর | Honest frequency beats "subscribe for updates" |
| `footer.newsletter.body` | No daily offers. One email a month. | রোজ রোজ অফার নয়। মাসে একটি ইমেইল। | |
| `footer.newsletter.placeholder` | Your email | আপনার ইমেইল | |
| `footer.newsletter.submit` | Sign up | যোগ দিন | |
| `footer.newsletter.success` | Done. Check your inbox. | হয়ে গেছে। ইনবক্স দেখুন। | |
| `footer.social.facebook.aria` | Toy Mart BD on Facebook | ফেসবুকে টয় মার্ট বিডি | |
| `footer.social.instagram.aria` | Toy Mart BD on Instagram | ইনস্টাগ্রামে টয় মার্ট বিডি | |
| `footer.social.youtube.aria` | Toy Mart BD on YouTube | ইউটিউবে টয় মার্ট বিডি | |
| `footer.payment.title` | Ways to pay | পেমেন্টের মাধ্যম | |
| `footer.copyright` | © {year} Toy Mart BD. All rights reserved. | © {year} টয় মার্ট বিডি। সর্বস্বত্ব সংরক্ষিত। | |
| `footer.trade_licence` | Trade licence {number} · BIN {bin} | ট্রেড লাইসেন্স {number} · বিআইএন {bin} | Fill from PRD §7 item 6 once confirmed |

### A.13 Trust strip (10)

Shown on PDP and checkout. Four items, each a label plus a one-line proof.

| key | en | bn | notes |
|---|---|---|---|
| `trust.cod.label` | Cash on delivery | ক্যাশ অন ডেলিভারি | |
| `trust.cod.detail` | Pay when the parcel reaches you | পার্সেল হাতে পেয়ে টাকা দিন | |
| `trust.returns.label` | 7-day return | 7 দিনের রিটার্ন | |
| `trust.returns.detail` | Unopened, we take it back | বাক্স না খুললে ফেরত নেব | Specific condition stated — a vague promise reads as a lie |
| `trust.authentic.label` | Genuine toys | আসল খেলনা | |
| `trust.authentic.detail` | Bought from importers, with papers | আমদানিকারকের কাছ থেকে কেনা, কাগজসহ | |
| `trust.support.label` | We answer the phone | ফোন আমরা ধরি | |
| `trust.support.detail` | 9 AM – 9 PM on 09600-123456 | সকাল 9টা – রাত 9টা, 09600-123456 | |
| `trust.safety.label` | Age-checked | বয়স মিলিয়ে দেখা | |
| `trust.safety.detail` | Every toy has a checked age range | প্রতিটি খেলনার বয়সসীমা যাচাই করা | |

### A.14 Policy and static page titles (15)

| key | en | bn | notes |
|---|---|---|---|
| `page.about.title` | About Toy Mart BD | টয় মার্ট বিডি সম্পর্কে | |
| `page.contact.title` | Contact us | যোগাযোগ করুন | |
| `page.faq.title` | Common questions | সাধারণ প্রশ্ন | Not `প্রায়শই জিজ্ঞাসিত প্রশ্ন` — nobody reads that |
| `page.delivery.title` | Delivery charges and times | ডেলিভারি চার্জ ও সময় | |
| `page.returns.title` | Returns and refunds | রিটার্ন ও টাকা ফেরত | |
| `page.warranty.title` | Warranty | ওয়ারেন্টি | |
| `page.privacy.title` | Privacy policy | গোপনীয়তা নীতি | |
| `page.terms.title` | Terms of use | ব্যবহারের শর্তাবলি | |
| `page.safety.title` | Toy safety and age ranges | খেলনার নিরাপত্তা ও বয়সসীমা | Our differentiator page — PRD §6a |
| `page.payment.title` | Payment methods | পেমেন্টের মাধ্যম | |
| `page.bulk.title` | Bulk and school orders | পাইকারি ও স্কুলের অর্ডার | |
| `page.blog.title` | Toy guide | খেলনা গাইড | |
| `page.delivery_checker.title` | Check delivery charge | ডেলিভারি চার্জ দেখুন | |
| `page.maintenance.title` | Back in a few minutes | কিছুক্ষণের মধ্যেই ফিরছি | |
| `page.maintenance.body` | We are updating the site. Orders already placed are safe. Call 09600-123456 if you need anything now. | সাইট আপডেট করা হচ্ছে। আগে করা অর্ডারগুলো ঠিক আছে। এখনই কিছু দরকার হলে 09600-123456 নম্বরে ফোন করুন। | |

### A.15 Toasts, confirmations and inline notices (27)

| key | en | bn | notes |
|---|---|---|---|
| `toast.cart.added` | Added to cart | কার্টে যোগ হয়েছে | |
| `toast.cart.added.action` | View cart | কার্ট দেখুন | |
| `toast.cart.updated` | Cart updated | কার্ট আপডেট হয়েছে | |
| `toast.cart.removed` | Removed from cart | কার্ট থেকে সরানো হয়েছে | |
| `toast.wishlist.added` | Saved | পছন্দে রাখা হয়েছে | |
| `toast.wishlist.removed` | Removed from saved | পছন্দ থেকে সরানো হয়েছে | |
| `toast.wishlist.login` | Log in to save toys | খেলনা পছন্দে রাখতে লগইন করুন | |
| `toast.copied` | Copied | কপি হয়েছে | |
| `toast.saved` | Saved | সেভ হয়েছে | |
| `toast.address.saved` | Address saved | ঠিকানা সেভ হয়েছে | |
| `toast.address.deleted` | Address deleted | ঠিকানা মুছে ফেলা হয়েছে | |
| `toast.coupon.applied` | Coupon applied | কুপন প্রয়োগ হয়েছে | |
| `toast.coupon.removed` | Coupon removed | কুপন সরানো হয়েছে | |
| `toast.review.submitted` | Review received | রিভিউ জমা হয়েছে | |
| `toast.notify.registered` | We'll text you | আমরা এসএমএস করব | |
| `toast.order.cancelled` | Order cancelled | অর্ডার বাতিল হয়েছে | |
| `toast.profile.saved` | Details saved | তথ্য সেভ হয়েছে | |
| `toast.logged_out` | Logged out | লগআউট হয়েছে | |
| `toast.error.generic` | Something went wrong. Try again. | কিছু একটা সমস্যা হয়েছে। আবার চেষ্টা করুন। | |
| `toast.error.offline` | You're offline. We'll retry when you're back. | আপনি অফলাইনে আছেন। কানেকশন ফিরলে আবার চেষ্টা করব। | Matters on 4G |
| `toast.error.slow` | This is taking longer than usual… | স্বাভাবিকের চেয়ে বেশি সময় লাগছে… | |
| `toast.retry` | Retry | আবার চেষ্টা করুন | |
| `toast.dismiss` | Dismiss | বন্ধ করুন | |
| `confirm.default.yes` | Yes, do it | হ্যাঁ, করুন | |
| `confirm.default.no` | No, go back | না, ফিরে যান | |
| `confirm.destructive.yes` | Yes, delete | হ্যাঁ, মুছুন | |
| `confirm.leave_checkout` | Leave checkout? Your cart is saved. | চেকআউট ছেড়ে যাবেন? আপনার কার্ট সেভ করা আছে। | |

> Empty-state, 404, 500 and order-success headline and body copy live in **Part C**, with their illustrations, so the words and the picture are specified in one place. Those 33 strings are included in the total.

### A.16 Validation — the exact user-facing error text (48)

Rules that govern all of them:
- The error names the field and says what to do, never what the system thinks. "Mobile number should be 11 digits" beats "Invalid input".
- No blame. Never "You entered…".
- Error text is programmatically associated with the input (`aria-describedby`), rendered below it, and announced via `aria-live="polite"`. Colour is never the only signal — the error icon and the text carry it.
- Phone validation runs inline on blur, against the live operator-prefix list. Valid prefixes are **013, 014, 015, 016, 017, 018, 019** only. `+880`, `880` and `0` prefixes are all accepted on input and normalised to `01XXXXXXXXX` on write.

| key | en | bn | notes |
|---|---|---|---|
| `error.required` | This is needed | এটি দরকার | Generic fallback; prefer a field-specific string below |
| `error.name.required` | Please write the receiver's name | যিনি পণ্য নেবেন তাঁর নাম লিখুন | |
| `error.name.too_short` | Please write the full name | পুরো নামটি লিখুন | Fires under 3 characters |
| `error.phone.required` | We need a mobile number to confirm the order | অর্ডার কনফার্ম করতে একটি মোবাইল নম্বর দরকার | Says *why*, which is what gets the field filled |
| `error.phone.too_short` | A Bangladeshi mobile number has 11 digits. {count} entered. | বাংলাদেশি মোবাইল নম্বর 11 সংখ্যার হয়। আপনি {count}টি দিয়েছেন। | Shows the count so the fix is obvious |
| `error.phone.too_long` | That's more than 11 digits. Please check the number. | 11 সংখ্যার বেশি হয়ে গেছে। নম্বরটি দেখে নিন। | |
| `error.phone.invalid_prefix` | Bangladeshi numbers start with 013 to 019. Please check the number. | বাংলাদেশি নম্বর 013 থেকে 019 দিয়ে শুরু হয়। নম্বরটি দেখে নিন। | The core prefix rule, stated in a way a person can act on |
| `error.phone.not_numeric` | Digits only, please | শুধু সংখ্যা লিখুন | |
| `error.phone.same_as_primary` | This is the same as the first number. Give a different one, or leave it empty. | এটি প্রথম নম্বরের মতোই। অন্য একটি দিন, বা খালি রাখুন। | |
| `error.email.invalid` | That email doesn't look right. Check for a missing @ or a typo. | ইমেইলটি ঠিক মনে হচ্ছে না। @ বাদ পড়েছে কি না বা টাইপো আছে কি না দেখুন। | |
| `error.password.too_short` | Use at least 8 characters | কমপক্ষে 8টি অক্ষর দিন | |
| `error.password.wrong` | That password doesn't match this number. Try again or reset it. | এই নম্বরের সঙ্গে পাসওয়ার্ডটি মিলছে না। আবার চেষ্টা করুন বা বদলে নিন। | Never reveals whether the account exists |
| `error.district.required` | Choose a district | জেলা বেছে নিন | |
| `error.upazila.required` | Choose an upazila | উপজেলা বেছে নিন | |
| `error.address.required` | Please write the address | ঠিকানাটি লিখুন | |
| `error.address.too_short` | Add house and road so the rider can find you | রাইডার যেন খুঁজে পায় — বাসা ও রোড লিখুন | Fires under 12 characters. Says why, which is how we cut failed deliveries |
| `error.landmark.too_short` | Name a shop, school or mosque nearby | কাছের কোনো দোকান, স্কুল বা মসজিদের নাম লিখুন | |
| `error.quantity.min` | At least 1 | কমপক্ষে 1টি | |
| `error.quantity.above_stock` | Only {max} left in stock. Quantity set to {max}. | স্টকে আর {max}টি আছে। পরিমাণ {max} করে দেওয়া হয়েছে। | The stepper self-corrects rather than blocking — PRD-level decision |
| `error.quantity.above_limit` | Maximum {max} per order on this toy | এই খেলনায় প্রতি অর্ডারে সর্বোচ্চ {max}টি | |
| `error.variant.required` | Choose {attribute} first | আগে {attribute} বেছে নিন | |
| `error.stock.gone` | {product} went out of stock while you were ordering. It has been removed and the total updated. | আপনি অর্ডার করার মাঝেই {product} স্টক শেষ হয়ে গেছে। এটি সরিয়ে দিয়ে মোট হিসাব নতুন করে দেখানো হয়েছে। | |
| `error.price.changed` | The price of {product} changed from {old} to {new} since you added it. Your total is now {total}. | আপনি কার্টে রাখার পর {product}-এর দাম {old} থেকে {new} হয়েছে। এখন আপনার সর্বমোট {total}। | Blocks submission once, requires an explicit `কনফার্ম করুন`. Never silently re-prices |
| `error.price.changed.accept` | Order at the new price | নতুন দামে অর্ডার করুন | |
| `error.price.changed.remove` | Remove this item | এই পণ্যটি সরান | |
| `error.coupon.required` | Enter a coupon code | কুপন কোড লিখুন | |
| `error.coupon.invalid` | We don't have a coupon with that code. Check the spelling. | এই কোডের কোনো কুপন আমাদের নেই। বানানটি দেখে নিন। | |
| `error.coupon.expired` | {code} expired on {date} | {code}-এর মেয়াদ {date}-এ শেষ হয়েছে | Gives the date — vagueness here reads as a trick |
| `error.coupon.used` | {code} has already been used on this number | {code} এই নম্বরে আগেই ব্যবহার করা হয়েছে | |
| `error.coupon.min_not_met` | {code} works on orders of {min} or more. Add {shortfall} more to use it. | {code} চলবে {min} বা তার বেশি অর্ডারে। ব্যবহার করতে আরও {shortfall}-এর কিনুন। | Tells them the shortfall, which recovers the sale |
| `error.coupon.not_applicable` | {code} doesn't apply to the toys in your cart | আপনার কার্টের খেলনাগুলোতে {code} চলবে না | |
| `error.coupon.first_order_only` | {code} is for first orders only | {code} শুধু প্রথম অর্ডারে চলবে | |
| `error.otp.required` | Enter the 6-digit code | 6 সংখ্যার কোডটি লিখুন | |
| `error.otp.incomplete` | The code has 6 digits | কোডটি 6 সংখ্যার | |
| `error.otp.wrong` | That code is wrong. {attempts} tries left. | কোডটি ঠিক নয়। আরও {attempts} বার চেষ্টা করতে পারবেন। | Showing the remaining tries prevents the silent lockout |
| `error.otp.expired` | The code has expired. Ask for a new one. | কোডের মেয়াদ শেষ। নতুন কোড নিন। | |
| `error.otp.locked` | Too many wrong codes. Try again in {minutes} minutes, or call 09600-123456. | অনেকবার ভুল কোড দেওয়া হয়েছে। {minutes} মিনিট পর আবার চেষ্টা করুন, বা 09600-123456 নম্বরে ফোন করুন। | |
| `error.otp.rate_limited` | Wait {seconds} seconds before asking for another code | আরেকটি কোড নিতে {seconds} সেকেন্ড অপেক্ষা করুন | |
| `error.payment.failed` | The payment didn't go through and no money was taken. Try again, choose another method, or pay cash on delivery. | পেমেন্ট হয়নি, কোনো টাকা কাটা হয়নি। আবার চেষ্টা করুন, অন্য মাধ্যম বেছে নিন, বা ক্যাশ অন ডেলিভারিতে নিন। | "No money was taken" goes first. It is the only sentence the customer is actually worried about |
| `error.payment.cancelled` | You cancelled the payment. Your cart is still here. | আপনি পেমেন্ট বাতিল করেছেন। আপনার কার্ট ঠিক আছে। | |
| `error.payment.timeout` | The payment page timed out. Nothing was charged. | পেমেন্ট পেজের সময় শেষ হয়ে গেছে। কোনো টাকা কাটা হয়নি। | |
| `error.cod.unavailable` | Cash on delivery isn't available for this area. Please pay now instead. | এই এলাকায় ক্যাশ অন ডেলিভারি নেই। এখনই পেমেন্ট করুন। | |
| `error.cod.advance_required` | This area needs {amount} paid in advance. The rest is cash on delivery. | এই এলাকার জন্য {amount} অগ্রিম দিতে হবে। বাকিটা ক্যাশ অন ডেলিভারি। | |
| `error.review.rating_required` | Give it a star rating first | আগে স্টার রেটিং দিন | |
| `error.review.body_too_short` | Write a little more — at least 20 characters | আরেকটু লিখুন — কমপক্ষে 20টি অক্ষর | |
| `error.upload.too_large` | {filename} is bigger than 5MB | {filename} 5MB-এর চেয়ে বড় | |
| `error.upload.wrong_type` | Only JPG and PNG photos | শুধু JPG আর PNG ছবি | |
| `error.form.summary` | {count} fields need fixing | {count}টি ঘর ঠিক করতে হবে | Rendered above the form, focusable, links to each error |

### A.17 Notification templates

**Channel policy, straight from PRD §5 and §6a.** Order and shipment updates go out as **WhatsApp utility messages** (≈৳0.45 each, cheaper than a masked SMS and 2–3× cheaper than a multi-segment Bangla SMS). **OTP goes out on SMS** — Bangladesh sits on Meta's *authentication-international* tier at roughly 26× the domestic authentication rate, so OTP on WhatsApp is the single most expensive mistake available here. SMS for order updates exists only as a **fallback** when the customer has no WhatsApp or the template send fails, and the table below is what that fallback costs.

**Encoding rules that are load-bearing on cost.**
- English SMS must stay inside GSM-7: 160 characters per segment, 153 per segment once concatenated. Every English template below is verified GSM-7 clean. Three characters routinely break this and must never enter an English SMS: `৳` (use `BDT`), the en-dash `–` (use `-`), and curly quotes `’ “ ”` (use straight).
- Bangla SMS is UCS-2 by definition: **70 characters per segment, 67 per segment once concatenated.** There is no way around this; Bengali is outside GSM-7.
- Counts below are of the **rendered** message with representative values (`TM10482`, `BDT 2450`, `2` items, `482917`, `PT4471920`), not the template with placeholders. Any variable longer than the sample pushes the count up — order numbers are fixed-width by design, which is why they are.
- The short link domain `tmbd.co` is a deliberate 7-character choice. `toymartbd.com/track/TM10482` would be 27 characters and would push three Bangla templates into a third segment.

#### A.17.1 SMS — character and segment counts, both languages

| key | rendered text | EN chars | EN seg | BN chars | BN seg | BN cost vs EN |
|---|---|---|---|---|---|---|
| `sms.otp` | **EN** Toy Mart BD verification code: 482917. Valid 5 minutes. Do not share this code.<br>**BN** টয় মার্ট বিডি কোড: 482917। 5 মিনিট কাজ করবে। কাউকে দেবেন না। | 79 | **1** | 61 | **1** | 1.0x |
| `sms.order.placed` | **EN** Toy Mart BD: order TM10482 received. BDT 2450, cash on delivery. We will call you to confirm. tmbd.co/TM10482<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 পেয়েছি। BDT 2450, ক্যাশ অন ডেলিভারি। কনফার্ম করতে ফোন করব। tmbd.co/TM10482 | 109 | **1** | 106 | **2** | 2.0x |
| `sms.order.confirmed` | **EN** Toy Mart BD: order TM10482 is confirmed and being packed. Delivery in 2-3 days. tmbd.co/TM10482<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 কনফার্ম হয়েছে, প্যাক করা হচ্ছে। 2-3 দিনে ডেলিভারি। tmbd.co/TM10482 | 95 | **1** | 98 | **2** | 2.0x |
| `sms.order.shipped` | **EN** Toy Mart BD: order TM10482 handed to Pathao. Consignment PT4471920. The rider will call before delivery. tmbd.co/TM10482<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 পাঠাওকে দেওয়া হয়েছে। কনসাইনমেন্ট PT4471920। রাইডার ফোন করবে। tmbd.co/TM10482 | 120 | **1** | 109 | **2** | 2.0x |
| `sms.order.out_for_delivery` | **EN** Toy Mart BD: order TM10482 is out for delivery today. Please keep BDT 2450 ready. Rider: 01712345678<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 আজ ডেলিভারি হবে। BDT 2450 হাতে রাখুন। রাইডার: 01712345678 | 100 | **1** | 88 | **2** | 2.0x |
| `sms.order.out_for_delivery.short` | **EN** Toy Mart BD: TM10482 out for delivery today. Keep BDT 2450 ready.<br>**BN** টয় মার্ট: TM10482 আজ ডেলিভারি হবে। BDT 2450 হাতে রাখুন। | 65 | **1** | 56 | **1** | 1.0x |
| `sms.order.delivered` | **EN** Toy Mart BD: order TM10482 delivered. Thank you. 7-day return applies. Problem? Call 09600123456<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 ডেলিভারি হয়েছে। ধন্যবাদ। 7 দিনের রিটার্ন সুবিধা আছে। সমস্যা হলে: 09600123456 | 96 | **1** | 108 | **2** | 2.0x |
| `sms.order.cancelled` | **EN** Toy Mart BD: order TM10482 has been cancelled. Nothing is owed. To order again: toymartbd.com<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482 বাতিল করা হয়েছে। কোনো টাকা দিতে হবে না। আবার অর্ডার: toymartbd.com | 93 | **1** | 98 | **2** | 2.0x |
| `sms.cart.abandoned` | **EN** Toy Mart BD: your cart is still saved. 2 items, BDT 2450. Finish the order: tmbd.co/c/9FJ2K<br>**BN** টয় মার্ট বিডি: আপনার কার্টের পণ্য এখনো রাখা আছে। 2টি পণ্য, BDT 2450। অর্ডার শেষ করুন: tmbd.co/c/9FJ2K | 91 | **1** | 102 | **2** | 2.0x |
| `sms.review.request` | **EN** Toy Mart BD: how was the toy from order TM10482? A short review helps other parents: tmbd.co/r/TM10482<br>**BN** টয় মার্ট বিডি: অর্ডার TM10482-এর খেলনাটি কেমন লাগল? আপনার রিভিউ অন্য অভিভাবকদের উপকার করবে: tmbd.co/r/TM10482 | 102 | **1** | 110 | **2** | 2.0x |

**Totals across the nine templates (excluding the `.short` fallback): English 9 segments, Bangla 17 segments — Bangla SMS costs 1.9x English for the identical message set.**

**What this table means for the budget.** At a nominal ৳0.35 per masked SMS segment, 1,000 orders a month running the five transactional order templates costs **৳1,750 in English and ৳3,500 in Bangla**. Since roughly 70% of customers will be on the Bangla locale, the all-SMS fallback path is the expensive one, and WhatsApp utility at ৳0.45 for the same five messages costs ৳2,250 regardless of language. **Two consequences for the build:** WhatsApp is not a nice-to-have, it is the thing that makes a Bangla launch affordable; and `sms.otp` must be held under 70 Bangla characters forever — it is the one template that cannot move to WhatsApp, and it is the highest-volume template we send. The Bangla OTP above is 61 characters, leaving 9 characters of headroom. A CI test asserts it.

`sms.order.out_for_delivery.short` is included as the deliberate one-segment Bangla fallback for the highest-volume order update: it drops the brand to `টয় মার্ট` and the rider's number, and fits 56 characters. Use it only when the SMS budget is the binding constraint; the rider's number is genuinely useful and worth the second segment when it is not.

#### A.17.2 WhatsApp utility templates

Meta requires pre-approved templates with positional variables `{{1}}`, `{{2}}`…, submitted per language (`en` and `bn_BD`). Bangla templates must be submitted separately and approved separately — budget two weeks. No marketing language may appear in a utility template or Meta will reject or reclassify it, which is why none of these contain an offer. Each gets a **Track order** URL button rather than an inline link.

| template name | lang | body | variables |
|---|---|---|---|
| `order_placed` | en | Hello {{1}}, we have your Toy Mart BD order *{{2}}*. {{3}} items, total BDT {{4}}, cash on delivery. We will call this number within 2 hours on working days to confirm. Keep BDT {{4}} ready for the rider on delivery day. | 1 name, 2 order_no, 3 item_count, 4 total |
| `order_placed` | bn_BD | {{1}}, আপনার টয় মার্ট বিডি অর্ডার *{{2}}* আমাদের কাছে পৌঁছেছে। {{3}}টি পণ্য, সর্বমোট BDT {{4}}, ক্যাশ অন ডেলিভারি। কর্মদিবসে 2 ঘণ্টার মধ্যে এই নম্বরে ফোন করে কনফার্ম করব। ডেলিভারির দিন রাইডারের জন্য BDT {{4}} হাতে রাখুন। | same |
| `order_confirmed` | en | Your order *{{1}}* is confirmed and being packed. Expected delivery {{2}}. If you need to change the address or anything else, reply here or call 09600-123456 today. | 1 order_no, 2 date_range |
| `order_confirmed` | bn_BD | আপনার অর্ডার *{{1}}* কনফার্ম হয়েছে, প্যাক করা হচ্ছে। ডেলিভারি হবে {{2}}। ঠিকানা বা অন্য কিছু বদলাতে হলে আজই এখানে উত্তর দিন বা 09600-123456 নম্বরে ফোন করুন। | same |
| `order_shipped` | en | Order *{{1}}* has been handed to {{2}}. Tracking number {{3}}. The rider will call before delivery, usually in {{4}}. | 1 order_no, 2 courier, 3 consignment, 4 eta |
| `order_shipped` | bn_BD | অর্ডার *{{1}}* {{2}}-কে দিয়ে দেওয়া হয়েছে। ট্র্যাকিং নম্বর {{3}}। ডেলিভারির আগে রাইডার ফোন করবে, সাধারণত {{4}}-এর মধ্যে। | same |
| `order_out_for_delivery` | en | Order *{{1}}* is out for delivery today. Please keep BDT {{2}} ready. The rider's number is {{3}}. If nobody will be home, reply here and we will reschedule. | 1 order_no, 2 cod_amount, 3 rider_phone |
| `order_out_for_delivery` | bn_BD | অর্ডার *{{1}}* আজ ডেলিভারি হবে। BDT {{2}} হাতে রাখুন। রাইডারের নম্বর {{3}}। বাসায় কেউ না থাকলে এখানে উত্তর দিন, আমরা অন্যদিন পাঠাব। | same |
| `order_delivered` | en | Order *{{1}}* was delivered on {{2}}. Thank you. If anything is wrong — wrong item, damage, missing part — tell us within 7 days and we will take it back. | 1 order_no, 2 date |
| `order_delivered` | bn_BD | অর্ডার *{{1}}* {{2}}-এ ডেলিভারি হয়েছে। ধন্যবাদ। কিছু ভুল হলে — ভুল পণ্য, ভাঙা, অংশ কম — 7 দিনের মধ্যে জানান, আমরা ফেরত নেব। | same |
| `order_cancelled` | en | Order *{{1}}* has been cancelled. Nothing is owed. {{2}} If this was not you, call 09600-123456 today. | 1 order_no, 2 refund_sentence (empty when nothing was paid) |
| `order_cancelled` | bn_BD | অর্ডার *{{1}}* বাতিল করা হয়েছে। কোনো টাকা দিতে হবে না। {{2}} আপনি না করলে আজই 09600-123456 নম্বরে ফোন করুন। | same |
| `cart_reminder` | en | Hello {{1}}, the {{2}} toys in your Toy Mart BD cart are still saved, total BDT {{3}}. They are not reserved — stock moves. Open your cart to finish. | 1 name, 2 item_count, 3 total |
| `cart_reminder` | bn_BD | {{1}}, আপনার টয় মার্ট বিডি কার্টে {{2}}টি খেলনা এখনো রাখা আছে, সর্বমোট BDT {{3}}। এগুলো আটকে রাখা নেই — স্টক শেষ হয়ে যেতে পারে। কার্ট খুলে অর্ডার শেষ করুন। | same |
| `review_request` | en | How was the {{1}} from order *{{2}}*? Two lines from you helps the next parent decide — especially the age it actually suited. | 1 product, 2 order_no |
| `review_request` | bn_BD | অর্ডার *{{2}}*-এর {{1}} কেমন লাগল? আপনার দুই লাইন পরের অভিভাবককে সিদ্ধান্ত নিতে সাহায্য করবে — বিশেষ করে কত বছরের বাচ্চার জন্য ঠিক হলো সেটা। | same |

`cart_reminder` and `review_request` sit at the boundary of Meta's utility category. Submit both as **utility** with the copy above; if either is reclassified as marketing, send it over SMS instead and accept the Bangla two-segment cost, rather than rewriting it into something that sounds like an advertisement.

#### A.17.3 Email — subject and preheader

Email is secondary (the field is optional) and carries the invoice. Subject budget 50 characters so it does not truncate in Gmail Android; preheader 90 characters.

| key | lang | subject | preheader |
|---|---|---|---|
| `email.order_placed` | en | Order TM10482 received — Toy Mart BD | We will call {phone} within 2 hours to confirm. Invoice attached. |
| `email.order_placed` | bn | অর্ডার TM10482 পেয়েছি — টয় মার্ট বিডি | কনফার্ম করতে 2 ঘণ্টার মধ্যে {phone} নম্বরে ফোন করব। ইনভয়েস সঙ্গে আছে। |
| `email.order_confirmed` | en | Order TM10482 confirmed | Packed and ready. Expected delivery {date_range}. |
| `email.order_confirmed` | bn | অর্ডার TM10482 কনফার্ম হয়েছে | প্যাক করা হয়ে গেছে। ডেলিভারি হবে {date_range}। |
| `email.order_shipped` | en | Order TM10482 is on the way | {courier} has it. Tracking {consignment}. The rider will call first. |
| `email.order_shipped` | bn | অর্ডার TM10482 পথে আছে | {courier}-এর কাছে আছে। ট্র্যাকিং {consignment}। রাইডার আগে ফোন করবে। |
| `email.order_out_for_delivery` | en | Arriving today — order TM10482 | Keep {amount} ready. The rider's number is {rider_phone}. |
| `email.order_out_for_delivery` | bn | আজ পৌঁছাবে — অর্ডার TM10482 | {amount} হাতে রাখুন। রাইডারের নম্বর {rider_phone}। |
| `email.order_delivered` | en | Delivered — order TM10482 | Thank you. Anything wrong, tell us within 7 days and we take it back. |
| `email.order_delivered` | bn | ডেলিভারি হয়েছে — অর্ডার TM10482 | ধন্যবাদ। কিছু ভুল হলে 7 দিনের মধ্যে জানান, আমরা ফেরত নেব। |
| `email.order_cancelled` | en | Order TM10482 cancelled | Nothing is owed. Call 09600-123456 if this was not you. |
| `email.order_cancelled` | bn | অর্ডার TM10482 বাতিল হয়েছে | কোনো টাকা দিতে হবে না। আপনি না করলে 09600-123456-এ ফোন করুন। |
| `email.otp` | en | Your Toy Mart BD code is 482917 | Valid 5 minutes. We never ask for this code on a call. |
| `email.otp` | bn | আপনার টয় মার্ট বিডি কোড 482917 | 5 মিনিট কাজ করবে। ফোনে আমরা কখনো এই কোড চাই না। |
| `email.cart_reminder` | en | Your cart is still saved | 2 toys, BDT 2450. Stock is not reserved. |
| `email.cart_reminder` | bn | আপনার কার্ট এখনো রাখা আছে | 2টি খেলনা, BDT 2450। স্টক আটকে রাখা নেই। |
| `email.review_request` | en | How was the toy? | Two lines from you helps the next parent choose. |
| `email.review_request` | bn | খেলনাটি কেমন লাগল? | আপনার দুই লাইন পরের অভিভাবকের উপকার করবে। |

`email.otp` exists because an OTP can be requested by email on the secondary login path. The "we never ask for this code on a call" line is anti-fraud copy and must survive translation review intact.

### A.18 The 14 Bangla terms flagged for native review

Nothing below is blank; each is my best version with the alternative I rejected and why. A Bangladeshi parent — ideally two, one Dhaka and one outside — should confirm or replace each, after which this table is deleted and the strings are final.

| # | key(s) | my version | alternative considered | why I chose it |
|---|---|---|---|---|
| 1 | `nav.link.wishlist` | পছন্দের তালিকা | উইশলিস্ট | Bangla reads better in a footer; the transliteration may be more recognisable in the header. Possibly both, by position |
| 2 | `product.card.price.save` | {amount} বাঁচল | {amount} সেভ হলো | `সেভ` is what people say; `বাঁচল` is what a shopkeeper says. Mid-range register call |
| 3 | `pdp.price.save_note`, `cart.summary.savings` | সাশ্রয় | বাঁচল / কম পড়ল | `সাশ্রয়` is slightly formal; acceptable in a summary row, maybe not on a badge |
| 4 | `pdp.spec.certification`, `pdp.safety.bsti` | সনদ / সনদপ্রাপ্ত | সার্টিফিকেশন / সার্টিফায়েড | Must match BSTI's own Bangla wording once PRD §7 item 9a is resolved. **Blocked on that answer** |
| 5 | `pdp.delivery.cod_advance`, `checkout.payment.cod.advance_note` | অগ্রিম | অ্যাডভান্স | `অগ্রিম` is correct; `অ্যাডভান্স` is what couriers say on the phone |
| 6 | `checkout.field.landmark.label` | কাছের চেনা জায়গা | ল্যান্ডমার্ক / কাছের নিশানা | This field directly reduces failed deliveries, so the label has to be instantly understood. Highest-priority item in this table |
| 7 | `status.partially_delivered.label` | কিছু অংশ পৌঁছেছে | আংশিক ডেলিভারি | Chose the plain phrase over the formal compound |
| 8 | `status.hold.label` | আটকে আছে | সাময়িক স্থগিত | Chose honest over official. Confirm it does not read as alarming |
| 9 | `reviews.filter.verified`, `reviews.card.verified` | যাঁরা সত্যিই কিনেছেন / এটি কিনেছেন | ভেরিফায়েড ক্রেতা | The point is credibility, not a label. But it is long for a filter chip |
| 10 | `track.consignment.label` | ট্র্যাকিং নম্বর | কনসাইনমেন্ট নম্বর | `কনসাইনমেন্ট` stays in the admin; confirm customers do not expect the courier's own word |
| 11 | `cart.summary.subtotal` | পণ্যের মোট দাম | উপমোট / সাবটোটাল | Rejected `উপমোট` as dictionary Bangla. Mine is longer — check it fits the summary column |
| 12 | `listing.filter.gender.*` | ছেলেদের / মেয়েদের / সব বাচ্চার | ছেলে / মেয়ে / সবার | Possessive form reads more natural as a filter; confirm |
| 13 | `nav.megamenu.featured` | বাছাই করা | আমাদের পছন্দ | "Picked for you" has no clean short Bangla. Confirm `বাছাই করা` does not read as "sorted" |
| 14 | `trust.authentic.detail` | আমদানিকারকের কাছ থেকে কেনা, কাগজসহ | আসল পণ্যের নিশ্চয়তা | Mine is a verifiable claim rather than a promise, which is the point — but confirm the phrasing is natural |

Also for the reviewer, not a term choice: **`error.*` strings are the highest-stakes translations in the file.** A validation message that reads as blame costs a checkout. Read all 36 as a set, out loud, as if speaking to a customer.

### A.19 String count

| Section | Strings |
|---|---|
| A.1 Navigation and mega menu | 34 |
| A.2 Search | 24 |
| A.3 Product card | 20 |
| A.4 Product detail | 94 |
| A.5 Cart | 33 |
| A.6 Checkout | 73 |
| A.7 Order confirmation | 18 |
| A.8 Tracking (16) + shipment status enum (24) | 40 |
| A.9 Account | 72 |
| A.10 Category listing, filters and sort | 30 |
| A.11 Reviews | 32 |
| A.12 Footer | 34 |
| A.13 Trust strip | 10 |
| A.14 Policy and static page titles | 15 |
| A.15 Toasts, confirmations, notices | 27 |
| A.16 Validation | 48 |
| Part C — 11 states × (headline + body + primary action) + 5 secondary actions | 38 |
| **Total UI strings** | **642** |
| A.17.1 SMS (10 templates × 2 languages) | 20 |
| A.17.2 WhatsApp utility (9 templates × 2 languages) | 18 |
| A.17.3 Email subject + preheader (9 × 2 languages × 2 fields) | 36 |
| **Total notification fields** | **74** |

Every one is written in both English and Bangla. There is no TBD in this file.

---

## Part B — Iconography

**System:** Lucide React, **1.75px stroke**, sizes **16 / 20 / 24** only. 16px sits inline with `body-sm` and `caption`; 20px is the default UI size and the only size used in buttons; 24px is for the mobile tab bar, the PDP action row and empty-state glyphs. No other size, no stroke override, no fill. Icons inherit `currentColor` — never a hardcoded hex.

Rules:
- An icon-only button always has an `aria-label` from the string set, and a 44×44px touch target regardless of the glyph size.
- An icon never carries meaning alone. Status, risk and stock are always icon **plus** text.
- Decorative icons get `aria-hidden="true"`.
- **Lucide renamed many icons at v1.** Where a name is commonly confused, the old alias is noted in brackets. Pin an exact Lucide version in `package.json` and import by the current name.

### B.1 Icons by surface

**Header, navigation, mobile tab bar**

| Need | Lucide | Size |
|---|---|---|
| Search | `Search` | 20 |
| Cart | `ShoppingCart` | 20 / 24 |
| Wishlist / saved | `Heart` (filled via `fill="currentColor"` when active) | 20 |
| Account | `User` | 20 / 24 |
| Home (tab bar) | `House` [was `Home`] | 24 |
| Categories (tab bar) | `LayoutGrid` | 24 |
| Open menu | `Menu` | 24 |
| Close / dismiss | `X` | 20 |
| Mega-menu expand | `ChevronDown` | 16 |
| Mega-menu / breadcrumb separator | `ChevronRight` | 16 |
| Back | `ChevronLeft` | 24 |
| Language switch | `Languages` | 20 |
| Phone in header | `Phone` | 16 |
| Announcement bar close | `X` | 16 |

**Search and listing**

| Need | Lucide | Size |
|---|---|---|
| Clear input | `X` | 16 |
| Recent search | `Clock` [`History` for the "see all recent" link] | 16 |
| Suggestion is a category | `LayoutGrid` | 16 |
| Suggestion is a brand | `Tag` | 16 |
| Filters | `SlidersHorizontal` | 20 |
| Sort | `ArrowUpDown` | 20 |
| Remove filter chip | `X` | 16 |
| Grid / list view toggle | `LayoutGrid` / `List` | 20 |
| Pagination arrows | `ChevronLeft` / `ChevronRight` | 20 |
| Loading | `LoaderCircle` [was `Loader2`], `animate-spin` | 16 / 20 |
| Zero results | `SearchX` | 24 |

**Product card and product detail**

| Need | Lucide | Size |
|---|---|---|
| Rating star, full | `Star` with `fill` | 16 |
| Rating star, half | `StarHalf` with `fill` | 16 |
| Rating star, empty | `Star` no fill | 16 |
| In stock | `Check` | 16 |
| Low stock | `TriangleAlert` [was `AlertTriangle`] | 16 |
| Out of stock | `CircleSlash` | 16 |
| Notify when back | `BellRing` | 20 |
| Quantity increase / decrease | `Plus` / `Minus` | 20 |
| Gallery next / prev | `ChevronRight` / `ChevronLeft` | 24 |
| Zoom | `ZoomIn` | 20 |
| Fullscreen lightbox | `Maximize2` | 20 |
| Video in gallery | `Play` | 24 |
| Share | `Share2` | 20 |
| Copy link | `Copy` → `Check` on success | 20 |
| Spec: material | `Layers` | 16 |
| Spec: batteries | `BatteryFull` | 16 |
| Spec: dimensions | `Ruler` | 16 |
| Spec: weight | `Weight` | 16 |
| Spec: country of origin | `Globe` | 16 |
| Spec: warranty | `ShieldCheck` | 16 |
| Spec: in the box | `PackageOpen` | 16 |
| Safety note | `TriangleAlert` | 16 |
| Age guidance link | `CircleHelp` [was `HelpCircle`] | 16 |
| Delivery estimator | `Truck` | 20 |
| Area / district picker | `MapPin` | 16 |
| Landmark hint | `Signpost` | 16 |
| Accordion expand | `ChevronDown` / `ChevronUp` | 20 |

**Cart, checkout, payment**

| Need | Lucide | Size |
|---|---|---|
| Remove line item | `Trash2` | 20 |
| Undo remove | `Undo2` | 16 |
| Coupon | `TicketPercent` | 20 |
| Discount applied | `Check` | 16 |
| Edit cart / address | `Pencil` | 16 |
| Add new address | `Plus` | 20 |
| Saved address | `MapPin` | 20 |
| Locked / secure | `Lock` | 16 |
| Card payment | `CreditCard` | 20 |
| Cash on delivery | **custom — see B.2** | 20 |
| Mobile wallet (generic) | `Smartphone` | 20 |
| Order summary toggle | `ChevronUp` / `ChevronDown` | 20 |
| Validation error | `CircleAlert` [was `AlertCircle`] | 16 |
| Field valid | `Check` | 16 |
| Show / hide password | `Eye` / `EyeOff` | 20 |

**Order confirmation, tracking, status**

| Need | Lucide | Size |
|---|---|---|
| Success | `CircleCheck` [was `CheckCircle2`] | 24 |
| Download invoice | `Download` | 20 |
| Print | `Printer` | 20 |
| `pending` | `Receipt` | 20 |
| `booked` | `PackageCheck` | 20 |
| `picked_up` | `PackageOpen` | 20 |
| `in_transit` | `Truck` | 20 |
| `out_for_delivery` | `Bike` | 20 |
| `delivered` | `CircleCheck` | 20 |
| `partially_delivered` | **custom — see B.2** | 20 |
| `hold` | `CirclePause` | 20 |
| `cancelled` | `CircleX` [was `XCircle`] | 20 |
| `returning` | `Undo2` | 20 |
| `returned` | `PackageMinus` | 20 |
| `lost` | `PackageX` | 20 |
| Timeline step complete | `Check` | 16 |
| Timeline step current | `CircleDot` | 16 |
| Timeline step future | `Circle` | 16 |

**Account**

| Need | Lucide | Size |
|---|---|---|
| Orders | `Package` | 20 |
| Addresses | `MapPin` | 20 |
| Wishlist | `Heart` | 20 |
| Reviews written | `MessageSquareQuote` | 20 |
| Profile | `UserRound` | 20 |
| Log in | `LogIn` | 20 |
| Log out | `LogOut` | 20 |
| OTP / verification code | `KeyRound` | 20 |
| Phone field | `Smartphone` | 16 |
| Email field | `Mail` | 16 |
| Default address badge | `Star` | 16 |
| Reorder | `RotateCcw` | 16 |
| Delete account | `Trash2` | 20 |

**Reviews**

| Need | Lucide | Size |
|---|---|---|
| Write a review | `PenLine` | 20 |
| Verified buyer | `BadgeCheck` | 16 |
| Helpful | `ThumbsUp` | 16 |
| Report | `Flag` | 16 |
| Add photo | `Camera` | 20 |
| Seller reply | `CornerDownRight` | 16 |
| Photo failed to load | `ImageOff` | 20 |

**Trust strip and footer**

| Need | Lucide | Size |
|---|---|---|
| Cash on delivery | **custom — see B.2** | 24 |
| 7-day return | `RotateCcw` | 24 |
| Genuine products | `BadgeCheck` | 24 |
| We answer the phone | `Headset` | 24 |
| Age-checked | **custom — see B.2** | 24 |
| BSTI certified | **custom — see B.2** | 20 |
| Phone | `Phone` | 16 |
| Email | `Mail` | 16 |
| Shop address | `MapPin` | 16 |
| Opening hours | `Clock` | 16 |
| Newsletter submit | `ArrowRight` | 16 |
| External link | `ExternalLink` | 12→16 |
| WhatsApp FAB | **brand asset — see B.2** | 24 |
| Facebook / Instagram / YouTube | **brand assets — see B.2** | 20 |

**System states, toasts, error pages**

| Need | Lucide | Size |
|---|---|---|
| Info | `Info` | 20 |
| Success toast | `CircleCheck` | 20 |
| Warning | `TriangleAlert` | 20 |
| Error | `CircleAlert` | 20 |
| Offline | `WifiOff` | 20 |
| Slow / retrying | `LoaderCircle` | 20 |
| Retry | `RefreshCw` | 20 |
| 404 | `Compass` (supporting glyph only; the illustration carries the page) | 24 |
| 500 | `ServerCrash` | 24 |
| Maintenance | `Wrench` | 24 |

**Admin, MVP only** (the two admin illustrated states in Part C need these)

| Need | Lucide | Size |
|---|---|---|
| Dashboard | `LayoutDashboard` | 20 |
| Catalog | `Boxes` | 20 |
| Inventory | `Warehouse` | 20 |
| Orders | `ClipboardList` | 20 |
| Fulfilment | `Truck` | 20 |
| Risk | `ShieldAlert` | 20 |
| Finance | `Wallet` | 20 |
| Customers | `Users` | 20 |
| Marketing | `Megaphone` | 20 |
| CMS | `FileText` | 20 |
| SEO | `ChartNoAxesColumn` | 20 |
| System settings | `Settings` | 20 |
| Courier provider not connected | `Unplug` | 24 |
| Stock empty | `PackageOpen` | 24 |
| Bulk select | `SquareCheck` | 16 |
| Row drag handle | `GripVertical` | 16 |
| Column visibility | `Columns3` | 20 |
| CSV export | `FileDown` | 20 |
| Date range | `CalendarRange` | 20 |
| Saved view | `Bookmark` | 16 |
| Row actions | `EllipsisVertical` [was `MoreVertical`] | 20 |
| Trend up / down | `TrendingUp` / `TrendingDown` | 16 |
| Theme toggle | `Sun` / `Moon` | 20 |

### B.2 Needs with no good Lucide equivalent

Six. Each is drawn as a **custom Lucide-compatible outline icon**: 24×24 viewBox, 1.75px stroke, round caps and joins, `currentColor`, no fill, built on the same 2px-inset grid as Lucide so it sits in a row of Lucide icons without reading as foreign. Delivered as an SVG sprite plus a React wrapper matching the Lucide prop signature.

1. **`CashOnDelivery`** — Lucide has `Banknote` and `Truck` but nothing combining them, and this is the single most important concept on the storefront. Form: a banknote rectangle with a small `৳` in it, front-left; behind and to the upper right, the cab-and-box silhouette of a delivery van, clipped so the two shapes overlap by about 3px. Reads at 20px as "money + van".

2. **`AgeChecked`** — no Lucide glyph says "this toy's age range has been verified." `Baby` only covers 0–2 and reads as infant-only. Form: a rounded-square badge outline containing the characters `3+` set in the UI font at 10px, with a small checkmark notched into the badge's lower-right corner. The number is a prop, so the same icon renders `0+`, `3+`, `6+`, `8+`.

3. **`PartiallyDelivered`** — `Package` variants cover checked, opened, minus and X, but there is no "some of it arrived." Form: the standard Lucide `Package` cube, with the right-hand vertical half of the cube drawn in a 2px dashed stroke while the left half stays solid. Unambiguous next to `PackageCheck` in a timeline.

4. **`BstiCertified`** — a regulatory mark, not a UI icon. Form: a seven-lobed scalloped seal outline (the common certification-rosette silhouette) with `BSTI` on two lines inside at 7px. **Do not reproduce BSTI's actual logo** — using a regulator's mark without authorisation is its own problem. This is a neutral seal that sits beside the text label `বিএসটিআই সনদপ্রাপ্ত`, and it ships only once PRD §7 item 9a is answered and the business actually holds the certificates.

5. **`PinchZoom`** — the mobile gallery needs a one-time affordance hint and Lucide has no gesture icons. Form: two small circles (thumb and finger contact points) on a diagonal, each with a short motion tick pointing outward, inside a rounded-rectangle frame. Shown once per session as a 24px overlay, then dismissed forever.

6. **`AdvancePayment`** — for the COD-advance notice, where the concept is "part now, rest later." Form: a banknote rectangle split by a vertical dashed line, the left portion carrying a small solid dot. Appears only beside `pdp.delivery.cod_advance`.

**Brand marks — not icons, and not Lucide's job.** Lucide deprecated and removed brand glyphs, so every one of these is supplied as an official SVG from the brand's own press kit, kept in `public/brands/`, rendered at its own correct colours (not `currentColor`), never restyled, never recoloured, and never put on a `bg.brand` surface where its own colour fails contrast:

WhatsApp · Facebook · Instagram · YouTube · bKash · Nagad · Rocket · Upay · Visa · Mastercard · American Express · SSLCommerz · Pathao · Steadfast.

Payment and courier marks go in a single `PaymentMarks` / `CourierMark` component so that adding a provider is one file. Each needs an `alt`/`title` from the string set (`checkout.payment.method.*`).

### B.3 Custom category icons

**Specification.** Flat, **two-tone**, no outline, no gradient, no shadow. Tone 1 is `brand.500` (#F4453A), tone 2 is `accent.500` (#F7B500); a third tone `neutral.900` at 100% is permitted only for pupils, keyholes and other sub-2px details, and never for more than 5% of the icon area. Drawn on a **48×48 artboard with a 4px safe margin**, so every shape lives inside 40×40. Minimum shape width 3px so nothing disappears when the tile renders at 32px on a 360px viewport. Corners are rounded at 2px minimum — no sharp vertices anywhere, which is the single rule that makes a flat icon set read as a toy shop rather than a bank. Exported as individually optimised SVG, ≤2KB each, `viewBox="0 0 48 48"`, colours as `currentColor`-independent literals (these icons are decorative and do not theme), plus a 96px PNG fallback for the Facebook/WhatsApp share card.

Each tile shows the icon above the category name, two-line clamped, in the active locale.

| # | slug | en | bn | icon form |
|---|---|---|---|---|
| 1 | `blocks-building` | Blocks & building sets | ব্লক ও বিল্ডিং সেট | Three studded bricks stacked off-centre, the bottom one brand, the top two accent, one stud visibly larger than the rest |
| 2 | `dolls-soft-toys` | Dolls & soft toys | পুতুল ও নরম খেলনা | A seated teddy silhouette in brand, with accent used for the muzzle oval, both inner ears and the belly patch; no facial expression, only two dark dot eyes |
| 3 | `cars-remote-control` | Cars & remote control | গাড়ি ও রিমোট কন্ট্রোল | A chunky side-view car body in brand with accent wheels and window, and a short accent antenna rising from the roof with a dot at its tip |
| 4 | `educational-stem` | Learning & STEM | শেখার ও স্টেম খেলনা | A brand conical flask with an accent liquid level and three accent bubbles rising, one bubble escaping past the flask's rim |
| 5 | `puzzles-board-games` | Puzzles & board games | পাজল ও বোর্ড গেম | Two interlocked jigsaw pieces, upper-left brand and lower-right accent, with a single accent die resting on the lower piece's corner |
| 6 | `baby-toddler` | Baby & toddler (0–2) | শিশু ও ছোট বাচ্চা (0–2 বছর) | A stacking-ring tower: brand base, three accent rings of decreasing width, topped by a small brand ball |
| 7 | `outdoor-ride-on` | Outdoor & ride-on | বাইরে খেলার ও চড়ার খেলনা | A tricycle in side view, brand frame and seat, accent for the large front wheel and both handlebar grips |
| 8 | `art-craft` | Art & craft | আঁকাআঁকি ও হাতের কাজ | Three crayons fanned from a single lower point, outer two brand, middle accent, each with a flat-cut tip and a visible wrapper band |
| 9 | `sports-cycles` | Sports & cycles | খেলাধুলা ও সাইকেল | A brand football with accent hexagonal panels, overlapped at the lower right by an accent badminton racquet head |
| 10 | `action-figures` | Action figures & heroes | অ্যাকশন ফিগার ও হিরো | A standing figure with arms raised, body in brand, with an accent cape sweeping right and an accent chest emblem diamond |
| 11 | `kitchen-role-play` | Kitchen & pretend play | রান্নাবাটি ও সাজানো খেলা | A brand frying pan seen from above with an accent handle and two accent fried-egg circles, plus a small accent spatula crossing behind |
| 12 | `musical` | Musical instruments | বাদ্যযন্ত্র | A brand toy drum with two accent tension lines, one accent drumstick resting on the skin and one lifted above it |
| 13 | `water-beach` | Water & beach | পানি ও সৈকতের খেলনা | A brand bucket with an accent handle arc, an accent spade crossing behind it, and three short accent water droplets at the base |
| 14 | `school-stationery` | School & stationery | স্কুল ও স্টেশনারি | A brand backpack with an accent front pocket and two accent strap loops, and one accent pencil tip emerging from the top opening |
| 15 | `electronic-toys` | Electronic toys | ইলেকট্রনিক খেলনা | A boxy brand robot head with an accent screen face, two accent dot eyes and a single accent antenna with a ball tip |
| 16 | `party-gifts` | Party & gifts | পার্টি ও উপহার | A brand gift box with an accent ribbon cross and bow, with an accent party hat tilted against its left side |
| 17 | `traditional-bd` | Bangladeshi traditional toys | দেশীয় ঐতিহ্যবাহী খেলনা | A brand `নাগরদোলা` (ferris-wheel) outline wheel with four accent spokes and two accent seat trapezoids at opposite ends |
| 18 | `gift-finder` | Not sure? Find a gift | বুঝতে পারছেন না? উপহার খুঁজুন | **Utility tile, not a category.** A brand magnifier ring whose lens holds a small accent gift box. P2 feature; the tile ships at launch linking to `shop by age` |

Seventeen real top-level categories plus one utility tile. Two notes for the catalog owner: **`traditional-bd` is the one no competitor has**, and it is cheap to stock and strongly differentiating — mud dolls, নাগরদোলা, কাঠের পুতুল, bamboo flutes. And `baby-toddler` must be an age-defined top-level category even though its contents overlap other categories, because "what can I buy for a 1-year-old" is the single most common gift question a toy shop receives.

Delivery: one `category-icons.svg` sprite plus individual files, named `cat-{slug}.svg` matching the slugs above exactly, so the CMS can resolve an icon from the category record without a mapping table.

---

## Part C — Illustration and empty states

**Illustration is allowed in eleven places and nowhere else.** The permitted set is exactly the eleven states below: nine storefront, two admin. No illustration on the home page, in a category tile, on a product card, in a banner, in the footer, beside a form field, or over a photograph. If a new screen wants an illustration, the answer is no; it wants better copy.

### C.0 The single illustration style

**Flat two-tone cut-paper vector, objects only, no faces.**

| Property | Rule |
|---|---|
| Subject | Always an **object** — a basket, a parcel, a kite, a shelf. **Never a person, never a child, never a face**, not even a simplified one |
| Fills | Exactly two: `brand.300` (#FF968C) and `accent.300` (#FFDB4D). Plus `neutral.200` for ground and recessed shapes and white for knockouts |
| Outline | A single **2.5px `neutral.800` rounded stroke on the primary subject only**. Secondary shapes carry no outline — that contrast is what creates depth |
| Forbidden | Gradients, textures, drop shadows, noise, 3D, perspective grids, line-art hatching, more than three fills |
| Geometry | Built from circles, rounded rectangles and arcs. Every vertex rounded, 2px minimum. Every object rotated 2–6° off-axis so the set reads hand-placed rather than drafted |
| Composition | 4:3 frame, subject centred, occupying ~60% of the height, resting on one flat elliptical `neutral.200` ground shape. Generous empty space at the top — the headline sits above the illustration, not over it |
| Size | 240px wide on mobile, 320px on desktop, 180px in the admin. SVG only |
| Weight | **≤12KB per illustration**, optimised, no embedded fonts, no raster |
| Dark mode | `neutral.200` → `neutral.700`, the `neutral.800` outline → `neutral.100`. The two brand fills are unchanged; both are surfaces on `neutral.900` and never carry text |
| Accessibility | `aria-hidden="true"` and `role="presentation"`. The headline and body carry 100% of the meaning. An empty state must be fully usable with images disabled |
| Motion | One mount animation: 320ms `decelerate` fade plus a 6px rise. Nothing loops, nothing bounces, nothing auto-plays. Suppressed under `prefers-reduced-motion` |

**Why this style and not a friendlier one.** Three reasons, all practical. It is producible by one illustrator from geometric primitives, which is the only way eleven illustrations stay visually identical. Depicting no children sidesteps a set of representation decisions nobody on this project has the authority to make, and avoids the stock-illustration look that reads as a template. And a 12KB SVG budget is the difference between an empty state that appears instantly on 4G and one that pops in after the text — which is exactly the moment the page looks broken.

Delivered as `illustrations/{state}.svg`, named by the keys below.

### C.1 `cart.empty`

**Illustration.** An open shopping basket tipped slightly onto its side, interior shown as a flat accent shape so it reads unmistakably empty, with one small brand ball rolling away from the mouth of the basket.

| | en | bn |
|---|---|---|
| Headline | Your cart is empty | আপনার কার্ট খালি |
| Body | Nothing in here yet. Have a look at what parents are buying this week. | এখনো কিছু নেই। এই সপ্তাহে অভিভাবকেরা কী কিনছেন দেখে নিন। |
| Primary action | Start shopping | কেনা শুরু করুন |

### C.2 `wishlist.empty`

**Illustration.** A single outlined heart standing upright on a small flat shelf, with a loose accent ribbon draped over its left shoulder and nothing hanging from the ribbon.

| | en | bn |
|---|---|---|
| Headline | Nothing saved yet | এখনো কিছু রাখা হয়নি |
| Body | Tap the heart on any toy and it waits here while you decide. | যেকোনো খেলনার হার্ট চিহ্নে চাপ দিন — সিদ্ধান্ত নেওয়া পর্যন্ত সেটি এখানে থাকবে। |
| Primary action | Browse toys | খেলনা দেখুন |

### C.3 `orders.empty`

**Illustration.** A flat parcel box with its lid propped open and leaning against its side, a blank address label on the front face, and two accent crescents inside standing in for packing paper.

| | en | bn |
|---|---|---|
| Headline | No orders yet | এখনো কোনো অর্ডার নেই |
| Body | Once you order, it shows up here with its delivery status and invoice. | অর্ডার করলে সেটি ডেলিভারির অবস্থা আর ইনভয়েস সহ এখানে দেখতে পাবেন। |
| Primary action | Browse toys | খেলনা দেখুন |

### C.4 `search.zero`

**Illustration.** A magnifier tilted lens-down over a bare ground ellipse, with one small accent tick mark floating above and right of the handle — the only abstract mark in the set, and it earns its place because the subject is an absence.

| | en | bn |
|---|---|---|
| Headline | No toys matched "{query}" | "{query}" দিয়ে কোনো খেলনা পাওয়া গেল না |
| Body | Check the spelling, try a shorter word, or look at the closest category below. Bangla and English both work. | বানান দেখে নিন, ছোট শব্দ দিয়ে খুঁজুন, বা নিচের কাছাকাছি ক্যাটাগরি দেখুন। বাংলা আর ইংরেজি, দুটোতেই খোঁজা যায়। |
| Primary action | See what parents are buying | অভিভাবকেরা কী কিনছেন দেখুন |

Below the action: the corrected-spelling suggestion (`search.correction.did_you_mean`), the closest category, and a best-sellers rail. The journey never ends here — DESIGN-SPEC §6.3.

### C.5 `addresses.empty`

**Illustration.** A simple house silhouette with a blank rectangular doorplate, and a map pin lying on the ground beside the house rather than planted in its roof.

| | en | bn |
|---|---|---|
| Headline | No addresses saved | কোনো ঠিকানা সেভ করা নেই |
| Body | Save one now and your next order takes two taps. | এখনই একটি সেভ করে রাখুন — পরের অর্ডারে দুটি ট্যাপই লাগবে। |
| Primary action | Add an address | ঠিকানা যোগ করুন |

### C.6 `reviews.empty`

**Illustration.** Five unfilled star outlines in a row, the last one lying flat on the ground at the end of the line, with one empty accent speech bubble above them.

| | en | bn |
|---|---|---|
| Headline | No reviews yet | এখনো কোনো রিভিউ নেই |
| Body | Be the first to tell other parents how this toy held up — and which age it actually suited. | এই খেলনাটি কেমন টিকল আর কত বছরের বাচ্চার জন্য ঠিক হলো, অন্য অভিভাবকদের প্রথম আপনিই জানান। |
| Primary action | Write the first review | প্রথম রিভিউ লিখুন |

### C.7 `404`

**Illustration.** A kite with a cut string, drifting; the loose end of the string curls below it and resolves into the numerals **404**. The numerals are part of the drawing, in Western digits, identical in both locales.

| | en | bn |
|---|---|---|
| Headline | This page isn't here | এই পাতাটি নেই |
| Body | The link may be old, or there may be a typo. Nothing has happened to your cart or your orders. | লিংকটি পুরনো হতে পারে, বা টাইপ করতে ভুল হয়েছে। আপনার কার্ট বা অর্ডারের কিছুই হয়নি। |
| Primary action | Go to the home page | হোম পেজে যান |
| Secondary action | Search for a toy | খেলনা খুঁজুন |

The reassurance sentence is not padding. A broken link on a shop a customer has already paid money to makes them wonder about the order, not the link.

### C.8 `500`

**Illustration.** A wind-up tin robot sitting down with its winding key lying loose on the ground beside it, and one small accent spark mark above its shoulder.

| | en | bn |
|---|---|---|
| Headline | Something broke on our side | আমাদের দিকে কিছু সমস্যা হয়েছে |
| Body | This is our fault, not yours. No money was taken and your cart is safe. Try again in a minute — or call 09600-123456 and we will place the order for you. | এটা আমাদের ভুল, আপনার নয়। কোনো টাকা কাটা হয়নি, আপনার কার্ট ঠিক আছে। এক মিনিট পর আবার চেষ্টা করুন — অথবা 09600-123456 নম্বরে ফোন করুন, আমরাই অর্ডারটা করে দেব। |
| Primary action | Try again | আবার চেষ্টা করুন |
| Secondary action | Call 09600-123456 | ফোন করুন 09600-123456 |

Offering the phone number on a 500 is the highest-value sentence on this page. A customer who was mid-checkout can still buy.

### C.9 `order.success`

**Illustration.** A sealed parcel with an accent ribbon cross and a brand tick stamped on its top face, with three small accent confetti triangles above it. **This is the only place confetti appears anywhere in the system** — one moment of celebration, once per order, and never animated beyond the standard mount fade.

| | en | bn |
|---|---|---|
| Headline | Order placed. Thank you! | অর্ডার হয়ে গেছে। ধন্যবাদ! |
| Body | Order {order_no}. We will call {phone} within 2 hours on working days to confirm. Keep the order number — you will need it to track or change anything. | অর্ডার {order_no}। কর্মদিবসে 2 ঘণ্টার মধ্যে {phone} নম্বরে ফোন করে কনফার্ম করব। অর্ডার নম্বরটি রেখে দিন — ট্র্যাক করতে বা কিছু বদলাতে কাজে লাগবে। |
| Primary action | Track this order | অর্ডার ট্র্যাক করুন |
| Secondary action | Keep shopping | আরও দেখুন |

### C.10 `provider.not_connected` (admin)

**Illustration.** Two flat cable ends facing each other with a clear gap between plug and socket, and a small accent dashed arc bridging the gap. Rendered at 180px, and it sits on `bg.surface` in both admin themes.

| | en | bn |
|---|---|---|
| Headline | {provider} is not connected | {provider} কানেক্ট করা নেই |
| Body | Add the API credentials and you can book shipments, pull live charges and sync tracking from here. Until then, bookings have to be done in the courier's own panel and pasted back in. | API তথ্য যোগ করলে এখান থেকেই শিপমেন্ট বুক করা, লাইভ চার্জ আনা আর ট্র্যাকিং সিংক করা যাবে। ততক্ষণ কুরিয়ারের নিজের প্যানেলে বুক করে তথ্য এখানে বসাতে হবে। |
| Primary action | Add credentials | তথ্য যোগ করুন |
| Secondary action | How to get API access | API অ্যাক্সেস কীভাবে পাবেন |

Naming the manual fallback is the point. An operator who does not know the workaround stops working. Credentials are PRD §7 item 7 and are a Week-1 blocker.

### C.11 `stock.empty` (admin)

**Illustration.** Three flat shelf lines with a single box on the top shelf and the lower two shelves bare.

| | en | bn |
|---|---|---|
| Headline | No stock movements yet | এখনো কোনো স্টক মুভমেন্ট নেই |
| Body | Receive a purchase order or make a manual adjustment, and every change will be logged here with who did it and when. | একটি পারচেজ অর্ডার রিসিভ করুন বা ম্যানুয়ালি অ্যাডজাস্ট করুন — এরপর প্রতিটি পরিবর্তন কে কখন করেছে সবই এখানে লেখা থাকবে। |
| Primary action | Receive a purchase order | পারচেজ অর্ডার রিসিভ করুন |
| Secondary action | Adjust stock manually | ম্যানুয়ালি স্টক অ্যাডজাস্ট করুন |

> **Admin language, flagged for the lead.** The PRD commits Bangla and English for the storefront. The admin's language is not stated anywhere. Bangla strings are supplied above for both admin states because the people who work the order board all day read Bangla more comfortably than English, and the cost of translating the operator-facing empty states is trivial. Whether the whole admin ships bilingual is a separate scope decision and is not mine to make.

---

## Part D — Photography standard

One page. Hand this to whoever shoots the catalog.

### Why consistency beats quality here

A set of twelve photos that vary in crop, background and brightness looks like a Facebook reseller, however good each individual photo is. A set of twelve shot identically looks like a shop, even if each one is merely adequate. The product grid is where this is decided: four cards side by side with four different backgrounds reads as four different sellers, and a parent who has been burned before notices that before they notice anything else. **Shoot every product the same way. If you have to choose between a better photo and a consistent one, choose consistent.**

### The setup

- **Background.** Pure white (#FFFFFF) or the light neutral #FAFAFA. Nothing else — no wood, no fabric, no carpet, no gradient, no cardboard, no bedsheet. One roll of white paper curving from vertical to horizontal, so there is no visible corner line behind the toy.
- **Lighting.** Two soft light sources at roughly 45° left and right, both the same distance and the same brightness, plus daylight through a window if it is available and consistent. **No on-camera flash.** No single hard light — hard shadows change shape between shots and that is what breaks a grid. Shadow under the toy should be a soft, light, barely-there contact shadow, never a hard dark shape.
- **Camera height.** Lens level with the middle of the toy, straight on. Not looking down at it. A phone camera is fine; a tripod or a stack of books is not optional.
- **Framing.** **Square 1:1, every single shot.** The toy occupies **85% of the frame width or height, whichever is the long dimension**, centred, with even margin all round. Do not crop into the toy. Do not leave it floating in 40% of the frame.
- **Colour.** Set white balance once, write it down, and use the same setting for the whole catalog. White background must come out white, not blue and not yellow. No filters. No "vivid" or "beauty" camera modes.
- **Resolution.** Minimum **1600×1600px** delivered, JPEG quality 90 or higher, sRGB. (The design spec's floor is 1200×1600; 1600 gives headroom for the PDP zoom.) Shoot at the camera's full resolution and deliver unedited originals alongside the crops.

### How many shots, and what each one shows

**Six per product, in this order. The order is the gallery order.**

1. **Hero** — the whole toy, straight on, as a parent would first see it. This is the grid thumbnail and the LCP image. Get this one right before anything else.
2. **Angle** — the same toy rotated roughly 35°, same height, same light, so the depth is readable.
3. **Scale** — see below. Mandatory. No product goes live without it.
4. **Detail** — the one thing that justifies the price: the stitching, the wheel mechanism, the printed circuit, the joint. Closer crop, same background, same light.
5. **What's in the box** — every piece laid out flat, evenly spaced, nothing overlapping, counted. This single shot prevents more "a part is missing" calls than anything else.
6. **In use** — the toy assembled, built, or standing as it is meant to. For a building set, the built model. For a tricycle, upright and ready. **No child in the frame** — see the note below.

Optional seventh: a 10–20 second video, same background, same light, phone held still on the tripod, showing the one motion that a photo cannot — it rolls, it lights up, it folds. Vertical or square, no music, no voice-over.

### The scale reference — mandatory, and the same one every time

Every product gets one shot with a **standard 30cm school ruler laid flat alongside the toy, numbers facing the camera, 0 at the toy's left edge.** Same ruler, same position, every product, for the whole catalog. Buy two identical rulers and keep one spare.

This exists because "how big is it, actually" is the question that a photo on a white background cannot answer and that causes returns. A tricycle and a keychain tricycle look identical in an 85%-of-frame crop. For toys over 50cm, the ruler is replaced by a **folding metre rule extended to 1m**, shot the same way; name which reference was used in the alt text. For very small toys, the ruler still works — do not substitute a coin, a hand, or a phone: all three vary, and a hand puts a person in the frame.

### Boxed versus unboxed

Shoot **both**, always.

- **Shots 1, 2, 4, 6 are the toy itself, out of the box.** The box is packaging; the parent is buying the toy.
- **One additional shot is the sealed box, front face, straight on**, same white background, same light, filling 85% of the frame. It goes **last** in the gallery, not first. Parents use it to confirm the brand, the certification marks and the printed age range, which is exactly why it must be legible — the printed age on the box and the age range on our product page have to agree, and this shot is how the admin checks that.
- If the toy only makes sense in its box — a 1,000-piece puzzle, a boxed board game — the box becomes shot 1 and the contents laid out becomes shot 2. Everything else is unchanged.
- **Never photograph a damaged, dented, taped or dusty box.** Either find a clean one or shoot the toy only. A scuffed box in a catalog photo reads as second-hand stock.

### No children, no hands, no props

No child in any catalog photograph. Three reasons, and the first is the one that matters: it requires consent from a parent for a commercial image and that is a liability nobody on this project is managing. Second, a child in the frame fixes the toy to one apparent age, which fights the age range we publish. Third, it cannot be shot consistently across 400 products, and consistency is the whole point. Lifestyle photography with children can be commissioned later, as a separate set, for the home page and social — never in the product gallery.

No adult hands either, except where the toy cannot be understood without one, and then the same hand, same position, every time.

### File naming

```
{sku}-{shot}-{n}.jpg
```

`shot` is one of `hero`, `angle`, `scale`, `detail`, `box-contents`, `in-use`, `packaging`, `video`. Lowercase, hyphens only, ASCII only, no spaces, no Bangla in filenames (it percent-encodes into unreadable URLs — the same reason slugs stay ASCII, PRD §6 item 4b).

```
TMB-1042-hero-1.jpg
TMB-1042-angle-1.jpg
TMB-1042-scale-1.jpg
TMB-1042-detail-1.jpg
TMB-1042-detail-2.jpg
TMB-1042-box-contents-1.jpg
TMB-1042-in-use-1.jpg
TMB-1042-packaging-1.jpg
TMB-1042-video-1.mp4
```

Deliver one folder per SKU, named with the SKU. Deliver the untouched originals in an `originals/` subfolder inside it. The admin uploader reorders and crops; it cannot recover a file that was never shot.

### Alt text

The admin auto-composes `{product} — {variant} — image {n}` and is overridable per image. Whoever shoots does not write alt text, but **shot 3 must be flagged in the upload as the scale shot**, because its alt text is generated differently: `{product} — size next to a 30cm ruler`. That sentence is read aloud by a screen reader and is also what a parent on a slow connection sees before the image loads.

### The one-line acceptance test

Put any four photos from the catalog side by side at thumbnail size. If you can tell they were shot on different days, reshoot.

---

## Open items this document hands back

1. **Native Bangla review of the 14 flagged terms** (§A.18) and a read-aloud pass over all 49 validation strings. This is the critical-path content dependency named in PRD §7 item 8a.
2. **BSTI's own Bangla wording** for `pdp.spec.certification` and `pdp.safety.bsti`, and whether we may ship a certification badge at all — blocked on PRD §7 item 9a.
3. **Refund window** in `status.returned.desc` and `page.returns.title` copy — 7 vs 10 days is unresolved in PRD §7 item 9c and must come from a lawyer, not from us.
4. **Trade licence and BIN numbers** for `footer.trade_licence` — PRD §7 item 6.
5. **Phone numbers, WhatsApp number, shop address and support hours** used throughout are placeholders (`09600-123456`, `01712-345678`, Dhanmondi) and must be replaced with the real ones before any string ships.
6. **Admin UI language** — Part C ships Bangla for the two admin empty states; whether the whole admin is bilingual is a scope decision for the lead.
7. **The short-link domain `tmbd.co`** must actually be registered, or every SMS template's character count changes.
8. **17 category names in Bangla** (§B.3) need the same native review as the UI strings, and the catalog owner needs to confirm the taxonomy before icons are drawn.
