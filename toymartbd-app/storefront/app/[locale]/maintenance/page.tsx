import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { ContactDetail } from '@/components/layout/ContactDetail';
import { Wordmark } from '@/components/layout/Wordmark';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// Maintenance page (Figma 169:10575 / 173:19641, storefront spec §B8): no site chrome — the shop
// is offline — just the wordmark, the content-spec copy and the ways to reach a person. Served at /maintenance and
// /bn/maintenance; noindex.
// TODO(ops): nothing routes visitors here yet. Switching it on belongs with deployment: the
// proxy (or the load balancer) should answer 503 + Retry-After with this HTML, not a 200 rewrite,
// so crawlers keep the indexed pages.

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: getMessages(locale).maintenance.title, robots: { index: false, follow: false } };
}

export default async function MaintenancePage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const [before, after] = t.maintenance.body.split('{phone}');
    const callLabel = format(t.maintenance.call, { phone: contact.hotline.display });
    const action = 'w-full lg:w-auto';
    // Placeholder contact values keep the design's buttons but are not links (lib/site-config.ts).
    const Cta = ({ href, placeholder, variant, icon, label }: { href: string; placeholder: boolean; variant: 'primary' | 'secondary'; icon: 'phoneFill' | 'whatsappLogo'; label: string }) => {
        const body = (
            <>
                <Icon name={icon} className={buttonIconClass.lg} />
                {label}
            </>
        );
        return placeholder ? (
            <span data-placeholder="owner-to-confirm" className={`${buttonClass(variant)} ${action}`}>
                {body}
            </span>
        ) : (
            <a href={href} className={`${buttonClass(variant)} ${action}`}>
                {body}
            </a>
        );
    };

    return (
        <main id="main" className="flex w-full grow flex-col items-center justify-center gap-10 px-gutter-mobile py-section-mobile md:px-gutter-tablet">
            <a href={localizedPath(locale, '/')} aria-label={t.nav.logoAria} className="inline-flex rounded-xs">
                <Wordmark locale={locale} size="sm" />
            </a>
            <section className="flex w-full max-w-150 flex-col items-center gap-4.5 rounded-lg border border-line-default bg-surface p-5 text-center lg:p-10">
                <span aria-hidden="true" className="inline-flex size-16 items-center justify-center rounded-full bg-info-subtle text-feedback-info">
                    <Icon name="clock" className="size-8" />
                </span>
                <h1 className="t-display-2 text-fg-primary">{t.maintenance.title}</h1>
                <p className="t-body-lg text-fg-secondary">
                    {before}
                    <ContactDetail value={contact.hotline} />
                    {after}
                </p>
                <div className="flex w-full flex-col justify-center gap-2.5 pt-2 lg:flex-row lg:gap-3">
                    <Cta href={contact.hotline.href} placeholder={contact.hotline.placeholder} variant="primary" icon="phoneFill" label={callLabel} />
                    <Cta href={contact.whatsapp.href} placeholder={contact.whatsapp.placeholder} variant="secondary" icon="whatsappLogo" label={t.maintenance.whatsapp} />
                </div>
            </section>
            <p className="t-body-sm text-center text-fg-secondary" data-placeholder="owner-to-confirm">
                {format(t.maintenance.legal, { year: String(new Date().getFullYear()), address: t.maintenance.address })}
            </p>
        </main>
    );
}
