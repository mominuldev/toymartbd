import { randomUUID } from 'node:crypto';

import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound, redirect } from 'next/navigation';

import { CartSummary } from '@/components/cart/CartSummary';
import { Field, inputClass, SelectBox, textareaClass } from '@/components/cart/fields';
import { PlainTrust } from '@/components/cart/PlainTrust';
import { CheckoutFooter, CheckoutHeader, CheckoutStepper, DeliveryBox, RadioCard, type StepState } from '@/components/checkout/CheckoutParts';
import { CharCount } from '@/components/checkout/CharCount';
import { CHECKOUT_FORM_ID, CheckoutForm } from '@/components/checkout/CheckoutForm';
import { InfoStrip } from '@/components/content/InfoStrip';
import { getBanners } from '@/lib/content/api';
import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { CHECKOUT_DRAFT_COOKIE, CHECKOUT_FLASH_COOKIE } from '@/lib/cart/cookies';
import { isZero, itemsLabel, lineName, lineProblem, minusPrice, noticeText, variantName } from '@/lib/cart/format';
import { loadCartPage } from '@/lib/cart/page';
import { decodeDraft, decodeFlash, draftId, FIELD_MAX, type CheckoutFlash, type DraftField } from '@/lib/checkout/form';
import { getUpazilas } from '@/lib/checkout/geo';
import { getDeliveryReference } from '@/lib/delivery/fetch';
import { placeName } from '@/lib/delivery/format';
import { format, getMessages, isLocale, localizedPath, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { getPaymentMethods } from '@/lib/payments/methods';
import { contact } from '@/lib/site-config';
import { privateMetadata } from '@/lib/seo/metadata';
import { TrackEvent } from '@/components/tracking/TrackEvent';
import { cartItems, TRACKING_CURRENCY } from '@/lib/tracking/items';

// /checkout — P4-STO-09, Figma Checkout 131:2601 (390) / 137:3670 (1440), storefront spec §B4.
// One page, three sections (contact & address · delivery · payment & review), the API's totals in
// the rail and on the Place order button. Guest checkout: no account needed. The form POSTs to
// /api/checkout and works without JavaScript; CheckoutForm enhances it in place.
//
// Idempotency (rule 17): a fresh Idempotency-Key is minted here, server-side, every time the page
// renders, and travels in the form. A double tap or a retried POST of the same render sends the same
// key, and the API answers with the same order — never two.
//
// Rule 25b: NOTHING is recommended on this page. No upsell, no cross-sell, no "also bought".

// Per visitor (cookies, a fresh Idempotency-Key per render): never prerendered or cached.
export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, '/checkout', getMessages(locale).checkout.title);
}

function generalNotice(flash: CheckoutFlash | null, t: Messages): string | null {
    if (!flash?.notice) return null;
    const c = t.checkout;
    const phone = contact.hotline.display;
    switch (flash.notice) {
        case 'summary':
            return c.errors.summary;
        case 'busy':
            return c.busy;
        case 'tooMany':
            return format(c.tooMany, { phone });
        case 'unavailable':
            return format(c.unavailable, { phone });
        case 'failed':
            return format(c.failed, { phone });
        default:
            return flash.notice; // the API's own localised sentence
    }
}

