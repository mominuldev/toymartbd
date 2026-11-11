import type { ReactNode } from 'react';

import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import type { StaticPageContent } from '@/lib/static-pages';

const tones = {
    sunrise: 'bg-tile-peach',
    sky: 'bg-tile-sky',
    mint: 'bg-tile-mint',
    candy: 'bg-tile-pink',
} as const;

interface Props {
    locale: Locale;
    content: StaticPageContent;
    /** Extra blocks after the sections (a contact form, a FAQ accordion…). */
    children?: ReactNode;
}

// Static page template (storefront spec §B8: About, Contact, FAQ, Privacy, Terms, Returns). A
// coloured header band, an optional lead, the sections, and a route back into the
// catalog. Content is data (lib/static-pages.ts today, the CMS later); `draft` pages show a
// visible notice so placeholder copy cannot ship unnoticed.
export function StaticPage({ locale, content, children }: Props) {
    const t = getMessages(locale);
    return (
        <article className="flex flex-col gap-8">
            <header className={`flex flex-col gap-3 rounded-2xl p-6 md:p-10 ${tones[content.tone]}`}>
                {content.draft ? (
                    <p className="t-caption self-start rounded-full bg-warning-subtle px-3 py-1 text-fg-primary" data-placeholder="draft-copy">
                        {t.pages.draftNotice}
                    </p>
                ) : null}
                <h1 className="t-h1 text-fg-primary">{content.title}</h1>
                {content.lead ? <p className="t-body-lg max-w-prose text-fg-secondary">{content.lead}</p> : null}
            </header>

            {content.sections.map((section) => (
                <section key={section.heading} className="flex max-w-prose flex-col gap-3">
                    <h2 className="t-h2 text-fg-primary">{section.heading}</h2>
                    {section.body.map((paragraph) => (
                        <p key={paragraph} className="t-body text-fg-secondary">
                            {paragraph}
                        </p>
                    ))}
                </section>
            ))}

            {children}

            <p className="flex flex-wrap gap-3">
                <a
                    href={localizedPath(locale, '/categories')}
                    className="t-button inline-flex min-h-touch-min items-center rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
                >
                    {t.pages.browse}
                </a>
                <a
                    href={localizedPath(locale, '/')}
                    className="t-button inline-flex min-h-touch-min items-center rounded-full border border-line-strong bg-surface px-6 text-fg-primary"
                >
                    {t.pages.home}
                </a>
            </p>
        </article>
    );
}
