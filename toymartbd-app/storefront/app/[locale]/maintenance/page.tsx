import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { ContactDetail } from '@/components/layout/ContactDetail';
import { Icon } from '@/components/icons/Icon';
import { format, getMessages, isLocale, localizedPath } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

// Maintenance page (storefront spec §B8): no site chrome — the shop is offline — just the
// wordmark, the content-spec copy and the ways to reach a person. Served at /maintenance and
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
    const body = t.maintenance.body.split('{phone}');

    return (
        <main id="main" className="mx-auto flex w-full max-w-140 grow flex-col items-center justify-center gap-6 px-gutter-mobile py-section-mobile md:px-gutter-tablet">
            <a href={localizedPath(locale, '/')} className="t-h2 rounded-xs text-fg-brand">
                {t.site.name}
            </a>
            <section className="flex w-full flex-col gap-4 rounded-lg border border-line-default bg-surface p-6 shadow-e1">
                <Icon name="wrench" className="size-icon-lg text-fg-secondary" />
                <h1 className="t-h1 text-fg-primary">{t.maintenance.title}</h1>
                <p className="t-body text-fg-secondary">
                    {body[0]}
                    <ContactDetail value={contact.hotline} sampleLabel={t.footer.sample} />
                    {body[1]}
                </p>
                <ul className="t-body flex flex-col gap-2 text-fg-primary">
                    <li className="flex items-center gap-2">
                        <Icon name="phone" className="size-icon-md" />
                        <span className="sr-only">{t.footer.contact.hotline}: </span>
                        <ContactDetail value={contact.hotline} sampleLabel={t.footer.sample} />
                    </li>
                    <li className="flex items-center gap-2">
                        <Icon name="whatsappLogo" className="size-icon-md" />
                        <span className="sr-only">{t.maintenance.whatsapp}: </span>
                        <ContactDetail value={contact.whatsapp} sampleLabel={t.footer.sample} />
                    </li>
                </ul>
                {contact.hotline.placeholder ? null : (
                    <a
                        href={contact.hotline.href}
                        className="t-button inline-flex min-h-touch-min items-center justify-center gap-2 rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
                    >
                        <Icon name="phone" className="size-icon-md" />
                        {format(t.maintenance.call, { phone: contact.hotline.display })}
                    </a>
                )}
            </section>
            <p className="t-caption text-center text-fg-tertiary" data-placeholder="owner-to-confirm">
                {t.footer.contact.address}
            </p>
        </main>
    );
}
