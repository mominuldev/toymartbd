import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { priceBandLabel } from '@/lib/listing/format';
import type { FacetOption, Facets } from '@/lib/listing/response';
import { defaultSortFor, hrefFor, type ListingScope, type ListingState } from '@/lib/listing/state';

// The facets of FilterPanel (storefront spec §A7, Figma FilterSidebar 116:857 / FilterOption
// 116:856). A plain GET form: every option is a real checkbox named after its URL key, so the
// filtered page is reachable, shareable and restorable with JavaScript off. The desktop sidebar
// auto-applies on change once FilterAutoApply has enhanced it; the mobile sheet applies on submit.
//
// Order is fixed: Age → Price → Category → Brand → Gender → Availability (Figma; Skill and Rating
// have no data yet, and Availability's "Free delivery" option waits for per-card delivery data). Zero-count options are shown disabled with a reason, never hidden.

export interface FilterFormProps {
    id: string;
    locale: Locale;
    path: string;
    scope: ListingScope;
    state: ListingState;
    facets: Facets;
    t: Messages['listing'];
    /** Mobile rows are 48 tall, desktop 40. */
    size: 'mobile' | 'desktop';
    /** Extra GET fields the form must carry, e.g. the search text `q`. */
    hidden?: Record<string, string>;
    children?: ReactNode;
}

function optionLabel(o: FacetOption, locale: Locale): { text: string; lang?: 'en' } {
    const text = locale === 'bn' && o.labelBn ? o.labelBn : (o.label ?? o.value);
    return locale === 'bn' && !o.labelBn ? { text, lang: 'en' } : { text };
}

function Section({ title, open, children }: { title: string; open: boolean; children: ReactNode }) {
    return (
        <details open={open} className="listing-disclosure border-b border-line-default py-3.5 last:border-b-0">
            <summary className="flex min-h-7 cursor-pointer items-center justify-between rounded-xs text-fg-primary max-lg:min-h-touch-min">
                <span className="t-h4">{title}</span>
                <Icon name="caretUp" className="caret-open size-icon-md text-fg-secondary" />
                <Icon name="caretDown" className="caret-closed size-icon-md text-fg-secondary" />
            </summary>
            <div className="mt-2 flex flex-col gap-2">{children}</div>
        </details>
    );
}

function Checkbox({ name, value, checked, disabled, type = 'checkbox' }: { name: string; value: string; checked: boolean; disabled: boolean; type?: 'checkbox' | 'radio' }) {
    return (
        <span className="relative flex size-5 shrink-0 items-center justify-center">
            <input
                type={type}
                name={name}
                value={value}
                defaultChecked={checked}
                disabled={disabled}
                className="peer absolute inset-0 m-0 cursor-pointer appearance-none rounded-xs border-(length:--tm-border-width-control) border-line-strong bg-surface checked:border-brand checked:bg-brand disabled:cursor-not-allowed disabled:border-line-default disabled:bg-disabled"
            />
            <Icon name="check" className="pointer-events-none relative size-3.5 text-fg-on-brand opacity-0 peer-checked:opacity-100" />
        </span>
    );
}

function OptionRow({ name, option, label, size, t, type }: { name: string; option: FacetOption; label: { text: string; lang?: 'en' }; size: 'mobile' | 'desktop'; t: Messages['listing']; type?: 'radio' }) {
    const zero = option.count === 0 && !option.selected;
    return (
        <label
            title={zero ? t.zeroOption : undefined}
            className={`flex items-center gap-3 rounded-xs px-2 ${size === 'mobile' ? 'h-12' : 'h-10'} ${zero ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-subtle'}`}
        >
            <Checkbox name={name} value={option.value} checked={option.selected} disabled={zero} type={type} />
            <span lang={label.lang} className={`grow ${option.selected ? 't-body-sm-strong text-fg-primary' : 't-body-sm'} ${zero ? 'text-fg-disabled' : 'text-fg-primary'}`}>
                {label.text}
            </span>
            <span className={`t-caption tabular-nums ${zero ? 'text-fg-disabled' : 'text-fg-tertiary'}`}>{option.count}</span>
        </label>
    );
}

/** Long lists (Brand) show 6 rows, then "Show N more" (§A7.1). */
const VISIBLE_OPTIONS = 6;

