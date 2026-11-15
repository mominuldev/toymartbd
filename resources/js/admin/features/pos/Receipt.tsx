import { useEffect } from 'react';
import { translate, type Locale } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { useReceipt, type PosReceipt } from './api';
import { discountReasonKey } from './cart';
import { dhakaDateTime, isZeroAmount, takaText } from './format';

/**
 * The printable counter receipt (browser print, any receipt printer the OS knows). Hidden on
 * screen; `window.print()` runs once the server's receipt has loaded. In the language the
 * cashier picks for this customer (English or Bangla, defaulting to the UI's), Western digits in
 * both (rule 36), times in Dhaka (rule 11). Every figure is the order's own; the customer's
 * phone is masked (rule 20).
 */
export function Receipt({ saleId, language, onDone }: { saleId: number; language: Locale; onDone: () => void }) {
    const receipt = useReceipt(saleId);

    useEffect(() => {
        if (!receipt.data) return;
        const done = () => onDone();
        window.addEventListener('afterprint', done, { once: true });
        window.print();
        return () => window.removeEventListener('afterprint', done);
    }, [receipt.data, onDone]);

    return receipt.data ? <ReceiptBody receipt={receipt.data} language={language} /> : null;
}

/**
 * `language` is the receipt's own, independent of the admin UI's. `preview` shows it on screen
 * as well (the Sale complete paper); otherwise it exists for print only.
 */
export function ReceiptBody({ receipt, language, preview = false }: { receipt: PosReceipt; language: Locale; preview?: boolean }) {
    const t = (key: MessageKey, vars?: Record<string, string | number>) => translate(language, key, vars);
    const locale = language;
    const bn = language === 'bn';
    const store = receipt.store;
    const name = (bn ? store.name_bn : store.name_en) || store.name_en;
    const address = bn ? (store.address_bn ?? store.address_en) : (store.address_en ?? store.address_bn);
    const footer = bn ? (store.footer_bn ?? store.footer_en) : (store.footer_en ?? store.footer_bn);
    const discount = (label: string, amount: string) =>
        isZeroAmount(amount) ? null : (
            <div className="flex justify-between">
                <dt>{label}</dt>
                <dd>{takaText(`-${amount}`)}</dd>
            </div>
        );

    return (
        <section
            aria-label={t('pos.receipt.title')}
            lang={language}
            data-testid="pos-receipt"
            className={`${preview ? 'flex' : 'hidden print:flex'} ${bn ? 'lang-bn' : ''} w-full max-w-xs flex-col gap-2 t-admin-caption text-fg-primary`}
        >
            <header className="flex flex-col items-center gap-0.5 text-center">
                <p className="t-admin-body-strong">{name}</p>
                {address && <p>{address}</p>}
                <p>{bn && receipt.branch.name_bn ? receipt.branch.name_bn : receipt.branch.name}</p>
                {store.hotline && <p>{t('pos.receipt.hotline', { hotline: store.hotline })}</p>}
                {store.bin && <p>{t('pos.receipt.bin', { bin: store.bin })}</p>}
            </header>
            <div className="border-t border-dashed border-line-strong pt-2">
                <p>{t('pos.receipt.order', { number: receipt.order_number })}</p>
                <p>{dhakaDateTime(receipt.sold_at, locale)}</p>
                <p>{t('pos.receipt.cashier', { name: receipt.cashier, register: receipt.register })}</p>
                {receipt.customer.name && <p>{t('pos.receipt.customer', { name: receipt.customer.name, phone: receipt.customer.phone_masked ?? '' })}</p>}
            </div>
            <ul className="flex flex-col gap-1 border-t border-dashed border-line-strong pt-2">
                {receipt.lines.map((line, i) => (
                    <li key={i} className="flex flex-col">
                        <span>{(bn && line.name_bn ? line.name_bn : line.name) + (line.variant_name ? ` / ${line.variant_name}` : '')}</span>
                        <span className="flex justify-between">
                            <span>{t('pos.receipt.qty', { count: line.quantity, price: takaText(line.unit_price) })}</span>
                            <span>{takaText(line.total)}</span>
                        </span>
                    </li>
                ))}
            </ul>
            <dl className="flex flex-col gap-0.5 border-t border-dashed border-line-strong pt-2">
                <div className="flex justify-between">
                    <dt>{t('pos.totals.subtotal')}</dt>
                    <dd>{takaText(receipt.subtotal)}</dd>
                </div>
                {discount(t('pos.receipt.promotion'), receipt.promotion_discount)}
                {discount(t('pos.totals.coupon', { code: receipt.coupon_code ?? '' }), receipt.coupon_discount)}
                {discount(receipt.discount_reason ? t('pos.totals.discount', { reason: t(discountReasonKey(receipt.discount_reason)) }) : t('pos.discount.title'), receipt.manual_discount)}
                <div className="flex justify-between t-admin-body-strong">
                    <dt>{t('pos.totals.total')}</dt>
                    <dd>{takaText(receipt.grand_total)}</dd>
                </div>
                {receipt.payments.map((p, i) => (
                    <div key={i} className="flex justify-between">
                        <dt>{t(`pos.tender.${p.method}` as MessageKey) + (p.reference ? ` · ${p.reference}` : '')}</dt>
                        <dd>{takaText(p.amount)}</dd>
                    </div>
                ))}
                {!isZeroAmount(receipt.cash_tendered) && (
                    <div className="flex justify-between">
                        <dt>{t('pos.receipt.tendered')}</dt>
                        <dd>{takaText(receipt.cash_tendered)}</dd>
                    </div>
                )}
                {!isZeroAmount(receipt.due_amount) && (
                    <div className="flex justify-between t-admin-body-strong">
                        <dt>{t('pos.receipt.due')}</dt>
                        <dd>{takaText(receipt.due_amount)}</dd>
                    </div>
                )}
                <div className="flex justify-between">
                    <dt>{t('pos.pay.change')}</dt>
                    <dd>{takaText(receipt.change_due)}</dd>
                </div>
            </dl>
            <p className="border-t border-dashed border-line-strong pt-2 text-center">{footer ?? t('pos.receipt.thanks')}</p>
        </section>
    );
}
