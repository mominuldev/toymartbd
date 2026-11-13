import Image from 'next/image';
import type { ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { cmsPaths } from '@/lib/site-config';
import type { AboutBlocks, StaticPageContent } from '@/lib/static-pages';
import blocks from '@/public/mock/about/blocks.jpg';
import shapeSorter from '@/public/mock/about/shape-sorter.jpg';
import slide from '@/public/mock/about/slide.jpg';

// Utility-page headers use the Bloom gradients (Figma: About = sunrise, Contact/Terms/FAQ = sky,
// Privacy = mint, 404/Returns = candy).
const tones = {
    sunrise: 'bg-(image:--tm-gradient-bloom-sunrise)',
    sky: 'bg-(image:--tm-gradient-bloom-sky)',
    mint: 'bg-(image:--tm-gradient-bloom-mint)',
    candy: 'bg-(image:--tm-gradient-bloom-candy)',
} as const;

interface Props {
    locale: Locale;
    content: StaticPageContent;
    /** Extra blocks after the sections (a contact form, a FAQ accordion…). */
    children?: ReactNode;
}

function DraftNotice({ locale }: { locale: Locale }) {
    return (
        <p className="t-caption self-start rounded-full bg-warning-subtle px-3 py-1 text-fg-primary max-lg:self-center" data-placeholder="draft-copy">
            {getMessages(locale).pages.draftNotice}
        </p>
    );
}

// Static page template (storefront spec §B8: About, Contact, FAQ, Privacy, Terms, Returns): a
// Bloom-gradient header, the sections, and a route back into the catalog. Content is data
// (lib/static-pages.ts today, the CMS later); `draft` pages show a visible notice so placeholder
// copy cannot ship unnoticed. A page with `about` blocks renders the About layout instead.
export function StaticPage({ locale, content, children }: Props) {
    if (content.about) return <AboutContent locale={locale} content={content} about={content.about} />;
    const t = getMessages(locale);
    return (
        <article className="flex flex-col gap-8">
            <header className={`flex flex-col gap-3 rounded-lg-plus p-6 lg:rounded-xl-plus lg:p-10 ${tones[content.tone]}`}>
                {content.draft ? <DraftNotice locale={locale} /> : null}
                <h1 className="t-display-2 text-fg-primary lg:t-display-1">{content.title}</h1>
                {content.lead ? <p className="t-body max-w-prose text-fg-secondary lg:t-body-lg">{content.lead}</p> : null}
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
                <a href={localizedPath(locale, '/categories')} className={buttonClass('primary')}>
                    {t.pages.browse}
                </a>
                <a href={localizedPath(locale, '/')} className={buttonClass('secondary')}>
                    {t.pages.home}
                </a>
            </p>
        </article>
    );
}

// Sticker-photo collage geometry, in % of a 500 × 400 box (Figma 170:10743): centre, width, tilt.
const collage = [
    { src: shapeSorter, x: 26.84, y: 32.65, w: 45.2, r: -6 },
    { src: slide, x: 72.72, y: 27.9, w: 41.2, r: 5 },
    { src: blocks, x: 54.8, y: 70.4, w: 47.2, r: -3 },
] as const;

const promiseStyle: Record<AboutBlocks['promises'][number]['key'], { ground: string; badge: string; icon: IconName }> = {
    age: { ground: 'bg-tile-mint', badge: 'bg-feedback-success text-fg-inverse', icon: 'shieldCheckFill' },
    returns: { ground: 'bg-tile-sky', badge: 'bg-brand text-fg-on-brand', icon: 'checkCircle' },
    cod: { ground: 'bg-tile-butter', badge: 'bg-accent text-fg-on-accent', icon: 'gift' },
    nationwide: { ground: 'bg-tile-lavender', badge: 'bg-grape text-fg-on-grape', icon: 'mapPin' },
};

const valueInk = ['text-fg-brand', 'text-feedback-success', 'text-feedback-info'] as const;

// About us — Figma 170:10502 (1440) / 174:8244 (390): a Bloom-sunrise hero with a sticker-style
// photo collage (mock AI photos from the Figma file, unbranded — replace with licensed
// photography before launch), four promise tiles, "What we believe" numbered values, and a
// candy visit band. The 390 frame uses shorter promise copy; one copy set is used here.
function AboutContent({ locale, content, about }: { locale: Locale; content: StaticPageContent; about: AboutBlocks }) {
    return (
        <article className="flex flex-col gap-10 lg:gap-16">
            <header className={`flex flex-col items-center gap-6 overflow-hidden rounded-lg-plus px-5 pt-6 pb-7 lg:flex-row lg:gap-14 lg:rounded-xl-plus lg:py-16 lg:pr-16 lg:pl-18 ${tones[content.tone]}`}>
                <div className="flex min-w-0 flex-col items-start gap-4.5 lg:flex-1">
                    {content.draft ? <DraftNotice locale={locale} /> : null}
                    <p className="t-overline rounded-full bg-surface px-3.5 py-1.5 text-fg-brand">{about.eyebrow}</p>
                    <h1 className="t-display-1 text-fg-primary">{content.title}</h1>
                    {about.story.map((paragraph, i) => (
                        <p key={paragraph} className={`t-body lg:t-body-lg ${i === 0 ? 'text-fg-primary' : 'text-fg-secondary'}`}>
                            {paragraph}
                        </p>
                    ))}
                </div>
                <div aria-hidden="true" className="relative aspect-5/4 w-full max-w-125 shrink-0 max-lg:-order-1 lg:w-125">
                    {collage.map((photo) => (
                        <span
                            key={photo.src.src}
                            className="absolute aspect-square -translate-1/2 rounded-md-plus bg-surface p-2 shadow-e2 lg:rounded-lg-plus"
                            style={{ left: `${photo.x}%`, top: `${photo.y}%`, width: `${photo.w}%`, rotate: `${photo.r}deg` }}
                        >
                            <Image src={photo.src} alt="" sizes="(min-width: 1024px) 236px, 45vw" className="size-full rounded-md-minus object-cover lg:rounded-md-plus" />
                        </span>
                    ))}
                    <span
                        className="t-badge-sticker absolute flex aspect-square w-[12.8%] -translate-1/2 rotate-10 flex-col items-center justify-center rounded-full bg-accent text-center text-fg-on-accent"
                        style={{ left: '89.2%', top: '59.3%' }}
                    >
                        <span>{about.agesBadge[0]}</span>
                        <span>{about.agesBadge[1]}</span>
                    </span>
                </div>
            </header>

            <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-5">
                {about.promises.map((promise) => {
                    const style = promiseStyle[promise.key];
                    return (
                        <li key={promise.key} className={`flex flex-col gap-2 rounded-md-plus p-4 lg:rounded-lg lg:p-6 ${style.ground}`}>
                            <span aria-hidden="true" className={`mb-1.5 inline-flex size-10 items-center justify-center rounded-full lg:size-12 ${style.badge}`}>
                                <Icon name={style.icon} className="size-icon-md lg:size-icon-lg" />
                            </span>
                            <h2 className="t-feature-title text-fg-primary">{promise.title}</h2>
                            <p className="t-body-sm text-fg-secondary lg:t-body">{promise.body}</p>
                        </li>
                    );
                })}
            </ul>

            <section aria-labelledby="about-values" className="flex flex-col gap-4 lg:gap-6">
                <h2 id="about-values" className="t-display-2 text-fg-primary">
                    {about.valuesTitle}
                </h2>
                <ol className="grid gap-3 lg:grid-cols-3 lg:gap-6">
                    {about.values.map((value, i) => (
                        <li key={value.title} className="flex gap-3 rounded-md-plus border border-line-default bg-surface p-5 lg:flex-col lg:rounded-lg lg:gap-2.5 lg:p-7">
                            <span aria-hidden="true" className={`t-display-1 shrink-0 ${valueInk[i % valueInk.length]}`}>
                                {String(i + 1).padStart(2, '0')}
                            </span>
                            <div className="flex flex-col gap-1 lg:gap-2.5">
                                <h3 className="t-feature-title text-fg-primary">{value.title}</h3>
                                <p className="t-body-sm text-fg-secondary lg:t-body">{value.body}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </section>

            <section
                aria-labelledby="about-visit"
                className="flex flex-col gap-6 rounded-lg-plus bg-(image:--tm-gradient-bloom-candy) p-6 lg:flex-row lg:rounded-xl-plus lg:items-center lg:gap-8 lg:px-14 lg:py-12"
            >
                <div className="flex min-w-0 flex-col gap-2 lg:flex-1">
                    <h2 id="about-visit" className="t-display-2 text-fg-primary">
                        {about.visit.title}
                    </h2>
                    <p className="t-body-lg text-fg-secondary" data-placeholder="owner-to-confirm">
                        {about.visit.body}
                    </p>
                </div>
                <div className="flex flex-col gap-2.5 lg:flex-row lg:gap-3">
                    <a href={localizedPath(locale, cmsPaths.contact)} className={buttonClass('primary')}>
                        <Icon name="mapPin" className={buttonIconClass.lg} />
                        {about.visit.directions}
                    </a>
                    <a href={localizedPath(locale, cmsPaths.contact)} className={buttonClass('secondary')}>
                        <Icon name="chatCircleDots" className={buttonIconClass.lg} />
                        {about.visit.contact}
                    </a>
                </div>
            </section>
        </article>
    );
}
