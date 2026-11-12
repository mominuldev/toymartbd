import { useRef } from 'react';
import { Link } from 'react-router';
import { ArrowsLeftRightIcon, CheckCircleIcon, DownloadSimpleIcon, FileTextIcon, UploadSimpleIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { buttonClass } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { useToast } from '@/components/ui/Toast';
import { certificateUrl, useRemoveCertificate, useUploadCertificate, type ProductCompliance, type StockByLocation } from './api';
import { ProductSection } from './ProductSection';

/**
 * "Stock by branch" (Figma 241:37976): every location's stock of all the product's
 * variants, from the Inventory domain. Read-only — the numbers change only through a
 * stock adjustment, so the card links to Inventory instead of offering an input.
 */
export function StockByBranchCard({ id, rows }: { id?: string; rows: StockByLocation[] }) {
    const { t, locale } = useI18n();

    return (
        <ProductSection id={id} title={t('products.stockBranch.title')} description={t('products.stockBranch.hint')}>
            {rows.length === 0 ? (
                <p className="t-admin-body text-fg-secondary">{t('products.stockBranch.empty')}</p>
            ) : (
                <ul className="-mt-2 flex flex-col">
                    {rows.map((row) => {
                        const name = locale === 'bn' && row.name_bn ? row.name_bn : row.name;
                        return (
                            <li key={row.location_id} className="flex items-center gap-2 border-b border-line-default py-2">
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <p lang={locale === 'bn' && row.name_bn ? 'bn' : undefined} className="truncate t-admin-body text-fg-primary">
                                        {name}
                                    </p>
                                    <p className="t-admin-caption text-fg-tertiary">{t('products.stockBranch.reserved', { count: row.reserved })}</p>
                                </div>
                                <p className="shrink-0 t-admin-num tabular text-fg-primary">
                                    <span className="sr-only">{t('products.stockBranch.onHandLabel')} </span>
                                    {row.on_hand}
                                </p>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Link to="/inventory" className={buttonClass('outline', 'sm')}>
                    {t('products.stockBranch.adjust')}
                </Link>
                <Link to="/inventory/movements" className={`${buttonClass('ghost', 'sm')} gap-2`}>
                    <ArrowsLeftRightIcon size={16} aria-hidden />
                    {t('products.stockBranch.movements')}
                </Link>
            </div>
        </ProductSection>
    );
}

/** "412 KB" / "1.2 MB" — display of a byte count the server sent. */
function fileSize(bytes: number, locale: string): string {
    const format = (n: number, digits: number) => new Intl.NumberFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { maximumFractionDigits: digits, numberingSystem: 'latn' }).format(n);
    return bytes >= 1024 * 1024 ? `${format(bytes / (1024 * 1024), 1)} MB` : `${format(Math.max(1, Math.round(bytes / 1024)), 0)} KB`;
}

/**
 * The certificate file row of the Compliance card (241:37971): the uploaded PDF with a
 * download link, replace and remove; or an upload button. The server sniffs the bytes
 * (PDF only, no scripts or attachments, ≤ 10 MB) and answers a refusal as a 422 on `file`.
 */
export function CertificateFile({ productId, compliance, disabled }: { productId: number | null; compliance?: ProductCompliance; disabled: boolean }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const input = useRef<HTMLInputElement>(null);
    const upload = useUploadCertificate(productId ?? 0);
    const remove = useRemoveCertificate(productId ?? 0);
    const error = upload.error instanceof ApiError ? (upload.error.fieldError('file') ?? upload.error.problem.title) : null;

    if (productId === null) {
        return <p className="t-admin-caption text-fg-secondary">{t('products.bsti.saveFirst')}</p>;
    }

    const certificate = compliance?.certificate ?? null;
    const pending = upload.isPending || remove.isPending;

    return (
        <div className="flex flex-col gap-1.5">
            {certificate ? (
                <div className="flex items-center gap-2 rounded-sm bg-subtle py-1 ps-2.5 pe-1">
                    <FileTextIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                    <a href={certificateUrl(productId)} download className="min-w-0 flex-1 truncate t-admin-caption text-fg-secondary hover:text-fg-primary hover:underline">
                        <span className="sr-only">{t('products.bsti.download')} </span>
                        {`${certificate.name} · ${fileSize(certificate.size_bytes, locale)}`}
                    </a>
                    <CheckCircleIcon size={14} aria-hidden className="shrink-0 text-feedback-success" />
                    {!disabled && (
                        <IconButton
                            size="sm"
                            disabled={pending}
                            aria-label={t('products.bsti.remove')}
                            onClick={() =>
                                remove.mutate(undefined, {
                                    onSuccess: () => notify(t('products.bsti.removed')),
                                    onError: (e) => notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger'),
                                })
                            }
                        >
                            <XIcon size={14} aria-hidden />
                        </IconButton>
                    )}
                </div>
            ) : (
                <p className="t-admin-caption text-fg-secondary">{t('products.bsti.none')}</p>
            )}
            {!disabled && (
                <>
                    <input
                        ref={input}
                        type="file"
                        accept="application/pdf,.pdf"
                        hidden
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';
                            if (file) upload.mutate(file, { onSuccess: () => notify(t('products.bsti.uploaded')) });
                        }}
                    />
                    <button type="button" disabled={pending} onClick={() => input.current?.click()} className={`${buttonClass('ghost', 'sm')} gap-2 self-start`}>
                        <UploadSimpleIcon size={16} aria-hidden />
                        {upload.isPending ? t('products.bsti.uploading') : certificate ? t('products.bsti.replace') : t('products.bsti.upload')}
                    </button>
                    <p className="t-admin-caption text-fg-tertiary">{t('products.bsti.pdfOnly')}</p>
                </>
            )}
            {error && (
                <p role="alert" className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
            {certificate && disabled && (
                <a href={certificateUrl(productId)} download className={`${buttonClass('ghost', 'sm')} gap-2 self-start`}>
                    <DownloadSimpleIcon size={16} aria-hidden />
                    {t('products.bsti.download')}
                </a>
            )}
        </div>
    );
}
