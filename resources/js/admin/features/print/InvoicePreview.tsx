import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ImageIcon } from '@phosphor-icons/react';
import { translate } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import type { InvoicePrint } from './api';
import { invoiceSample as sample } from './invoiceSample';

/** What the header prints, from the store's saved (or draft) invoice details. */
export type InvoiceStore = {
    name_en: string | null;
    name_bn: string | null;
    address_en: string | null;
    address_bn: string | null;
    hotline: string | null;
    trade_licence: string | null;
    bin: string | null;
    logoUrl: string | null;
    footer_en: string | null;
    footer_bn: string | null;
};

/** Paper at 96 dpi: A4 210 × 297 mm, A5 148 × 210 mm. The page is drawn at this size, then scaled to fit. */
const PAPER = { a4: { width: 794, height: 1123 }, a5: { width: 559, height: 794 } } as const;

/**
 * The on-screen A4 invoice preview (Figma 528:81351 artboard): the sample order drawn the way the
 * PDF lays it out, reacting to every choice — paper, language (English, Bangla, or English with
 * the Bangla label beneath), brand colour or black and white, and each optional part. The
 * figures are the fixture's display strings (invoiceSample.ts); nothing is computed.
 * Decorative barcode and QR marks stand in for the real ones ("Open full size" has those).
 */
