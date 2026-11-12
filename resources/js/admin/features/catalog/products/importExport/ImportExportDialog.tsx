import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { DownloadSimpleIcon, FileArrowUpIcon, WarningIcon, CheckCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button, buttonClass } from '@/components/ui/Button';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { TabButton } from '@/components/ui/Tabs';
import { ApiError } from '@/lib/api';
import type { ProductFilters } from '../api';
import { errorReportUrl, exportUrl, isRunning, useCommitImport, useProductImport, useUploadImport, type ImportFormat, type ProductImport } from './api';

type Tab = 'export' | 'import';

type Props = {
    open: boolean;
    onClose: () => void;
    filters: ProductFilters;
    /** catalog.import: without it only the export tab is offered. */
    canImport: boolean;
};

/**
 * Import / export dialog behind the Products list's "Import / export" AdminButton (Figma
 * "B · 11 Products" 241:37251 draws the button; the dialog itself is not drawn, so it uses
 * the A23 dialog frame — 520 wide (640 here, for the problem table), radius/lg, e4, header,
 * body, border-topped footer — with AdminTab and SegmentedControl inside).
 *
 * Every figure is the server's: the preview counts, the progress and the row-level problems
 * come from `GET /product-imports/{id}`, polled while the queued job runs.
 */
export function ImportExportDialog({ open, onClose, filters, canImport }: Props) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [tab, setTab] = useState<Tab>('export');
    const [format, setFormat] = useState<ImportFormat>('csv');
    const [file, setFile] = useState<File | null>(null);
    const [importId, setImportId] = useState<number | null>(null);
    const upload = useUploadImport();
    const current = useProductImport(importId);
    const commit = useCommitImport(importId);
    const data = current.data;

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    const reset = () => {
        setFile(null);
        setImportId(null);
        upload.reset();
        commit.reset();
    };

    const filtered = Boolean(filters.search || filters.status || filters.type || filters.brand || filters.category);
    const uploadError = upload.error instanceof ApiError ? (upload.error.fieldError('file') ?? upload.error.problem.detail ?? upload.error.problem.title) : null;
    const valid = data ? data.create_count + data.update_count : 0;

    let footer: ReactNode;
    if (tab === 'export') {
        footer = (
            <>
                <Button variant="outline" onClick={onClose}>
                    {t('productIo.close')}
                </Button>
                <a href={exportUrl(filters, format)} download className={buttonClass('primary')}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('productIo.export.download')}
                </a>
            </>
        );
    } else if (data?.status === 'previewed') {
        footer = (
            <>
                <Button variant="outline" onClick={reset}>
                    {t('productIo.import.another')}
                </Button>
                <Button onClick={() => commit.mutate()} disabled={valid === 0 || commit.isPending}>
                    {t('productIo.import.commit', { count: valid })}
                </Button>
            </>
        );
    } else if (data?.status === 'completed' || data?.status === 'failed') {
        footer = (
            <>
                <Button variant="outline" onClick={reset}>
                    {t('productIo.import.another')}
                </Button>
                <Button onClick={onClose}>{t('productIo.close')}</Button>
            </>
        );
    } else {
        footer = (
            <>
                <Button variant="outline" onClick={onClose}>
                    {t('productIo.close')}
                </Button>
                <Button
                    onClick={() => file && upload.mutate(file, { onSuccess: (created) => setImportId(created.id) })}
                    disabled={!file || upload.isPending || importId !== null}
                >
                    <FileArrowUpIcon size={16} aria-hidden />
                    {t('productIo.import.upload')}
                </Button>
            </>
        );
    }

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            className="m-auto w-[min(100%-2rem,40rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            {open && (
                <>
                    <div className="flex flex-col gap-4 px-6 pt-6 pb-4">
                        <h2 id={titleId} className="t-admin-section text-fg-primary">
                            {t('productIo.title')}
                        </h2>
                        {canImport && (
                            <div role="group" aria-label={t('productIo.title')} className="flex border-b border-line-default">
                                <TabButton active={tab === 'export'} label={t('productIo.tab.export')} onClick={() => setTab('export')} />
                                <TabButton active={tab === 'import'} label={t('productIo.tab.import')} onClick={() => setTab('import')} />
                            </div>
                        )}

                        {tab === 'export' ? (
                            <div className="flex flex-col gap-3">
                                <p className="t-admin-body text-fg-secondary">{t('productIo.export.body')}</p>
                                {filtered && <p className="t-admin-caption text-fg-secondary">{t('productIo.export.filtered')}</p>}
                                <SegmentedControl
                                    label={t('productIo.export.format')}
                                    value={format}
                                    onChange={setFormat}
                                    className="self-start"
                                    options={[
                                        { value: 'csv', label: t('productIo.export.csv') },
                                        { value: 'xlsx', label: t('productIo.export.xlsx') },
                                    ]}
                                />
                            </div>
                        ) : (
                            <ImportBody
                                file={file}
                                onFile={(next) => {
                                    reset();
                                    setFile(next);
                                }}
                                data={data}
                                uploading={upload.isPending}
                                uploadError={uploadError}
                            />
                        )}
                    </div>
                    <div className="flex flex-wrap justify-end gap-3 border-t border-line-default px-6 py-4">{footer}</div>
                </>
            )}
        </dialog>
    );
}

