import { useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { translate, type Locale } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { PosReceipt } from './api';
import { discountReasonKey } from './cart';
import { dhakaDateTime, isZeroAmount, takaText } from './format';

/** The owner's Thermal receipt settings, as the receipt response carries them. */
export type ReceiptLook = PosReceipt['print'];

/** The roll width as a physical size: 80 mm ≈ 302 px, 58 mm ≈ 219 px on screen. Paper, not spacing. */
const PAPER_WIDTH: Record<ReceiptLook['paper'], string> = { '80': 'w-[80mm]', '58': 'w-[58mm]' };

/** `https://wa.me/8801700000000` → `01700-000000` — the store's own number, as printed under its QR. */
function whatsappNumber(link: string): string {
    const digits = link.replace(/\D+/g, '');
    const national = digits.startsWith('880') ? `0${digits.slice(3)}` : digits;
    return national.length === 11 ? `${national.slice(0, 5)}-${national.slice(5)}` : national;
}

/** While a receipt prints: only it, on a page the roll's width, no margin. Print CSS, not copy. */
function printCss(paper: ReceiptLook['paper']): string {
    return ['@media print {', 'body > :not(#receipt-print) { display: none !important; }', `@page { size: ${paper}mm auto; margin: 0; }`, '}'].join(' ');
}

const svgSrc = (svg: string) => `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;

/**
 * Prints one receipt through the browser on any receipt printer the OS knows (Settings ›
 * Invoices, receipts & labels › Thermal receipt, Figma 529:81638). The receipt is portalled to
 * <body>; while it exists, print CSS hides everything else and sets the page to the roll width
 * with no margin. `onDone` runs after the print dialog closes.
 */
export function ReceiptPrint({ receipt, language, logo, onDone }: { receipt: PosReceipt; language: Locale; logo?: ReactNode; onDone: () => void }) {
    useEffect(() => {
        const done = () => onDone();
        window.addEventListener('afterprint', done, { once: true });
        window.print();
        return () => window.removeEventListener('afterprint', done);
        // Print once per mount; a new receipt is a new mount.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return createPortal(
        <div id="receipt-print" className="hidden print:block">
            <style>{printCss(receipt.print.paper)}</style>
            <ReceiptBody receipt={receipt} language={language} logo={logo} testId="pos-receipt-print" />
        </div>,
        document.body,
    );
}

/**
 * The counter receipt as it prints — the POS print, the Sale complete paper and the settings
 * preview all render this. `language` is the receipt's own, independent of the admin UI's;
 * Western digits in both (rule 36), times in Dhaka (rule 11). Every figure is the order's own,
 * shown as sent (rule 1); the customer's phone is only ever the masked one (rule 20). What
 * shows, the roll width and the text size follow `receipt.print`. `logo` replaces the store
 * logo image (the settings preview's stand-in).
 */
export function ReceiptBody({ receipt, language, logo, testId = 'pos-receipt' }: { receipt: PosReceipt; language: Locale; logo?: ReactNode; testId?: string }) {
    const t = (key: MessageKey, vars?: Record<string, string | number>) => translate(language, key, vars);
    const bn = language === 'bn';
    const look = receipt.print;
    const large = look.text_size === 'large';
    const base = large ? 't-admin-body' : 't-admin-caption';
    const strong = large ? 't-admin-section' : 't-admin-body-strong';
    const store = receipt.store;
    const name = (bn ? store.name_bn : store.name_en) || store.name_en;
    const branch = bn && receipt.branch.name_bn ? receipt.branch.name_bn : receipt.branch.name;
    const address = receipt.branch.address ?? (bn ? (store.address_bn ?? store.address_en) : (store.address_en ?? store.address_bn));
    const ownFooter = (bn ? look.footer_bn : look.footer_en)?.trim();
    const footer = ownFooter ? ownFooter : t('pos.receipt.thanks');
    const rule = 'border-t border-dashed border-line-strong pt-2';
    const discount = (label: string, amount: string) =>
        isZeroAmount(amount) ? null : (
            <div className="flex justify-between gap-2">
                <dt>{label}</dt>
                <dd>{takaText(`-${amount}`)}</dd>
            </div>
        );
    const logoMark = look.show_logo
        ? (logo ?? (receipt.logo_url ? <img src={receipt.logo_url} alt="" className="h-10 w-auto max-w-full object-contain contrast-200 grayscale" /> : null))
        : null;

    return (
        <section
            aria-label={t('pos.receipt.title')}
            lang={language}
            data-testid={testId}
            data-paper={look.paper}
            className={`${PAPER_WIDTH[look.paper]} ${bn ? 'lang-bn' : ''} flex shrink-0 flex-col gap-2 px-3 py-4 ${base} text-fg-primary`}
        >
            <header className="flex flex-col items-center gap-0.5 text-center">
                {logoMark && <div className="mb-1 flex justify-center">{logoMark}</div>}
                <p className={strong}>{name}</p>
                {look.show_branch && <p>{address ? `${branch} · ${address}` : branch}</p>}
                {store.hotline && <p>{t('pos.receipt.hotline', { hotline: store.hotline })}</p>}
                {look.show_bin && store.bin && <p>{t('pos.receipt.bin', { bin: store.bin })}</p>}
            </header>
            <div className={rule}>
                <p>{t('pos.receipt.order', { number: receipt.order_number })}</p>
                <p>{dhakaDateTime(receipt.sold_at, language)}</p>
                {look.show_cashier && <p>{t('pos.receipt.cashier', { name: receipt.cashier, register: receipt.register })}</p>}
                {look.show_customer_name && receipt.customer.name && <p>{t('pos.receipt.customer', { name: receipt.customer.name, phone: receipt.customer.phone_masked ?? '' })}</p>}
            </div>
            <ul className={`flex flex-col gap-1 ${rule}`}>
                {receipt.lines.map((line, i) => (
                    <li key={i} className="flex flex-col">
                        <span>{(bn && line.name_bn ? line.name_bn : line.name) + (line.variant_name ? ` / ${line.variant_name}` : '')}</span>
                        {look.show_sku && <span className="text-fg-secondary">{t('printReceipt.paper.sku', { sku: line.sku })}</span>}
                        <span className="flex justify-between gap-2">
                            <span>{t('pos.receipt.qty', { count: line.quantity, price: takaText(line.unit_price) })}</span>
                            <span>{takaText(line.total)}</span>
                        </span>
                    </li>
                ))}
            </ul>
            <dl className={`flex flex-col gap-0.5 ${rule}`}>
                <div className="flex justify-between gap-2">
                    <dt>{t('pos.totals.subtotal')}</dt>
                    <dd>{takaText(receipt.subtotal)}</dd>
                </div>
                {discount(t('pos.receipt.promotion'), receipt.promotion_discount)}
                {discount(t('pos.totals.coupon', { code: receipt.coupon_code ?? '' }), receipt.coupon_discount)}
                {discount(receipt.discount_reason ? t('pos.totals.discount', { reason: t(discountReasonKey(receipt.discount_reason)) }) : t('pos.discount.title'), receipt.manual_discount)}
                <div className={`flex justify-between gap-2 ${strong}`}>
                    <dt>{t('pos.totals.total')}</dt>
                    <dd>{takaText(receipt.grand_total)}</dd>
                </div>
                {receipt.payments.map((p, i) => (
                    <div key={i} className="flex justify-between gap-2">
                        <dt>{t(`pos.tender.${p.method}` as MessageKey) + (p.reference ? ` · ${p.reference}` : '')}</dt>
                        <dd>{takaText(p.amount)}</dd>
                    </div>
                ))}
                {!isZeroAmount(receipt.cash_tendered) && (
                    <div className="flex justify-between gap-2">
                        <dt>{t('pos.receipt.tendered')}</dt>
                        <dd>{takaText(receipt.cash_tendered)}</dd>
                    </div>
                )}
                {!isZeroAmount(receipt.due_amount) && (
                    <div className={`flex justify-between gap-2 ${strong}`}>
                        <dt>{t('pos.receipt.due')}</dt>
                        <dd>{takaText(receipt.due_amount)}</dd>
                    </div>
                )}
                <div className="flex justify-between gap-2">
                    <dt>{t('pos.pay.change')}</dt>
                    <dd>{takaText(receipt.change_due)}</dd>
                </div>
            </dl>
            <p className={`${rule} text-center`}>{footer}</p>
            {look.show_barcode && receipt.barcode_svg && (
                <figure className="flex flex-col items-center gap-1">
                    <img src={svgSrc(receipt.barcode_svg)} alt={t('printReceipt.paper.barcode', { number: receipt.order_number })} className="h-12 w-full" />
                    <figcaption className="t-admin-mono-sm">{receipt.order_number}</figcaption>
                </figure>
            )}
            {look.show_whatsapp_qr && receipt.whatsapp_link && receipt.whatsapp_qr_svg && (
                <div className="flex items-center justify-center gap-3">
                    <img src={svgSrc(receipt.whatsapp_qr_svg)} alt={t('printReceipt.paper.whatsappQr')} className="size-14 shrink-0" />
                    <p className="flex flex-col">
                        <span>{t('printReceipt.paper.whatsapp')}</span>
                        <span>{whatsappNumber(receipt.whatsapp_link)}</span>
                    </p>
                </div>
            )}
            {look.show_cut_line && <p className={`${rule} text-center text-fg-secondary`}>{t('printReceipt.paper.cut')}</p>}
        </section>
    );
}
