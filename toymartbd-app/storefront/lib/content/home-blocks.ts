import type { BlogCard } from "@/lib/blog/types";
import type { CategoryNode } from "@/lib/category-tree";
import { isCard, type ProductCard } from "@/lib/listing/response";

// CMS homepage blocks (Phase 7) — the storefront side of docs/api/cms-blocks.md. Client-safe and
// pure: the API body is untrusted, so every block is checked here and a malformed one is dropped
// rather than taking the page down. Copy arrives already in the page's language (Bangla falls
// back to English on the server); product, category and blog cards keep both names, like every
// other list.

export interface BlockImage {
    url: string;
    srcset_webp: string | null;
    srcset_avif: string | null;
    width: number | null;
    height: number | null;
    alt: string;
}

interface Base {
    id: string;
    show_mobile: boolean;
    show_desktop: boolean;
}

export interface HeroSlide {
    overline: string | null;
    headline: string;
    sub: string | null;
    cta_label: string | null;
    cta_href: string | null;
    image: BlockImage | null;
}

export type UspIconKey =
    | "cod"
    | "return"
    | "authentic"
    | "support"
    | "delivery"
    | "gift"
    | "educational";

export type HomeBlock = Base &
    (
        | {
              type: "hero";
              content: { show_age_pills: boolean; slides: HeroSlide[] };
          }
        | {
              type: "category_tiles";
              content: { title: string; show_see_all: boolean };
              categories: CategoryNode[];
          }
        | {
              type: "age_tiles";
              content: { title: string; show_see_all: boolean };
              age_counts: Record<string, number>;
          }
        | {
              type: "product_rail";
              content: { title: string };
              products: ProductCard[];
              see_all_href: string | null;
          }
        | { type: "flash_sale"; content: { title: string; limit: number } }
        | {
              type: "brand_strip";
              content: { title: string };
              brands: Array<{
                  name: string;
                  slug: string;
                  logo_url: string | null;
              }>;
          }
        | {
              type: "usp_band";
              content: {
                  eyebrow: string | null;
                  title: string;
                  items: Array<{
                      icon: UspIconKey;
                      title: string;
                      detail: string | null;
                  }>;
              };
          }
        | {
              type: "promo_split";
              content: {
                  overline: string | null;
                  headline: string;
                  body: string | null;
                  cta_label: string | null;
                  cta_href: string | null;
                  image: BlockImage | null;
                  image_side: "left" | "right";
              };
          }
        | {
              type: "testimonials";
              content: { title: string };
              testimonials: Array<{
                  id: number;
                  name: string;
                  location: string | null;
                  quote: string;
                  rating: number | null;
                  image: BlockImage | null;
              }>;
          }
        | {
              type: "blog_teaser";
              content: { title: string; sub: string | null };
              posts: BlogCard[];
          }
        | { type: "rich_text"; content: { html: string } }
        | {
              type: "faq";
              content: {
                  title: string;
                  items: Array<{ question: string; answer: string }>;
              };
          }
        | { type: "contact_info"; content: ContactInfoContent }
        | { type: "image"; content: ImageBlockContent }
        | { type: "story_hero"; content: StoryHeroContent }
        | {
              type: "feature_tiles";
              content: { title: string | null; items: FeatureTile[] };
          }
        | {
              type: "numbered_cards";
              content: {
                  title: string;
                  items: Array<{ title: string; body: string | null }>;
              };
          }
        | { type: "cta_band"; content: CtaBandContent }
        | { type: "contact_hero"; content: ContactHeroContent }
        | { type: "contact_form"; content: ContactFormContent }
    );

/** Contact hero (Contact us 165:9891): copy, a status pill and up to four chat bubbles. */
export interface ContactHeroContent {
    overline: string | null;
    title: string;
    lead: string;
    status: string | null;
    bubbles: Array<{ from: "customer" | "store"; label: string | null; text: string }>;
}

