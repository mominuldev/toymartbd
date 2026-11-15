import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { ArrowLeftIcon, CheckIcon, CreditCardIcon, MoneyIcon, PlusIcon, QrCodeIcon, WalletIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useToast } from '@/components/ui/Toast';
import { useCollectDue, useDueQuote, type CollectDueInput, type PosContext, type PosSession } from './api';
import { closeDialog, useDialog } from './dialog';
import { isMoneyInput, isZeroAmount, phoneDisplay, TAKA, takaText } from './format';
import { fKey } from './keys';

type Method = CollectDueInput['tenders'][number]['method'];
type Draft = { method: Method; amount: string; reference: string };
const icons: Record<Method, typeof MoneyIcon> = { cash: MoneyIcon, bkash: QrCodeIcon, nagad: WalletIcon, card: CreditCardIcon };
const QUICK_CASH = ['500', '1000', '5000'];

/**
 * Collect due — Figma B · 33 POS v2 350:64404. Money taken against the customer's open dues,
 * which the server applies to their oldest orders first; a partial collection is allowed. The
 * cash lands in this drawer. Every figure (applied, still due, change) is the server's quote.
 */
export function CollectDueDialog({
    open,
    context,
    session,
    phone,
    customerName,
    onClose,
}: {
    open: boolean;
    context: PosContext;
    session: PosSession;
    phone: string;
    customerName: string;
    onClose: () => void;
}) {
    const { t } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const amountRef = useRef<HTMLInputElement>(null);
    const keyRef = useRef<{ body: string; key: string } | null>(null);
    const methods = context.tenders.map((x) => x.method).filter((m): m is Method => m !== 'due');
    const [tenders, setTenders] = useState<Draft[]>([]);
    const [method, setMethod] = useState<Method>('cash');
    const [amount, setAmount] = useState('');
    const [reference, setReference] = useState('');
    const [formError, setFormError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const ref = useDialog(open, () => {
        setTenders([]);
        setMethod('cash');
        setAmount('');
        setReference('');
        setFormError(null);
        setError(null);
        keyRef.current = null;
    });

    const body = useMemo<CollectDueInput>(
        () => ({ customer_phone: phone, tenders: tenders.map((d) => ({ method: d.method, amount: d.amount, reference: d.reference.trim() || null })) }),
        [phone, tenders],
    );
    const quote = useDueQuote(session.id, body, open);
    const collect = useCollectDue(session.id);
    const q = quote.data;
    const label = (m: Method) => t(`pos.tender.${m}` as MessageKey);
    const needsRef = context.tenders.find((x) => x.method === method)?.requires_reference ?? false;
    const openDue = q?.open_due ?? '0.00';
    // Still owed before any money is taken = the open due; once tenders are in, the quote's `remaining`.
    const remaining = q?.tenders.remaining ?? openDue;
    const tenderError = (field: string) => q?.tenders.errors.find((e) => e.field === field)?.message;

    const upsert = (d: Draft) => setTenders((list) => (list.some((x) => x.method === d.method) ? list.map((x) => (x.method === d.method ? d : x)) : [...list, d]));

    const pick = (m: Method) => {
        const d = tenders.find((x) => x.method === m);
        setMethod(m);
        setAmount(d?.amount ?? '');
        setReference(d?.reference ?? '');
        setFormError(null);
        requestAnimationFrame(() => amountRef.current?.focus());
    };

    const add = () => {
        if (!isMoneyInput(amount)) return setFormError(t('pos.open.invalid'));
        upsert({ method, amount: amount.trim(), reference: reference.trim() });
        setAmount('');
        setReference('');
        setFormError(null);
    };

    const submit = () => {
        const serial = JSON.stringify(body);
        if (keyRef.current?.body !== serial) keyRef.current = { body: serial, key: crypto.randomUUID() };
        collect.mutate(
            { input: body, key: keyRef.current.key },
            {
                onSuccess: (done) => {
                    keyRef.current = null;
                    notify(t('pos.dueCollect.done', { amount: takaText(done.collected), customer: done.customer.name, remaining: takaText(done.remaining_due) }), 'success');
                    closeDialog(ref.current, onClose);
                },
                onError: (e) => {
                    setError(e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title) : t('pos.dueCollect.failed'));
                    void quote.refetch();
                },
            },
        );
    };

    const canCollect = Boolean(q?.chargeable) && tenders.length > 0 && !quote.isFetching && !collect.isPending;

    const onKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        const i = ['F1', 'F2', 'F3', 'F4'].indexOf(event.key);
        const m = i >= 0 ? methods[i] : undefined;
        if (m) {
            event.preventDefault();
            pick(m);
            return;
        }
        const target = event.target as HTMLElement;
        if (event.key === 'Enter' && !['INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'A'].includes(target.tagName) && canCollect) {
            event.preventDefault();
            submit();
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
                        {t('pos.dueCollect.title')}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t('pos.dueCollect.sub', { customer: customerName, phone: phoneDisplay(phone) })}</p>
                </div>
                <div className="flex flex-col items-end gap-0.5">
                    <span className="t-admin-overline text-fg-secondary">{t('pos.dueCollect.open')}</span>
                    <span className="t-admin-num-xl text-feedback-warning">{takaText(openDue)}</span>
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
                        add();
                    }}
                >
                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-3 t-admin-body-strong">{t('pos.pay.method')}</legend>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {methods.map((m, i) => {
                                const Icon = icons[m];
                                const on = method === m;
                                return (
                                    <button
                                        key={m}
                                        type="button"
                                        aria-pressed={on}
                                        aria-keyshortcuts={`F${i + 1}`}
                                        onClick={() => pick(m)}
                                        className={`flex h-21 flex-col items-center justify-center gap-1 rounded-md border t-admin-body-strong transition-colors ${on ? 'border-line-brand bg-brand-subtle text-fg-brand ring-1 ring-inset ring-line-brand' : 'border-line-default bg-surface text-fg-primary hover:bg-subtle'}`}
                                    >
                                        <Icon size={20} aria-hidden className={on ? 'text-fg-brand' : 'text-fg-secondary'} />
                                        {label(m)}
                                        <span aria-hidden className="t-admin-caption text-fg-tertiary">
                                            {fKey(i)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </fieldset>
                    <label htmlFor="pos-due-amount" className="mt-2 t-admin-body-strong">
                        {method === 'cash' ? t('pos.pay.cashReceived') : t('pos.pay.amountFor', { method: label(method) })}
                    </label>
                    <div className="flex items-center gap-2">
                        <Input ref={amountRef} id="pos-due-amount" prefix={TAKA} inputMode="decimal" className="h-11 flex-1" value={amount} aria-invalid={Boolean(formError)} onChange={(e) => setAmount(e.target.value)} />
                        {!isZeroAmount(remaining) && (
                            <Button variant="secondary" size="lg" onClick={() => setAmount(remaining)}>
                                {t('pos.pay.fill')}
                            </Button>
                        )}
                    </div>
                    {formError && <p className="t-admin-caption text-feedback-danger">{formError}</p>}
                    {needsRef && (
                        <>
                            <label htmlFor="pos-due-ref" className="t-admin-body-strong">
                                {t('pos.pay.reference', { method: label(method) })}
                            </label>
                            <Input id="pos-due-ref" value={reference} maxLength={64} className="h-11" onChange={(e) => setReference(e.target.value)} />
                            <p className="t-admin-caption text-fg-tertiary">{t('pos.pay.referenceHelp', { method: label(method) })}</p>
                        </>
                    )}
                    <Button type="submit" variant="outline" size="lg" className="mt-1 w-full">
                        <PlusIcon size={16} aria-hidden />
                        {tenders.some((d) => d.method === method) ? t('pos.pay.update') : t('pos.pay.add')}
                    </Button>
                    {tenders.length > 0 && (
                        <ul aria-label={t('pos.pay.list')} className="flex flex-col gap-2">
                            {tenders.map((d, index) => {
                                const Icon = icons[d.method];
                                const problem = tenderError(`tenders.${index}.amount`) ?? tenderError(`tenders.${index}.reference`);
                                return (
                                    <li key={d.method} className={`flex items-center gap-3 rounded-md border bg-surface p-3 ${problem ? 'border-line-danger' : 'border-line-default'}`}>
                                        <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                                            <Icon size={18} />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="t-admin-body-strong">{label(d.method)}</span>
                                            {(problem ?? d.reference) && <span className={`truncate t-admin-caption ${problem ? 'text-feedback-danger' : 'text-fg-secondary'}`}>{problem ?? t('pos.pay.refMeta', { reference: d.reference })}</span>}
                                        </span>
                                        <span className="t-admin-num">{takaText(d.amount)}</span>
                                        <IconButton aria-label={t('pos.pay.removeTender', { method: label(d.method) })} onClick={() => setTenders((list) => list.filter((x) => x.method !== d.method))}>
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </form>

                <section aria-label={t('pos.dueCollect.applied')} className="flex flex-col gap-3 border-t border-line-default bg-subtle px-7 py-6 md:border-s md:border-t-0">
                    <h3 className="t-admin-body-strong">{t('pos.dueCollect.applied')}</h3>
                    <ul className="overflow-hidden rounded-md border border-line-default bg-surface">
                        {(q?.orders ?? []).map((o) => (
                            <li key={o.order_id} className="flex items-center gap-3 border-b border-line-default px-3.5 py-3 last:border-0">
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="t-admin-mono">{o.order_number}</span>
                                    <span className="t-admin-caption text-fg-secondary">{t('pos.dueCollect.orderDue', { amount: takaText(o.due) })}</span>
                                </span>
                                <span className={`t-admin-num ${isZeroAmount(o.applied) ? 'text-fg-tertiary' : 'text-feedback-success'}`}>{t('pos.dueCollect.orderApplied', { amount: takaText(o.applied) })}</span>
                            </li>
                        ))}
                    </ul>
                    <dl className="flex flex-col gap-2">
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body text-fg-secondary">{t('pos.dueCollect.paid')}</dt>
                            <dd className="t-admin-num">{takaText(q?.tenders.tendered ?? '0.00')}</dd>
                        </div>
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body text-fg-secondary">{t('pos.dueCollect.after')}</dt>
                            <dd className={`t-admin-num ${isZeroAmount(remaining) ? 'text-feedback-success' : 'text-feedback-warning'}`}>{takaText(remaining)}</dd>
                        </div>
                        <div className="flex items-baseline justify-between">
                            <dt className="t-admin-body-strong">{t('pos.pay.change')}</dt>
                            <dd className="t-admin-num" aria-live="polite">
                                {takaText(q?.tenders.change ?? '0.00')}
                            </dd>
                        </div>
                    </dl>
                    {tenderError('tenders') && <p className="t-admin-caption text-feedback-danger">{tenderError('tenders')}</p>}
                    {tenders.length > 0 && q?.chargeable && (
                        <p role="status" className={`flex items-center gap-2 rounded-sm px-3 py-2.5 t-admin-body-strong ${isZeroAmount(remaining) ? 'bg-success-subtle text-feedback-success' : 'bg-warning-subtle text-feedback-warning'}`}>
                            {isZeroAmount(remaining) ? <CheckIcon size={18} aria-hidden /> : <WarningIcon size={18} aria-hidden />}
                            {isZeroAmount(remaining) ? t('pos.dueCollect.cleared') : t('pos.dueCollect.partial', { amount: takaText(remaining) })}
                        </p>
                    )}
                    <h3 className="mt-auto pt-2 t-admin-body-strong">{t('pos.pay.quick')}</h3>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <Button variant="outline" size="lg" disabled={isZeroAmount(openDue)} onClick={() => setTenders([{ method: 'cash', amount: openDue, reference: '' }])}>
                            {t('pos.dueCollect.all')}
                        </Button>
                        {QUICK_CASH.map((v) => (
                            <Button key={v} variant="outline" size="lg" onClick={() => upsert({ method: 'cash', amount: v, reference: '' })}>
                                {takaText(v)}
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
                <p className="min-w-0 flex-1 t-admin-caption text-fg-secondary">{t('pos.dueCollect.footer')}</p>
                <Button variant="ghost" size="lg" onClick={() => closeDialog(ref.current, onClose)}>
                    <ArrowLeftIcon size={18} aria-hidden />
                    {t('pos.pay.close')}
                </Button>
                <Button size="lg" onClick={submit} disabled={!canCollect} aria-keyshortcuts="Enter">
                    <CheckIcon size={18} aria-hidden />
                    {q && tenders.length > 0 ? t('pos.dueCollect.submit', { amount: takaText(q.tenders.tendered) }) : t('pos.dueCollect.submitIdle')}
                </Button>
            </footer>
        </dialog>
    );
}