function ImportBody({ file, onFile, data, uploading, uploadError }: { file: File | null; onFile: (file: File | null) => void; data: ProductImport | undefined; uploading: boolean; uploadError: string | null }) {
    const { t } = useI18n();
    const inputId = useId();

    if (data && data.status !== 'previewed' && isRunning(data.status)) {
        const label =
            data.status === 'queued'
                ? t('productIo.import.queued')
                : t(data.status === 'importing' ? 'productIo.import.importing' : 'productIo.import.checking', { done: data.processed_rows, total: data.total_rows });
        return (
            <div className="flex flex-col gap-2" aria-live="polite">
                <p className="t-admin-body text-fg-secondary">{label}</p>
                <progress className="h-2 w-full accent-brand" value={data.processed_rows} max={Math.max(data.total_rows, 1)} />
            </div>
        );
    }

    if (data && (data.status === 'previewed' || data.status === 'completed' || data.status === 'failed')) {
        return <ImportResult data={data} />;
    }

    return (
        <div className="flex flex-col gap-3">
            <p className="t-admin-body text-fg-secondary">{t('productIo.import.body')}</p>
            <label htmlFor={inputId} className="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-dashed border-line-strong bg-subtle px-4 py-6 text-center">
                <FileArrowUpIcon size={24} aria-hidden className="text-fg-brand" />
                <span className="t-admin-body-strong text-fg-primary">{file ? file.name : t('productIo.import.choose')}</span>
                <span className="t-admin-caption text-fg-tertiary">{t('productIo.import.hint')}</span>
                <input
                    id={inputId}
                    type="file"
                    accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    className="sr-only"
                    disabled={uploading}
                    onChange={(e) => onFile(e.target.files?.[0] ?? null)}
                />
            </label>
            {uploadError && (
                <p role="alert" className="t-admin-caption text-feedback-danger">
                    {uploadError}
                </p>
            )}
        </div>
    );
}

function ImportResult({ data }: { data: ProductImport }) {
    const { t } = useI18n();
    const failed = data.status === 'failed';
    const rowErrors = data.errors.length;

    return (
        <div className="flex flex-col gap-3" aria-live="polite">
            {failed ? (
                <p className="t-admin-body-strong flex items-center gap-2 text-fg-primary">
                    <WarningIcon size={16} aria-hidden className="text-feedback-danger" />
                    {data.failure_message ?? t('productIo.import.failed')}
                </p>
            ) : data.status === 'completed' ? (
                <p className="t-admin-body-strong flex items-center gap-2 text-fg-primary">
                    <CheckCircleIcon size={16} aria-hidden className="text-feedback-success" />
                    {t('productIo.import.done', { created: data.create_count, updated: data.update_count })}
                </p>
            ) : (
                <div className="flex flex-col gap-1">
                    <p className="t-admin-body-strong text-fg-primary">{t('productIo.import.previewTitle')}</p>
                    <p className="t-admin-caption text-fg-tertiary">
                        {t('productIo.import.file')}: {data.original_name}
                    </p>
                </div>
            )}

            {!failed && (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <Stat label={t('productIo.import.creates', { count: data.create_count })} />
                    <Stat label={t('productIo.import.updates', { count: data.update_count })} />
                    <Stat label={t('productIo.import.errors', { count: data.error_count })} warn={data.error_count > 0} />
                </div>
            )}

            {data.status === 'previewed' && data.create_count + data.update_count === 0 && <p className="t-admin-caption text-feedback-danger">{t('productIo.import.nothing')}</p>}

            {rowErrors > 0 && (
                <>
                    {!failed && <p className="t-admin-caption text-fg-secondary">{t('productIo.import.errorsNote')}</p>}
                    <div className="max-h-60 overflow-auto rounded-md border border-line-default">
                        <table className="w-full text-start">
                            <thead className="sticky top-0 bg-subtle">
                                <tr>
                                    <th scope="col" className="t-admin-caption-strong px-3 py-2 text-start text-fg-secondary">
                                        {t('productIo.import.col.row')}
                                    </th>
                                    <th scope="col" className="t-admin-caption-strong px-3 py-2 text-start text-fg-secondary">
                                        {t('productIo.import.col.column')}
                                    </th>
                                    <th scope="col" className="t-admin-caption-strong px-3 py-2 text-start text-fg-secondary">
                                        {t('productIo.import.col.message')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.errors.map((error, i) => (
                                    <tr key={i} className="border-t border-line-default align-top">
                                        <td className="t-admin-body px-3 py-2 tabular">{error.row}</td>
                                        <td className="t-admin-mono-sm px-3 py-2 text-fg-secondary">{error.column ?? '—'}</td>
                                        <td className="t-admin-body px-3 py-2">{error.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {rowErrors < data.error_count && <p className="t-admin-caption text-fg-tertiary">{t('productIo.import.moreErrors', { shown: rowErrors, count: data.error_count })}</p>}
                    <a href={errorReportUrl(data.id)} download className={`${buttonClass('outline', 'sm')} self-start`}>
                        <DownloadSimpleIcon size={16} aria-hidden />
                        {t('productIo.import.report')}
                    </a>
                </>
            )}
        </div>
    );
}

function Stat({ label, warn }: { label: string; warn?: boolean }) {
    return (
        <div className="flex items-center gap-1.5 rounded-sm border border-line-default bg-subtle px-3 py-2">
            {warn && <WarningIcon size={14} aria-hidden className="text-feedback-warning" />}
            <p className="t-admin-body-strong text-fg-primary tabular">{label}</p>
        </div>
    );
}
