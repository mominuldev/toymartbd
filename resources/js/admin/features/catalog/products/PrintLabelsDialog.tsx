import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { PrinterIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { ApiError } from '@/lib/api';
import { useLabelTemplates, usePrintLabels, type LabelTemplate, type Product } from './api';

/** App\Http\Requests\Admin\Product\PrintLabelsRequest caps — the server enforces them; these only keep the inputs in range. */
const MAX_COPIES = 500;

const templateLabel: Record<LabelTemplate, MessageKey> = {
    roll_38x25: 'products.labels.template.roll_38x25',
    roll_50x25: 'products.labels.template.roll_50x25',
    a4_65: 'products.labels.template.a4_65',
    a4_24: 'products.labels.template.a4_24',
};

type Row = { id: number; name: string; variant: string | null; sku: string; barcode: string | null; onHand: number | null };

type Props = {
    open: boolean;
    onClose: () => void;
    /** Full product documents; their active variants are the rows. */
    products: Product[];
    /** Still fetching some of `products` (list selection). */
    loading?: boolean;
    /** catalog.update — may generate missing barcodes first. */
    canGenerate: boolean;
    /**
     * On the product form: generate through the form so it picks the codes up, instead of the
     * print request's `generate_missing` (which would leave the open form with stale blanks).
     */
    onGenerateMissing?(variantIds: number[]): Promise<void>;
};

/**
 * "Print labels" (owner request 2026-11-16). The A23 dialog frame like Import / export:
 * template with its size, copies per variant (or each variant's stock on hand when the admin
 * data carries it), show price / show name, label language, and the variants without a
 * barcode — skipped, or generated first by someone with catalog.update. The server lays out
 * and prices every label; this only sends the choices and downloads the PDF.
 */
export function PrintLabelsDialog({ open, onClose, products, loading = false, canGenerate, onGenerateMissing }: Props) {
    const { t, locale } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const firstField = useRef<HTMLSelectElement>(null);
    const titleId = useId();
    const templates = useLabelTemplates(open);
    const print = usePrintLabels();
    const [template, setTemplate] = useState<LabelTemplate>('roll_38x25');
    const [copies, setCopies] = useState<Record<number, string>>({});
    const [showPrice, setShowPrice] = useState(true);
    const [showName, setShowName] = useState(true);
    const [labelLocale, setLabelLocale] = useState<'en' | 'bn'>(locale === 'bn' ? 'bn' : 'en');
    const [generate, setGenerate] = useState(canGenerate);
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState<string[]>([]);

    const rows: Row[] = useMemo(
        () =>
            products.flatMap((product) =>
                product.variants
                    .filter((v) => v.status === 'active')
                    .map((v) => ({
                        id: v.id,
                        name: locale === 'bn' ? (product.name_bn ?? product.name) : product.name,
                        variant: locale === 'bn' ? (v.name_bn ?? v.name) : v.name,
                        sku: v.sku,
                        barcode: v.barcode,
                        onHand: v.stock ? v.stock.on_hand : null,
                    })),
            ),
        [products, locale],
    );

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setError([]);
            print.reset();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            firstField.current?.focus();
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const copiesOf = (id: number): number => {
        const raw = copies[id] ?? '1';
        const n = /^\d+$/.test(raw.trim()) ? Number(raw.trim()) : 0;
        return Math.min(n, MAX_COPIES);
    };
    const missing = rows.filter((row) => !row.barcode);
    const willGenerate = canGenerate && generate;
    const printable = rows.filter((row) => copiesOf(row.id) > 0 && (row.barcode || willGenerate));
    const total = printable.reduce((sum, row) => sum + copiesOf(row.id), 0);
    const hasStock = rows.some((row) => row.onHand !== null);
    const busy = print.isPending || generating;

    const fillFromStock = () => setCopies(Object.fromEntries(rows.map((row) => [row.id, String(Math.max(0, Math.min(row.onHand ?? 0, MAX_COPIES)))])));

    const submit = async () => {
        setError([]);
        const toGenerate = willGenerate ? printable.filter((row) => !row.barcode).map((row) => row.id) : [];
        try {
            if (toGenerate.length > 0 && onGenerateMissing) {
                setGenerating(true);
                await onGenerateMissing(toGenerate);
            }
            await print.mutateAsync({
                template,
                items: printable.map((row) => ({ variant_id: row.id, copies: copiesOf(row.id) })),
                show_price: showPrice,
                show_name: showName,
                locale: labelLocale,
                generate_missing: toGenerate.length > 0 && !onGenerateMissing ? true : undefined,
            });
            onClose();
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
        } finally {
            setGenerating(false);
        }
    };

    return createPortal(
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                if (!busy) onClose();
            }}
            className="m-auto w-[min(100%-2rem,48rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            {open && (
                <>
                    <div className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto px-6 pt-6 pb-4">
                        <h2 id={titleId} className="t-admin-section text-fg-primary">
                            {t('products.labels.title')}
                        </h2>

                        <div className="grid gap-3 md:grid-cols-2">
                            <label className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('products.labels.template')}</span>
                                <Select ref={firstField} value={template} onChange={(e) => setTemplate(e.target.value as LabelTemplate)} disabled={busy}>
                                    {(templates.data ?? []).map((info) => (
                                        <option key={info.value} value={info.value}>
                                            {t('products.labels.templateOption', { name: t(templateLabel[info.value]), width: info.width_mm, height: info.height_mm })}
                                        </option>
                                    ))}
                                </Select>
                            </label>
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('products.labels.language')}</span>
                                <SegmentedControl
                                    label={t('products.labels.language')}
                                    value={labelLocale}
                                    onChange={setLabelLocale}
                                    className="self-start"
                                    options={[
                                        { value: 'en', label: 'EN', lang: 'en' },
                                        { value: 'bn', label: 'বাং', lang: 'bn' },
                                    ]}
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            <label className="flex items-center gap-2 t-admin-body text-fg-primary">
                                <input type="checkbox" checked={showName} onChange={(e) => setShowName(e.target.checked)} disabled={busy} className="size-4 accent-brand" />
                                {t('products.labels.showName')}
                            </label>
                            <label className="flex items-center gap-2 t-admin-body text-fg-primary">
                                <input type="checkbox" checked={showPrice} onChange={(e) => setShowPrice(e.target.checked)} disabled={busy} className="size-4 accent-brand" />
                                {t('products.labels.showPrice')}
                            </label>
                        </div>

                        {missing.length > 0 && (
                            <div role="note" className="flex flex-col gap-2 rounded-md border border-line-default bg-warning-subtle px-4 py-3">
                                <p className="flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
                                    <WarningIcon size={16} aria-hidden className="shrink-0 text-feedback-warning" />
                                    {t('products.labels.missing', { count: missing.length })}
                                </p>
                                <p className="t-admin-caption text-fg-secondary">{missing.map((row) => (row.variant ? `${row.name} — ${row.variant}` : row.name)).join(' · ')}</p>
                                {canGenerate ? (
                                    <label className="flex items-center gap-2 t-admin-body text-fg-primary">
                                        <input type="checkbox" checked={generate} onChange={(e) => setGenerate(e.target.checked)} disabled={busy} className="size-4 accent-brand" />
                                        {t('products.labels.generateFirst')}
                                    </label>
                                ) : (
                                    <p className="t-admin-caption text-fg-secondary">{t('products.labels.missingSkipped')}</p>
                                )}
                            </div>
                        )}

                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center gap-3">
                                <h3 className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t('products.labels.variants')}</h3>
                                {hasStock && (
                                    <Button variant="ghost" size="sm" onClick={fillFromStock} disabled={busy}>
                                        {t('products.labels.useStock')}
                                    </Button>
                                )}
                            </div>
                            {loading && (
                                <p aria-busy="true" className="t-admin-caption text-fg-tertiary">
                                    {t('common.loading')}
                                </p>
                            )}
                            <ul className="flex flex-col divide-y divide-line-default rounded-md border border-line-default">
                                {rows.map((row) => {
                                    const label = row.variant ? `${row.name} — ${row.variant}` : row.name;
                                    return (
                                        <li key={row.id} className="flex items-center gap-3 px-3 py-2">
                                            <div className="flex min-w-0 flex-1 flex-col">
                                                <span lang={locale === 'bn' ? 'bn' : undefined} className="truncate t-admin-body text-fg-primary">
                                                    {label}
                                                </span>
                                                <span className="truncate t-admin-mono-sm text-fg-tertiary">
                                                    {row.sku} · {row.barcode ?? t('products.labels.noBarcode')}
                                                </span>
                                            </div>
                                            {row.onHand !== null && <span className="t-admin-caption tabular text-fg-secondary">{t('products.labels.onHand', { count: row.onHand })}</span>}
                                            <div className="w-20">
                                                <input
                                                    type="number"
                                                    min={0}
                                                    max={MAX_COPIES}
                                                    inputMode="numeric"
                                                    aria-label={t('products.labels.copiesFor', { variant: label })}
                                                    value={copies[row.id] ?? '1'}
                                                    onChange={(e) => setCopies((current) => ({ ...current, [row.id]: e.target.value }))}
                                                    disabled={busy}
                                                    className={`${controlHeight.sm} ${inputClass} text-end tabular`}
                                                />
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>

                        {error.length > 0 && (
                            <div role="alert" className="flex flex-col gap-1 rounded-md bg-danger-subtle px-4 py-3">
                                {error.map((message) => (
                                    <p key={message} className="t-admin-caption-strong text-fg-primary">
                                        {message}
                                    </p>
                                ))}
                            </div>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-3 border-t border-line-default px-6 py-4">
                        <p className="me-auto t-admin-caption tabular text-fg-secondary" aria-live="polite">
                            {t('products.labels.total', { count: total })}
                        </p>
                        <Button variant="outline" onClick={onClose} disabled={busy}>
                            {t('products.labels.cancel')}
                        </Button>
                        <Button onClick={() => void submit()} disabled={busy || loading || total === 0}>
                            <PrinterIcon size={16} aria-hidden />
                            {busy ? t('products.labels.printing') : t('products.labels.print')}
                        </Button>
                    </div>
                </>
            )}
        </dialog>,
        document.body,
    );
}
