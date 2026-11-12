import { Icon } from '@/components/icons/Icon';
import { lineName, lineProblem, lowStockLabel, noticeText, variantName } from '@/lib/cart/format';
import type { LineImage } from '@/lib/cart/page';
import type { CartLine, CartNotice } from '@/lib/cart/types';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { CartForm } from './CartForm';

interface Props {
    line: CartLine;
    image: LineImage | null;
    locale: Locale;
    t: Messages;
    /** The flash notice of a no-JS post about this line (the API's "Only 2 left in stock."). */
    notice: CartNotice | null;
}

const stepCell =
    'relative flex size-9 items-center justify-center text-fg-primary transition-colors duration-(--tm-duration-fast) ease-standard hover:bg-fg-primary/8 disabled:text-fg-disabled disabled:hover:bg-transparent after:absolute after:-inset-1 after:content-[""]';

// CartLineItem — Figma 120:2744 (390 FILL, min 120; desktop in a 1px-bordered list). Photo 88 ·
// 2-line title + trash (44 hit) · variant caption · StockIndicator · CartStepper (36) + line total ·
// StockAlert. Every control is a real form POST to a cart Route Handler (works without JS);
// CartForm enhances it in place and dims the line while it runs (the "Updating" state).
// Unavailable lines are faded and their price struck: the API already excludes them from totals.
export function CartLineItem({ line, image, locale, t, notice }: Props) {
    const c = t.cart;
    const name = lineName(line, locale);
    const variant = variantName(line, locale);
    const stock = lowStockLabel(line, c);
    const problem = lineProblem(line, c);
    const action = `/api/cart/items/${line.id}`;
    const href = localizedPath(locale, `/products/${line.product.slug}`);
    const flash = notice ? noticeText(notice, c.notices) : null;
    const dead = !line.sellable;

    return (
        <li className="flex min-h-30 gap-3 border-b border-line-default bg-surface p-4 last:border-b-0 has-[form[data-pending]]:opacity-40">
            <a href={href} tabIndex={-1} aria-hidden="true" className={`relative size-22 shrink-0 overflow-hidden rounded-xs border border-line-default bg-tile-pink ${dead ? 'opacity-50' : ''}`}>
                {image ? (
                    <img src={image.url} alt="" width={88} height={88} loading="lazy" decoding="async" className="size-full object-cover" />
                ) : (
                    <span className="flex size-full items-center justify-center text-fg-tertiary">
                        <Icon name="puzzlePiece" className="size-icon-lg" />
                    </span>
                )}
            </a>
            <div className="flex min-w-0 grow flex-col gap-1.5">
                <div className="flex items-start gap-1">
                    <a href={href} lang={name.lang} className="t-card-title line-clamp-2 max-w-45 min-w-0 grow text-fg-primary hover:underline">
                        {name.text}
                    </a>
                    <CartForm action={action} notices={c.notices} label={format(c.remove, { name: name.text })} className="shrink-0" errorClassName="sr-only">
                        <input type="hidden" name="locale" value={locale} />
                        <button
                            type="submit"
                            name="intent"
                            value="remove"
                            aria-label={format(c.remove, { name: name.text })}
                            className="relative flex size-8 items-center justify-center rounded-full text-fg-secondary transition-colors duration-(--tm-duration-fast) ease-standard after:absolute after:-inset-1.5 after:content-[''] hover:bg-fg-primary/8 hover:text-fg-primary"
                        >
                            <Icon name="trash" className="size-icon-md" />
                        </button>
                    </CartForm>
                </div>
                {variant ? <p className="t-caption text-fg-tertiary">{variant}</p> : null}
                {stock ? (
                    <p className="t-badge inline-flex h-5 w-fit items-center gap-2 rounded-full bg-accent-subtle px-2 text-fg-primary">
                        <span aria-hidden="true" className={`size-2 rounded-full ${line.available_quantity <= 0 ? 'bg-status-out-of-stock' : 'bg-status-low-stock'}`} />
                        {stock}
                    </p>
                ) : null}
                <CartForm
                    action={action}
                    notices={c.notices}
                    label={format(c.quantity, { name: name.text })}
                    initialError={flash}
                    errorClassName="t-caption-strong flex min-h-8 items-center gap-2 rounded-xs bg-warning-subtle px-3 py-1.5 text-status-low-stock"
                    className="flex flex-col gap-1.5"
                >
                    <input type="hidden" name="locale" value={locale} />
                    <input type="hidden" name="quantity" value={line.quantity} />
                    <div className="flex h-9 items-center justify-between gap-3">
                        {dead ? (
                            <span className="t-caption text-fg-tertiary">{c.outOfStock}</span>
                        ) : (
                            <div className="flex h-9 w-28 items-center rounded-full border border-line-default bg-brand-subtle">
                                <button type="submit" name="intent" value="dec" aria-label={format(c.decrease, { name: name.text })} className={`${stepCell} rounded-s-full`}>
                                    <Icon name="minus" className="size-icon-sm" />
                                </button>
                                <output aria-label={format(c.quantity, { name: name.text })} className="t-price-current-card min-w-0 grow text-center tabular-nums text-fg-price">
                                    {line.quantity}
                                </output>
                                <button
                                    type="submit"
                                    name="intent"
                                    value="inc"
                                    aria-label={format(c.increase, { name: name.text })}
                                    disabled={line.quantity >= 99 || line.quantity >= line.available_quantity}
                                    className={`${stepCell} rounded-e-full`}
                                >
                                    <Icon name="plus" className="size-icon-sm" />
                                </button>
                            </div>
                        )}
                        <span className="flex flex-col items-end">
                            {line.price_changed && !dead ? <span className="t-caption text-fg-tertiary">{format(c.priceChanged, { was: formatPrice(line.added_unit_price) })}</span> : null}
                            <span className={`t-price-current-card tabular-nums ${dead ? 'text-fg-tertiary line-through' : 'text-fg-price'}`}>{formatPrice(line.total)}</span>
                        </span>
                    </div>
                </CartForm>
                {problem && !flash ? (
                    <p role="status" className="t-caption-strong flex min-h-8 items-center gap-2 rounded-xs bg-warning-subtle px-3 py-1.5 text-status-low-stock">
                        <Icon name="warning" className="size-icon-sm shrink-0" />
                        {problem}
                    </p>
                ) : null}
            </div>
        </li>
    );
}
