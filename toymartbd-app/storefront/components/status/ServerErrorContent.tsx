import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { ContactDetail } from '@/components/layout/ContactDetail';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { contact } from '@/lib/site-config';

interface Props {
    locale: Locale;
    /** Re-render the failed segment (error.tsx `reset`). Absent in global-error. */
    onRetry?: () => void;
}

// The 500 body — Figma 169:10286 (1440) / 173:19350 (390), storefront spec §B8, content spec
// §C.8. Deliberately plain: no illustration, no gradient — a surface card, the success-subtle
// reassurance list, `Try again` + `Call {hotline}`. The phone number is the most useful sentence
// here (a customer mid-checkout can still order by phone) and comes from lib/site-config.ts.
// While the hotline is a placeholder the Call control is shown as in the design but is not a
// tel: link (data-placeholder), so nobody dials a made-up number.
export function ServerErrorContent({ locale, onRetry }: Props) {
    const t = getMessages(locale);
    const [before, after] = t.serverError.body.split('{phone}');
    const reassurances = [t.serverError.reassureMoney, t.serverError.reassureCart, t.serverError.reassureOrders];
    const callLabel = format(t.serverError.call, { phone: contact.hotline.display });
    const action = 'w-full lg:w-auto';

    return (
        <div className="flex justify-center pt-2 lg:pt-16 lg:pb-10">
            <section className="flex w-full max-w-160 flex-col gap-3 rounded-lg border border-line-default bg-surface p-5 lg:gap-4.5 lg:p-10">
                <p className="t-overline text-fg-secondary">{t.serverError.eyebrow}</p>
                <h1 className="t-display-2 text-fg-primary">{t.serverError.title}</h1>
                <p className="t-body-lg text-fg-secondary">
                    {before}
                    <ContactDetail value={contact.hotline} />
                    {after}
                </p>
                <ul aria-label={t.serverError.reassureLabel} className="flex flex-col gap-2.5 rounded-md bg-success-subtle px-4 py-3.5 lg:px-5 lg:py-4">
                    {reassurances.map((line) => (
                        <li key={line} className="t-body flex items-center gap-2.5 font-semibold text-fg-primary">
                            <Icon name="checkCircle" className="size-icon-md text-feedback-success" />
                            {line}
                        </li>
                    ))}
                </ul>
                <div className="flex flex-col gap-2.5 pt-2 lg:flex-row lg:gap-3">
                    {onRetry ? (
                        <button type="button" onClick={onRetry} className={`${buttonClass('primary')} ${action}`}>
                            {t.serverError.retry}
                        </button>
                    ) : (
                        <a href={localizedPath(locale, '/')} className={`${buttonClass('primary')} ${action}`}>
                            {t.serverError.retry}
                        </a>
                    )}
                    {contact.hotline.placeholder ? (
                        <span data-placeholder="owner-to-confirm" className={`${buttonClass('secondary')} ${action}`}>
                            <Icon name="phoneFill" className={buttonIconClass.lg} />
                            {callLabel}
                        </span>
                    ) : (
                        <a href={contact.hotline.href} className={`${buttonClass('secondary')} ${action}`}>
                            <Icon name="phoneFill" className={buttonIconClass.lg} />
                            {callLabel}
                        </a>
                    )}
                </div>
            </section>
        </div>
    );
}
