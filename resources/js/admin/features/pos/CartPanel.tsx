import { useEffect, useId, useMemo, useState, type Dispatch, type MutableRefObject, type SetStateAction } from 'react';
import { CreditCardIcon, MinusIcon, PercentIcon, PhoneIcon, PlusIcon, StackIcon, TagIcon, TrashIcon, UserIcon, UserPlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { useCustomer, useQuote, type PosContext, type PosSale, type PosSession } from './api';
import { addLine, cleanPhone, discountReasonKey, emptyCart, isDiscountReason, quoteBody, removeLine, type CartState } from './cart';
import { DiscountDialog } from './DiscountDialog';
import { isZeroAmount, phoneDisplay, takaText } from './format';
import { PaymentDialog } from './PaymentDialog';
import { KEY } from './keys';

type CartPanelProps = {
    /** Below `lg` the register shows one column at a time. */
    visible: boolean;
    context: PosContext;
    session: PosSession;
    cart: CartState;
    setCart: Dispatch<SetStateAction<CartState>>;
    names: Record<number, string>;
    onOpenCustomer: () => void;
    onCollectDue: () => void;
    onHold: () => void;
    holdPending: boolean;
    paying: boolean;
    setPaying: (open: boolean) => void;
    onCharged: (sale: PosSale) => void;
    /** What F12 does: open Collect payment when the quote allows a charge, else say why. */
    chargeKey: MutableRefObject<() => void>;
};

/**
 * "Current sale" (Figma B · 33 POS v2 — Checkout, 344:63830): customer, lines with a stepper,
 * the quote's itemised totals and Total payable, Hold / Coupon / Clear, then one Charge button
 * that opens Collect payment. Every amount here is the server's quote (rule 1).
 */
export function CartPanel({ visible, context, session, cart, setCart, names, onOpenCustomer, onCollectDue, onHold, holdPending, paying, setPaying, onCharged, chargeKey }: CartPanelProps) {
    const { t, locale } = useI18n();
    const headingId = useId();
    const body = useMemo(() => quoteBody(cart), [cart]);
    const quote = useQuote(session.id, body, cart.lines.length > 0);
    const phone = cleanPhone(cart.phone);
    const customer = useCustomer(phone);
    const [showCoupon, setShowCoupon] = useState(false);
    const [showDiscount, setShowDiscount] = useState(false);

    const q = cart.lines.length > 0 ? quote.data : undefined;
    const fieldError = (field: string) => q?.blockers.find((b) => b.field === field)?.message ?? q?.tenders?.errors.find((e) => e.field === field)?.message;
    // A count of pieces, not money.
    const pieces = cart.lines.reduce((sum, l) => sum + l.quantity, 0);
    const total = q?.grand_total ?? '0.00';
    const known = customer.data?.customer ?? null;
    const customerName = known?.name ?? (cart.name.trim() || null);
    const { notify } = useToast();
    const blockersId = useId();
    const canCharge = q !== undefined && q.blockers.length === 0 && !(quote.isFetching && quote.isPlaceholderData);
    // F12 is the Charge button's key, so it obeys the same rule; a refusal is spoken, not silent.
    useEffect(() => {
        chargeKey.current = () => {
            if (canCharge) setPaying(true);
            else if (q?.blockers[0]) notify(q.blockers[0].message, 'info');
        };
    }, [chargeKey, canCharge, q, setPaying, notify]);

    return (
        <>
            {/* One polite status, mounted before the first line, speaks each new quote: the count
                and the server's total, after a scan, a tap or a stepper press. Outside the aside,
                so it still speaks on a tablet or phone while the Products view hides the cart. */}
            <p role="status" data-testid="pos-cart-status" className="sr-only">
                {q ? t('pos.cart.status', { count: q.item_count, amount: takaText(total) }) : ''}
            </p>
            <aside aria-labelledby={headingId} className={`${visible ? 'flex' : 'hidden'} min-h-0 w-full flex-1 flex-col bg-surface lg:flex lg:w-[27.5rem] lg:flex-none lg:shrink-0 lg:border-s lg:border-line-default print:hidden`}>
                <div className="flex flex-col gap-3 px-6 pt-5 pb-4">
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 id={headingId} className="t-admin-section text-fg-primary">
                            {t('pos.cart.title')}
                        </h2>
                        {cart.lines.length > 0 && <span className="t-admin-caption text-fg-secondary">{t('pos.cart.items', { count: pieces })}</span>}
                    </div>

                    {phone ? (
                        <div className="flex flex-col gap-2 rounded-md border border-line-default bg-subtle px-3 py-3">
                            <div className="flex items-center gap-3">
                                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-subtle text-fg-brand">
                                    <PhoneIcon size={18} />
                                </span>
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate t-admin-body-strong text-fg-primary">{customerName ?? t('pos.customer.new')}</span>
                                    <span className="truncate t-admin-caption text-fg-secondary">
                                        {phoneDisplay(phone.replace(/^\+?880/, '0'))}
                                        {known ? ` · ${t('pos.customer.known', { count: known.orders_count })}` : ` · ${t('pos.customer.new')}`}
                                    </span>
                                </div>
                                <Button variant="ghost" aria-keyshortcuts="F9" onClick={onOpenCustomer}>
                                    {t('pos.customer.change')}
                                </Button>
                            </div>
                            {known && (
                                <div className="flex items-center justify-between gap-2">
                                    <span className={`rounded-xs px-2 py-0.5 t-admin-caption-strong ${isZeroAmount(known.open_due) ? 'bg-surface text-fg-secondary' : 'bg-warning-subtle text-feedback-warning'}`}>
                                        {t('pos.customer.due', { amount: takaText(known.open_due), limit: takaText(known.due_limit) })}
                                    </span>
                                    {!isZeroAmount(known.open_due) && (
                                        <Button variant="secondary" onClick={onCollectDue}>
                                            {t('pos.customer.collect')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 rounded-md border border-dashed border-line-default px-3 py-3">
                            <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-full bg-subtle text-fg-secondary">
                                <UserIcon size={18} />
                            </span>
                            <div className="flex min-w-0 flex-1 flex-col">
                                <span className="t-admin-body-strong text-fg-primary">{t('pos.customer.walkIn')}</span>
                                <span className="t-admin-caption text-fg-secondary">{t('pos.customer.walkInHint')}</span>
                            </div>
                            <Button variant="secondary" aria-keyshortcuts="F9" onClick={onOpenCustomer}>
                                <UserPlusIcon size={16} aria-hidden />
                                {t('pos.customer.add')}
                            </Button>
                        </div>
                    )}
                    {fieldError('customer_phone') && <p className="t-admin-caption text-feedback-danger">{fieldError('customer_phone')}</p>}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto border-t border-line-default px-6">
                    {cart.lines.length === 0 ? (
                        <p className="my-4 rounded-md border border-dashed border-line-default p-4 text-center t-admin-body text-fg-secondary">{t('pos.cart.empty')}</p>
                    ) : (
                        <ul aria-label={t('pos.cart.label')} className="flex flex-col">
                            {cart.lines.map((line) => {
                                const priced = q?.lines.find((l) => l.variant_id === line.variantId);
                                const name = priced
                                    ? (locale === 'bn' && priced.name_bn ? priced.name_bn : priced.name) + (priced.variant_name ? ` / ${priced.variant_name}` : '')
                                    : names[line.variantId] ?? '';
                                const lineError = fieldError(`lines.${line.variantId}`);
                                return (
                                    <li key={line.variantId} className="flex items-center gap-3 border-b border-line-default py-3 last:border-0">
                                        <span className="grid size-10 shrink-0 place-items-center overflow-hidden rounded-xs bg-image">
                                            {priced?.image_url && <img src={priced.image_url} alt="" className="size-full object-cover" />}
                                        </span>
                                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className="truncate t-admin-body-strong text-fg-primary">{name}</span>
                                            <span className="t-admin-caption text-fg-secondary">
                                                {priced
                                                    ? `${takaText(priced.unit_price)} ${t('pos.cart.qty', {
                                                          count: line.quantity,
                                                      })}`
                                                    : t('pos.cart.qty', {
                                                          count: line.quantity,
                                                      })}
                                            </span>
                                            {lineError && <span className="t-admin-caption text-feedback-danger">{lineError}</span>}
                                        </div>
                                        <span className="flex shrink-0 items-center rounded-sm border border-line-default">
                                            <IconButton
                                                size="sm"
                                                aria-label={t('pos.cart.decrease', { name })}
                                                disabled={line.quantity <= 1}
                                                onClick={() => setCart((c) => addLine(c, line.variantId, -1))}
                                            >
                                                <MinusIcon size={14} aria-hidden />
                                            </IconButton>
                                            <span className="min-w-6 text-center t-admin-body-strong text-fg-primary">
                                                {line.quantity}
                                            </span>
                                            <IconButton size="sm" aria-label={t('pos.cart.increase', { name })} onClick={() => setCart((c) => addLine(c, line.variantId, 1))}>
                                                <PlusIcon size={14} aria-hidden />
                                            </IconButton>
                                        </span>
                                        <span className="w-18 shrink-0 text-end t-admin-num text-fg-primary">{priced ? takaText(priced.total) : ''}</span>
                                        <IconButton
                                            size="sm"
                                            aria-label={t('pos.cart.remove', { name })}
                                            className="text-feedback-danger! hover:bg-danger-subtle"
                                            onClick={() => setCart((c) => removeLine(c, line.variantId))}
                                        >
                                            <TrashIcon size={16} aria-hidden />
                                        </IconButton>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="flex flex-col gap-3 border-t border-line-default bg-subtle px-6 pt-4 pb-5">
                    {q && (
                        <dl className="flex flex-col gap-2.5">
                            {q.rows.map((row, i) => (
                                <div key={`${row.type}-${i}`} className="flex items-baseline justify-between t-admin-body text-fg-secondary">
                                    <dt>
                                        {row.type === 'subtotal'
                                            ? t('pos.totals.subtotalItems', {
                                                  count: q.item_count,
                                              })
                                            : row.type === 'coupon'
                                            ? t('pos.totals.coupon', {
                                                  code: row.label ?? '',
                                              })
                                            : row.type === 'manual'
                                            ? t('pos.totals.discount', { reason: isDiscountReason(row.label) ? t(discountReasonKey(row.label)) : '' })
                                            : (locale === 'bn' ? row.label_bn : null) ?? row.label ?? t('pos.totals.promotion')}
                                    </dt>
                                    <dd className={`t-admin-num ${row.amount.startsWith('-') ? 'text-feedback-success' : 'text-fg-primary'}`}>{takaText(row.amount)}</dd>
                                </div>
                            ))}
                            <div className="flex items-baseline justify-between border-t border-line-default pt-3">
                                <dt className="t-admin-body-strong text-fg-primary">{t('pos.totals.payable')}</dt>
                                <dd className="t-admin-num-xl text-fg-primary">
                                    {takaText(total)}
                                </dd>
                            </div>
                        </dl>
                    )}

                    {fieldError('discount') && (
                        <p className="flex items-center justify-between gap-2 t-admin-caption text-feedback-danger">
                            <span>{fieldError('discount')}</span>
                            <Button variant="ghost" size="sm" onClick={() => setShowDiscount(true)}>
                                {t('pos.discount.edit')}
                            </Button>
                        </p>
                    )}

                    {cart.lines.length > 0 && (showCoupon || cart.coupon !== '') && (
                        <div className="flex flex-col gap-1">
                            <Input
                                aria-label={t('pos.coupon.label')}
                                placeholder={t('pos.coupon.label')}
                                leading={<TagIcon size={16} />}
                                value={cart.coupon}
                                autoFocus={showCoupon && cart.coupon === ''}
                                onChange={(e) =>
                                    setCart((c) => ({
                                        ...c,
                                        coupon: e.target.value.toUpperCase(),
                                    }))
                                }
                            />
                            {fieldError('coupon_code') && <span className="t-admin-caption text-feedback-danger">{fieldError('coupon_code')}</span>}
                        </div>
                    )}

                    {cart.lines.length > 0 && !showCoupon && cart.coupon === '' && (
                        <Button variant="ghost" size="sm" className="self-start" onClick={() => setShowCoupon(true)}>
                            <TagIcon size={16} aria-hidden />
                            {t('pos.coupon.toggle')}
                        </Button>
                    )}

                    <div className="grid grid-cols-3 gap-2">
                        <Button variant="outline" onClick={onHold} disabled={cart.lines.length === 0 || holdPending}>
                            <StackIcon size={16} aria-hidden />
                            {t('pos.hold')}
                        </Button>
                        <Button variant="outline" aria-pressed={cart.discount !== null} onClick={() => setShowDiscount(true)} disabled={cart.lines.length === 0}>
                            <PercentIcon size={16} aria-hidden />
                            {t('pos.discount.toggle')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setCart(emptyCart());
                                setShowCoupon(false);
                            }}
                            disabled={cart.lines.length === 0 && cart.phone === ''}
                        >
                            <TrashIcon size={16} aria-hidden />
                            {t('pos.cart.clear')}
                        </Button>
                    </div>

                    <Button
                        size="lg"
                        className="h-13 justify-between"
                        aria-keyshortcuts="F12"
                        aria-describedby={q && q.blockers.length > 0 ? blockersId : undefined}
                        onClick={() => setPaying(true)}
                        disabled={!canCharge}
                    >
                        <span className="flex items-center gap-2">
                            <CreditCardIcon size={20} aria-hidden />
                            {t('pos.charge', { amount: takaText(total) })}
                        </span>
                        <kbd aria-hidden className="rounded-xs bg-brand-shelf px-1.5 t-admin-mono-sm text-fg-on-brand">
                            {KEY.f12}
                        </kbd>
                    </Button>
                    {/* Why Charge is disabled, as its description (the messages are also shown by their fields). */}
                    <span id={blockersId} hidden>
                        {q?.blockers.map((b) => b.message).join(' ')}
                    </span>
                    <p className="text-center t-admin-caption text-fg-tertiary">{t('pos.shortcuts')}</p>
                </div>
            </aside>

            {q && (
                <PaymentDialog
                    open={paying}
                    context={context}
                    session={session}
                    cart={cart}
                    setCart={setCart}
                    quote={q}
                    quoteCurrent={!quote.isPlaceholderData && !quote.isFetching}
                    refetchQuote={() => void quote.refetch()}
                    customerName={customerName}
                    customerReady={Boolean(phone && customerName)}
                    dueAvailable={known?.due_available ?? null}
                    pieces={pieces}
                    onClose={() => setPaying(false)}
                    onCharged={(sale) => {
                        setPaying(false);
                        setShowCoupon(false);
                        onCharged(sale);
                    }}
                />
            )}
            <DiscountDialog
                open={showDiscount}
                current={cart.discount}
                maxPercent={q?.discount.max_percent ?? ''}
                onApply={(discount) => setCart((c) => ({ ...c, discount }))}
                onClose={() => setShowDiscount(false)}
            />
        </>
    );
}
