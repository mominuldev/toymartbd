import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { Wordmark } from '@/components/layout/Wordmark';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';

interface Props {
    locale: Locale;
    /** Re-render the failed segment (error.tsx `reset`). Absent in global-error: a link home instead. */
    onRetry?: () => void;
}

// The maintenance page as an error boundary renders it (Gate 5): the API answered a page's render
// with its `maintenance` 503 before proxy.ts knew the API was down, so this one request shows the
// maintenance look instead of the 500 page; from the next request on, the proxy answers the full
// page (./page.tsx) as a 503. Same layout as the page (Figma 169:10575), but a client boundary
// has no API credential (rule 28) and so no real hotline: it says what is safe, not whom to call,
// rather than show a sample number to a customer.
export function MaintenanceNotice({ locale, onRetry }: Props) {
    const t = getMessages(locale);
    const reassurances = [t.serverError.reassureOrders, t.serverError.reassureCart];
    const action = `${buttonClass('primary')} w-full lg:w-auto`;
    return (
        <main id="main" data-maintenance="" className="flex w-full grow flex-col items-center justify-center gap-10 px-gutter-mobile py-section-mobile md:px-gutter-tablet">
            <a href={localizedPath(locale, '/')} aria-label={t.nav.logoAria} className="inline-flex rounded-xs">
                <Wordmark locale={locale} size="sm" />
            </a>
            <section className="flex w-full max-w-150 flex-col items-center gap-4.5 rounded-lg border border-line-default bg-surface p-5 text-center lg:p-10">
                <span aria-hidden="true" className="inline-flex size-16 items-center justify-center rounded-full bg-info-subtle text-feedback-info">
                    <Icon name="clock" className="size-8" />
                </span>
                <h1 className="t-display-2 text-fg-primary">{t.maintenance.title}</h1>
                <ul aria-label={t.serverError.reassureLabel} className="flex flex-col gap-2.5 rounded-md bg-success-subtle px-4 py-3.5 text-left lg:px-5 lg:py-4">
                    {reassurances.map((line) => (
                        <li key={line} className="t-body flex items-center gap-2.5 font-semibold text-fg-primary">
                            <Icon name="checkCircle" className="size-icon-md text-feedback-success" />
                            {line}
                        </li>
                    ))}
                </ul>
                <div className="flex w-full justify-center pt-2">
                    {onRetry ? (
                        <button type="button" onClick={onRetry} className={action}>
                            {t.serverError.retry}
                        </button>
                    ) : (
                        <a href={localizedPath(locale, '/')} className={action}>
                            {t.serverError.retry}
                        </a>
                    )}
                </div>
            </section>
        </main>
    );
}
