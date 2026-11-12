import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { iconPaths } from '@/components/icons/paths';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { ageForLabel, ratingValue, starFills } from '@/lib/product/format';
import type { ProductVariant, StorefrontProduct } from '@/lib/product/variants';

// The top of the PDP details column — Figma "Details" 135:1222 (1440) / "Info" 128:742 (390):
// brand + title, rating, the fixed-height price block, StockIndicator md and AgeRangeIndicator md.
// All server-rendered from the API's values; the price block never reflows (§B2.1).

export function ProductTitle({ product, name, nameLang, locale }: { product: StorefrontProduct; name: string; nameLang?: 'en'; locale: Locale }) {
    const brand = product.brand;
    return (
        <div className="flex flex-col gap-1">
            {brand ? (
                <a href={localizedPath(locale, `/brands/${brand.slug}`)} className="t-caption-strong self-start text-fg-brand hover:underline" lang={locale === 'bn' && !brand.name_bn ? 'en' : undefined}>
                    {locale === 'bn' && brand.name_bn ? brand.name_bn : brand.name}
                </a>
            ) : null}
            <h1 className="t-h1 line-clamp-2 text-fg-primary" lang={nameLang}>
                {name}
            </h1>
        </div>
    );
}

function Star({ fill }: { fill: 0 | 50 | 100 }) {
    const d = iconPaths.starFill;
    return (
        <span className="relative size-4.5 shrink-0" aria-hidden="true">
            <svg viewBox="0 0 256 256" className="absolute inset-0 size-full fill-subtle stroke-line-strong [stroke-width:14]">
                {d.map((p) => (
                    <path key={p} d={p} />
                ))}
            </svg>
            {fill > 0 ? (
                <span className={`absolute inset-y-0 left-0 overflow-hidden ${fill === 50 ? 'w-1/2' : 'w-full'}`}>
                    <svg viewBox="0 0 256 256" className="size-4.5 fill-accent stroke-line-strong [stroke-width:14]">
                        {d.map((p) => (
                            <path key={p} d={p} />
                        ))}
                    </svg>
                </span>
            ) : null}
        </span>
    );
}

/** Figma Rating Display/Large (69:654) — "4.7 (42 reviews)"; zero reviews show empty stars and words, never "0.0". */
export function RatingRow({ product, t }: { product: StorefrontProduct; t: Messages }) {
    const rated = product.rating_avg !== null && product.rating_count > 0;
    const value = rated && product.rating_avg ? ratingValue(product.rating_avg) : null;
    const fills = value ? starFills(Number(value)) : starFills(0);
    const count = String(product.rating_count);
    return (
        <div className="flex h-6 items-center gap-2">
            <span className="flex items-center gap-1" role="img" aria-label={value ? format(t.pdp.ratingAria, { rating: value, count }) : t.pdp.ratingNone}>
                {fills.map((fill, i) => (
                    <Star key={i} fill={fill} />
                ))}
            </span>
            {value ? (
                <span aria-hidden="true" className="flex items-center gap-1 whitespace-nowrap">
                    <span className="t-rating-numeric-md text-fg-primary">{value}</span>
                    <span className="t-body-sm text-fg-secondary">{format(t.pdp.ratingCount, { count })}</span>
                </span>
            ) : (
                <span className="t-body-sm text-fg-secondary">{t.pdp.ratingNone}</span>
            )}
        </div>
    );
}

/**
 * Price block (§B2.1): fixed 96 at 390, Figma 89 hug at 1440. The discount pill and the saving line
 * are the API's numbers; the second line is reserved (it always carries the COD note) so a variant
 * with no discount does not change the height.
 */
