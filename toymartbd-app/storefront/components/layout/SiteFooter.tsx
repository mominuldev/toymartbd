import { LocaleToggle } from '@/components/LocaleToggle';
import { Icon } from '@/components/icons/Icon';
import { format, getMessages, localizedPath, otherLocale, type Locale } from '@/lib/i18n';
import { addressIsPlaceholder, contact, footerColumns, legal } from '@/lib/site-config';
import { ContactDetail } from './ContactDetail';

interface Props {
    locale: Locale;
    path: string;
}

const tileTone = {
    shop: 'bg-tile-butter',
    help: 'bg-tile-mint',
    company: 'bg-tile-sky',
} as const;

const linkClass = 't-body-sm inline-flex min-h-touch-min items-center rounded-xs text-fg-primary underline-offset-4 hover:underline lg:min-h-8';

// Footer — storefront spec §B1.9 (bento tiles, owner redesign 2026-11-11) with content spec §A.12
// copy. Contact values come only from lib/site-config.ts and are shown as "sample" until the
// owner confirms them. The decorative giant wordmark is left out: it needs a display size the
// type tokens do not define yet.
export function SiteFooter({ locale, path }: Props) {
    const t = getMessages(locale);
    const target = otherLocale(locale);
    const anyPlaceholder = addressIsPlaceholder || Object.values(contact).some((c) => c.placeholder);
    const sample = t.footer.sample;

    return (
        <footer className="mt-section-mobile border-t border-line-default bg-surface pb-[calc(--spacing(14)+env(safe-area-inset-bottom))] lg:mt-section-desktop lg:pb-0">
            <div className="mx-auto grid max-w-content gap-4 px-gutter-mobile py-8 md:grid-cols-2 md:px-gutter-tablet lg:grid-cols-5 lg:gap-6 lg:px-gutter-desktop lg:py-16">
                <section aria-labelledby="footer-about" className="flex flex-col gap-3 rounded-xl bg-tile-pink p-6 md:col-span-2 lg:col-span-1">
                    <h2 id="footer-about" className="t-h3 text-fg-primary">
                        {t.site.name}
                    </h2>
                    <p className="t-body-sm text-fg-secondary">{t.footer.aboutBody}</p>
                    <p className="t-h4 flex items-center gap-2 text-fg-primary">
                        <Icon name="phone" className="size-icon-md" />
                        <span className="sr-only">{t.footer.contact.hotline}: </span>
                        <ContactDetail value={contact.hotline} sampleLabel={sample} />
                    </p>
                </section>

                {footerColumns.map((column) => (
                    <section key={column.title} aria-labelledby={`footer-${column.title}`} className={`rounded-xl p-6 ${tileTone[column.title]}`}>
                        <h2 id={`footer-${column.title}`} className="t-h4 text-fg-primary">
                            {t.footer.columns[column.title]}
                        </h2>
                        <ul className="mt-2 flex flex-col">
                            {column.links.map((link) => (
                                <li key={link.path}>
                                    <a href={localizedPath(locale, link.path)} className={linkClass}>
                                        {t.footer.links[link.label]}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                <section aria-labelledby="footer-contact" className="flex flex-col gap-3 rounded-xl bg-tile-lavender p-6 md:col-span-2 lg:col-span-1">
                    <h2 id="footer-contact" className="t-h4 text-fg-primary">
                        {t.footer.contact.title}
                    </h2>
                    <dl className="t-body-sm flex flex-col gap-2 text-fg-primary">
                        <div className="flex items-start gap-2">
                            <dt>
                                <Icon name="whatsappLogo" className="size-icon-md" />
                                <span className="sr-only">{t.footer.contact.whatsapp}</span>
                            </dt>
                            <dd>
                                <ContactDetail value={contact.whatsapp} sampleLabel={sample} />
                            </dd>
                        </div>
                        <div className="flex items-start gap-2">
                            <dt>
                                <Icon name="envelopeSimple" className="size-icon-md" />
                                <span className="sr-only">{t.footer.contact.email}</span>
                            </dt>
                            <dd className="min-w-0 break-words">
                                <ContactDetail value={contact.email} sampleLabel={sample} />
                            </dd>
                        </div>
                        <div className="flex items-start gap-2">
                            <dt>
                                <Icon name="mapPin" className="size-icon-md" />
                                <span className="sr-only">{t.footer.contact.title}</span>
                            </dt>
                            <dd data-placeholder={addressIsPlaceholder ? 'owner-to-confirm' : undefined}>
                                {t.footer.contact.address}
                                <br />
                                {t.footer.contact.hours}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <div className="border-t border-line-default">
                <div className="t-caption mx-auto flex max-w-content flex-wrap items-center gap-x-4 gap-y-2 px-gutter-mobile py-5 text-fg-tertiary md:px-gutter-tablet lg:px-gutter-desktop">
                    <p>{format(t.footer.copyright, { year: String(new Date().getFullYear()) })}</p>
                    <p data-placeholder={legal.tradeLicence && legal.bin ? undefined : 'owner-to-confirm'}>
                        {legal.tradeLicence && legal.bin
                            ? format(t.footer.tradeLicence, { number: legal.tradeLicence, bin: legal.bin })
                            : t.footer.legalPending}
                    </p>
                    <p>
                        <span className="sr-only">{t.footer.paymentTitle}: </span>
                        <span className="rounded-full bg-success-subtle px-3 py-1 text-fg-primary">{t.footer.cod}</span>
                    </p>
                    <p className="lg:ml-auto">
                        <span lang={locale} aria-current="true" className="px-1 font-semibold text-fg-primary">
                            {t.language[locale]}
                        </span>
                        <span aria-hidden="true"> · </span>
                        <LocaleToggle
                            target={target}
                            href={localizedPath(target, path)}
                            label={t.language[target]}
                            accessibleLabel={t.language.switchLabel}
                            variant="text"
                        />
                    </p>
                    {anyPlaceholder ? <p className="w-full">{t.footer.sampleNotice}</p> : null}
                </div>
            </div>
        </footer>
    );
}