export default async function CheckoutPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const c = t.checkout;
    const bn = locale === 'bn';
    const jar = await cookies();
    const [{ cart, dest, notice, failed }, reference, methods] = await Promise.all([loadCartPage(locale), getDeliveryReference(locale), getPaymentMethods(locale)]);
    const online = methods.filter((m) => m.online);
    if (!failed && (!cart || cart.items.length === 0)) redirect(localizedPath(locale, '/cart'));

    const draft = decodeDraft(jar.get(CHECKOUT_DRAFT_COOKIE)?.value);
    const flash = decodeFlash(jar.get(CHECKOUT_FLASH_COOKIE)?.value);
    const errors = flash?.errors ?? {};
    const general = generalNotice(flash, t) ?? (notice ? noticeText(notice, t.cart.notices) : null);

    const districtId = draftId(draft.district_id) ?? dest?.districtId ?? null;
    const upazilaId = draftId(draft.upazila_id) ?? (dest && dest.districtId === districtId ? dest.upazilaId : null);
    const district = districtId !== null ? (reference.districts.find((d) => d.id === districtId) ?? null) : null;
    const upazilas = district ? (reference.upazilas[district.id] ?? (await getUpazilas(district.id, locale))) : [];

    if (!cart) {
        return (
            <>
                <CheckoutHeader locale={locale} t={t} />
                <main id="main" className="mx-auto w-full max-w-content grow px-gutter-mobile py-8">
                    <p role="alert" className="t-body rounded-sm bg-surface p-6 text-fg-primary">
                        {format(c.failed, { phone: contact.hotline.display })}
                    </p>
                </main>
            </>
        );
    }

    const blocked = cart.items.some((line) => lineProblem(line, t.cart) !== null);
    const total = formatPrice(cart.totals.grand_total);
    const contactDone = Boolean(draft.name && draft.phone);
    const deliveryDone = Boolean(district && draft.address_line && cart.delivery?.serviceable);
    const step = (done: boolean, isCurrent: boolean): StepState => (done ? 'complete' : isCurrent ? 'current' : 'upcoming');
    const steps = [
        { label: c.steps.contact, state: step(contactDone, true) },
        { label: c.steps.delivery, state: step(deliveryDone, contactDone) },
        { label: c.steps.payment, state: step(false, contactDone && deliveryDone) },
    ];

    const err = (key: DraftField | 'payment_method') => errors[key] ?? null;
    const described = (key: DraftField, has: boolean) => (has ? { 'aria-describedby': `${key}-help` } : {});
    const invalid = (key: DraftField) => (errors[key] ? { 'aria-invalid': true as const } : {});
    const errorList = Object.entries(errors) as Array<[DraftField, string]>;

    const placeButton = (extra: string) => (
        <button type="submit" form={CHECKOUT_FORM_ID} name="intent" value="place" className={`group/place ${buttonClass('primary')} w-full disabled:opacity-60 ${extra}`}>
            <span className="group-aria-busy/place:hidden">{format(c.placeOrder, { total })}</span>
            <span className="hidden group-aria-busy/place:inline">{c.placing}</span>
        </button>
    );

    const coupon = (
        <details open={Boolean(cart.coupon && !cart.coupon.applied)} className="group flex flex-col">
            <summary className="t-body-sm-strong flex h-11 w-fit cursor-pointer list-none items-center gap-2 text-fg-brand [&::-webkit-details-marker]:hidden">
                <Icon name="ticket" className="size-icon-md" />
                {cart.coupon?.applied ? format(t.cart.couponApplied, { code: cart.coupon.code }) : t.cart.couponToggle}
                <Icon name="caretDown" className="size-icon-sm transition-transform group-open:rotate-180" />
            </summary>
            {cart.coupon?.applied ? (
                <div className="flex h-12 items-center gap-2 rounded-xs bg-success-subtle ps-3 pe-1">
                    <Icon name="checkCircle" className="size-4.5 text-status-in-stock" />
                    <p className="t-body-sm-strong grow text-fg-primary">{format(t.cart.couponApplied, { code: cart.coupon.code })}</p>
                    {!isZero(cart.totals.coupon_discount) ? <p className="t-caption-strong tabular-nums text-fg-discount">{minusPrice(cart.totals.coupon_discount)}</p> : null}
                    <button type="submit" form={CHECKOUT_FORM_ID} formNoValidate name="intent" value="coupon_remove" aria-label={t.cart.couponRemove} className="flex size-9 items-center justify-center rounded-full text-fg-primary hover:bg-fg-primary/8">
                        <Icon name="x" className="size-icon-sm" />
                    </button>
                </div>
            ) : (
                <div className="flex flex-col gap-2 pt-1">
                    <div className="flex items-center gap-2">
                        <label className="min-w-0 grow">
                            <span className="sr-only">{t.cart.couponLabel}</span>
                            <input
                                form={CHECKOUT_FORM_ID}
                                name="coupon_code"
                                type="text"
                                autoComplete="off"
                                spellCheck={false}
                                maxLength={64}
                                defaultValue={cart.coupon?.code ?? ''}
                                placeholder={t.cart.couponPlaceholder}
                                aria-invalid={cart.coupon && !cart.coupon.applied ? true : undefined}
                                className={`${inputClass} uppercase placeholder:normal-case`}
                            />
                        </label>
                        <button type="submit" form={CHECKOUT_FORM_ID} formNoValidate name="intent" value="coupon" className={`${buttonClass('secondary', 'md')} min-w-0`}>
                            {t.cart.couponApply}
                        </button>
                    </div>
                    {cart.coupon && !cart.coupon.applied ? (
                        <p className="t-caption text-feedback-danger">
                            {format(t.cart.couponNotApplied, { code: cart.coupon.code })}
                            {cart.coupon.message ? ` — ${cart.coupon.message}` : ''}
                        </p>
                    ) : null}
                </div>
            )}
        </details>
    );

    const agree = (
        <p className="t-caption text-fg-secondary">
            {c.agree.split(/(\{terms\}|\{returns\})/).map((part, i) =>
                part === '{terms}' ? (
                    <a key={i} href={localizedPath(locale, '/pages/terms')} className="text-fg-link underline">
                        {c.terms}
                    </a>
                ) : part === '{returns}' ? (
                    <a key={i} href={localizedPath(locale, '/pages/returns')} className="text-fg-link underline">
                        {c.returns}
                    </a>
                ) : (
                    part
                ),
            )}
        </p>
    );

    return (
        <>
            <CheckoutHeader locale={locale} t={t} />
            {/* K-01 begin_checkout, once per visit to this page (the in-place re-renders keep it mounted); value = the API's subtotal. */}
            <TrackEvent name="begin_checkout" dedupeKey="checkout" data={{ value: cart.totals.subtotal, currency: TRACKING_CURRENCY, items: cartItems(cart) }} />

            {/* Mobile summary bar: "3 items · ৳6,825  Details" opens the lines (native <details>). */}
            <details className="group border-b border-line-default bg-subtle lg:hidden">
                <summary className="t-body-sm-strong flex h-11 cursor-pointer list-none items-center gap-2 px-gutter-mobile text-fg-primary [&::-webkit-details-marker]:hidden">
                    <Icon name="shoppingBagOpenFill" className="size-icon-sm text-fg-brand" />
                    <span className="grow tabular-nums">{format(c.summaryBar, { items: itemsLabel(cart.item_count, t.cart), total })}</span>
                    <span className="text-fg-brand">{c.details}</span>
                    <Icon name="caretDown" className="size-icon-sm text-fg-brand transition-transform group-open:rotate-180" />
                </summary>
                <ul className="flex flex-col gap-3 px-gutter-mobile pb-4">
                    {cart.items.map((line) => {
                        const name = lineName(line, locale);
                        const variant = variantName(line, locale);
                        return (
                            <li key={line.id} className="t-body-sm flex items-start justify-between gap-3">
                                <span className="min-w-0">
                                    <span lang={name.lang} className="text-fg-primary">
                                        {name.text}
                                    </span>
                                    <span className="t-caption block text-fg-tertiary">
                                        {variant ? `${variant} · ` : ''}
                                        {format(c.qty, { count: String(line.quantity) })}
                                    </span>
                                </span>
                                <span className="tabular-nums text-fg-primary">{formatPrice(line.total)}</span>
                            </li>
                        );
                    })}
                    <li>
                        <a href={localizedPath(locale, '/cart')} className="t-body-sm-strong text-fg-brand">
                            {c.editCart}
                        </a>
                    </li>
                </ul>
            </details>

            <main id="main" tabIndex={-1} className="mx-auto flex w-full max-w-content grow flex-col gap-6 px-gutter-mobile pt-4 pb-28 focus:outline-none md:px-gutter-tablet lg:px-gutter-desktop lg:pt-8 lg:pb-20">
                <h1 className="sr-only">{c.title}</h1>
                <CheckoutStepper steps={steps} t={c} />
                <InfoStrip locale={locale} banners={await getBanners('info_strip', 'checkout')} context="checkout" />

                <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:gap-8">
                    <div className="flex min-w-0 flex-col gap-5 lg:grow">
                        {general || errorList.length > 0 || blocked ? (
                            <div role="alert" tabIndex={-1} id="checkout-errors" className="t-body-sm flex flex-col gap-1 rounded-sm border border-line-danger bg-danger-subtle p-4 text-fg-primary">
                                {general ? <p className="t-body-sm-strong">{general}</p> : null}
                                {blocked ? (
                                    <p>
                                        {c.notReady}{' '}
                                        <a href={localizedPath(locale, '/cart')} className="font-semibold text-fg-link underline">
                                            {c.fixCart}
                                        </a>
                                    </p>
                                ) : null}
                                {errorList.length > 0 ? (
                                    <ul className="list-disc ps-5">
                                        {errorList.map(([key, message]) => (
                                            <li key={key}>
                                                <a href={`#${key}`} className="underline">
                                                    {message}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        ) : null}

                        <CheckoutForm>
                            <input type="hidden" name="locale" value={locale} />
                            <input type="hidden" name="idempotency_key" value={randomUUID()} />
                            <input type="hidden" name="expected_total" value={cart.totals.grand_total} />

                            <section aria-labelledby="sec-contact" className="flex flex-col gap-3.5 rounded-sm border border-line-default bg-surface p-4">
                                <h2 id="sec-contact" className="t-h3 text-fg-primary">
                                    {c.sectionContact}
                                </h2>
                                <Field id="name" label={c.name} required requiredLabel={c.requiredMark} error={err('name')}>
                                    <input id="name" name="name" type="text" autoComplete="name" required minLength={2} maxLength={FIELD_MAX.name} defaultValue={draft.name} placeholder={c.namePlaceholder} className={inputClass} {...invalid('name')} {...described('name', Boolean(err('name')))} />
                                </Field>
                                <Field id="phone" label={c.phone} required requiredLabel={c.requiredMark} help={c.phoneHelp} error={err('phone')}>
                                    <input id="phone" name="phone" type="tel" inputMode="tel" autoComplete="tel-national" required maxLength={FIELD_MAX.phone} defaultValue={draft.phone} placeholder={c.phonePlaceholder} className={`${inputClass} tabular-nums`} {...invalid('phone')} {...described('phone', true)} />
                                </Field>
                                <Field id="email" label={c.email} help={c.emailHelp} error={err('email')}>
                                    <input id="email" name="email" type="email" autoComplete="email" maxLength={FIELD_MAX.email} defaultValue={draft.email} placeholder={c.emailPlaceholder} className={inputClass} {...invalid('email')} {...described('email', true)} />
                                </Field>
                                <Field id="district_id" label={c.district} required requiredLabel={c.requiredMark} help={c.districtHelp} error={err('district_id')}>
                                    <SelectBox id="district_id" name="district_id" required autoComplete="address-level1" defaultValue={district ? String(district.id) : ''} {...invalid('district_id')} {...described('district_id', true)}>
                                        <option value="">{c.districtPlaceholder}</option>
                                        {reference.districts.map((d) => (
                                            <option key={d.id} value={d.id}>
                                                {placeName(d, bn)}
                                            </option>
                                        ))}
                                    </SelectBox>
                                </Field>
                                <Field id="upazila_id" label={c.upazila} required={!district?.city_option} requiredLabel={c.requiredMark} help={district ? c.districtHelp : c.upazilaFirst} error={err('upazila_id')}>
                                    <SelectBox
                                        id="upazila_id"
                                        name="upazila_id"
                                        required={Boolean(district) && !district?.city_option}
                                        disabled={!district}
                                        autoComplete="address-level2"
                                        defaultValue={upazilaId !== null ? String(upazilaId) : ''}
                                        key={district?.id ?? 0}
                                        {...invalid('upazila_id')}
                                        {...described('upazila_id', true)}
                                    >
                                        <option value="">{district?.city_option ? format(c.cityOption, { district: placeName(district, bn) }) : district ? c.upazilaPlaceholder : c.upazilaFirst}</option>
                                        {upazilas.map((u) => (
                                            <option key={u.id} value={u.id} disabled={!u.serviceable}>
                                                {placeName(u, bn)}
                                            </option>
                                        ))}
                                    </SelectBox>
                                </Field>
                                {/* Without JS: re-render with this district's upazilas and delivery charge. With JS this happens on change. */}
                                <button type="submit" name="intent" value="refresh" formNoValidate className={`${buttonClass('secondary', 'md')} w-full group-data-[js]/checkout:hidden`}>
                                    {district ? t.cart.destinationSave : c.showUpazilas}
                                </button>
                                <Field id="area" label={c.area} required requiredLabel={c.requiredMark} error={err('area')}>
                                    <input id="area" name="area" type="text" autoComplete="address-level3" required minLength={2} maxLength={FIELD_MAX.area} defaultValue={draft.area} placeholder={c.areaPlaceholder} className={inputClass} {...invalid('area')} {...described('area', Boolean(err('area')))} />
                                </Field>
                                <Field id="address_line" label={c.address} required requiredLabel={c.requiredMark} error={err('address_line')} aside={<CharCount target="address_line" initial={draft.address_line?.length ?? 0} max={FIELD_MAX.address_line} template={c.charCount} />}>
                                    <textarea id="address_line" name="address_line" autoComplete="street-address" required minLength={5} maxLength={FIELD_MAX.address_line} rows={3} defaultValue={draft.address_line} placeholder={c.addressPlaceholder} className={textareaClass} {...invalid('address_line')} {...described('address_line', true)} />
                                </Field>
                                <Field id="landmark" label={c.landmark} help={c.landmarkHelp} error={err('landmark')}>
                                    <input id="landmark" name="landmark" type="text" maxLength={FIELD_MAX.landmark} defaultValue={draft.landmark} placeholder={c.landmarkPlaceholder} className={inputClass} {...invalid('landmark')} {...described('landmark', true)} />
                                </Field>
                            </section>

                            <section id="delivery" aria-labelledby="sec-delivery" className="flex flex-col gap-3.5 rounded-sm border border-line-default bg-surface p-4">
                                <h2 id="sec-delivery" className="t-h3 text-fg-primary">
                                    {c.sectionDelivery}
                                </h2>
                                <DeliveryBox cart={cart} locale={locale} t={t} changeHref="#district_id" />
                                {cart.delivery?.serviceable && cart.delivery.charge !== null ? (
                                    <RadioCard
                                        name="delivery_method"
                                        value="standard"
                                        checked
                                        label={
                                            cart.delivery.eta
                                                ? format(c.standard, { charge: isZero(cart.delivery.charge) ? t.cart.free : formatPrice(cart.delivery.charge), min: String(cart.delivery.eta.min_days), max: String(cart.delivery.eta.max_days) })
                                                : format(c.standardNoEta, { charge: isZero(cart.delivery.charge) ? t.cart.free : formatPrice(cart.delivery.charge) })
                                        }
                                    />
                                ) : null}
                            </section>

                            <section aria-labelledby="sec-payment" className="flex flex-col gap-3.5 rounded-sm border border-line-default bg-surface p-4">
                                <h2 id="sec-payment" className="t-h3 text-fg-primary">
                                    {c.sectionPayment}
                                </h2>
                                <RadioCard name="payment_method" value="cod" checked label={c.cod} />
                                <p className="t-caption text-fg-secondary">{cart.delivery?.cod_advance ? format(c.codAdvance, { amount: formatPrice(cart.delivery.cod_advance) }) : c.codNote}</p>
                                {/* Online methods only while the API reports the gateway enabled (P4-PAY-02); COD stays the default (rule 33). */}
                                {online.map((m) => (
                                    <div key={m.method} className="flex flex-col gap-1.5">
                                        <RadioCard name="payment_method" value={m.method} checked={false} label={t.payment.online} />
                                        <p className="t-caption text-fg-secondary">{t.payment.onlineNote}</p>
                                        {m.sandbox ? <p className="t-caption text-feedback-warning">{t.payment.sandbox}</p> : null}
                                    </div>
                                ))}
                                {err('payment_method') ? <p className="t-caption text-feedback-danger">{err('payment_method')}</p> : null}
                            </section>
                        </CheckoutForm>
                    </div>

                    <aside className="flex flex-col gap-4 lg:sticky lg:top-24 lg:w-110 lg:shrink-0">
                        {coupon}
                        <CartSummary cart={cart} locale={locale} t={t} totalId="checkout-total" cta={placeButton('hidden lg:inline-flex')} className="max-lg:border-0 max-lg:bg-transparent max-lg:p-0" />
                        <PlainTrust t={t} />
                        {agree}
                    </aside>
                </div>
            </main>

            {/* Mobile: Place order pinned to the bottom (Figma 131:2601), above the safe area. */}
            <div className="fixed inset-x-0 bottom-0 z-20 border-t border-line-brand-subtle bg-surface px-4 pt-3 pb-[calc(--spacing(3)+env(safe-area-inset-bottom))] shadow-up-nav lg:hidden">{placeButton('')}</div>
            <CheckoutFooter t={t} />
        </>
    );
}
