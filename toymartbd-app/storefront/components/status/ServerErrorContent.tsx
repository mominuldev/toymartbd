import { ContactDetail } from '@/components/layout/ContactDetail';
import { Icon } from '@/components/icons/Icon';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

interface Props {
    locale: Locale;
    /** Re-render the failed segment (error.tsx `reset`). Absent in global-error. */
    onRetry?: () => void;
}

// The 500 body (storefront spec §B8, content spec §C.8). Deliberately plain: no illustration, no
// gradient, the site chrome left out (it may be what failed). The phone number is the most
// useful sentence here — a customer mid-checkout can still order by phone — and it comes from
// lib/site-config.ts like everywhere else.
export function ServerErrorContent({ locale, onRetry }: Props) {
    const t = getMessages(locale);
    const [before, after] = t.serverError.body.split('{phone}');
    const reassurances = [t.serverError.reassureMoney, t.serverError.reassureCart, t.serverError.reassureOrders];

    return (
        <main id="main" className="mx-auto flex w-full max-w-160 grow flex-col justify-center gap-6 px-gutter-mobile py-section-mobile md:px-gutter-tablet">
            <a href={localizedPath(locale, '/')} className="t-h3 self-start rounded-xs text-fg-brand">
                {t.site.name}
            </a>
            <section className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-6 shadow-e1">
                <h1 className="t-h1 text-fg-primary">{t.serverError.title}</h1>
                <p className="t-body text-fg-secondary">
                    {before}
                    <ContactDetail value={contact.hotline} sampleLabel={t.footer.sample} />
                    {after}
                </p>
                <ul aria-label={t.serverError.reassureLabel} className="t-body-sm flex flex-col gap-2 rounded-md bg-success-subtle p-4 text-fg-primary">
                    {reassurances.map((line) => (
                        <li key={line} className="flex items-center gap-2">
                            <Icon name="checkCircle" className="size-icon-md text-feedback-success" />
                            {line}
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap gap-3">
                    {onRetry ? (
                        <button
                            type="button"
                            onClick={onRetry}
                            className="t-button inline-flex min-h-touch-min items-center gap-2 rounded-full bg-brand px-6 text-fg-on-brand shadow-e1"
                        >
                            <Icon name="arrowClockwise" className="size-icon-md" />
                            {t.serverError.retry}
                        </button>
                    ) : null}
                    {contact.hotline.placeholder ? null : (
                        <a
                            href={contact.hotline.href}
                            className="t-button inline-flex min-h-touch-min items-center gap-2 rounded-full border border-line-strong bg-surface px-6 text-fg-primary"
                        >
                            <Icon name="phone" className="size-icon-md" />
                            {format(t.serverError.call, { phone: contact.hotline.display })}
                        </a>
                    )}
                    <a
                        href={localizedPath(locale, '/categories')}
                        className="t-button inline-flex min-h-touch-min items-center rounded-full border border-line-strong bg-surface px-6 text-fg-primary"
                    >
                        {t.notFound.browse}
                    </a>
                </div>
            </section>
        </main>
    );
}