export function FilterForm({ id, locale, path, scope, state, facets, t, size, hidden = {}, children }: FilterFormProps) {
    const ageLabels = t.ageBands as Record<string, string>;
    const genderLabels = t.genders as Record<string, string>;
    const brandRows = facets.brand.map((o) => <OptionRow key={o.value} name="brand" option={o} label={optionLabel(o, locale)} size={size} t={t} />);
    const anyIn = (options: FacetOption[]) => options.some((o) => o.selected);
    const query = (category: string) => hrefFor(localizedPath(locale, `/categories/${category}`), { ...state, category: null, cursor: null });

    return (
        <form id={id} method="get" action={localizedPath(locale, path)} className="group/filters flex flex-col">
            {Object.entries(hidden).map(([name, value]) => (
                <input key={name} type="hidden" name={name} value={value} />
            ))}
            {state.sort !== defaultSortFor(scope) ? <input type="hidden" name="sort" value={state.sort} /> : null}

            {facets.age.length > 0 ? (
                <Section title={t.facetAge} open>
                    <div className="flex flex-wrap gap-2">
                        {facets.age.map((o) => {
                            const zero = o.count === 0 && !o.selected;
                            return (
                                <label key={o.value} title={zero ? t.zeroOption : undefined} className={zero ? 'cursor-not-allowed' : 'cursor-pointer'}>
                                    <input type="checkbox" name="age" value={o.value} defaultChecked={o.selected} disabled={zero} className="peer sr-only" />
                                    <span className="t-chip flex h-control-sm min-w-14 items-center justify-center rounded-full border border-line-default bg-surface px-3 text-fg-primary peer-checked:border-brand peer-checked:bg-brand-subtle peer-focus-visible:shadow-focus peer-disabled:bg-disabled peer-disabled:text-fg-disabled max-lg:h-touch-min">
                                        {ageLabels[o.value] ?? o.value}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </Section>
            ) : null}

            {facets.price.length > 0 ? (
                <Section title={t.facetPrice} open>
                    {facets.price.map((o) => (
                        <OptionRow key={o.value} name="price" option={o} label={{ text: o.min !== undefined ? priceBandLabel(o.min, o.max ?? null, t) : o.value }} size={size} t={t} />
                    ))}
                </Section>
            ) : null}

            {facets.category.length > 0 ? (
                <Section title={t.facetCategory} open={scope.kind !== 'category' && state.category !== null}>
                    {scope.kind !== 'category'
                        ? facets.category.map((o) => <OptionRow key={o.value} name="category" type="radio" option={{ ...o, selected: o.value === state.category }} label={optionLabel(o, locale)} size={size} t={t} />)
                        : facets.category.map((o) => {
                              const label = optionLabel(o, locale);
                              return (
                                  <a key={o.value} href={query(o.value)} className={`flex items-center gap-3 rounded-xs px-2 hover:bg-subtle ${size === 'mobile' ? 'h-12' : 'h-10'}`}>
                                      <span lang={label.lang} className="t-body-sm grow text-fg-link">
                                          {label.text}
                                      </span>
                                      <span className="t-caption tabular-nums text-fg-tertiary">{o.count}</span>
                                  </a>
                              );
                          })}
                </Section>
            ) : null}

            {facets.brand.length > 0 && scope.kind !== 'brand' ? (
                <Section title={t.facetBrand} open={anyIn(facets.brand)}>
                    {brandRows.slice(0, VISIBLE_OPTIONS)}
                    {brandRows.length > VISIBLE_OPTIONS ? (
                        <details className="listing-disclosure flex flex-col gap-2" open={facets.brand.slice(VISIBLE_OPTIONS).some((o) => o.selected)}>
                            <summary className="t-body-sm-strong flex min-h-touch-min cursor-pointer items-center px-2 text-fg-brand">
                                {format(t.showMore, { count: String(brandRows.length - VISIBLE_OPTIONS) })}
                            </summary>
                            {brandRows.slice(VISIBLE_OPTIONS)}
                        </details>
                    ) : null}
                </Section>
            ) : null}

            {facets.gender.length > 0 ? (
                <Section title={t.facetGender} open={anyIn(facets.gender)}>
                    {facets.gender.map((o) => (
                        <OptionRow key={o.value} name="gender" option={o} label={{ text: genderLabels[o.value] ?? o.value }} size={size} t={t} />
                    ))}
                </Section>
            ) : null}

            {facets.availability.length > 0 ? (
                <Section title={t.facetAvailability} open={anyIn(facets.availability)}>
                    {facets.availability.map((o) => (
                        <OptionRow key={o.value} name="availability" option={o} label={{ text: t.inStockOnly }} size={size} t={t} />
                    ))}
                </Section>
            ) : null}

            {children ?? (
                <button
                    type="submit"
                    className="t-chip mt-4 flex h-control-md items-center justify-center rounded-full bg-(image:--tm-gradient-button-primary) px-5 text-fg-on-brand group-data-enhanced/filters:hidden"
                >
                    {t.apply}
                </button>
            )}
        </form>
    );
}
