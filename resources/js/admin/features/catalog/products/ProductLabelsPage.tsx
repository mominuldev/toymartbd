import { useId, useMemo, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router';
import { ArrowLeftIcon, CheckIcon, EyeIcon, ImageIcon, MinusIcon, PlusIcon, PrinterIcon, WarningIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button, buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { usePrintSettings, type LabelsPrint } from '@/features/print/api';
import { LABEL_PAPER, LabelPreview, type LabelContent, type LabelPaper, type LabelZoom } from '@/features/print/LabelPreview';
import { PaperChoice } from '@/features/print/LabelsPrintTab';
import { ToggleRow } from '@/features/print/parts';
import { ApiError } from '@/lib/api';
import { useDebouncedValue, useProducts, useProductsById, usePrintLabels, type PrintLabelsInput, type Product } from './api';
import { formatMoney } from './document';
import { ProductStatusChip, Taka } from './parts';

/** PrintLabelsRequest caps — the server enforces them; these only warn before sending. */
const MAX_COPIES = 500;
const MAX_ITEMS = 300;
const MAX_LABELS = 5000;
/** Products per page load: each brings its full document (variants). The list caps a selection at the same. */
export const MAX_LABEL_PRODUCTS = 50;

/**
 * What the server prints when nothing is saved yet (PrintSettings::SCHEMA labels). Used only to
 * draw the preview for someone who cannot read the saved settings; for them only the options
 * changed here are sent, and the server fills the rest from the saved settings.
 */
const FALLBACK: LabelsPrint = { template: 'roll_50x25', show_name: true, show_options: true, show_price: true, show_store: true, show_digits: true, generate_missing: true };
/** Labels per A4 sheet — LabelTemplate::perPage(); a count for the summary, not a layout. */
const PER_SHEET: Partial<Record<LabelPaper, number>> = { a4_65: 65, a4_24: 24 };

const SHOWS = [
    { field: 'show_name', label: 'printLabels.shows.name' },
    { field: 'show_options', label: 'printLabels.shows.options' },
    { field: 'show_price', label: 'printLabels.shows.price', hint: 'printLabels.shows.priceHint' },
    { field: 'show_store', label: 'printLabels.shows.store' },
    { field: 'show_digits', label: 'printLabels.shows.digits' },
] as const;

/** Variant rows: one grid for the header and every row, so the columns line up (≥ 1024). */
const ROW_GRID = 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.2fr)_6.5rem_8.5rem_8.5rem_4.5rem]';

type Row = {
    id: number;
    name: string;
    nameBn: string | null;
    variant: string | null;
    variantBn: string | null;
    sku: string;
    barcode: string | null;
    price: string;
    onHand: number | null;
    available: number | null;
};

/** `?ids=42,43` — the products to label, in order, de-duplicated and capped. */
function readIds(params: URLSearchParams): number[] {
    const ids = (params.get('ids') ?? '')
        .split(',')
        .map((part) => part.trim())
        .filter((part) => /^\d+$/.test(part))
        .map(Number);
    return [...new Set(ids)].slice(0, MAX_LABEL_PRODUCTS);
}

function toRows(product: Product): Row[] {
    return product.variants
        .filter((v) => v.status === 'active')
        .map((v) => ({
            id: v.id,
            name: product.name,
            nameBn: product.name_bn,
            variant: v.name,
            variantBn: v.name_bn,
            sku: v.sku,
            barcode: v.barcode,
            price: v.price,
            onHand: v.stock ? v.stock.on_hand : null,
            available: v.stock ? v.stock.available : null,
        }));
}

/** "1 variant" / "3 variants": the one/other pair of keys, picked by count. */
function plural(t: (key: MessageKey, values?: Record<string, string | number>) => string, count: number, one: MessageKey, other: MessageKey): string {
    return t(count === 1 ? one : other, { count });
}