export function InvoicePreview({ print, store }: { print: InvoicePrint; store: InvoiceStore }) {
    const frame = useRef<HTMLDivElement>(null);
    const [available, setAvailable] = useState<number | null>(null);
    const paper = PAPER[print.paper];

    useEffect(() => {
        const node = frame.current;
        if (!node || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => setAvailable(entry?.contentRect.width ?? null));
        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    const scale = available ? Math.min(1, available / paper.width) : 1;
    const primary = print.language === 'bn' ? 'bn' : 'en';
    const both = print.language === 'both';
    const doc = (key: MessageKey, vars?: Record<string, string>) => translate(primary, key, vars);
    /** A label in the invoice's language; with "Both", the Bangla beneath it. */
    const label = (key: MessageKey, vars?: Record<string, string>): ReactNode =>
        both ? (
            <>
                {doc(key, vars)}
                <span lang="bn" className="lang-bn block t-admin-caption font-normal text-fg-tertiary normal-case">
                    {translate('bn', key, vars)}
                </span>
            </>
        ) : (
            doc(key, vars)
        );

    const brand = print.colour === 'brand';
    const accent = brand ? 'text-fg-brand' : 'text-fg-primary';
    const placeholder = doc('printInvoice.doc.placeholder');
    const name = (primary === 'bn' ? (store.name_bn ?? store.name_en) : store.name_en) ?? placeholder;
    const address = (primary === 'bn' ? store.address_bn : store.address_en) ?? placeholder;
    const footer = (primary === 'bn' ? store.footer_bn : store.footer_en) || doc('printInvoice.doc.thanks');

    return (
        <div ref={frame} className="w-full min-w-0" data-testid="invoice-preview" data-paper={print.paper} data-language={print.language} data-colour={print.colour}>
            <div className="mx-auto" style={{ width: paper.width * scale, height: paper.height * scale }}>
                <article
                    aria-label={doc('printInvoice.preview.label')}
                    lang={primary}
                    className={`flex origin-top-left flex-col gap-4 overflow-hidden bg-surface p-10 text-fg-primary shadow-e2 ${primary === 'bn' ? 'lang-bn' : ''}`}
                    style={{ width: paper.width, height: paper.height, transform: `scale(${scale})` }}
                >
                    <header className="flex items-start gap-4">
                        <span className="grid size-14 shrink-0 place-items-center overflow-hidden rounded-sm border border-line-default bg-subtle">
                            {store.logoUrl ? <img src={store.logoUrl} alt="" className="size-full object-contain" /> : <ImageIcon size={24} aria-hidden className="text-fg-tertiary" />}
                        </span>
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <p className={`t-admin-section ${accent}`}>{name}</p>
                            {both && store.name_bn && (
                                <p lang="bn" className="lang-bn t-admin-body-strong">
                                    {store.name_bn}
                                </p>
                            )}
                            <p className="t-admin-caption text-fg-secondary">{address}</p>
                            {both && (
                                <p lang="bn" className="lang-bn t-admin-caption text-fg-secondary">
                                    {store.address_bn ?? placeholder}
                                </p>
                            )}
                            <p className="t-admin-caption text-fg-secondary">
                                {doc('printInvoice.doc.hotline')}: {store.hotline ?? placeholder}
                            </p>
                            {print.show_bin && (
                                <p className="t-admin-caption text-fg-secondary" data-part="bin">
                                    {doc('printInvoice.doc.tradeLicence')}: {store.trade_licence ?? placeholder} · {doc('printInvoice.doc.bin')}: {store.bin ?? placeholder}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1 text-right">
                            <p className={`t-admin-section uppercase ${accent}`}>{label('printInvoice.doc.title')}</p>
                            <p className="t-admin-mono">{sample.number}</p>
                            {print.show_barcode && <Barcode text={sample.order} />}
                        </div>
                    </header>

                    <div className="grid grid-cols-3 gap-4 border-y border-line-default py-3">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <p className="t-admin-overline text-fg-tertiary">{label('printInvoice.doc.billTo')}</p>
                            <p className="t-admin-body-strong" lang={primary}>
                                {sample.customer[primary]}
                            </p>
                            {print.show_phone && (
                                <p className="t-admin-caption" data-part="phone">
                                    {sample.phone}
                                </p>
                            )}
                            {print.show_email && (
                                <p className="t-admin-caption" data-part="email">
                                    {sample.email}
                                </p>
                            )}
                        </div>
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <p className="t-admin-overline text-fg-tertiary">{label('printInvoice.doc.deliverTo')}</p>
                            <p className="t-admin-caption">{sample.address}</p>
                            <p className="t-admin-caption text-fg-secondary">
                                {doc('printInvoice.doc.landmark')}: {sample.landmark[primary]}
                            </p>
                        </div>
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <p className="t-admin-overline text-fg-tertiary">{label('printInvoice.doc.order')}</p>
                            <p className="t-admin-mono">{sample.order}</p>
                            <p className="t-admin-caption">
                                {doc('printInvoice.doc.date')}: {sample.date}
                            </p>
                            <p className="t-admin-caption">
                                {doc('printInvoice.doc.payment')}: {doc('printInvoice.doc.cod')}
                            </p>
                        </div>
                    </div>

                    <table className="w-full border-collapse">
                        <thead>
                            <tr className={brand ? 'bg-brand-subtle' : 'bg-subtle'}>
                                <th className="w-8 px-2 py-1.5 text-left t-admin-caption-strong">#</th>
                                <th className="px-2 py-1.5 text-left t-admin-caption-strong">{label('printInvoice.doc.item')}</th>
                                {print.show_sku && <th className="px-2 py-1.5 text-left t-admin-caption-strong">{label('printInvoice.doc.sku')}</th>}
                                <th className="px-2 py-1.5 text-right t-admin-caption-strong">{label('printInvoice.doc.qty')}</th>
                                <th className="px-2 py-1.5 text-right t-admin-caption-strong">{label('printInvoice.doc.unitPrice')}</th>
                                <th className="px-2 py-1.5 text-right t-admin-caption-strong">{label('printInvoice.doc.amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sample.lines.map((line, i) => (
                                <tr key={line.sku} className="border-b border-line-default align-top">
                                    <td className="px-2 py-1.5 t-admin-num">{i + 1}</td>
                                    <td className="px-2 py-1.5">
                                        <span className="block t-admin-body" lang={primary}>
                                            {line.name[primary]}
                                        </span>
                                        {print.show_options && <span className="block t-admin-caption text-fg-secondary">{line.options}</span>}
                                    </td>
                                    {print.show_sku && <td className="px-2 py-1.5 t-admin-mono-sm text-fg-secondary">{line.sku}</td>}
                                    <td className="px-2 py-1.5 text-right t-admin-num">{line.qty}</td>
                                    <td className="px-2 py-1.5 text-right t-admin-num">{line.unit}</td>
                                    <td className="px-2 py-1.5 text-right t-admin-num">{line.amount}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="flex items-start gap-6">
                        <div className="flex min-w-0 flex-1 flex-col gap-3">
                            {print.show_payment_stamp && (
                                <p data-part="stamp" className={`self-start rounded-sm border-2 px-3 py-1.5 t-admin-body-strong uppercase ${brand ? 'border-line-brand text-fg-brand' : 'border-line-strong text-fg-primary'}`}>
                                    {label('printInvoice.doc.stampCod', { amount: sample.due })}
                                </p>
                            )}
                            <p className="t-admin-caption text-fg-secondary">{doc('printInvoice.doc.returns')}</p>
                            {print.show_track_qr && (
                                <div className="flex items-center gap-2" data-part="qr">
                                    <QrMark />
                                    <span className="t-admin-caption text-fg-secondary">{label('printInvoice.doc.track')}</span>
                                </div>
                            )}
                        </div>
                        <dl className="flex w-64 shrink-0 flex-col gap-1">
                            <Total label={label('printInvoice.doc.subtotal')} value={sample.subtotal} />
                            <Total label={label('printInvoice.doc.delivery')} value={sample.delivery} />
                            <Total label={label('printInvoice.doc.discount', { code: sample.coupon })} value={sample.discount} />
                            <Total label={label('printInvoice.doc.total')} value={sample.total} strong className={`border-t border-line-strong pt-1 ${accent}`} />
                            <Total label={label('printInvoice.doc.paid')} value={sample.paid} />
                            <Total label={label('printInvoice.doc.due')} value={sample.due} strong className="rounded-xs bg-subtle px-1 py-0.5" />
                        </dl>
                    </div>

                    {print.show_signatures && (
                        <div className="mt-6 grid grid-cols-2 gap-16" data-part="signatures">
                            <p className="border-t border-line-strong pt-1 t-admin-caption text-fg-secondary">{label('printInvoice.doc.signCustomer')}</p>
                            <p className="border-t border-line-strong pt-1 t-admin-caption text-fg-secondary">{label('printInvoice.doc.signStore')}</p>
                        </div>
                    )}

                    <footer className="mt-auto flex flex-col items-center gap-0.5 border-t border-line-default pt-3 text-center">
                        <p className="t-admin-caption text-fg-secondary">{footer}</p>
                        {both && store.footer_bn && (
                            <p lang="bn" className="lang-bn t-admin-caption text-fg-secondary">
                                {store.footer_bn}
                            </p>
                        )}
                        <p className="t-admin-caption text-fg-tertiary">{doc('printInvoice.doc.page')}</p>
                    </footer>
                </article>
            </div>
        </div>
    );
}

function Total({ label, value, strong = false, className = '' }: { label: ReactNode; value: string; strong?: boolean; className?: string }) {
    return (
        <div className={`flex items-start justify-between gap-3 ${className}`}>
            <dt className={strong ? 't-admin-body-strong' : 't-admin-caption'}>{label}</dt>
            <dd className={`text-right ${strong ? 't-admin-currency-num' : 't-admin-num'}`}>{value}</dd>
        </div>
    );
}

/** A decorative Code 128-like mark: bar widths from the text's characters (the PDF draws the real code). */
function Barcode({ text }: { text: string }) {
    const bars: { x: number; w: number }[] = [];
    let x = 0;
    for (const char of text) {
        const code = char.charCodeAt(0);
        for (const w of [1 + (code % 3), 1 + ((code >> 2) % 2), 1 + ((code >> 3) % 3)]) {
            bars.push({ x, w });
            x += w * 2;
        }
    }
    return (
        <svg data-part="barcode" aria-hidden viewBox={`0 0 ${x} 24`} preserveAspectRatio="none" className="h-8 w-40 text-fg-primary">
            {bars.map((bar) => (
                <rect key={bar.x} x={bar.x} y={0} width={bar.w} height={24} fill="currentColor" />
            ))}
        </svg>
    );
}

/** A decorative QR-like mark with the three finder squares (the PDF draws the real track-order QR). */
function QrMark() {
    const size = 21;
    const finder = (r: number, c: number) => (r < 7 && c < 7) || (r < 7 && c >= size - 7) || (r >= size - 7 && c < 7);
    const cells: { r: number; c: number }[] = [];
    for (let r = 0; r < size; r += 1) {
        for (let c = 0; c < size; c += 1) {
            if (finder(r, c)) {
                const rr = r % (size - 7) > 6 ? r - (size - 7) : r % (size - 7);
                const cc = c % (size - 7) > 6 ? c - (size - 7) : c % (size - 7);
                const ring = rr === 0 || rr === 6 || cc === 0 || cc === 6;
                const core = rr >= 2 && rr <= 4 && cc >= 2 && cc <= 4;
                if (ring || core) cells.push({ r, c });
            } else if ((r * 7 + c * 13 + r * c) % 3 === 0) {
                cells.push({ r, c });
            }
        }
    }
    return (
        <svg aria-hidden viewBox={`0 0 ${size} ${size}`} className="size-16 shrink-0 text-fg-primary">
            {cells.map(({ r, c }) => (
                <rect key={`${r}-${c}`} x={c} y={r} width={1} height={1} fill="currentColor" />
            ))}
        </svg>
    );
}
