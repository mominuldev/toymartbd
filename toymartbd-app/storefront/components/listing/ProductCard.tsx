import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { ageLabel, formatPrice, sameAmount } from '@/lib/listing/format';
import type { ProductCard as Card } from '@/lib/listing/response';

// ProductCard, Layout=Grid / GridDesktop — Figma 100:852 (owner-chosen Version B; storefront spec
// §A1 owner note). Photo framed inside a soft-pink-bordered card; discount badge top-left,
// wishlist top-right, derived age pill bottom-left; title (2-line clamp), coral price beside the
// struck was-price, then "Buy now" + the cart icon button.
//
// Not wired yet, by design: there is no cart or wishlist (later batches), so "Buy now" links to the
// product page and the cart / wishlist controls render disabled with their final look. Out of stock
// (Figma 100:741, from the API's `in_stock`): the photo fades to 60%, an "Out of stock" pill joins
// the badge stack, and the actions become one outline "Notify me" (to the product page until
// back-in-stock alerts exist). "Only N left" and the free-delivery pill need per-card data the
// listing does not carry yet. Money is shown exactly as the API sends it (rule 1).

interface Props {
    product: Card;
    locale: Locale;
    t: Messages['listing'];
    /** The first row is above the fold: load eagerly. */
    priority?: boolean;
}

function ageIcon(min: number | null): IconName {
    const lo = min ?? 0;
    if (lo < 12) return 'baby';
    if (lo < 48) return 'puzzlePiece';
    if (lo < 96) return 'smiley';
    return 'graduationCap';
}

export function ProductCard({ product, locale, t, priority = false }: Props) {
    const name = locale === 'bn' && product.name_bn ? product.name_bn : product.name;
    const nameLang = locale === 'bn' && !product.name_bn ? 'en' : undefined;
    const href = localizedPath(locale, `/products/${product.slug}`);
    const age = ageLabel(product.age_min_months, product.age_max_months, t);
    const image = product.image;
    const alt = image ? ((locale === 'bn' ? image.alt_bn : image.alt) ?? name) : '';
    const price = formatPrice(product.price);
    const ranged = !sameAmount(product.price, product.price_max);
    const soldOut = product.in_stock === false;

    return (
        <article className="flex h-full flex-col rounded-sm border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface px-1.5 pt-2 pb-3 shadow-e1 sm:px-2">
            <div className="relative aspect-square w-full overflow-hidden rounded-xs bg-tile-pink">
                <a href={href} tabIndex={-1} className={`absolute inset-0 ${soldOut ? 'opacity-60' : ''}`}>
                    {image ? (
                        <picture>
                            {image.srcset_avif ? <source type="image/avif" srcSet={image.srcset_avif} sizes="(min-width: 64rem) 19rem, 50vw" /> : null}
                            {image.srcset_webp ? <source type="image/webp" srcSet={image.srcset_webp} sizes="(min-width: 64rem) 19rem, 50vw" /> : null}
                            <img
                                src={image.url}
                                alt={alt}
                                width={image.width ?? undefined}
                                height={image.height ?? undefined}
                                loading={priority ? 'eager' : 'lazy'}
                                fetchPriority={priority ? 'high' : undefined}
                                decoding="async"
                                className="size-full rounded-none object-cover"
                            />
                        </picture>
                    ) : (
                        <span aria-hidden="true" className="flex size-full flex-col items-center justify-center gap-2 text-fg-tertiary">
                            <Icon name="puzzlePiece" className="size-icon-category" />
                            <span className="t-caption">{t.noImage}</span>
                        </span>
                    )}
                </a>
                {product.discount_percent || soldOut ? (
                    <div className="pointer-events-none absolute top-2.5 left-2 flex flex-col items-start gap-1">
                        {product.discount_percent ? (
                            <span className="t-badge inline-flex h-6 min-w-10 items-center justify-center rounded-full bg-brand px-2 text-fg-on-brand">
                                {format(t.discount, { percent: String(product.discount_percent) })}
                            </span>
                        ) : null}
                        {soldOut ? (
                            <span className="t-caption-strong inline-flex h-5 items-center gap-2 rounded-full bg-surface px-2 text-fg-tertiary">
                                <span aria-hidden="true" className="size-2 rounded-full bg-status-out-of-stock" />
                                {t.outOfStock}
                            </span>
                        ) : null}
                    </div>
                ) : null}
                <button
                    type="button"
                    disabled
                    aria-label={format(t.wishlist, { name })}
                    className="absolute top-2 right-2 flex size-7 items-center justify-center rounded-full bg-surface text-fg-secondary shadow-e1 lg:size-8 before:absolute before:size-touch-min before:content-['']"
                >
                    <Icon name="heart" className="size-icon-sm" />
                </button>
                {age ? (
                    <span className="t-caption-strong pointer-events-none absolute bottom-2 left-2 inline-flex h-5.5 items-center gap-1 rounded-full bg-surface px-2 text-fg-primary">
                        <Icon name={ageIcon(product.age_min_months)} className="size-3.5" />
                        {age}
                    </span>
                ) : null}
            </div>
            <div className="flex w-full grow flex-col gap-1.5 pt-3 sm:px-1">
                <h3 className={`t-card-title line-clamp-2 h-[2lh] ${soldOut ? 'text-fg-secondary' : 'text-fg-primary'}`} lang={nameLang}>
                    <a href={href} className="rounded-xs hover:text-fg-link">
                        {name}
                    </a>
                </h3>
                <p className="flex flex-wrap items-baseline gap-x-1.5 tabular-nums">
                    <span className={`t-price ${soldOut ? 'text-fg-secondary' : 'text-fg-discount'}`}>{ranged ? format(t.from, { price }) : price}</span>
                    {product.compare_at_price ? (
                        <>
                            <span className="sr-only">{format(t.was, { price: formatPrice(product.compare_at_price) })}</span>
                            <s aria-hidden="true" className="t-price-was text-fg-price-was">
                                {formatPrice(product.compare_at_price)}
                            </s>
                        </>
                    ) : null}
                </p>
                <div className="mt-auto flex items-center gap-1.5 pt-2 sm:gap-2">
                    {soldOut ? (
                        <a
                            href={href}
                            className="t-button-small relative flex h-control-sm min-w-24 grow items-center justify-center gap-3 whitespace-nowrap rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface px-4 text-fg-brand"
                        >
                            <Icon name="bellFill" className="size-icon-sm" />
                            {t.notifyMe}
                        </a>
                    ) : (
                    <>
                    <a
                        href={href}
                        className="t-chip relative flex h-control-sm min-w-0 grow items-center justify-center whitespace-nowrap rounded-full bg-(image:--tm-gradient-button-primary) px-2 sm:min-w-24 sm:px-4 text-fg-on-brand shadow-[inset_0_var(--tm-border-width-control)_0_0_color-mix(in_srgb,var(--tm-color-bg-surface)_28%,transparent)] "
                    >
                        {t.buyNow}
                    </a>
                    <button
                        type="button"
                        disabled
                        aria-label={format(t.addToCart, { name })}
                        className="relative flex size-control-sm shrink-0 items-center justify-center rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface text-fg-brand lg:size-control-md before:absolute before:size-touch-min before:content-['']"
                    >
                        <Icon name="shoppingCartFill" className="size-icon-sm lg:size-icon-md" />
                    </button>
                    </>
                    )}
                </div>
            </div>
        </article>
    );
}