/** "Send us a message" plus the optional visit and quick-links cards (Contact us 165:9974). */
export interface ContactFormContent {
    title: string;
    intro: string | null;
    privacy: string | null;
    visit: {
        title: string;
        body: string | null;
        map_href: string | null;
        hours: Array<{ label: string; value: string }>;
    } | null;
    links: {
        title: string;
        items: Array<{ icon: TileIconKey; label: string; href: string }>;
    } | null;
}

/** The glyphs of feature tiles and call-to-action buttons (TileIcon on the API). */
export type TileIconKey =
    | "shield_check"
    | "check_circle"
    | "gift"
    | "map_pin"
    | "truck"
    | "money"
    | "chat"
    | "phone"
    | "star"
    | "heart"
    | "puzzle"
    | "books"
    | "clock";

const TILE_ICONS: readonly TileIconKey[] = [
    "shield_check",
    "check_circle",
    "gift",
    "map_pin",
    "truck",
    "money",
    "chat",
    "phone",
    "star",
    "heart",
    "puzzle",
    "books",
    "clock",
];

const tileIcon = (v: unknown): TileIconKey | null =>
    TILE_ICONS.includes(v as TileIconKey) ? (v as TileIconKey) : null;

export type BloomTone = "sunrise" | "sky" | "mint" | "candy";

const TONES: readonly BloomTone[] = ["sunrise", "sky", "mint", "candy"];

const tone = (v: unknown, fallback: BloomTone): BloomTone =>
    TONES.includes(v as BloomTone) ? (v as BloomTone) : fallback;

/** Story hero (About us 170:10502): copy beside a collage of 0–3 photos. */
export interface StoryHeroContent {
    overline: string | null;
    title: string;
    lead: string;
    body: string | null;
    images: BlockImage[];
    badge: string | null;
    badge_sub: string | null;
    tone: BloomTone;
}

export interface FeatureTile {
    icon: TileIconKey;
    title: string;
    body: string | null;
}

export interface CtaButton {
    label: string;
    href: string;
    icon: TileIconKey | null;
}

export interface CtaBandContent {
    title: string;
    body: string | null;
    primary: CtaButton | null;
    secondary: CtaButton | null;
    tone: BloomTone;
}

/** The Image block: never without an image (the API drops one without, and so does this guard). */
export interface ImageBlockContent {
    image: BlockImage;
    caption: string | null;
    href: string | null;
    width: "content" | "wide";
}

export type ContactChannelKey =
    | "hotline"
    | "whatsapp"
    | "messenger"
    | "email"
    | "address";

/** The Contact block: copy and switches only — the values come from lib/store-info.ts. */
export interface ContactInfoContent {
    /** Optional (2026-11-16): the Contact page shows the tiles without a heading. */
    title: string | null;
    sub: string | null;
    hours: string | null;
    whatsapp_note: string | null;
    email_note: string | null;
    /** The Visit tile's value instead of the full store address. */
    address_label: string | null;
    address_note: string | null;
    map_href: string | null;
    channels: ContactChannelKey[];
}

const CONTACT_CHANNELS: readonly ContactChannelKey[] = [
    "hotline",
    "whatsapp",
    "messenger",
    "email",
    "address",
];

export type HomeBlockType = HomeBlock["type"];

export interface HomeLayout {
    version: number | null;
    preview: boolean;
    blocks: HomeBlock[];
}

type Obj = Record<string, unknown>;

const isObj = (v: unknown): v is Obj =>
    typeof v === "object" && v !== null && !Array.isArray(v);
const str = (v: unknown): string | null =>
    typeof v === "string" && v.trim() !== "" ? v : null;
const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const USP_ICONS: readonly UspIconKey[] = [
    "cod",
    "return",
    "authentic",
    "support",
    "delivery",
    "gift",
    "educational",
];

/** A storefront path (`/toys?age=…`, never `//host`) or an https URL; anything else is dropped. */
export function safeHref(v: unknown): string | null {
    const href = str(v);
    if (!href) return null;
    if (/^\/(?!\/)[^\s]*$/.test(href)) return href;
    if (/^https:\/\/[^\s/]+[^\s]*$/.test(href)) return href;
    return null;
}

