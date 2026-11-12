import { Icon } from '@/components/icons/Icon';
import { Wordmark } from '@/components/layout/Wordmark';
import { isZero } from '@/lib/cart/format';
import type { Cart } from '@/lib/cart/types';
import { arrivalLine, quotePlace, workingDaysLine } from '@/lib/delivery/format';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { contact, legal } from '@/lib/site-config';

// Pieces of the checkout page (Figma Checkout 131:2601 / 137:3670). Server components.

/** Header (checkout): back to cart · centred wordmark · "Secure checkout". 56 / 72 tall, no menu or search. */
export function CheckoutHeader({ locale, t }: { locale: Locale; t: Messages }) {
    return (
        <header className="sticky top-0 z-30 border-b border-line-default bg-surface">
            <div className="mx-auto flex h-14 max-w-content items-center gap-3 px-gutter-mobile md:px-gutter-tablet lg:h-18 lg:px-gutter-desktop">
                <a href={localizedPath(locale, '/cart')} aria-label={t.checkout.backToCart} className="t-body-sm-strong flex min-h-11 min-w-11 items-center gap-1.5 text-fg-brand">
                    <Icon name="arrowLeft" className="size-icon-md lg:size-icon-sm" />
                    <span className="hidden lg:inline">{t.checkout.backToCart}</span>
                </a>
                <div className="flex grow justify-center">
                    <a href={localizedPath(locale, '/')} aria-label={t.nav.logoAria}>
                        <Wordmark locale={locale} />
                    </a>
                </div>
                <p className="t-body-sm-strong flex items-center gap-1.5 text-status-in-stock">
                    <Icon name="shieldCheck" className="size-icon-sm" />
                    <span className="hidden lg:inline">{t.checkout.secure}</span>
                    <span className="lg:hidden">{t.checkout.secureShort}</span>
                </p>
            </div>
        </header>
    );
}

export function CheckoutFooter({ t }: { t: Messages }) {
    const year = String(new Date().getUTCFullYear());
    return (
        <footer className="mt-auto hidden bg-surface lg:block">
            <p className="t-caption mx-auto max-w-content px-gutter-desktop py-6 text-fg-secondary">
                {format(t.footer.copyright, { year })} · {legal.tradeLicence && legal.bin ? format(t.footer.tradeLicence, { number: legal.tradeLicence, bin: legal.bin }) : t.footer.legalPending}
            </p>
        </footer>
    );
}

export type StepState = 'complete' | 'current' | 'upcoming';

