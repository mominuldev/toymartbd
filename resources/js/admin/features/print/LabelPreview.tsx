import type { CSSProperties } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { LabelsPrint } from './api';

export type LabelPaper = LabelsPrint['template'];

/**
 * The label stocks, in mm — App\Domain\Catalog\Enums\LabelTemplate::labelSize(). Display only:
 * the server lays out the PDF.
 */
export const LABEL_PAPER: Record<LabelPaper, { width: number; height: number; sheet: boolean }> = {
    roll_38x25: { width: 38, height: 25, sheet: false },
    roll_50x25: { width: 50, height: 25, sheet: false },
    a4_65: { width: 38.1, height: 21.2, sheet: true },
    a4_24: { width: 70, height: 37, sheet: true },
};

export const LABEL_PAPERS = Object.keys(LABEL_PAPER) as LabelPaper[];

/**
 * Type sizes (pt), padding and gap (mm) per stock — ProductLabelPdf::style(), mirrored for the
 * preview (Figma "Print · Barcode labels · premium" 533:82212).
 */
const STYLE: Record<LabelPaper, { padding: number; gap: number; store: number; sku: number; name: number; options: number; price: number; digits: number; twoLines: boolean }> = {
    roll_38x25: { padding: 1.6, gap: 0.7, store: 4.6, sku: 4.2, name: 6.6, options: 5.2, price: 10, digits: 5.4, twoLines: true },
    roll_50x25: { padding: 1.6, gap: 0.7, store: 5, sku: 4.6, name: 7.2, options: 5.6, price: 11.5, digits: 5.8, twoLines: true },
    a4_65: { padding: 1.2, gap: 0.5, store: 4.2, sku: 3.9, name: 6, options: 4.8, price: 9, digits: 5, twoLines: false },
    a4_24: { padding: 1.6, gap: 1, store: 6.4, sku: 5.6, name: 9.5, options: 7, price: 15, digits: 7.4, twoLines: true },
};

/** The sample's EAN-13 (an in-store code, prefix 20) and its 95 modules, as mPDF encodes it. */
export const SAMPLE_EAN = '2000000001173';
const SAMPLE_MODULES = '10100011010001101010011101001110001101010011101010111001011100101100110110011010001001000010101';
/** Quiet zones in modules (mPDF's EAN-13: 11 left, 7 right). */
const QUIET = { left: 11, right: 7 };
const TOTAL = QUIET.left + SAMPLE_MODULES.length + QUIET.right;
/** Static sample strings — the preview never computes money (rule 1). */
const SAMPLE_PRICE = '4,900';
const SAMPLE_SKU = 'TRI-RED';
const TAKA = '\u09F3';

/** Enlarged preview: 1.5 spacing units (6 px at the default root size) per millimetre, as in Figma. */
const SCALE = 1.5;
const PT = 25.4 / 72;
/**
 * `enlarged` (the default, Settings) or `actual`: CSS's absolute `mm` unit, the label's real
 * printed size at the screen's standard 96 px per inch (a ruler held to the screen may differ
 * with the display's own density).
 */
export type LabelZoom = 'enlarged' | 'actual';
const sizer = (zoom: LabelZoom) => (value: number) => (zoom === 'actual' ? `${value.toFixed(3)}mm` : `calc(${(value * SCALE).toFixed(3)} * var(--spacing))`);

/** Bar runs as [start, width] in modules, for the SVG. */
const BARS: [number, number][] = (() => {
    const runs: [number, number][] = [];
    for (let i = 0; i < SAMPLE_MODULES.length; i++) {
        if (SAMPLE_MODULES[i] !== '1') continue;
        const last = runs[runs.length - 1];
        if (last && last[0] + last[1] === i) last[1] += 1;
        else runs.push([i, 1]);
    }
    return runs;
})();
/** EAN-13 guard bars run down into the digit band. */
function isGuard(start: number): boolean {
    return start < 3 || (start >= 45 && start < 50) || start >= 92;
}
const GUARD_BARS = BARS.filter(([start]) => isGuard(start));
const DATA_BARS = BARS.filter(([start]) => !isGuard(start));

/**
 * Real text for the sample (the Print labels page previews the item picked in its list): the
 * name, options line, SKU and the server's formatted price, already in the label language.
 * The bars and digits stay the sample's — the server encodes the real barcode in the PDF.
 */
export type LabelContent = { name: string; options: string | null; sku: string; price: string; lang: 'en' | 'bn' };

/**
 * One sample label for the chosen stock, laid out like the PDF (resources/views/labels/
 * products.blade.php): a header with the store name in tracked capitals and the SKU, a hairline,
 * the bold name and options beside a large price, then the barcode, which takes every
 * millimetre that is left — no blank band at the bottom. Black ink only. Every toggle shows or
 * hides its line.
 */