function image(v: unknown): BlockImage | null {
    if (!isObj(v)) return null;
    const url = str(v.url);
    if (!url || !/^(https?:\/\/|\/)/.test(url)) return null;
    return {
        url,
        srcset_webp: str(v.srcset_webp),
        srcset_avif: str(v.srcset_avif),
        width: typeof v.width === "number" ? v.width : null,
        height: typeof v.height === "number" ? v.height : null,
        alt: typeof v.alt === "string" ? v.alt : "",
    };
}

function category(v: unknown): CategoryNode | null {
    if (!isObj(v)) return null;
    const name = str(v.name);
    const slug = str(v.slug);
    if (typeof v.id !== "number" || !name || !slug || !SLUG.test(slug))
        return null;
    return {
        id: v.id,
        name,
        name_bn: str(v.name_bn),
        slug,
        icon: str(v.icon),
        image_url: str(v.image_url),
        children: [],
    };
}

function blogCard(v: unknown): BlogCard | null {
    if (!isObj(v) || typeof v.id !== "number" || !str(v.title)) return null;
    const slug = str(v.slug);
    return slug && SLUG.test(slug) ? (v as unknown as BlogCard) : null;
}

const nonEmpty = <T>(items: Array<T | null>): T[] =>
    items.filter((i): i is T => i !== null);

