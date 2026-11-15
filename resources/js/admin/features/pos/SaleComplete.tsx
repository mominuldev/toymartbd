import { useEffect, useId, useRef, useState } from 'react';
import { CheckCircleIcon, InfoIcon, PlusIcon, PrinterIcon, ReceiptIcon, WhatsappLogoIcon } from '@phosphor-icons/react';
import { useI18n, type Locale } from '@/i18n/I18nProvider';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { useReceipt, type PosContext, type PosSale } from './api';
import { dhakaTime, isZeroAmount, takaText } from './format';
import { ReceiptBody } from './Receipt';
import { KEY } from './keys';

/**
 * Sale complete (Figma B · 33 POS v2 — 346:64332): the sale's own total / paid / change, the
 * tenders as recorded, Print receipt, WhatsApp receipt (disabled with the reason until a
 * WhatsApp provider is connected), Start new sale (Enter), and the receipt as it will print.
 */
export function SaleComplete({ context, sale, onNext }: { context: PosContext; sale: PosSale; onNext: () => void }) {
    const { t, locale } = useI18n();
    // The customer's language, not the cashier's: defaults to the UI, one tap to switch (owner, 2026-11-15).
    const [language, setLanguage] = useState<Locale>(locale);
    const receipt = useReceipt(sale.id);
    const waNote = useId();
    const nextRef = useRef<HTMLButtonElement>(null);

    useEffect(() => nextRef.current?.focus(), []);

    const customer = receipt.data?.customer.name ?? t('pos.pay.walkIn');
    const time = sale.created_at ? dhakaTime(sale.created_at, locale) : '';
    const stats = [
        {
            label: t('pos.done.total'),
            value: sale.grand_total,
            tone: 'text-fg-primary',
        },
        {
            label: t('pos.done.paid'),
            value: sale.tendered_total,
            tone: 'text-feedback-success',
        },
        {
            label: t('pos.done.change'),
            value: sale.change_due,
            tone: 'text-fg-brand',
        },
        ...(isZeroAmount(sale.due_amount) ? [] : [{ label: t('pos.done.due'), value: sale.due_amount, tone: 'text-feedback-warning' }]),
    ];

    return (
        <div className="flex min-h-0 flex-1 items-start justify-center gap-8 overflow-y-auto px-4 py-6 sm:px-8 lg:items-center lg:gap-24 lg:px-16 lg:py-8 print:block print:p-0">
            <section aria-labelledby="pos-done-title" className="flex w-full max-w-120 flex-col gap-5 print:hidden">
                <span aria-hidden className="grid size-16 place-items-center rounded-full bg-success-subtle text-feedback-success">
                    <CheckCircleIcon size={36} weight="fill" />
                </span>
                <div className="flex flex-col gap-1.5">
                    <h2 id="pos-done-title" className="t-admin-display text-fg-primary">
                        {t('pos.done.title')}
                    </h2>
                    <p id="pos-done-sub" className="t-admin-body-lg text-fg-secondary">
                        {t('pos.done.sub', {
                            number: sale.order_number,
                            customer,
                            time,
                        })}
                    </p>
                </div>
                <dl id="pos-done-stats" className={`grid gap-3 ${stats.length > 3 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-3'}`}>
                    {stats.map((s) => (
                        <div key={s.label} className="flex flex-col gap-1.5 rounded-md border border-line-default bg-surface px-4 py-3.5">
                            <dt className="t-admin-overline text-fg-secondary">{s.label}</dt>
                            <dd className={`t-admin-num-lg ${s.tone}`}>{takaText(s.value)}</dd>
                        </div>
                    ))}
                </dl>
                {receipt.data && receipt.data.payments.length > 0 && (
                    <ul className="flex flex-wrap gap-2">
                        {receipt.data.payments.map((p, i) => (
                            <li
                                key={i}
                                className={`rounded-full px-2.5 py-1 t-admin-caption-strong ${
                                    p.method === 'cash' ? 'bg-success-subtle text-feedback-success' : 'bg-brand-subtle text-fg-brand'
                                }`}
                            >
                                {`${t(`pos.tender.${p.method}` as MessageKey)} ${takaText(p.amount)}${p.reference ? ` · ${p.reference}` : ''}`}
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="t-admin-body-strong text-fg-primary">{t('pos.receipt.language')}</span>
                    <SegmentedControl<Locale>
                        label={t('pos.receipt.language')}
                        options={[
                            { value: 'en', label: 'English', lang: 'en' },
                            { value: 'bn', label: 'বাংলা', lang: 'bn' },
                        ]}
                        value={language}
                        onChange={setLanguage}
                        className="h-11 [&>button]:h-10 [&>button]:px-4"
                    />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Button variant="outline" size="lg" disabled={!receipt.data} onClick={() => window.print()}>
                        <PrinterIcon size={20} aria-hidden />
                        {t('pos.receipt.print')}
                    </Button>
                    <Button variant="outline" size="lg" disabled aria-describedby={context.whatsapp_receipt.enabled ? undefined : waNote}>
                        <WhatsappLogoIcon size={20} aria-hidden />
                        {t('pos.receipt.whatsapp')}
                    </Button>
                </div>
                {/* Focus lands here, so its description carries what just happened: the title, the order
                    and the total / paid / change — "Start new sale, button, Sale complete, …". */}
                <Button
                    ref={nextRef}
                    size="lg"
                    className="h-14 justify-between"
                    aria-keyshortcuts="Enter"
                    aria-describedby="pos-done-title pos-done-sub pos-done-stats"
                    onClick={onNext}
                >
                    <span className="flex items-center gap-2">
                        <PlusIcon size={20} aria-hidden />
                        {t('pos.done.next')}
                    </span>
                    <kbd aria-hidden className="rounded-xs bg-brand-shelf px-1.5 t-admin-mono-sm text-fg-on-brand">
                        {KEY.enter}
                    </kbd>
                </Button>
                <p className="flex items-start gap-2 t-admin-caption text-fg-tertiary">
                    <InfoIcon size={16} aria-hidden className="mt-px shrink-0" />
                    <span>
                        {t('pos.done.note')}
                        {!context.whatsapp_receipt.enabled && (
                            <>
                                {' '}
                                <span id={waNote}>{t('pos.receipt.whatsappOff')}</span>
                            </>
                        )}
                    </span>
                </p>
            </section>

            <section aria-label={t('pos.receipt.preview')} className="hidden shrink-0 flex-col items-center gap-2.5 lg:flex print:flex">
                <p className="flex items-center gap-1.5 t-admin-caption text-fg-secondary print:hidden">
                    <ReceiptIcon size={14} aria-hidden />
                    {t('pos.receipt.preview')}
                </p>
                {receipt.data && (
                    <div className="w-80 rounded-xs bg-surface p-5 shadow-e2 print:w-full print:p-0 print:shadow-none">
                        <ReceiptBody receipt={receipt.data} language={language} preview />
                    </div>
                )}
            </section>
        </div>
    );
}
