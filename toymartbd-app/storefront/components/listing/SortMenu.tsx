import { Icon } from '@/components/icons/Icon';
import { format, type Messages } from '@/lib/i18n';
import { hrefFor, SORTS, withSort, type ListingState, type Sort } from '@/lib/listing/state';

// SortTrigger 115:906 + SortMenu 115:986 (storefront spec §A8). A <details> disclosure whose
// options are plain links to the same listing with `sort=` — no JavaScript needed, every sorted
// view has a URL, and back restores the previous order. The trigger truncates the value, never
// the word "Sort".

interface Props {
    /** Localised listing path, e.g. `/bn/categories/lego`. */
    href: string;
    state: ListingState;
    t: Messages['listing'];
    /** Mobile: half of the filter bar (fill, 44 tall). Desktop: hug pill, 40 tall. */
    context: 'mobile' | 'desktop';
    /** The scope's sorts (search adds "Best match" and drops "Featured"). */
    sorts?: readonly Sort[];
}

export function SortMenu({ href, state, t, context, sorts: options = SORTS }: Props) {
    const sorts = t.sorts as Record<string, string>;
    const mobile = context === 'mobile';
    return (
        <details data-dismissible className={`listing-disclosure relative ${mobile ? 'min-w-0 flex-1' : ''}`}>
            <summary
                className={`flex cursor-pointer items-center justify-center gap-2 rounded-full border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface px-4 text-fg-primary ${mobile ? 'h-control-md w-full' : 'h-10'}`}
            >
                <Icon name="slidersHorizontal" className="size-4.5 shrink-0" />
                <span className="t-body-sm-strong min-w-0 truncate">{format(t.sortLabel, { value: sorts[state.sort] ?? state.sort })}</span>
                <Icon name="caretDown" className="size-4.5 shrink-0" />
            </summary>
            <div className="absolute top-full right-0 z-30 mt-2 w-62 max-w-[calc(100vw-2rem)] rounded-lg bg-surface-raised p-2 shadow-e3">
                <p className="t-caption px-3 py-2 text-fg-secondary">{t.sortBy}</p>
                <ul>
                    {options.map((sort) => {
                        const current = sort === state.sort;
                        return (
                            <li key={sort}>
                                <a
                                    href={hrefFor(href, withSort(state, sort))}
                                    aria-current={current ? 'true' : undefined}
                                    rel="nofollow"
                                    className={`flex min-h-10 items-center gap-2 rounded-sm px-3 max-lg:min-h-13 ${current ? 't-body-sm-strong bg-brand-subtle text-fg-primary' : 't-body-sm text-fg-primary hover:bg-subtle'}`}
                                >
                                    <span className="grow">{sorts[sort]}</span>
                                    {current ? <Icon name="check" className="size-icon-md text-fg-brand" /> : null}
                                </a>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </details>
    );
}