export function PriceBlock({ variant, t }: { variant: ProductVariant; t: Messages }) {
    const price = formatPrice(variant.price);
    const was = variant.compare_at_price ? formatPrice(variant.compare_at_price) : null;
    return (
        <div className="flex h-24 flex-col justify-center gap-1 rounded-sm bg-brand-subtle px-4 py-3 lg:h-auto lg:gap-1.5 lg:px-5 lg:py-4">
            <p className="flex items-baseline gap-2.5 tabular-nums lg:gap-3">
                <span className="t-price-lg text-fg-discount">
                    <span className="sr-only">{format(t.pdp.priceAria, { amount: price })}</span>
                    <span aria-hidden="true">{price}</span>
                </span>
                {was ? (
                    <>
                        <span className="sr-only">{format(t.pdp.wasAria, { amount: was })}</span>
                        <s aria-hidden="true" className="t-price--was text-fg-price-was">
                            {was}
                        </s>
                    </>
                ) : null}
                {variant.discount_percent ? (
                    <span className="t-caption-strong self-center rounded-full bg-brand px-2 py-0.5 text-fg-on-brand">{format(t.pdp.discount, { percent: String(variant.discount_percent) })}</span>
                ) : null}
            </p>
            <p className="t-caption-strong text-fg-discount">{variant.saving ? format(t.pdp.saveCod, { amount: formatPrice(variant.saving) }) : t.pdp.cod}</p>
        </div>
    );
}

const stockLook = {
    in_stock: { dot: 'bg-status-in-stock', fill: 'bg-subtle', text: 'text-fg-secondary' },
    low_stock: { dot: 'bg-status-low-stock', fill: 'bg-accent-subtle', text: 'text-fg-primary' },
    out_of_stock: { dot: 'bg-status-out-of-stock', fill: 'bg-subtle', text: 'text-fg-tertiary' },
} as const;

/** StockIndicator Size=md (Figma 90:52) + the cut-off caption (InStock/LowStock, before cut-off). */
export function StockLine({ variant, t, cutoff }: { variant: ProductVariant; t: Messages; cutoff: string | null }) {
    const look = stockLook[variant.stock_state];
    const label =
        variant.stock_state === 'in_stock' ? t.pdp.stockIn : variant.stock_state === 'low_stock' ? format(t.pdp.stockLow, { count: String(variant.available) }) : t.pdp.stockOut;
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <p aria-live="polite" className={`t-body-sm-strong inline-flex h-7 items-center gap-2 rounded-full px-3 ${look.fill} ${look.text}`}>
                <span aria-hidden="true" className={`size-2 rounded-full ${look.dot}`} />
                {label}
            </p>
            {cutoff && variant.stock_state !== 'out_of_stock' ? <p className="t-caption hidden text-fg-secondary lg:block">{format(t.pdp.cutoff, { time: cutoff })}</p> : null}
            {variant.stock_state === 'out_of_stock' ? <p className="t-caption w-full text-fg-secondary">{t.pdp.stockOutNote}</p> : null}
        </div>
    );
}

function ageIcon(min: number | null): IconName {
    const lo = min ?? 0;
    if (lo < 12) return 'baby';
    if (lo < 48) return 'puzzlePiece';
    if (lo < 96) return 'smiley';
    return 'graduationCap';
}

/** AgeRangeIndicator Size=md, Tone=Brand (Figma 90:86) with the under-3 safety line from the component. */
export function AgeBlock({ product, t }: { product: StorefrontProduct; t: Messages }) {
    const label = ageForLabel(product.age_min_months, product.age_max_months, t);
    if (!label) return null;
    const under3 = (product.age_min_months ?? 0) < 36;
    return (
        <div className="flex flex-col rounded-md bg-brand-subtle pr-2 pl-4">
            <div className="flex min-h-11 items-center gap-3">
                <Icon name={ageIcon(product.age_min_months)} className="size-icon-md text-fg-primary" />
                <p className="t-body-strong grow text-fg-primary">{label}</p>
                <a href="#pdp-details" className="t-body-sm-strong flex h-11 items-center px-2 text-fg-link hover:underline">
                    {t.pdp.ageGuide}
                </a>
            </div>
            {under3 ? (
                <p className="t-caption flex items-start gap-1.5 pb-3 text-status-low-stock">
                    <Icon name="warning" className="mt-0.5 size-3.5" />
                    {t.pdp.ageSafety}
                </p>
            ) : null}
        </div>
    );
}