/**
 * Print labels — its own page (owner request 2026-11-24), opened from the products list (the
 * selection, or one row's ⋯ menu) and from a product page, as `/products/labels?ids=…`.
 * Three numbered steps: 1 choose products and copies (the chosen products with their active
 * variants — SKU, barcode, the server's price, stock — and a picker to add more), 2 label
 * settings (paper, language, what the label shows, missing barcodes), 3 preview and print,
 * which stays in view beside the steps at ≥ 1280 (sticky) and comes last below that.
 *
 * Options start from Settings › Invoices, receipts & labels › Barcode labels when the user may
 * read them; otherwise only what is changed here is sent. The server lays out and prices every
 * label (rule 1); the only sums here are label and sheet counts.
 */
export function ProductLabelsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const ids = readIds(params);
    const canGenerate = can(me, 'catalog.update');
    const canReadSettings = can(me, 'settings.view');
    const settings = usePrintSettings(canReadSettings);
    const saved = canReadSettings ? settings.data?.labels : undefined;
    const [overrides, setOverrides] = useState<Partial<LabelsPrint>>({});
    const options: LabelsPrint = { ...FALLBACK, ...saved, ...overrides };
    const [labelLocale, setLabelLocale] = useState<'en' | 'bn'>(locale === 'bn' ? 'bn' : 'en');
    const [zoom, setZoom] = useState<LabelZoom>('actual');
    const set = <K extends keyof LabelsPrint>(field: K, value: LabelsPrint[K]) => setOverrides((current) => ({ ...current, [field]: value }));

    const products = useProductsById(ids, ids.length > 0);
    const loading = products.some((q) => q.isPending);
    const loaded = products.flatMap((q) => (q.data ? [q.data] : []));
    const [removed, setRemoved] = useState<number[]>([]);
    const [copies, setCopies] = useState<Record<number, string>>({});
    const [previewId, setPreviewId] = useState<number | null>(null);
    const [error, setError] = useState<string[]>([]);
    const print = usePrintLabels();
    const busy = print.isPending;

    const groups = useMemo(() => loaded.map((product) => ({ product, rows: toRows(product).filter((row) => !removed.includes(row.id)) })), [loaded, removed]);
    const rows = groups.flatMap((group) => group.rows);
    const display = (en: string, bnText: string | null) => (labelLocale === 'bn' ? (bnText ?? en) : en);

    const copiesOf = (id: number): number => {
        const raw = (copies[id] ?? '1').trim();
        return /^\d+$/.test(raw) ? Math.min(Number(raw), MAX_COPIES) : 0;
    };
    const setCopy = (id: number, value: string) => setCopies((current) => ({ ...current, [id]: value }));
    const generate = canGenerate && options.generate_missing;
    const missing = rows.filter((row) => !row.barcode);
    const printable = rows.filter((row) => copiesOf(row.id) > 0 && (row.barcode || generate));
    const total = printable.reduce((sum, row) => sum + copiesOf(row.id), 0);
    const paper = LABEL_PAPER[options.template];
    const perSheet = PER_SHEET[options.template];
    const sheets = perSheet ? Math.ceil(total / perSheet) : 0;
    const overLimit = printable.length > MAX_ITEMS || total > MAX_LABELS;
    const hasStock = rows.some((row) => row.onHand !== null);

    const previewRow = rows.find((row) => row.id === previewId) ?? rows[0];
    const preview: LabelContent | undefined = previewRow && {
        name: display(previewRow.name, previewRow.nameBn),
        options: previewRow.variant === null ? null : display(previewRow.variant, previewRow.variantBn),
        sku: previewRow.sku,
        price: formatMoney(previewRow.price),
        lang: labelLocale,
    };

    const fillAll = (value: (row: Row) => number) => setCopies(Object.fromEntries(rows.map((row) => [row.id, String(Math.max(0, Math.min(value(row), MAX_COPIES)))])));
    const [bulk, setBulk] = useState('1');
    const bulkValue = /^\d+$/.test(bulk.trim()) ? Number(bulk.trim()) : null;
    const [picking, setPicking] = useState(false);
    const pickerOpen = picking || (ids.length === 0 && !loading);

    const setIds = (next: number[]) =>
        setParams((current) => {
            const out = new URLSearchParams(current);
            if (next.length > 0) out.set('ids', next.join(','));
            else out.delete('ids');
            return out;
        });
    const addProducts = (more: number[]) => {
        setIds([...ids, ...more.filter((id) => !ids.includes(id))].slice(0, MAX_LABEL_PRODUCTS));
        setPicking(false);
    };

    const submit = async () => {
        setError([]);
        // With the saved settings readable, send every option as shown; without, only what changed here.
        const sent: Partial<LabelsPrint> = saved ? options : overrides;
        const input: PrintLabelsInput = {
            ...(sent.template ? { template: sent.template } : {}),
            items: printable.map((row) => ({ variant_id: row.id, copies: copiesOf(row.id) })),
            ...(sent.show_name === undefined ? {} : { show_name: sent.show_name }),
            ...(sent.show_options === undefined ? {} : { show_options: sent.show_options }),
            ...(sent.show_price === undefined ? {} : { show_price: sent.show_price }),
            ...(sent.show_store === undefined ? {} : { show_store: sent.show_store }),
            ...(sent.show_digits === undefined ? {} : { show_digits: sent.show_digits }),
            locale: labelLocale,
            // Always sent: omitted, the server would apply the saved "create missing barcodes".
            generate_missing: generate && printable.some((row) => !row.barcode),
        };
        try {
            await print.mutateAsync(input);
        } catch (caught) {
            if (caught instanceof ApiError) {
                const messages = (caught.problem.errors ?? []).map((e) => {
                    const match = /^items\.(\d+)\./.exec(e.field);
                    const row = match ? printable[Number(match[1])] : undefined;
                    return row ? `${row.sku}: ${e.message}` : e.message;
                });
                setError(messages.length > 0 ? messages : [caught.problem.detail ?? caught.problem.title]);
            } else {
                setError([t('error.title')]);
            }
        }
    };

    // Why Print is unavailable, said in words rather than a silently grey button.
    const blocked = loading ? null : rows.length === 0 ? t('labelsPage.blocked.empty') : total === 0 ? (missing.length > 0 && !generate ? t('labelsPage.blocked.barcodes') : t('labelsPage.blocked.copies')) : null;

    return (
        <section className="flex min-w-0 flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('catalog.tab.products'), to: '/products' }, { label: t('products.labels.open') }]}
                title={t('products.labels.title')}
                subtitle={t('labelsPage.subtitle')}
                actions={
                    <Link to="/products" className={buttonClass('outline')}>
                        <ArrowLeftIcon size={16} aria-hidden />
                        {t('labelsPage.back')}
                    </Link>
                }
            />

            <div className="grid min-w-0 grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,25rem)]">
                <div className="flex min-w-0 flex-col gap-6">
                    {/* Step 1 — products and copies */}
                    <Step
                        n={1}
                        title={t('labelsPage.step.products')}
                        hint={rows.length > 0 ? `${plural(t, groups.length, 'labelsPage.productsOne', 'labelsPage.productsMany')} · ${plural(t, rows.length, 'labelsPage.variantTotalOne', 'labelsPage.variantTotal')}` : t('labelsPage.step.productsHint')}
                        action={
                            !pickerOpen && (
                                <Button variant="outline" size="sm" onClick={() => setPicking(true)} disabled={ids.length >= MAX_LABEL_PRODUCTS}>
                                    <PlusIcon size={16} aria-hidden />
                                    {t('printLabels.job.add')}
                                </Button>
                            )
                        }
                    >
                        {rows.length > 0 && (
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line-default bg-subtle px-4 py-3 sm:px-6">
                                <span className="t-admin-caption-strong text-fg-secondary">{t('labelsPage.quick')}</span>
                                <div className="flex items-center gap-2">
                                    <label className="flex items-center gap-2 t-admin-caption text-fg-secondary">
                                        {t('labelsPage.setAll')}
                                        <input
                                            type="number"
                                            min={0}
                                            max={MAX_COPIES}
                                            inputMode="numeric"
                                            value={bulk}
                                            onChange={(e) => setBulk(e.target.value)}
                                            disabled={busy}
                                            className={`${controlHeight.sm} ${inputClass} w-16 text-end tabular`}
                                        />
                                    </label>
                                    <Button variant="outline" size="sm" onClick={() => bulkValue !== null && fillAll(() => bulkValue)} disabled={busy || bulkValue === null}>
                                        {t('labelsPage.apply')}
                                    </Button>
                                </div>
                                {hasStock && (
                                    <Button variant="ghost" size="sm" onClick={() => fillAll((row) => row.onHand ?? 0)} disabled={busy}>
                                        {t('products.labels.useStock')}
                                    </Button>
                                )}
                            </div>
                        )}

                        {loading && (
                            <p aria-busy="true" className="px-4 py-4 t-admin-caption text-fg-tertiary sm:px-6">
                                {t('printLabels.job.loading')}
                            </p>
                        )}
                        {!loading && rows.length === 0 && !pickerOpen && <p className="px-4 py-6 t-admin-body text-fg-secondary sm:px-6">{t('printLabels.job.empty')}</p>}

                        {groups.length > 0 && (
                            <div aria-hidden className={`${ROW_GRID} hidden border-b border-line-default px-6 py-2 t-admin-overline text-fg-secondary lg:grid`}>
                                <span>{t('labelsPage.col.variant')}</span>
                                <span>{t('labelsPage.col.barcode')}</span>
                                <span className="text-end">{t('labelsPage.col.price')}</span>
                                <span className="text-end">{t('labelsPage.col.stock')}</span>
                                <span className="text-center">{t('labelsPage.col.copies')}</span>
                                <span />
                            </div>
                        )}

                        {groups.map(({ product, rows: productRows }) => (
                            <ProductGroup
                                key={product.id}
                                product={product}
                                rows={productRows}
                                copies={copies}
                                setCopy={setCopy}
                                copiesOf={copiesOf}
                                previewId={previewRow?.id ?? null}
                                onPreview={setPreviewId}
                                onRemoveVariant={(id) => setRemoved((current) => [...current, id])}
                                onRemoveProduct={() => setIds(ids.filter((id) => id !== product.id))}
                                busy={busy}
                            />
                        ))}

                        {pickerOpen && <ProductPicker chosen={ids} onAdd={addProducts} onClose={ids.length > 0 ? () => setPicking(false) : undefined} />}
                    </Step>

                    {/* Step 2 — label settings */}
                    <Step n={2} title={t('labelsPage.step.settings')} hint={t('printLabels.paper.hint')}>
                        <div className="grid gap-6 p-4 sm:p-6 lg:grid-cols-2">
                            <fieldset className="flex min-w-0 flex-col gap-3">
                                <legend className="mb-3 t-admin-body-strong text-fg-primary">{t('printLabels.paper.title')}</legend>
                                <PaperChoice value={options.template} onChange={(value: LabelPaper) => set('template', value)} disabled={busy} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2" />
                            </fieldset>
                            <div className="flex min-w-0 flex-col gap-6">
                                <div className="flex flex-col gap-3">
                                    <h3 className="t-admin-body-strong text-fg-primary">{t('products.labels.language')}</h3>
                                    <SegmentedControl
                                        label={t('products.labels.language')}
                                        value={labelLocale}
                                        onChange={setLabelLocale}
                                        className="self-start"
                                        options={[
                                            { value: 'en', label: 'English', lang: 'en' },
                                            { value: 'bn', label: 'বাংলা', lang: 'bn' },
                                        ]}
                                    />
                                </div>
                                <div className="flex flex-col gap-3">
                                    <h3 className="t-admin-body-strong text-fg-primary">{t('printLabels.shows.title')}</h3>
                                    <div className="flex flex-col gap-3 rounded-sm border border-line-default p-4">
                                        {SHOWS.map((row) => (
                                            <ToggleRow key={row.field} label={t(row.label)} hint={'hint' in row ? t(row.hint) : undefined} checked={options[row.field]} onChange={(value) => set(row.field, value)} disabled={busy} />
                                        ))}
                                    </div>
                                </div>
                                {missing.length > 0 && (
                                    <div role="note" className="flex flex-col gap-3 rounded-sm border border-line-default bg-warning-subtle p-4">
                                        <p className="flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
                                            <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                                            {plural(t, missing.length, 'labelsPage.missingOne', 'products.labels.missing')}
                                        </p>
                                        {canGenerate ? (
                                            <ToggleRow label={t('products.labels.generateFirst')} hint={t('printLabels.barcodes.hint')} checked={options.generate_missing} onChange={(value) => set('generate_missing', value)} disabled={busy} />
                                        ) : (
                                            <p className="t-admin-caption text-fg-secondary">{t('products.labels.missingSkipped')}</p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </Step>
                </div>

                {/* Step 3 — preview and print, kept in view at ≥ 1280 */}
                <div className="min-w-0 xl:sticky xl:top-6">
                    <Step
                        n={3}
                        title={t('labelsPage.step.print')}
                        action={
                            <SegmentedControl
                                label={t('labelsPage.zoom')}
                                value={zoom}
                                onChange={setZoom}
                                options={[
                                    { value: 'actual', label: t('labelsPage.zoom.actual') },
                                    { value: 'enlarged', label: t('labelsPage.zoom.enlarged') },
                                ]}
                            />
                        }
                    >
                        <div className="flex flex-col gap-4 p-4 sm:p-6">
                            <figure className="flex min-w-0 flex-col gap-2">
                                <div className="flex min-h-44 items-center justify-center overflow-x-auto rounded-sm bg-subtle p-6">
                                    <LabelPreview value={options} content={preview} zoom={zoom} />
                                </div>
                                <figcaption className="flex flex-col gap-1">
                                    <span className="t-admin-caption-strong tabular text-fg-primary">
                                        {paper.sheet ? t('labelsPage.previewCaption', { paper: t(`printLabels.paper.${options.template}`), width: paper.width, height: paper.height }) : t(`printLabels.paper.${options.template}`)}
                                    </span>
                                    {previewRow && (
                                        <span lang={labelLocale === 'bn' ? 'bn' : undefined} className="t-admin-caption text-fg-secondary">
                                            {t('labelsPage.previewing', { name: preview?.options ? `${preview.name} — ${preview.options}` : (preview?.name ?? '') })}
                                        </span>
                                    )}
                                    <span className="t-admin-caption text-fg-tertiary">{zoom === 'actual' ? t('labelsPage.actualNote') : t('labelsPage.previewNote')}</span>
                                </figcaption>
                            </figure>

                            <dl className="grid grid-cols-3 divide-x divide-line-default rounded-sm border border-line-default">
                                <Stat label={t('labelsPage.stat.variants')} value={printable.length} />
                                <Stat label={t('labelsPage.stat.labels')} value={total} />
                                {perSheet ? <Stat label={t('labelsPage.stat.sheets')} value={sheets} /> : <Stat label={t('labelsPage.stat.paper')} value={t('labelsPage.stat.roll')} />}
                            </dl>

                            {overLimit && (
                                <p role="note" className="t-admin-caption text-feedback-danger">
                                    {t('labelsPage.overLimit', { items: MAX_ITEMS, labels: MAX_LABELS })}
                                </p>
                            )}
                            {error.length > 0 && (
                                <div role="alert" className="flex flex-col gap-1 rounded-md bg-danger-subtle px-4 py-3">
                                    {error.map((message) => (
                                        <p key={message} className="t-admin-caption-strong text-fg-primary">
                                            {message}
                                        </p>
                                    ))}
                                </div>
                            )}

                            <Button size="lg" onClick={() => void submit()} disabled={busy || loading || total === 0 || overLimit} className="w-full">
                                <PrinterIcon size={18} aria-hidden />
                                {busy ? t('products.labels.printing') : plural(t, total, 'labelsPage.printOne', 'printLabels.job.print')}
                            </Button>
                            {blocked && <p className="t-admin-caption text-fg-secondary">{blocked}</p>}
                            {!saved && <p className="t-admin-caption text-fg-tertiary">{t('printLabels.dialog.followsSettings')}</p>}
                        </div>
                    </Step>
                </div>
            </div>
        </section>
    );
}

/** A numbered step card: number badge, title, optional hint and header action. */
function Step({ n, title, hint, action, children }: { n: number; title: string; hint?: string; action?: ReactNode; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
            <div className="flex flex-wrap items-center gap-3 border-b border-line-default px-4 py-4 sm:px-6">
                <span aria-hidden className="grid size-7 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand tabular">
                    {n}
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id={id} className="t-admin-body-strong text-fg-primary">
                        {title}
                    </h2>
                    {hint && <p className="t-admin-caption text-fg-secondary">{hint}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Stat({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="flex min-w-0 flex-col items-center gap-0.5 px-2 py-3 text-center">
            <dt className="t-admin-caption text-fg-secondary">{label}</dt>
            <dd className="t-admin-num tabular text-fg-primary">{value}</dd>
        </div>
    );
}

type GroupProps = {
    product: Product;
    rows: Row[];
    copies: Record<number, string>;
    setCopy: (id: number, value: string) => void;
    copiesOf: (id: number) => number;
    previewId: number | null;
    onPreview: (id: number) => void;
    onRemoveVariant: (id: number) => void;
    onRemoveProduct: () => void;
    busy: boolean;
};

/** One chosen product: a header (image, name, status, remove) over its variant rows. */
function ProductGroup({ product, rows, copies, setCopy, copiesOf, previewId, onPreview, onRemoveVariant, onRemoveProduct, busy }: GroupProps) {
    const { t, locale } = useI18n();
    const uiName = (en: string, bn: string | null) => (locale === 'bn' ? (bn ?? en) : en);
    const name = uiName(product.name, product.name_bn);
    const image = product.images[0]?.urls.thumb ?? product.images[0]?.url ?? null;

    return (
        <div className="flex min-w-0 flex-col border-b border-line-default last:border-b-0">
            <div className="flex items-center gap-3 px-4 pt-4 pb-2 sm:px-6">
                {image ? (
                    <img src={image} alt="" className="size-10 shrink-0 rounded-xs-plus object-cover" />
                ) : (
                    <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                        <ImageIcon size={18} />
                    </span>
                )}
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <Link to={`/products/${product.id}`} lang={locale === 'bn' && product.name_bn ? 'bn' : undefined} className="truncate t-admin-body-strong text-fg-primary hover:text-fg-brand">
                        {name}
                    </Link>
                    <p className="t-admin-caption text-fg-secondary">{plural(t, rows.length, 'labelsPage.variantCountOne', 'labelsPage.variantCount')}</p>
                </div>
                <ProductStatusChip status={product.status} />
                <IconButton size="sm" aria-label={t('printLabels.job.remove', { name })} onClick={onRemoveProduct} disabled={busy}>
                    <XIcon size={16} aria-hidden />
                </IconButton>
            </div>
            <ul className="flex flex-col pb-2">
                {rows.map((row) => {
                    const label = row.variant ? `${name} — ${uiName(row.variant, row.variantBn)}` : name;
                    const previewing = row.id === previewId;
                    const count = copiesOf(row.id);
                    return (
                        <li key={row.id} className={`${ROW_GRID} mx-2 rounded-sm px-2 py-2.5 sm:mx-4 ${previewing ? 'bg-brand-subtle' : ''}`}>
                            <div className="min-w-0">
                                <p lang={locale === 'bn' && row.variantBn ? 'bn' : undefined} className="truncate t-admin-body text-fg-primary">
                                    {row.variant ? uiName(row.variant, row.variantBn) : t('labelsPage.single')}
                                </p>
                                <p className="truncate t-admin-mono-sm text-fg-tertiary">{row.sku}</p>
                            </div>
                            {/* Below 1024 the facts share one line under the name; at ≥ 1024 each is its own column. */}
                            <div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 lg:contents">
                                <span className="min-w-0">
                                    {row.barcode ? (
                                        <span className="t-admin-mono-sm text-fg-primary">{row.barcode}</span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 t-admin-caption text-fg-secondary">
                                            <WarningIcon size={14} aria-hidden className="text-feedback-warning" />
                                            {t('products.labels.noBarcode')}
                                        </span>
                                    )}
                                </span>
                                <span className="t-admin-num tabular whitespace-nowrap lg:text-end">
                                    <Taka amount={formatMoney(row.price)} />
                                </span>
                                <span className="t-admin-caption tabular whitespace-nowrap text-fg-secondary lg:text-end">
                                    {row.onHand === null ? '—' : t('labelsPage.stock', { onHand: row.onHand, available: row.available ?? 0 })}
                                </span>
                            </div>
                            <div className="flex items-center gap-2 max-lg:col-start-1 lg:justify-center">
                                <span aria-hidden className="t-admin-caption text-fg-secondary lg:hidden">
                                    {t('labelsPage.col.copies')}
                                </span>
                                <div className="inline-flex items-center rounded-sm border border-line-default bg-surface">
                                    <IconButton size="sm" aria-label={t('labelsPage.less', { name: label })} onClick={() => setCopy(row.id, String(Math.max(0, count - 1)))} disabled={busy || count === 0}>
                                        <MinusIcon size={14} aria-hidden />
                                    </IconButton>
                                    <input
                                        type="number"
                                        min={0}
                                        max={MAX_COPIES}
                                        inputMode="numeric"
                                        aria-label={t('products.labels.copiesFor', { variant: label })}
                                        value={copies[row.id] ?? '1'}
                                        onChange={(e) => setCopy(row.id, e.target.value)}
                                        disabled={busy}
                                        className={`${controlHeight.sm} w-12 border-0 bg-transparent text-center t-admin-body tabular text-fg-primary outline-none [appearance:textfield] focus-visible:ring-2 focus-visible:ring-line-focus [&::-webkit-inner-spin-button]:appearance-none`}
                                    />
                                    <IconButton size="sm" aria-label={t('labelsPage.more', { name: label })} onClick={() => setCopy(row.id, String(Math.min(MAX_COPIES, count + 1)))} disabled={busy || count >= MAX_COPIES}>
                                        <PlusIcon size={14} aria-hidden />
                                    </IconButton>
                                </div>
                            </div>
                            <div className="flex justify-end gap-1 max-lg:col-start-2 max-lg:row-start-1">
                                <IconButton size="sm" aria-label={t('labelsPage.preview', { name: label })} aria-pressed={previewing} onClick={() => onPreview(row.id)}>
                                    <EyeIcon size={16} aria-hidden />
                                </IconButton>
                                <IconButton size="sm" aria-label={t('printLabels.job.remove', { name: label })} onClick={() => onRemoveVariant(row.id)} disabled={busy}>
                                    <XIcon size={16} aria-hidden />
                                </IconButton>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * Add products, inside step 1: the catalogue list (server search and paging, newest first) with
 * a checkbox per product; tick several, then add them in one go. Already-chosen ones show Added.
 */
function ProductPicker({ chosen, onAdd, onClose }: { chosen: number[]; onAdd: (ids: number[]) => void; onClose?: () => void }) {
    const { t, locale } = useI18n();
    const titleId = useId();
    const [term, setTerm] = useState('');
    const search = useDebouncedValue(term.trim(), 250);
    const list = useProducts({ search, status: '', type: '', brand: '', category: '', sort: '-updated_at' });
    const items = list.data?.pages.flatMap((page) => page.data) ?? [];
    const [ticked, setTicked] = useState<number[]>([]);
    const room = MAX_LABEL_PRODUCTS - chosen.length;
    const toggle = (id: number) => setTicked((current) => (current.includes(id) ? current.filter((x) => x !== id) : current.length < room ? [...current, id] : current));

    return (
        <section aria-labelledby={titleId} className="flex min-w-0 flex-col border-t border-line-default bg-subtle">
            <div className="flex flex-wrap items-center gap-3 px-4 pt-4 sm:px-6">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h3 id={titleId} className="t-admin-body-strong text-fg-primary">
                        {t('labelsPage.picker.title')}
                    </h3>
                    <p className="t-admin-caption text-fg-secondary">{room <= 0 ? t('products.labels.tooMany', { max: MAX_LABEL_PRODUCTS }) : t('labelsPage.picker.hint', { count: room })}</p>
                </div>
                {onClose && (
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        {t('printLabels.job.done')}
                    </Button>
                )}
                <Button
                    size="sm"
                    onClick={() => {
                        onAdd(ticked);
                        setTicked([]);
                    }}
                    disabled={ticked.length === 0}
                >
                    <PlusIcon size={16} aria-hidden />
                    {t('labelsPage.picker.add', { count: ticked.length })}
                </Button>
            </div>
            <div className="px-4 pt-3 sm:px-6">
                <SearchField label={t('printLabels.job.search')} value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('printLabels.job.searchPlaceholder')} className="w-full" />
            </div>
            {list.isPending ? (
                <p aria-busy="true" className="px-4 py-4 t-admin-caption text-fg-tertiary sm:px-6">
                    {t('common.loading')}
                </p>
            ) : items.length === 0 ? (
                <p className="px-4 py-4 t-admin-body text-fg-secondary sm:px-6">{t('products.emptyFiltered')}</p>
            ) : (
                <ul aria-label={t('printLabels.job.results')} className="m-4 flex max-h-96 flex-col overflow-y-auto rounded-sm border border-line-default bg-surface sm:mx-6">
                    {items.map((item) => {
                        const added = chosen.includes(item.id);
                        const checked = added || ticked.includes(item.id);
                        const name = locale === 'bn' ? (item.name_bn ?? item.name) : item.name;
                        return (
                            <li key={item.id} className="border-b border-line-default last:border-b-0">
                                <label className={`flex items-center gap-3 px-4 py-2.5 ${added ? 'cursor-default' : 'cursor-pointer hover:bg-subtle'} ${checked && !added ? 'bg-brand-subtle' : ''}`}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        disabled={added || (!checked && ticked.length >= room)}
                                        onChange={() => toggle(item.id)}
                                        aria-label={t('products.select', { name: item.name })}
                                        className="size-4 accent-brand"
                                    />
                                    {item.image_url ? (
                                        <img src={item.image_url} alt="" className="size-9 shrink-0 rounded-xs-plus object-cover" />
                                    ) : (
                                        <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xs-plus bg-subtle text-fg-tertiary">
                                            <ImageIcon size={16} />
                                        </span>
                                    )}
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span lang={locale === 'bn' && item.name_bn ? 'bn' : undefined} className="truncate t-admin-body text-fg-primary">
                                            {name}
                                        </span>
                                        <span className="truncate t-admin-mono-sm text-fg-tertiary">
                                            {[item.sku, plural(t, item.variants_count, 'labelsPage.variantTotalOne', 'labelsPage.variantTotal')].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    {added ? (
                                        <span className="inline-flex items-center gap-1 t-admin-caption text-feedback-success">
                                            <CheckIcon size={14} aria-hidden />
                                            {t('labelsPage.picker.added')}
                                        </span>
                                    ) : (
                                        <span className="max-sm:hidden">
                                            <ProductStatusChip status={item.status} />
                                        </span>
                                    )}
                                </label>
                            </li>
                        );
                    })}
                    {list.hasNextPage && (
                        <li className="flex justify-center px-4 py-3">
                            <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                                {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                            </Button>
                        </li>
                    )}
                </ul>
            )}
        </section>
    );
}
