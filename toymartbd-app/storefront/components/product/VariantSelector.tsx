import Link from 'next/link';

import { Icon } from '@/components/icons/Icon';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { axisViews, variantHref, type AxisOption, type AxisValue, type OptionReason, type ProductVariant, type StorefrontProduct } from '@/lib/product/variants';

interface Props {
    product: StorefrontProduct;
    selected: ProductVariant;
    locale: Locale;
    t: Messages;
    /** Locale-prefixed product path, e.g. `/bn/products/lego-classic`. */
    path: string;
}

const label = (value: AxisValue, locale: Locale): string => (locale === 'bn' && value.value_bn ? value.value_bn : value.value);

export function reasonText(reason: OptionReason, locale: Locale, t: Messages): string {
    const value = label(reason.value, locale);
    switch (reason.kind) {
        case 'soldOut':
            return format(t.pdp.reasonSoldOut, { value });
        case 'lowStock':
            return format(t.pdp.reasonLowStock, { value, count: String(reason.count) });
        case 'comboSoldOut':
            return format(t.pdp.reasonComboSoldOut, { value, others: reason.others.map((o) => label(o, locale)).join(', ') });
        case 'impossible':
            return format(t.pdp.reasonImpossible, { value, others: reason.others.map((o) => label(o, locale)).join(', ') });
    }
}

// VariantSelector — Figma 117:2519 (composition of VariantOption 117:2518), storefront spec §A10.
// Every option is a link to `?variant=<id>`: the URL is the selection, so it is shareable and
// works without JavaScript (next/link keeps the scroll position when it is on). An option that
// cannot be chosen is never hidden: it stays focusable (`aria-disabled`, not `disabled`), keeps
// the dashed look and names its reason in the always-reserved reason line below its axis.
export function VariantSelector({ product, selected, locale, t, path }: Props) {
    if (product.attributes.length === 0) return null;
    const views = axisViews(product, selected);
    const priced = new Set(product.variants.map((v) => v.price)).size > 1;

    return (
        <div id="pdp-variants" className="flex scroll-mt-36 flex-col gap-5">
            {views.map((view, axisIndex) => {
                const axisName = locale === 'bn' && view.axis.name_bn ? view.axis.name_bn : view.axis.name;
                const reasonId = `pdp-reason-${view.axis.id}`;
                const reasons = view.options.map((o) => o.reason).filter((r): r is OptionReason => r !== null);
                const current = view.selected ? label(view.selected, locale) : null;
                return (
                    <div key={view.axis.id} role="group" aria-labelledby={`pdp-axis-${view.axis.id}`} className="flex flex-col gap-3">
                        <div className="flex items-center gap-2">
                            <h2 id={`pdp-axis-${view.axis.id}`} className="t-h4 whitespace-nowrap text-fg-primary">
                                {axisName}
                            </h2>
                            <p className="t-body-sm min-w-0 grow text-fg-secondary">
                                {current ? (axisIndex === 0 && priced ? format(t.pdp.variantPrice, { value: current, price: formatPrice(selected.price) }) : current) : null}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            {view.options.map((option) => (
                                <Option key={option.value.id} option={option} color={view.axis.type === 'color'} axisName={axisName} reasonId={reasonId} path={path} locale={locale} t={t} />
                            ))}
                        </div>
                        <p id={reasonId} className="pdp-reason t-caption flex items-start gap-1.5 text-fg-secondary">
                            {reasons.length > 0 ? (
                                <>
                                    <Icon name="info" className="mt-0.5 size-3.5" />
                                    <span>{reasons.map((r) => reasonText(r, locale, t)).join(' · ')}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                );
            })}
        </div>
    );
}

interface OptionProps {
    option: AxisOption;
    color: boolean;
    axisName: string;
    reasonId: string;
    path: string;
    locale: Locale;
    t: Messages;
}

function Option({ option, color, axisName, reasonId, path, locale, t }: OptionProps) {
    const name = label(option.value, locale);
    const blocked = option.state === 'Unavailable' || option.state === 'OtherAxis';
    const selected = option.state === 'Selected';
    const pill = selected
        ? 'border-(length:--tm-border-width-strong) border-brand bg-brand-subtle t-body-strong text-fg-primary'
        : blocked
          ? 'border border-dashed border-line-default bg-subtle t-body-sm-strong text-fg-disabled'
          : 'border border-line-default bg-surface t-body-sm-strong text-fg-primary hover:border-line-strong';
    const shape = color ? 'relative flex size-11 items-center justify-center rounded-full' : `inline-flex h-11 min-w-14 items-center justify-center rounded-full px-4 whitespace-nowrap ${pill}`;

    const body = color ? (
        <>
            <span
                aria-hidden="true"
                className={`absolute inset-0.5 rounded-full ${selected ? 'border-(length:--tm-border-width-strong) border-brand' : blocked ? 'border border-dashed border-line-default' : 'border border-line-default'}`}
            />
            <span
                aria-hidden="true"
                className={`relative flex size-7.5 items-center justify-center overflow-hidden rounded-full bg-subtle ${blocked ? 'opacity-40' : ''}`}
                style={option.value.color_hex ? { backgroundColor: option.value.color_hex } : undefined}
            >
                {selected ? <Icon name="check" className="size-4 text-fg-on-brand" /> : null}
            </span>
            {blocked ? <span aria-hidden="true" className="absolute top-1/2 left-1/2 h-px w-8.5 -translate-1/2 -rotate-45 bg-status-out-of-stock" /> : null}
            {option.state === 'LowStock' ? <span aria-hidden="true" className="absolute top-1 right-1 size-1.5 rounded-full bg-status-low-stock" /> : null}
            <span className="sr-only">{name}</span>
        </>
    ) : (
        name
    );

    if (selected) {
        return (
            <span aria-current="true" aria-label={format(t.pdp.variantSelected, { attribute: axisName, value: name })} className={shape}>
                {body}
            </span>
        );
    }
    if (blocked || option.variantId === null) {
        return (
            <span role="link" aria-disabled="true" tabIndex={0} aria-describedby={reasonId} className={`${shape} cursor-not-allowed`}>
                {body}
            </span>
        );
    }
    return (
        <Link href={variantHref(path, option.variantId)} scroll={false} replace aria-describedby={option.reason ? reasonId : undefined} className={shape}>
            {body}
        </Link>
    );
}