/** Stepper (Figma Stepper/Step, Numbered, Horizontal): 28 indicators joined by 2px connectors. */
export function CheckoutStepper({ steps, t }: { steps: Array<{ label: string; state: StepState }>; t: Messages['checkout'] }) {
    const current = steps.findIndex((s) => s.state === 'current');
    return (
        <nav aria-label={t.progress} className="w-full lg:w-130">
            <ol className="flex items-start gap-2">
                {steps.map((step, i) => (
                    <li key={step.label} className={`flex items-start gap-2 ${i > 0 ? 'grow' : ''}`} aria-current={step.state === 'current' ? 'step' : undefined}>
                        {i > 0 ? (
                            <span aria-hidden="true" className="flex h-7 grow items-center">
                                <span className={`h-0.5 w-full rounded-full ${step.state === 'upcoming' ? 'bg-line-default' : 'bg-brand'}`} />
                            </span>
                        ) : null}
                        <span className="flex min-w-22 flex-col items-center gap-2">
                            <span
                                className={`flex size-7 items-center justify-center rounded-full border-(length:--tm-border-width-strong) ${
                                    step.state === 'complete'
                                        ? 'border-brand bg-brand text-fg-on-brand'
                                        : step.state === 'current'
                                          ? 'border-brand bg-surface text-fg-primary'
                                          : 'border-line-default bg-subtle text-fg-tertiary'
                                }`}
                            >
                                {step.state === 'complete' ? <Icon name="check" className="size-icon-sm" /> : <span className="t-stepper-number">{i + 1}</span>}
                            </span>
                            <span className={step.state === 'upcoming' ? 't-stepper-label text-fg-tertiary' : step.state === 'current' ? 't-stepper-label-current text-fg-primary' : 't-stepper-label text-fg-primary'}>
                                {step.label}
                                <span className="sr-only">
                                    {' '}
                                    {step.state === 'complete' ? `(${format(t.stepDone, { label: step.label })})` : i === current ? `(${format(t.stepOf, { n: String(i + 1), label: step.label })})` : ''}
                                </span>
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
        </nav>
    );
}

/** The DeliveryEstimator card in its Selected state, from the cart's quote (the API's charge and dates). */
export function DeliveryBox({ cart, locale, t, changeHref }: { cart: Cart; locale: Locale; t: Messages; changeHref: string }) {
    const quote = cart.delivery;
    const d = t.delivery;
    const bn = locale === 'bn';
    return (
        <section aria-labelledby="checkout-delivery-box" className="flex min-h-33 flex-col gap-3 rounded-sm border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface p-4">
            <div className="flex items-center gap-2">
                <span aria-hidden="true" className="flex size-7 shrink-0 items-center justify-center rounded-full bg-tile-mint text-status-in-stock">
                    <Icon name="truck" className="size-icon-sm" />
                </span>
                <h3 id="checkout-delivery-box" className="t-h4 grow text-fg-primary">
                    {t.checkout.deliveryTitle}
                </h3>
                {quote ? (
                    <a href={changeHref} className="t-body-sm-strong text-fg-brand">
                        {t.checkout.change}
                    </a>
                ) : null}
            </div>
            {!quote ? (
                <p className="t-body-sm text-fg-secondary">{t.checkout.pickDistrict}</p>
            ) : !quote.serviceable || quote.charge === null ? (
                <p className="t-body-sm text-feedback-danger">{format(t.checkout.notServiced, { place: quotePlace(quote, bn), phone: contact.hotline.display })}</p>
            ) : (
                <div className="flex flex-col gap-1 rounded-xs bg-subtle p-3">
                    <p className="t-body-sm flex flex-wrap items-baseline gap-x-2 text-fg-secondary">
                        <span className={`t-h4 ${isZero(quote.charge) ? 'text-status-in-stock' : 'text-fg-primary'}`}>{isZero(quote.charge) ? d.free : formatPrice(quote.charge)}</span>
                        {isZero(quote.charge) && quote.standard_charge && !isZero(quote.standard_charge) ? <s className="text-fg-tertiary">{formatPrice(quote.standard_charge)}</s> : null}
                        <span>{format(t.checkout.chargeFor, { place: quotePlace(quote, bn) })}</span>
                    </p>
                    {quote.eta ? (
                        <p className="t-body-sm-strong flex flex-wrap items-center gap-x-2 text-fg-primary">
                            <Icon name="calendarBlank" className="size-icon-sm text-fg-brand" />
                            {arrivalLine(quote.eta, d)}
                            <span className="t-caption font-normal text-fg-secondary">{workingDaysLine(quote.eta, d)}</span>
                        </p>
                    ) : null}
                    {quote.courier ? <p className="t-caption text-fg-secondary">{format(d.courier, { name: (bn ? quote.courier.name_bn : null) ?? quote.courier.name })}</p> : null}
                </div>
            )}
        </section>
    );
}

/** RadioCard (Figma 44:379): 64 tall, radius lg, 1.5px border; selected = brand border + filled dot. */
export function RadioCard({ name, value, checked, label, readOnly = false }: { name: string; value: string; checked: boolean; label: string; readOnly?: boolean }) {
    return (
        <label className="flex min-h-16 cursor-pointer items-start gap-3 rounded-lg border-(length:--tm-border-width-control) border-line-default bg-surface p-4 has-checked:border-brand">
            <input type="radio" name={name} value={value} defaultChecked={checked} readOnly={readOnly} className="mt-0.5 size-5 shrink-0 accent-(--tm-color-bg-brand)" />
            <span className="t-body text-fg-primary in-has-checked:font-semibold">{label}</span>
        </label>
    );
}