export function LabelPreview({
    value,
    content,
    zoom = 'enlarged',
}: {
    value: Pick<LabelsPrint, 'template' | 'show_name' | 'show_options' | 'show_price' | 'show_store' | 'show_digits'>;
    content?: LabelContent;
    zoom?: LabelZoom;
}) {
    const { t, locale } = useI18n();
    const mm = sizer(zoom);
    const font = (pt: number, lineHeight = 1.15): CSSProperties => ({ fontSize: mm(pt * PT), lineHeight });
    const paper = LABEL_PAPER[value.template];
    const s = STYLE[value.template];
    const bn = content ? content.lang === 'bn' : locale === 'bn';
    const store = t('printLabels.sample.store');
    const options = content ? content.options : t('printLabels.sample.options');
    const hasMid = value.show_name || (value.show_options && options) || value.show_price;
    const digitsBand = s.digits * PT * 1.05;

    return (
        <div
            role="img"
            aria-label={t('printLabels.preview.label')}
            data-paper={value.template}
            data-zoom={zoom}
            className="flex shrink-0 flex-col overflow-hidden rounded-xs border border-line-default bg-surface text-fg-primary shadow-e1"
            style={{ width: mm(paper.width), height: mm(paper.height), padding: mm(s.padding), gap: mm(s.gap) }}
        >
            <div aria-hidden className={`flex min-w-0 items-center gap-1 ${value.show_store ? 'justify-between' : 'justify-end'}`}>
                {value.show_store && (
                    <span data-part="store" lang={bn ? 'bn' : undefined} className={`min-w-0 truncate font-bold ${bn ? '' : 'uppercase'}`} style={{ ...font(s.store), letterSpacing: bn ? undefined : '0.14em' }}>
                        {store}
                    </span>
                )}
                <span className="shrink-0 font-mono tracking-wide" style={font(s.sku)}>
                    {content?.sku ?? SAMPLE_SKU}
                </span>
            </div>
            {value.show_store && <div aria-hidden data-part="rule" className="shrink-0 bg-current" style={{ height: mm(0.18), marginTop: mm(-s.gap * 0.4) }} />}
            {hasMid && (
                <div aria-hidden className="flex min-w-0 items-start gap-2" lang={bn ? 'bn' : undefined}>
                    <div className="flex min-w-0 flex-1 flex-col" style={{ gap: mm(0.2) }}>
                        {value.show_name && (
                            <span data-part="name" className={`font-bold ${s.twoLines ? 'line-clamp-2' : 'truncate'}`} style={font(s.name, 1.1)}>
                                {content?.name ?? t('printLabels.sample.name')}
                            </span>
                        )}
                        {value.show_options && options && (
                            <span data-part="options" className="truncate" style={font(s.options)}>
                                {options}
                            </span>
                        )}
                    </div>
                    {value.show_price && (
                        <span data-part="price" className="shrink-0 font-bold whitespace-nowrap tabular" style={{ ...font(s.price, 1), letterSpacing: '-0.02em' }}>
                            <span style={{ fontSize: '0.62em' }}>{TAKA}</span>
                            {content?.price ?? SAMPLE_PRICE}
                        </span>
                    )}
                </div>
            )}
            <div aria-hidden data-part="bars" className="relative min-h-0 flex-1">
                {/* Guard bars run the full height; data bars stop above the digits when they show. */}
                <svg viewBox={`0 0 ${TOTAL} 100`} preserveAspectRatio="none" className="absolute inset-0 size-full fill-current">
                    {GUARD_BARS.map(([x, width]) => (
                        <rect key={x} x={QUIET.left + x} y={0} width={width} height={100} />
                    ))}
                </svg>
                <div className="absolute inset-x-0 top-0" style={{ bottom: value.show_digits ? mm(digitsBand) : 0 }}>
                    <svg viewBox={`0 0 ${TOTAL} 100`} preserveAspectRatio="none" className="size-full fill-current">
                        {DATA_BARS.map(([x, width]) => (
                            <rect key={x} x={QUIET.left + x} y={0} width={width} height={100} />
                        ))}
                    </svg>
                </div>
                {value.show_digits && (
                    <div data-part="digits" className="absolute inset-x-0 bottom-0 font-mono tabular" style={{ ...font(s.digits, 1), height: mm(digitsBand) }}>
                        <span className="absolute text-center" style={{ left: 0, width: `${(QUIET.left / TOTAL) * 100}%` }}>
                            {SAMPLE_EAN.slice(0, 1)}
                        </span>{' '}
                        <span className="absolute text-center" style={{ left: `${((QUIET.left + 3) / TOTAL) * 100}%`, width: `${(42 / TOTAL) * 100}%` }}>
                            {SAMPLE_EAN.slice(1, 7)}
                        </span>{' '}
                        <span className="absolute text-center" style={{ left: `${((QUIET.left + 50) / TOTAL) * 100}%`, width: `${(42 / TOTAL) * 100}%` }}>
                            {SAMPLE_EAN.slice(7)}
                        </span>
                    </div>
                )}
            </div>
        </div>
    );
}
