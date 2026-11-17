import { useId, useMemo, useState } from 'react';
import { PlusIcon, PrinterIcon, XIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { IconButton } from '@/components/ui/IconButton';
import { SearchField } from '@/components/ui/SearchField';
import { useDebouncedValue, useProductSearch, useProductsById, usePrintLabels, type PrintLabelsInput } from '@/features/catalog/products/api';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import type { LabelsPrint } from './api';

/** PrintLabelsRequest's per-row cap — the server enforces it; this only keeps the input in range. */
const MAX_COPIES = 500;

type Row = { id: number; name: string; sku: string; barcode: string | null };

/**
 * "Print labels" beside the preview (Figma 529:82666): add products (the product search the
 * relation picker uses; each product brings its active variants), copies per row, the label
 * count, and Print — POST /products/labels with the options as they stand on the left, saved
 * or not, then the PDF downloads like the product page's dialog. The server prices and lays
 * out every label; counting copies is the only sum here.
 */
export function LabelsPrintJob({ value, canGenerate }: { value: LabelsPrint; canGenerate: boolean }) {
    const { t, locale } = useI18n();
    const [adding, setAdding] = useState(false);
    const [term, setTerm] = useState('');
    const [productIds, setProductIds] = useState<number[]>([]);
    const [removed, setRemoved] = useState<number[]>([]);
    const [copies, setCopies] = useState<Record<number, string>>({});
    const [error, setError] = useState<string[]>([]);
    const search = useProductSearch(useDebouncedValue(term.trim(), 250));
    const products = useProductsById(productIds, productIds.length > 0);
    const print = usePrintLabels();
    const titleId = useId();

    const loading = products.some((q) => q.isPending);
    const rows: Row[] = useMemo(
        () =>
            products.flatMap((q) => {
                const product = q.data;
                if (!product) return [];
                const name = locale === 'bn' ? (product.name_bn ?? product.name) : product.name;
                return product.variants
                    .filter((v) => v.status === 'active' && !removed.includes(v.id))
                    .map((v) => {
                        const option = locale === 'bn' ? (v.name_bn ?? v.name) : v.name;
                        return { id: v.id, name: option ? `${name} · ${option}` : name, sku: v.sku, barcode: v.barcode };
                    });
            }),
        [products, locale, removed],
    );
    const results = term.trim().length >= 2 ? (search.data ?? []).filter((p) => !productIds.includes(p.id)).slice(0, 8) : [];

    const copiesOf = (id: number): number => {
        const raw = (copies[id] ?? '1').trim();
        return /^\d+$/.test(raw) ? Math.min(Number(raw), MAX_COPIES) : 0;
    };
    const generate = canGenerate && value.generate_missing;
    const printable = rows.filter((row) => copiesOf(row.id) > 0 && (row.barcode || generate));
    const count = printable.reduce((sum, row) => sum + copiesOf(row.id), 0);

    const submit = async () => {
        setError([]);
        const input: PrintLabelsInput = {
            template: value.template,
            items: printable.map((row) => ({ variant_id: row.id, copies: copiesOf(row.id) })),
            show_name: value.show_name,
            show_options: value.show_options,
            show_price: value.show_price,
            show_store: value.show_store,
            show_digits: value.show_digits,
            locale: locale === 'bn' ? 'bn' : 'en',
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

    return (
        <section aria-labelledby={titleId} className="flex w-full min-w-0 flex-col gap-4 rounded-md border border-line-default bg-surface p-6 shadow-e1">
            <h2 id={titleId} className="t-admin-body-strong text-fg-primary">
                {t('printLabels.job.title')}
            </h2>

            {rows.length === 0 && !loading && <p className="t-admin-caption text-fg-secondary">{t('printLabels.job.empty')}</p>}
            {loading && (
                <p aria-busy="true" className="t-admin-caption text-fg-tertiary">
                    {t('printLabels.job.loading')}
                </p>
            )}

            {rows.length > 0 && (
                <ul className="flex flex-col gap-3">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center gap-3">
                            <div className="flex min-w-0 flex-1 flex-col">
                                <span lang={locale === 'bn' ? 'bn' : undefined} className="truncate t-admin-body text-fg-primary">
                                    {row.name}
                                </span>
                                {!row.barcode && <span className="t-admin-caption text-fg-tertiary">{t('printLabels.job.noBarcode')}</span>}
                            </div>
                            <input
                                type="number"
                                min={0}
                                max={MAX_COPIES}
                                inputMode="numeric"
                                aria-label={t('printLabels.job.copiesFor', { name: row.name })}
                                value={copies[row.id] ?? '1'}
                                onChange={(e) => setCopies((current) => ({ ...current, [row.id]: e.target.value }))}
                                disabled={print.isPending}
                                className={`${controlHeight.md} ${inputClass} w-16 text-end tabular`}
                            />
                            <span aria-hidden className="t-admin-caption text-fg-secondary">
                                {t('printLabels.job.copies')}
                            </span>
                            <IconButton size="sm" aria-label={t('printLabels.job.remove', { name: row.name })} onClick={() => setRemoved((current) => [...current, row.id])} disabled={print.isPending}>
                                <XIcon size={16} aria-hidden />
                            </IconButton>
                        </li>
                    ))}
                </ul>
            )}

            {adding ? (
                <div className="flex flex-col gap-2 rounded-sm border border-line-default p-3">
                    <SearchField label={t('printLabels.job.search')} value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('printLabels.job.searchPlaceholder')} autoFocus />
                    {results.length > 0 && (
                        <ul aria-label={t('printLabels.job.results')} className="flex flex-col rounded-sm border border-line-default bg-surface-raised shadow-e3">
                            {results.map((product) => (
                                <li key={product.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setProductIds((current) => [...current, product.id]);
                                            setTerm('');
                                        }}
                                        className="flex w-full items-center gap-2 px-3 py-2 text-start hover:bg-subtle"
                                    >
                                        <PlusIcon size={14} aria-hidden className="text-fg-tertiary" />
                                        <span lang={locale === 'bn' && product.name_bn ? 'bn' : undefined} className="min-w-0 flex-1 t-admin-body text-fg-primary">
                                            {locale === 'bn' ? (product.name_bn ?? product.name) : product.name}
                                        </span>
                                        {product.sku && <span className="t-admin-mono-sm text-fg-tertiary">{product.sku}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Button variant="ghost" size="sm" className="self-end" onClick={() => setAdding(false)}>
                        {t('printLabels.job.done')}
                    </Button>
                </div>
            ) : (
                <Button variant="ghost" size="sm" className="self-start" onClick={() => setAdding(true)}>
                    <PlusIcon size={16} aria-hidden />
                    {t('printLabels.job.add')}
                </Button>
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

            <div className="flex flex-col gap-2">
                <p className="t-admin-caption tabular text-fg-secondary" aria-live="polite">
                    {t('printLabels.job.total', { count, paper: t(`printLabels.paperShort.${value.template}`) })}
                </p>
                <Button onClick={() => void submit()} disabled={print.isPending || loading || count === 0} className="w-full">
                    <PrinterIcon size={16} aria-hidden />
                    {print.isPending ? t('printLabels.job.printing') : t('printLabels.job.print', { count })}
                </Button>
                <p className="t-admin-caption text-fg-tertiary">{t('printLabels.job.usesDraft')}</p>
            </div>
        </section>
    );
}
