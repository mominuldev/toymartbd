import { useEffect, useId, useRef, useState, type Dispatch, type KeyboardEvent, type SetStateAction } from 'react';
import { ArrowLeftIcon, CheckCircleIcon, CheckIcon, ClockIcon, CreditCardIcon, MoneyIcon, PlusIcon, PrinterIcon, QrCodeIcon, WalletIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useCharge, type PosContext, type PosQuote, type PosSale, type PosSession, type TenderMethod } from './api';
import { quoteBody, type CartState, type TenderDraft } from './cart';
import { closeDialog, useDialog } from './dialog';
import { isMoneyInput, isZeroAmount, TAKA, takaText } from './format';
import { fKey, KEY } from './keys';

const tenderIcons: Record<TenderMethod, typeof MoneyIcon> = {
    cash: MoneyIcon,
    bkash: QrCodeIcon,
    nagad: WalletIcon,
    card: CreditCardIcon,
    due: ClockIcon,
};

/** Cash the cashier can tap instead of typing — amounts handed over, not computed. */
const QUICK_CASH = ['500', '1000', '5000'];

type PaymentDialogProps = {
    open: boolean;
    context: PosContext;
    session: PosSession;
    cart: CartState;
    setCart: Dispatch<SetStateAction<CartState>>;
    quote: PosQuote;
    quoteCurrent: boolean;
    refetchQuote: () => void;
    customerName: string | null;
    /** A phone and a name are on the sale — what a Due tender needs (owner, 2026-11-15). */
    customerReady: boolean;
    /** The customer's room under the due limit, as the server reports it; null when unknown. */
    dueAvailable: string | null;
    pieces: number;
    onClose: () => void;
    onCharged: (sale: PosSale) => void;
};

/**
 * Collect payment (Figma B · 33 POS v2 — 345:64035). Left: pick a method (F1–F4), enter the
 * amount (or "Fill remaining", the server's figure) and, for bKash / Nagad / card, the
 * transaction ID, then Add payment. Right: the payments so far with Paid / Remaining / Change
 * from the quote, and quick cash. Complete sale (Enter) charges with an Idempotency-Key.
 * The register adds nothing up: every figure is the quote's (rule 1).
 */
