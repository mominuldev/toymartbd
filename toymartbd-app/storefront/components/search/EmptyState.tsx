import { Icon } from '@/components/icons/Icon';

// EmptyState Type=NoResults — Figma 80:1589, as placed on Search results 161:16255 (1440) and
// 132:3465 (390). Content column 420, centred; illustration 160 (the 1440 placement keeps 160 too), decorative;
// stack gap 16 with 8 more above the actions; the Extra block (chips) breaks out of the column
// with 32 above it. Buttons are Large and full width on mobile, Medium and hugging on desktop.
// Navigation actions are links. Focus is never moved here; the page's live region announces it.

export interface EmptyAction {
    label: string;
    href: string;
}

interface Props {
    title: string;
    /** Real heading level for the page: h2 in a listing. */
    headingLevel?: 'h2' | 'h3';
    body: string;
    primary: EmptyAction;
    secondary?: EmptyAction;
    extra?: { heading: string; chips: Array<EmptyAction & { lang?: 'en' }> };
}

/** illustration/no-results 80:1085 (placeholder art): brand-subtle ground, coral glass, sun sparkle. */
function NoResultsIllustration() {
    return (
        <span aria-hidden="true" className="relative block size-40 shrink-0 rounded-full bg-brand-subtle">
            <Icon name="magnifyingGlass" className="absolute inset-1/4 size-1/2 text-brand" />
            <Icon name="sparkle" className="absolute top-[12.5%] left-[70%] size-1/5 text-accent" />
        </span>
    );
}

const button = 't-button flex h-control-lg w-full items-center justify-center rounded-full px-5 lg:h-control-md lg:w-auto lg:min-w-28';

export function EmptyState({ title, headingLevel: Heading = 'h2', body, primary, secondary, extra }: Props) {
    return (
        <div className="flex flex-col items-center gap-4 px-4 py-12 text-center">
            <NoResultsIllustration />
            <div className="flex w-full max-w-105 flex-col items-center gap-4">
                <Heading className="t-emptystate-title text-fg-primary">{title}</Heading>
                <p className="t-body-sm text-fg-secondary">{body}</p>
                <div className="flex w-full flex-col items-stretch gap-3 pt-2 lg:w-auto lg:flex-row lg:items-start">
                    <a href={primary.href} className={`${button} bg-(image:--tm-gradient-button-primary) text-fg-on-brand shadow-gloss-button`}>
                        {primary.label}
                    </a>
                    {secondary ? (
                        <a href={secondary.href} className={`${button} text-fg-primary hover:bg-fg-primary/8`}>
                            {secondary.label}
                        </a>
                    ) : null}
                </div>
            </div>
            {extra && extra.chips.length > 0 ? (
                <div className="flex w-full flex-col items-center gap-3 pt-4">
                    <p className="t-body-strong text-fg-primary">{extra.heading}</p>
                    <ul className="flex w-full flex-wrap justify-center gap-2">
                        {extra.chips.map((chip) => (
                            <li key={chip.href}>
                                <a
                                    href={chip.href}
                                    lang={chip.lang}
                                    className="t-chip relative flex h-control-sm min-w-14 items-center justify-center rounded-full border border-line-default bg-surface px-3 text-fg-primary hover:border-line-strong before:absolute before:h-touch-min before:w-full before:content-['']"
                                >
                                    {chip.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