function block(raw: unknown): HomeBlock | null {
    if (!isObj(raw) || !isObj(raw.content)) return null;
    const base: Base = {
        id: str(raw.id) ?? "",
        show_mobile: raw.show_mobile !== false,
        show_desktop: raw.show_desktop !== false,
    };
    const c = raw.content;
    const title = str(c.title);

    switch (raw.type) {
        case "hero": {
            const slides = nonEmpty(
                list(c.slides).map((s): HeroSlide | null => {
                    if (!isObj(s) || !str(s.headline)) return null;
                    const href = safeHref(s.cta_href);
                    return {
                        overline: str(s.overline),
                        headline: str(s.headline)!,
                        sub: str(s.sub),
                        cta_label: href ? str(s.cta_label) : null,
                        cta_href: href,
                        image: image(s.image),
                    };
                })
            ).slice(0, 5);
            return slides.length
                ? {
                      ...base,
                      type: "hero",
                      content: {
                          show_age_pills: c.show_age_pills !== false,
                          slides,
                      },
                  }
                : null;
        }
        case "category_tiles": {
            const categories = nonEmpty(list(raw.categories).map(category));
            return title && categories.length
                ? {
                      ...base,
                      type: "category_tiles",
                      content: {
                          title,
                          show_see_all: c.show_see_all !== false,
                      },
                      categories,
                  }
                : null;
        }
        case "age_tiles": {
            const counts: Record<string, number> = {};
            if (isObj(raw.age_counts))
                for (const [k, n] of Object.entries(raw.age_counts))
                    if (typeof n === "number") counts[k] = n;
            return title
                ? {
                      ...base,
                      type: "age_tiles",
                      content: {
                          title,
                          show_see_all: c.show_see_all !== false,
                      },
                      age_counts: counts,
                  }
                : null;
        }
        case "product_rail": {
            const products = list(raw.products).filter(isCard);
            return title && products.length
                ? {
                      ...base,
                      type: "product_rail",
                      content: { title },
                      products,
                      see_all_href: safeHref(raw.see_all_href),
                  }
                : null;
        }
        case "flash_sale": {
            const limit =
                typeof c.limit === "number" && c.limit >= 1
                    ? Math.min(12, Math.floor(c.limit))
                    : 8;
            return title
                ? { ...base, type: "flash_sale", content: { title, limit } }
                : null;
        }
        case "brand_strip": {
            const brands = nonEmpty(
                list(raw.brands).map((b) => {
                    if (!isObj(b)) return null;
                    const name = str(b.name);
                    const slug = str(b.slug);
                    return name && slug && SLUG.test(slug)
                        ? { name, slug, logo_url: str(b.logo_url) }
                        : null;
                })
            );
            return title && brands.length
                ? { ...base, type: "brand_strip", content: { title }, brands }
                : null;
        }
        case "usp_band": {
            const items = nonEmpty(
                list(c.items).map((i) => {
                    if (!isObj(i) || !str(i.title)) return null;
                    const icon = USP_ICONS.includes(i.icon as UspIconKey)
                        ? (i.icon as UspIconKey)
                        : "cod";
                    return {
                        icon,
                        title: str(i.title)!,
                        detail: str(i.detail),
                    };
                })
            ).slice(0, 4);
            return title && items.length
                ? {
                      ...base,
                      type: "usp_band",
                      content: { eyebrow: str(c.eyebrow), title, items },
                  }
                : null;
        }
        case "promo_split": {
            const headline = str(c.headline);
            const href = safeHref(c.cta_href);
            return headline
                ? {
                      ...base,
                      type: "promo_split",
                      content: {
                          overline: str(c.overline),
                          headline,
                          body: str(c.body),
                          cta_label: href ? str(c.cta_label) : null,
                          cta_href: href,
                          image: image(c.image),
                          image_side:
                              c.image_side === "right" ? "right" : "left",
                      },
                  }
                : null;
        }
        case "testimonials": {
            const testimonials = nonEmpty(
                list(raw.testimonials).map((t) => {
                    if (
                        !isObj(t) ||
                        typeof t.id !== "number" ||
                        !str(t.name) ||
                        !str(t.quote)
                    )
                        return null;
                    const rating =
                        typeof t.rating === "number" &&
                        t.rating >= 1 &&
                        t.rating <= 5
                            ? Math.round(t.rating)
                            : null;
                    return {
                        id: t.id,
                        name: str(t.name)!,
                        location: str(t.location),
                        quote: str(t.quote)!,
                        rating,
                        image: image(t.image),
                    };
                })
            );
            return title && testimonials.length
                ? {
                      ...base,
                      type: "testimonials",
                      content: { title },
                      testimonials,
                  }
                : null;
        }
        case "blog_teaser": {
            const posts = nonEmpty(list(raw.posts).map(blogCard));
            return title && posts.length
                ? {
                      ...base,
                      type: "blog_teaser",
                      content: { title, sub: str(c.sub) },
                      posts,
                  }
                : null;
        }
        case "rich_text": {
            const html = str(c.html);
            return html
                ? { ...base, type: "rich_text", content: { html } }
                : null;
        }
        case "faq": {
            const items = nonEmpty(
                list(c.items).map((i) =>
                    isObj(i) && str(i.question) && str(i.answer)
                        ? { question: str(i.question)!, answer: str(i.answer)! }
                        : null
                )
            );
            return title && items.length
                ? { ...base, type: "faq", content: { title, items } }
                : null;
        }
        case "image": {
            const img = image(c.image);
            return img
                ? {
                      ...base,
                      type: "image",
                      content: {
                          image: img,
                          caption: str(c.caption),
                          href: safeHref(c.href),
                          width: c.width === "wide" ? "wide" : "content",
                      },
                  }
                : null;
        }
        case "contact_info": {
            const channels = CONTACT_CHANNELS.filter(
                (ch) => c[`show_${ch}`] !== false
            );
            const map = safeHref(c.map_href);
            return {
                ...base,
                type: "contact_info",
                content: {
                    title,
                    sub: str(c.sub),
                    hours: str(c.hours),
                    whatsapp_note: str(c.whatsapp_note),
                    email_note: str(c.email_note),
                    address_label: str(c.address_label),
                    address_note: str(c.address_note),
                    map_href: map,
                    channels,
                },
            };
        }
        case "story_hero": {
            const lead = str(c.lead);
            return title && lead
                ? {
                      ...base,
                      type: "story_hero",
                      content: {
                          overline: str(c.overline),
                          title,
                          lead,
                          body: str(c.body),
                          images: nonEmpty(list(c.images).map(image)).slice(
                              0,
                              3
                          ),
                          badge: str(c.badge),
                          badge_sub: str(c.badge_sub),
                          tone: tone(c.tone, "sunrise"),
                      },
                  }
                : null;
        }
        case "feature_tiles": {
            const items = nonEmpty(
                list(c.items).map((i) =>
                    isObj(i) && str(i.title)
                        ? {
                              icon: tileIcon(i.icon) ?? "check_circle",
                              title: str(i.title)!,
                              body: str(i.body),
                          }
                        : null
                )
            ).slice(0, 4);
            return items.length
                ? {
                      ...base,
                      type: "feature_tiles",
                      content: { title, items },
                  }
                : null;
        }
        case "numbered_cards": {
            const items = nonEmpty(
                list(c.items).map((i) =>
                    isObj(i) && str(i.title)
                        ? { title: str(i.title)!, body: str(i.body) }
                        : null
                )
            ).slice(0, 6);
            return title && items.length
                ? {
                      ...base,
                      type: "numbered_cards",
                      content: { title, items },
                  }
                : null;
        }
        case "cta_band": {
            const button = (key: string): CtaButton | null => {
                const label = str(c[`${key}_label`]);
                const href = safeHref(c[`${key}_href`]);
                return label && href
                    ? { label, href, icon: tileIcon(c[`${key}_icon`]) }
                    : null;
            };
            return title
                ? {
                      ...base,
                      type: "cta_band",
                      content: {
                          title,
                          body: str(c.body),
                          primary: button("primary"),
                          secondary: button("secondary"),
                          tone: tone(c.tone, "candy"),
                      },
                  }
                : null;
        }
        case "contact_hero": {
            const lead = str(c.lead);
            const bubbles = nonEmpty(
                list(c.bubbles).map((b) =>
                    isObj(b) && str(b.text)
                        ? {
                              from:
                                  b.from === "store"
                                      ? ("store" as const)
                                      : ("customer" as const),
                              label: str(b.label),
                              text: str(b.text)!,
                          }
                        : null
                )
            ).slice(0, 4);
            return title && lead
                ? {
                      ...base,
                      type: "contact_hero",
                      content: {
                          overline: str(c.overline),
                          title,
                          lead,
                          status: str(c.status),
                          bubbles,
                      },
                  }
                : null;
        }
        case "contact_form": {
            if (!title) return null;
            const hours = nonEmpty(
                list(c.hours).map((h) =>
                    isObj(h) && str(h.label) && str(h.value)
                        ? { label: str(h.label)!, value: str(h.value)! }
                        : null
                )
            ).slice(0, 6);
            const links = nonEmpty(
                list(c.links).map((l) => {
                    const href = isObj(l) ? safeHref(l.href) : null;
                    return isObj(l) && str(l.label) && href
                        ? {
                              icon: tileIcon(l.icon) ?? "check_circle",
                              label: str(l.label)!,
                              href,
                          }
                        : null;
                })
            ).slice(0, 6);
            const visitTitle = str(c.visit_title);
            const linksTitle = str(c.links_title);
            return {
                ...base,
                type: "contact_form",
                content: {
                    title,
                    intro: str(c.intro),
                    privacy: str(c.privacy),
                    visit: visitTitle
                        ? {
                              title: visitTitle,
                              body: str(c.visit_body),
                              map_href: safeHref(c.visit_map_href),
                              hours,
                          }
                        : null,
                    links:
                        linksTitle && links.length
                            ? { title: linksTitle, items: links }
                            : null,
                },
            };
        }
        default:
            return null;
    }
}

/** Validates `GET /storefront/content/home[/preview]`. Unknown or malformed blocks are dropped. */
export function normalizeHome(body: unknown): HomeLayout {
    if (!isObj(body)) return { version: null, preview: false, blocks: [] };
    return {
        version: typeof body.version === "number" ? body.version : null,
        preview: body.preview === true,
        blocks: nonEmpty(list(body.blocks).map(block)),
    };
}

/** Tailwind classes for the per-device switches (one breakpoint: the 1024 desktop layout). */
export function deviceClass(
    b: Pick<HomeBlock, "show_mobile" | "show_desktop">
): string {
    if (b.show_mobile && b.show_desktop) return "";
    if (!b.show_mobile && !b.show_desktop) return "hidden";
    return b.show_mobile ? "lg:hidden" : "max-lg:hidden";
}
