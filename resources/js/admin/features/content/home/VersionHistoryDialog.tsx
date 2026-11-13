import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { formatDhakaDateTime } from '@/features/content/shared/dhaka';
import { useHomeVersions, useRevert, type CmsLayoutVersion } from './api';

/**
 * Version history (checklist §2.10 "version history"; no frame of its own — the ConfirmDialog
 * surface at 560 wide). Newest first; the live one is marked. "Revert to this" publishes the old
 * version again as a new version (confirmed); "Edit in draft" only loads it into the draft.
 */
export function VersionHistoryDialog({ open, onClose, canManage }: { open: boolean; onClose: () => void; canManage: boolean }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const versions = useHomeVersions(open);
    const revert = useRevert();
    const [confirm, setConfirm] = useState<CmsLayoutVersion | null>(null);

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

    const run = (version: CmsLayoutVersion, publish: boolean) =>
        revert.mutate(
            { versionId: version.id, publish },
            {
                onSuccess: () => {
                    toast.notify(t(publish ? 'homeBuilder.versions.reverted' : 'homeBuilder.versions.restored', { version: version.version }), 'success');
                    setConfirm(null);
                    onClose();
                },
                onError: () => toast.notify(t('homeBuilder.loadFailed'), 'danger'),
            },
        );

    return createPortal(
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} onCancel={onClose} className="m-auto w-[min(100%-2rem,35rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <div className="flex items-start gap-3 border-b border-line-default px-6 py-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {t('homeBuilder.versions.title')}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.versions.subtitle')}</p>
                </div>
                <IconButton size="sm" aria-label={t('homeBuilder.close')} onClick={onClose}>
                    <XIcon size={16} aria-hidden />
                </IconButton>
            </div>
            <div className="max-h-[70vh] overflow-y-auto px-6 py-4">
                {versions.isError && <QueryError error={versions.error} onRetry={() => void versions.refetch()} />}
                {versions.data && versions.data.length === 0 && <p className="t-admin-body text-fg-secondary">{t('homeBuilder.versions.empty')}</p>}
                <ol className="flex flex-col divide-y divide-line-default">
                    {(versions.data ?? []).map((v) => (
                        <li key={v.id} className="flex flex-wrap items-center gap-3 py-3">
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <p className="flex items-center gap-2 t-admin-body-strong text-fg-primary">
                                    {t('homeBuilder.versions.version', { version: v.version })}
                                    {v.is_live && <StatusChip tone="success" label={t('homeBuilder.versions.live')} />}
                                </p>
                                <p className="t-admin-caption text-fg-tertiary">
                                    {t('homeBuilder.versions.meta', {
                                        date: v.published_at ? formatDhakaDateTime(v.published_at, locale) : '',
                                        name: v.published_by?.name ?? t('homeBuilder.versions.system'),
                                        count: v.block_count,
                                    })}
                                </p>
                                {v.restored_from_version !== null && <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.versions.restoredFrom', { version: v.restored_from_version })}</p>}
                                {v.note && <p className="t-admin-caption text-fg-secondary">{v.note}</p>}
                            </div>
                            {canManage && (
                                <div className="flex gap-2">
                                    <Button variant="ghost" size="sm" disabled={revert.isPending} onClick={() => run(v, false)}>
                                        {t('homeBuilder.versions.restore')}
                                    </Button>
                                    {!v.is_live && (
                                        <Button variant="outline" size="sm" disabled={revert.isPending} onClick={() => setConfirm(v)}>
                                            {t('homeBuilder.versions.revert')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
            <ConfirmDialog
                open={confirm !== null}
                title={t('homeBuilder.versions.confirmTitle', { version: confirm?.version ?? 0 })}
                body={t('homeBuilder.versions.confirmBody', { version: confirm?.version ?? 0 })}
                confirmLabel={t('homeBuilder.versions.revert')}
                pending={revert.isPending}
                onConfirm={() => confirm && run(confirm, true)}
                onClose={() => setConfirm(null)}
            />
        </dialog>,
        document.body,
    );
}
