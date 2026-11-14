import { useEffect, useId, useMemo, useRef, useState, type Dispatch, type SetStateAction } from 'react';
import { CheckIcon, CreditCardIcon, MinusIcon, MoneyIcon, PhoneIcon, PlusIcon, PrinterIcon, QrCodeIcon, TrashIcon, WalletIcon, WhatsappLogoIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useCharge, useCustomer, useQuote, type PosContext, type PosSale, type PosSession, type TenderMethod } from './api';
import { cleanPhone, emptyCart, quoteBody, removeLine, addLine, type CartState } from './cart';
import { isZeroAmount, phoneDisplay, TAKA, takaText } from './format';
import { Receipt } from './Receipt';

const tenderIcons: Record<TenderMethod, typeof MoneyIcon> = { cash: MoneyIcon, bkash: QrCodeIcon, nagad: WalletIcon, card: CreditCardIcon };

type CartPanelProps = {
    context: PosContext;
    session: PosSession;
    cart: CartState;
    setCart: Dispatch<SetStateAction<CartState>>;
    names: Record<number, string>;
};

/**
 * The right-hand panel (Figma B · 33): customer, lines, totals, payment (split allowed),
 * Charge, then Print / WhatsApp receipt. Every amount on it is the server's quote or sale.
 */
export function CartPanel({ context, session, cart, setCart, names }: CartPanelProps) {
    const { t, locale } = useI18n();
    const headingId = useId();
    const body = useMemo(() => quoteBody(cart), [cart]);
    const quote = useQuote(session.id, body, cart.lines.length > 0);
    const charge = useCharge(session.id);
    const phone = cleanPhone(cart.phone);
    const customer = useCustomer(phone);
    const [lastSale, setLastSale] = useState<PosSale | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [showCoupon, setShowCoupon] = useState(false);
    const [printing, setPrinting] = useState(false);
    // One Idempotency-Key per cart content: a double tap or a retry of the same cart reuses it.
    const keyRef = useRef<{ body: string; key: string } | null>(null);

    const q = cart.lines.length > 0 ? quote.data : undefined;
    const current = !quote.isPlaceholderData && !quote.isFetching;
    const fieldError = (field: string) => q?.blockers.find((b) => b.field === field)?.message ?? q?.tenders?.errors.find((e) => e.field === field)?.message;

    useEffect(() => setError(null), [cart]);

    const setTender = (index: number, patch: Partial<CartState['tenders'][number]>) =>
        setCart((c) => ({ ...c, tenders: c.tenders.map((d, i) => (i === index ? { ...d, ...patch } : d)) }));

    const toggleTender = (method: TenderMethod) =>
        setCart((c) =>
            c.tenders.some((d) => d.method === method)
                ? { ...c, tenders: c.tenders.filter((d) => d.method !== method) }
                : // A new tender starts at what the server says is still due (never a client sum).
                  { ...c, tenders: [...c.tenders, { method, amount: method === 'cash' ? '' : q?.tenders && !isZeroAmount(q.tenders.remaining) ? q.tenders.remaining : '', reference: '' }] },
        );

    const onCharge = () => {
        if (!q) return;
        const input = { ...body, tenders: body.tenders ?? [], expected_total: q.grand_total, customer_name: cart.name.trim() || null };
        const serial = JSON.stringify(input);
        if (keyRef.current?.body !== serial) keyRef.current = { body: serial, key: crypto.randomUUID() };
        charge.mutate(
            { input, key: keyRef.current.key },
            {
                onSuccess: (sale) => {
                    setLastSale(sale);
                    setCart(emptyCart());
                    keyRef.current = null;
                },
                onError: (e) => {
                    if (e instanceof ApiError) {
                        setError(e.is('price-changed') ? t('pos.error.priceChanged') : (e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title));
                        void quote.refetch();
                    } else setError(t('pos.error.generic'));
                },
            },
        );
    };

    const print = () => setPrinting(true);
    const total = q?.grand_total ?? '0.00';
    const canCharge = Boolean(q?.chargeable) && current && !charge.isPending;
    const selected = new Set(cart.tenders.map((d) => d.method));

    return (
        <aside aria-labelledby={headingId} className="flex w-96 shrink-0 flex-col gap-4 overflow-y-auto border-s border-line-default bg-surface p-5 print:w-full print:border-0 print:p-0">
            <h2 id={headingId} className="sr-only">
                {t('pos.cart.label')}
            </h2>

            <div className="flex flex-col gap-4 print:hidden">
                {/* Customer */}
                {phone && customer.data ? (
                    <div className="flex items-center gap-3 rounded-md bg-subtle px-3.5 py-3">
                        <PhoneIcon size={18} aria-hidden className="shrink-0 text-fg-secondary" />
                        <div className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate t-admin-body-strong text-fg-primary">
                                {customer.data.customer ? `${phoneDisplay(customer.data.phone)} · ${customer.data.customer.name}` : phoneDisplay(customer.data.phone)}
                            </span>
                            <span className="t-admin-caption text-fg-secondary">
                                {customer.data.customer ? t('pos.customer.known', { count: customer.data.customer.orders_count }) : t('pos.customer.new')}
                            </span>
                        </div>
                        <IconButton aria-label={t('pos.customer.clear')} size="sm" onClick={() => setCart((c) => ({ ...c, phone: '', name: '' }))}>
                            <XIcon size={16} aria-hidden />
                        </IconButton>
                    </div>
                ) : (
                    <label className="flex flex-col gap-1.5">
                        <span className="t-admin-caption-strong text-fg-secondary">{t('pos.customer.phone')}</span>
                        <Input
                            leading={<PhoneIcon size={16} />}
                            inputMode="tel"
                            value={cart.phone}
                            placeholder={t('pos.customer.placeholder')}
                            aria-invalid={cart.phone.trim() !== '' && !phone}
                            onChange={(e) => setCart((c) => ({ ...c, phone: e.target.value }))}
                        />
                        <span className="t-admin-caption text-fg-tertiary">{cart.phone.trim() !== '' && !phone ? t('pos.customer.invalid') : t('pos.customer.hint')}</span>
                    </label>
                )}
                {phone && customer.data && !customer.data.customer && (
                    <Input aria-label={t('pos.customer.name')} placeholder={t('pos.customer.name')} value={cart.name} maxLength={160} onChange={(e) => setCart((c) => ({ ...c, name: e.target.value }))} />
                )}
                {fieldError('customer_phone') && <p className="t-admin-caption text-feedback-danger">{fieldError('customer_phone')}</p>}

                {/* Lines */}
                {lastSale && cart.lines.length === 0 ? (
                    <div role="status" className="flex flex-col gap-2 rounded-md border border-line-default bg-subtle p-4">
                        <p className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                            <CheckIcon size={18} aria-hidden className="text-feedback-success" />
                            {t('pos.done.title', { number: lastSale.order_number })}
                        </p>
                        <p className="flex items-baseline justify-between t-admin-body text-fg-primary">
                            <span>{t('pos.pay.change')}</span>
                            <span className="t-admin-num-lg">{takaText(lastSale.change_due)}</span>
                        </p>
                    </div>
                ) : cart.lines.length === 0 ? (
                    <p className="rounded-md border border-dashed border-line-default p-4 text-center t-admin-body text-fg-secondary">{t('pos.cart.empty')}</p>
                ) : (
                    <ul aria-label={t('pos.cart.label')} className="flex flex-col">
                        {cart.lines.map((line) => {
                            const priced = q?.lines.find((l) => l.variant_id === line.variantId);
                            const name = priced ? (locale === 'bn' && priced.name_bn ? priced.name_bn : priced.name) + (priced.variant_name ? ` / ${priced.variant_name}` : '') : (names[line.variantId] ?? '');
                            const lineError = fieldError(`lines.${line.variantId}`);
                            return (
                                <li key={line.variantId} className="flex items-center gap-3 border-b border-line-default py-3 last:border-0">
                                    <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-sm bg-subtle">
                                        {priced?.image_url && <img src={priced.image_url} alt="" className="size-full object-cover" />}
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="truncate t-admin-body text-fg-primary">{name}</span>
                                        <span className="flex items-center gap-1">
                                            <IconButton size="sm" aria-label={t('pos.cart.decrease', { name })} onClick={() => setCart((c) => addLine(c, line.variantId, -1))}>
                                                <MinusIcon size={14} aria-hidden />
                                            </IconButton>
                                            <span className="min-w-8 text-center t-admin-caption text-fg-secondary">{t('pos.cart.qty', { count: line.quantity })}</span>
                                            <IconButton size="sm" aria-label={t('pos.cart.increase', { name })} onClick={() => setCart((c) => addLine(c, line.variantId, 1))}>
                                                <PlusIcon size={14} aria-hidden />
                                            </IconButton>
                                            <IconButton size="sm" aria-label={t('pos.cart.remove', { name })} onClick={() => setCart((c) => removeLine(c, line.variantId))}>
                                                <TrashIcon size={14} aria-hidden />
                                            </IconButton>
                                        </span>
                                        {lineError && <span className="t-admin-caption text-feedback-danger">{lineError}</span>}
                                    </div>
                                    <span className="t-admin-body-strong text-fg-primary">{priced ? takaText(priced.total) : ''}</span>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {/* Totals — the quote's itemised rows, which sum to the total on the server. */}
                {q && cart.lines.length > 0 && (
                    <dl className="flex flex-col gap-2.5 border-t border-line-default pt-3">
                        {q.rows.map((row, i) => (
                            <div key={`${row.type}-${i}`} className="flex items-baseline justify-between t-admin-body text-fg-secondary">
                                <dt>{row.type === 'subtotal' ? t('pos.totals.subtotal') : row.type === 'coupon' ? t('pos.totals.coupon', { code: row.label ?? '' }) : ((locale === 'bn' ? row.label_bn : null) ?? row.label ?? t('pos.totals.promotion'))}</dt>
                                <dd className="text-fg-primary">{takaText(row.amount)}</dd>
                            </div>
                        ))}
                        <div className="flex items-baseline justify-between border-t border-line-default pt-3">
                            <dt className="t-admin-body-strong text-fg-primary">{t('pos.totals.total')}</dt>
                            <dd className="t-admin-num-xl text-fg-primary" aria-live="polite">
                                {takaText(total)}
                            </dd>
                        </div>
                    </dl>
                )}
                {cart.lines.length > 0 &&
                    (showCoupon || cart.coupon !== '' ? (
                        <div className="flex flex-col gap-1">
                            <Input aria-label={t('pos.coupon.label')} placeholder={t('pos.coupon.label')} value={cart.coupon} onChange={(e) => setCart((c) => ({ ...c, coupon: e.target.value.toUpperCase() }))} />
                            {fieldError('coupon_code') && <span className="t-admin-caption text-feedback-danger">{fieldError('coupon_code')}</span>}
                        </div>
                    ) : (
                        <button type="button" className="self-start t-admin-caption-strong text-fg-link hover:underline" onClick={() => setShowCoupon(true)}>
                            {t('pos.coupon.add')}
                        </button>
                    ))}

                {/* Payment */}
                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-2 t-admin-overline text-fg-secondary">{t('pos.pay.title')}</legend>
                    <div className="grid grid-cols-2 gap-2.5">
                        {context.tenders.map((tender) => {
                            const Icon = tenderIcons[tender.method];
                            const on = selected.has(tender.method);
                            return (
                                <button
                                    key={tender.method}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => toggleTender(tender.method)}
                                    className={`flex h-16 flex-col items-center justify-center gap-1 rounded-md border t-admin-body transition-colors ${on ? 'border-2 border-line-focus bg-brand-subtle text-fg-primary' : 'border-line-default bg-surface text-fg-primary hover:bg-subtle'}`}
                                >
                                    <Icon size={18} aria-hidden className={on ? 'text-fg-brand' : 'text-fg-secondary'} />
                                    {t(`pos.tender.${tender.method}` as MessageKey)}
                                </button>
                            );
                        })}
                    </div>

                    {cart.tenders.map((draft, index) => {
                        const method = t(`pos.tender.${draft.method}` as MessageKey);
                        const needsRef = context.tenders.find((x) => x.method === draft.method)?.requires_reference ?? false;
                        return (
                            <div key={draft.method} className="flex flex-col gap-1.5">
                                <div className="flex items-center justify-between gap-3">
                                    <label htmlFor={`tender-${draft.method}`} className="t-admin-body text-fg-secondary">
                                        {draft.method === 'cash' ? t('pos.pay.cashReceived') : t('pos.pay.amount', { method })}
                                    </label>
                                    <span className="flex items-center gap-1.5">
                                        {/* A blank tender is not sent, so the server's `remaining` is exactly what this one should take. */}
                                        {q && draft.amount.trim() === '' && !isZeroAmount(q.tenders?.remaining ?? q.grand_total) && (
                                            <Button size="sm" variant="ghost" onClick={() => setTender(index, { amount: q.tenders?.remaining ?? q.grand_total })}>
                                                {t('pos.pay.exact')}
                                            </Button>
                                        )}
                                        <Input
                                            id={`tender-${draft.method}`}
                                            size="sm"
                                            prefix={TAKA}
                                            inputMode="decimal"
                                            className="w-32 text-end"
                                            value={draft.amount}
                                            aria-invalid={Boolean(fieldError(`tenders.${index}.amount`))}
                                            onChange={(e) => setTender(index, { amount: e.target.value })}
                                        />
                                    </span>
                                </div>
                                {needsRef && (
                                    <Input
                                        size="sm"
                                        aria-label={t('pos.pay.reference', { method })}
                                        placeholder={t('pos.pay.reference', { method })}
                                        value={draft.reference}
                                        maxLength={64}
                                        aria-invalid={Boolean(fieldError(`tenders.${index}.reference`))}
                                        onChange={(e) => setTender(index, { reference: e.target.value })}
                                    />
                                )}
                                {fieldError(`tenders.${index}.reference`) && <span className="t-admin-caption text-feedback-danger">{fieldError(`tenders.${index}.reference`)}</span>}
                            </div>
                        );
                    })}
                    {fieldError('tenders') && <p className="t-admin-caption text-feedback-danger">{fieldError('tenders')}</p>}

                    {q?.tenders && cart.lines.length > 0 && (
                        <dl className="flex flex-col gap-2">
                            {!isZeroAmount(q.tenders.remaining) && (
                                <div className="flex items-baseline justify-between">
                                    <dt className="t-admin-body text-fg-secondary">{t('pos.pay.remaining')}</dt>
                                    <dd className="t-admin-body-strong text-fg-primary">{takaText(q.tenders.remaining)}</dd>
                                </div>
                            )}
                            <div className="flex items-baseline justify-between">
                                <dt className="t-admin-body-strong text-fg-primary">{t('pos.pay.change')}</dt>
                                <dd className="t-admin-body-strong text-fg-primary" aria-live="polite">
                                    {takaText(q.tenders.change)}
                                </dd>
                            </div>
                        </dl>
                    )}
                </fieldset>

                {error && (
                    <p role="alert" className="rounded-sm bg-danger-subtle px-3 py-2 t-admin-body text-fg-primary">
                        {error}
                    </p>
                )}

                <Button size="lg" onClick={onCharge} disabled={!canCharge}>
                    <CheckIcon size={18} aria-hidden />
                    {charge.isPending ? t('pos.charging') : cart.lines.length === 0 ? t('pos.chargeIdle') : t('pos.charge', { amount: takaText(total) })}
                </Button>
                <div className="grid grid-cols-2 gap-2.5">
                    <Button variant="outline" onClick={print} disabled={!lastSale}>
                        <PrinterIcon size={16} aria-hidden />
                        {t('pos.receipt.print')}
                    </Button>
                    <Button variant="outline" disabled aria-describedby={`${headingId}-wa`}>
                        <WhatsappLogoIcon size={16} aria-hidden />
                        {t('pos.receipt.whatsapp')}
                    </Button>
                </div>
                {!context.whatsapp_receipt.enabled && (
                    <p id={`${headingId}-wa`} className="t-admin-caption text-fg-tertiary">
                        {t('pos.receipt.whatsappOff')}
                    </p>
                )}
                {lastSale && cart.lines.length === 0 && (
                    <Button variant="ghost" onClick={() => setLastSale(null)}>
                        {t('pos.done.next')}
                    </Button>
                )}
            </div>

            {lastSale && printing && <Receipt saleId={lastSale.id} onDone={() => setPrinting(false)} />}
        </aside>
    );
}