export function PaymentDialog({ open, context, session, cart, setCart, quote: q, quoteCurrent, refetchQuote, customerName, customerReady, dueAvailable, pieces, onClose, onCharged }: PaymentDialogProps) {
    const { t } = useI18n();
    const titleId = useId();
    const amountRef = useRef<HTMLInputElement>(null);
    const charge = useCharge(session.id);
    const keyRef = useRef<{ body: string; key: string } | null>(null);
    const [method, setMethod] = useState<TenderMethod>('cash');
    const [amount, setAmount] = useState('');
    const [reference, setReference] = useState('');
    const [formError, setFormError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const ref = useDialog(open, () => {
        const first = context.tenders.find((x) => !cart.tenders.some((d) => d.method === x.method))?.method ?? 'cash';
        pick(first);
        setError(null);
    });

    useEffect(() => setError(null), [cart]);

    const label = (m: TenderMethod) => t(`pos.tender.${m}` as MessageKey);
    const needsRef = context.tenders.find((x) => x.method === method)?.requires_reference ?? false;
    const remaining = q.tenders?.remaining ?? q.grand_total;
    const added = cart.tenders.filter((d) => isMoneyInput(d.amount));
    const existing = cart.tenders.find((d) => d.method === method);
    const tenderError = (field: string) => q.tenders?.errors.find((e) => e.field === field)?.message;

    function pick(m: TenderMethod) {
        const draft = cart.tenders.find((d) => d.method === m);
        setMethod(m);
        setAmount(draft?.amount ?? '');
        setReference(draft?.reference ?? '');
        setFormError(null);
        requestAnimationFrame(() => amountRef.current?.focus());
    }

    const upsert = (draft: TenderDraft) =>
        setCart((c) => ({
            ...c,
            tenders: c.tenders.some((d) => d.method === draft.method)
                ? c.tenders.map((d) => (d.method === draft.method ? draft : d))
                : [...c.tenders.filter((d) => isMoneyInput(d.amount)), draft],
        }));

    const addPayment = () => {
        if (!isMoneyInput(amount)) return setFormError(t('pos.open.invalid'));
        upsert({ method, amount: amount.trim(), reference: reference.trim() });
        setFormError(null);
        setAmount('');
        setReference('');
        // Move on to the next method not used yet, ready for a split.
        const next = context.tenders.find((x) => x.method !== method && !cart.tenders.some((d) => d.method === x.method))?.method;
        if (next) setMethod(next);
    };

    const complete = () => {
        const body = quoteBody(cart);
        const input = {
            ...body,
            tenders: body.tenders ?? [],
            expected_total: q.grand_total,
            customer_name: cart.name.trim() || null,
        };
        const serial = JSON.stringify(input);
        if (keyRef.current?.body !== serial) keyRef.current = { body: serial, key: crypto.randomUUID() };
        charge.mutate(
            { input, key: keyRef.current.key },
            {
                onSuccess: (sale) => {
                    keyRef.current = null;
                    closeDialog(ref.current, () => undefined);
                    onCharged(sale);
                },
                onError: (e) => {
                    if (e instanceof ApiError) {
                        setError(e.is('price-changed') ? t('pos.error.priceChanged') : e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title);
                        refetchQuote();
                    } else setError(t('pos.error.generic'));
                },
            },
        );
    };

    const canComplete = q.chargeable && quoteCurrent && added.length > 0 && !charge.isPending;

    const onKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        const index = ['F1', 'F2', 'F3', 'F4', 'F5'].indexOf(event.key);
        const tender = index >= 0 ? context.tenders[index] : undefined;
        if (tender) {
            event.preventDefault();
            if (!tender.requires_customer || customerReady) pick(tender.method);
            return;
        }
        const target = event.target as HTMLElement;
        if (event.key === 'Enter' && !['INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'A'].includes(target.tagName) && canComplete) {
            event.preventDefault();
            complete();
        }
    };

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onKeyDown={onKeyDown}
            className="m-auto w-[min(100%-2rem,55rem)] overflow-hidden rounded-xl bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <header className="flex items-start gap-4 border-b border-line-default px-7 py-6">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="t-admin-page-title">
                        {t('pos.pay.dialogTitle')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">
                        {t('pos.pay.dialogSub', {
                            customer: customerName ?? t('pos.pay.walkIn'),
                            count: pieces,
                        })}
                    </p>
                </div>
                <div className="flex flex-col items-end gap-0.5">
                    <span className="t-admin-overline text-fg-secondary">{t('pos.pay.payable')}</span>
                    <span className="t-admin-num-xl">{takaText(q.grand_total)}</span>
                </div>
                <IconButton variant="outline" aria-label={t('pos.pay.close')} onClick={() => closeDialog(ref.current, onClose)}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>

            <div className="grid md:grid-cols-[1fr_26.25rem]">
                <form
                    className="flex flex-col gap-3 px-7 py-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        // Enter in an empty amount once it is fully paid completes the sale, so a
                        // keyboard-only cashier goes amount → Enter (add) → Enter (complete).
                        if (amount.trim() === '' && canComplete) complete();
                        else addPayment();
                    }}
                >
                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-3 t-admin-body-strong">{t('pos.pay.method')}</legend>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(5.5rem,1fr))] gap-2">
                            {context.tenders.map((tender, i) => {
                                const Icon = tenderIcons[tender.method];
                                const on = method === tender.method;
                                const blocked = tender.requires_customer && !customerReady;
                                return (
                                    <button
                                        key={tender.method}
                                        type="button"
                                        aria-pressed={on}
                                        disabled={blocked}
                                        aria-describedby={blocked ? 'pos-pay-due-hint' : undefined}
                                        aria-keyshortcuts={`F${i + 1}`}
                                        onClick={() => pick(tender.method)}
                                        className={`flex h-21 flex-col items-center justify-center gap-1 rounded-md border t-admin-body-strong transition-colors ${
                                            on
                                                ? 'border-line-brand bg-brand-subtle text-fg-brand ring-1 ring-inset ring-line-brand'
                                                : 'border-line-default bg-surface text-fg-primary hover:bg-subtle disabled:cursor-not-allowed disabled:bg-disabled disabled:text-fg-disabled'
                                        }`}
                                    >
                                        <Icon size={20} aria-hidden className={on ? 'text-fg-brand' : 'text-fg-secondary'} />
                                        {label(tender.method)}
                                        <span aria-hidden className="t-admin-caption text-fg-tertiary">
                                            {fKey(i)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        {context.tenders.some((x) => x.requires_customer) && !customerReady && (
                            <p id="pos-pay-due-hint" className="t-admin-caption text-fg-tertiary">
                                {t('pos.pay.dueNeedsCustomer')}
                            </p>
                        )}
                    </fieldset>

                    <label htmlFor="pos-pay-amount" className="mt-2 t-admin-body-strong">
                        {method === 'cash' ? t('pos.pay.cashReceived') : t('pos.pay.amountFor', { method: label(method) })}
                    </label>
                    <div className="flex items-center gap-2">
                        <Input
                            ref={amountRef}
                            id="pos-pay-amount"
                            prefix={TAKA}
                            inputMode="decimal"
                            className="flex-1"
                            value={amount}
                            aria-invalid={Boolean(formError)}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                        {!isZeroAmount(remaining) && (
                            <Button variant="secondary" onClick={() => setAmount(remaining)}>
                                {t('pos.pay.fill')}
                            </Button>
                        )}
                    </div>
                    {formError && <p className="t-admin-caption text-feedback-danger">{formError}</p>}

                    {needsRef && (
                        <>
                            <label htmlFor="pos-pay-ref" className="t-admin-body-strong">
                                {t('pos.pay.reference', {
                                    method: label(method),
                                })}
                            </label>
                            <Input id="pos-pay-ref" value={reference} maxLength={64} className="t-admin-mono-input" onChange={(e) => setReference(e.target.value)} />
                            <p className="t-admin-caption text-fg-tertiary">
                                {t('pos.pay.referenceHelp', {
                                    method: label(method),
                                })}
                            </p>
                        </>
                    )}

                    <Button type="submit" variant="outline" className="mt-1 w-full">
                        <PlusIcon size={16} aria-hidden />
                        {existing ? t('pos.pay.update') : t('pos.pay.add')}
                    </Button>
                </form>

                <section aria-label={t('pos.pay.list')} className="flex flex-col gap-3 border-t border-line-default bg-subtle px-7 py-6 md:border-s md:border-t-0">
                    <h3 className="t-admin-body-strong">{t('pos.pay.list')}</h3>
                    {added.length === 0 ? (
                        <p className="rounded-md border border-dashed border-line-default p-4 t-admin-body text-fg-secondary">{t('pos.pay.none')}</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {added.map((d, index) => {
                                const Icon = tenderIcons[d.method];
                                // The quote numbers tenders as sent: only those with an amount (quoteBody).
                                const problem = tenderError(`tenders.${index}.amount`) ?? tenderError(`tenders.${index}.reference`);
                                return (
                                    <li
                                        key={d.method}
                                        className={`flex items-center gap-3 rounded-md border bg-surface p-3 ${problem ? 'border-line-danger' : 'border-line-default'}`}
                                    >
                                        <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                                            <Icon size={18} />
                                        </span>
                                        <button type="button" className="flex min-w-0 flex-1 flex-col text-start" onClick={() => pick(d.method)}>
                                            <span className="t-admin-body-strong">{label(d.method)}</span>
                                            <span className={`truncate t-admin-caption ${problem ? 'text-feedback-danger' : 'text-fg-secondary'}`}>
                                                {problem ??
                                                    (d.method === 'cash'
                                                        ? t('pos.pay.cashMeta')
                                                        : d.method === 'due'
                                                        ? dueAvailable
                                                            ? t('pos.pay.dueMeta', { amount: takaText(dueAvailable) })
                                                            : ''
                                                        : d.reference
                                                        ? t('pos.pay.refMeta', {
                                                              reference: d.reference,
                                                          })
                                                        : '')}
                                            </span>
                                        </button>
                                        <span className="t-admin-num">{takaText(d.amount)}</span>
                                        <IconButton
                                            size="sm"
                                            aria-label={t('pos.pay.removeTender', { method: label(d.method) })}
                                            onClick={() =>
                                                setCart((c) => ({
                                                    ...c,
                                                    tenders: c.tenders.filter((x) => x.method !== d.method),
                                                }))
                                            }
                                        >
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {/* Proportions are left to CSS flex-grow on the server's strings: no sum in JS. */}
                    <div aria-hidden className="flex h-1.5 overflow-hidden rounded-full bg-line-default">
                        {added.map((d, i) => (
                            <span key={d.method} className={i % 2 === 0 ? 'bg-brand' : 'bg-grape'} style={{ flexGrow: d.amount }} />
                        ))}
                        {!isZeroAmount(remaining) && <span style={{ flexGrow: remaining }} />}
                    </div>

                    <dl className="flex flex-col gap-2">
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body text-fg-secondary">{t('pos.pay.paid')}</dt>
                            <dd className="t-admin-num">{takaText(q.tenders?.tendered ?? '0.00')}</dd>
                        </div>
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body text-fg-secondary">{t('pos.pay.remaining')}</dt>
                            <dd className={`t-admin-num ${isZeroAmount(remaining) ? 'text-feedback-success' : 'text-fg-primary'}`}>{takaText(remaining)}</dd>
                        </div>
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body-strong">{t('pos.pay.change')}</dt>
                            <dd className="t-admin-num" aria-live="polite">
                                {takaText(q.tenders?.change ?? '0.00')}
                            </dd>
                        </div>
                    </dl>

                    {tenderError('tenders') && <p className="t-admin-caption text-feedback-danger">{tenderError('tenders')}</p>}
                    {/* Mounted empty with the dialog, so "fully paid" is announced when it appears. */}
                    <div role="status">
                        {q.chargeable && added.length > 0 && (
                            <p className="flex items-center gap-2 rounded-sm bg-success-subtle px-3 py-2.5 t-admin-body-strong text-feedback-success">
                                <CheckCircleIcon size={18} aria-hidden />
                                {t('pos.pay.fullyPaid')}
                            </p>
                        )}
                    </div>

                    <h3 className="mt-auto pt-2 t-admin-body-strong">{t('pos.pay.quick')}</h3>
                    <div className="grid grid-cols-4 gap-2">
                        <Button
                            variant="outline"
                            disabled={isZeroAmount(remaining) || cart.tenders.some((d) => d.method === 'cash' && isMoneyInput(d.amount))}
                            onClick={() =>
                                upsert({
                                    method: 'cash',
                                    amount: remaining,
                                    reference: '',
                                })
                            }
                        >
                            {t('pos.pay.exact')}
                        </Button>
                        {QUICK_CASH.map((value) => (
                            <Button
                                key={value}
                                variant="outline"
                                onClick={() =>
                                    upsert({
                                        method: 'cash',
                                        amount: value,
                                        reference: '',
                                    })
                                }
                            >
                                {takaText(value)}
                            </Button>
                        ))}
                    </div>
                </section>
            </div>

            {error && (
                <p role="alert" className="mx-7 mb-3 rounded-sm bg-danger-subtle px-3 py-2 t-admin-body text-fg-primary">
                    {error}
                </p>
            )}

            <footer className="flex flex-wrap items-center gap-3 border-t border-line-default px-7 py-4">
                <p className="flex min-w-0 flex-1 items-center gap-1.5 t-admin-caption text-fg-secondary">
                    <PrinterIcon size={16} aria-hidden className="shrink-0" />
                    {t('pos.pay.footer')}
                </p>
                <Button variant="ghost" size="lg" onClick={() => closeDialog(ref.current, onClose)}>
                    <ArrowLeftIcon size={18} aria-hidden />
                    {t('pos.pay.close')}
                </Button>
                <Button size="lg" onClick={complete} disabled={!canComplete} aria-keyshortcuts="Enter">
                    <CheckIcon size={18} aria-hidden />
                    {charge.isPending ? t('pos.charging') : t('pos.pay.complete')}
                    <kbd aria-hidden className="rounded-xs bg-brand-shelf px-1.5 t-admin-mono-sm text-fg-on-brand">
                        {KEY.enter}
                    </kbd>
                </Button>
            </footer>
        </dialog>
    );
}
