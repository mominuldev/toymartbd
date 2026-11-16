# Babur Gallery theme — design reference

> **Design only. Not implemented.** Figma page **Babur Gallery** (`445:2`), file `PWZp5VzEgEEf5N2Y38lGIh`.
> Babur Gallery is one **theme** of the single store (see [THEMES-SPEC.md](THEMES-SPEC.md)), not a separate brand or database.

## Identity

- **Colours** come from the owner's logo: a "BG" monogram with a clothes hanger, blue "Babur" and orange-to-yellow "Gallery".
  - **Blue** (`blue/700 #0846C2`) is the brand colour and is used for primary actions.
  - **Orange** (`orange/500 #FF7A00`) is for offers and sale.
  - **Sun** (`sun/500 #FFB300`) marks bestsellers.
  - **Sky** (`sky/500 #0EA0F0`) marks age and information.
  - **Paper** (`#FFFCF8`) is the page background. **Navy ink** (`#0E1A33`) is used for text.
- **Type:** Fraunces for headings, prices and the italic voice; Instrument Sans for interface and body text.
- **Images use "gallery frames":**
  - 4px corners, a white mat and a hard 10px offset block in a logo colour (effect styles `BG/Frame/Sun | Orange | Sky | Blue`).
  - An orange **swing tag** carries labels such as "New season".
  - **No arches and no large image radii** (owner direction). Pills are for controls only.
- **Tokens:** Figma collections `BG · Primitives`, `BG · Color` (Light/Dark), `BG · Space` and `BG · Radius`; text styles `BG/…`.

## Components (Figma, "02 · Components" and "07 · Site chrome")

**Reused across pages:**

| Group | Components |
|---|---|
| Basics | Icons (Phosphor Regular), Button (Primary / Accent / Outline / Ghost / Inverse × L/M, swappable icon), Icon Button, Badge, Chip, Search Field |
| Commerce cards | Product Card (Default / Compact, full-width **Add to cart** button), Category Frame, Section Header, Value Prop |
| Flash sale | Countdown, Stock Meter, Flash Deal Card |
| Forms & checkout | Text Field (Default / Focus / Error), Select Field, Checkbox, Radio Card, Quantity Stepper, Checkout Steps, Pagination, Accordion Row |
| Site chrome | Site Header / Footer (Desktop, Tablet, Mobile), Tab Bar, Logo (Default / On dark) |

## Pages

| # | Page | Desktop 1440 | Mobile 390 | Notes |
|---|---|---|---|---|
| 04–06 | Homepage | ✓ | ✓ (+ tablet 834) | Hero gallery wall, shop by age, flash sale, category frames, new arrivals, winter editorial, bento, bestsellers, promises, reviews, club |
| 08 | Category listing | ✓ | ✓ + filter sheet | Sidebar filters (age, price, material, colour), active chips, sort, promo tile, load more |
| 09 | Product detail | ✓ | ✓ | Gallery, colour and size, low stock, COD buy-now, delivery box, accordions, one bundle, 4 cross-sells, reviews |
| 10 | Cart | ✓ | ✓ | Free-delivery progress, coupon, **one** cross-sell rail, no upsell (rule 25b) |
| 11 | Checkout | ✓ | ✓ | Minimal header, BD address with landmark, delivery options, COD first, **no recommendations** |
| 12 | Order confirmed | ✓ | ✓ | Next-steps timeline, COD amount |
| 13 | Track order | ✓ | ✓ | Order number **plus** phone (rule 22), courier status with last-checked time |
| 14 | Sign in | ✓ | ✓ | Phone plus **SMS** code. Never WhatsApp OTP (rule 32b). |
| 15 | Account | ✓ | ✓ | Orders, wishlist, addresses, children and sizes with birthday consent, club |

## Photography

The photos are AI-generated stand-ins (Figma `generate_image`), all from one brief: soft daylight, a paper backdrop tinted to the palette, and no text or logos. **Replace them with real catalogue photography shot to the same brief before launch.**

## Placeholders still in the design

- Hotline, the ৳ 2,000 free-delivery threshold, express-delivery areas and charges, coupon codes, bundle discounts and review counts.
- "64 districts" and the trade licence/BIN.
- Brand names (Little Loom, Kantha Kids and so on) are invented.
- The Gallery Club perks depend on a loyalty system that does not exist yet (see [GAP-ANALYSIS.md](GAP-ANALYSIS.md)).
