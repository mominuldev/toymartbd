import { useRef } from 'react';
import { ArrowClockwiseIcon, ArrowSquareOutIcon, CheckIcon, LinkSimpleIcon, LockSimpleIcon, TrashIcon, WarningIcon } from '@phosphor-icons/react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/Button';
import { useI18n } from '@/i18n/I18nProvider';
import type { MediaUsage } from './api';
import { ModalDialog } from './parts/ModalDialog';
import { UsageIcon } from './parts/UsageIcon';
import { usageContext, usageTitle } from './parts/display';

/** `admin_path` is "/admin/products/12"; the SPA router is mounted under /admin. */
export function adminRoute(path: string): string {
    return path.replace(/^\/admin(?=\/|$)/, '') || '/';
}

type Blocked = { state: 'blocked'; filename: string; usages: MediaUsage[]; onReplace?: () => void };
type Confirm = { state: 'confirm'; filenames: string[]; skipped?: number; pending?: boolean; onConfirm: () => void };

export type MediaDeleteDialogProps = { open: boolean; onClose: () => void } & (Blocked | Confirm);

/**
 * Figma MediaDeleteDialog 292:2359 (A23 geometry). Blocked: a lock on bg/warning-subtle, why,
 * every usage with a link, Close + Replace file. Confirm (unused files only): the
 * consequences box on bg/subtle, Cancel (focused, so Enter is safe) + Danger "Delete N files".
 */
export function MediaDeleteDialog(props: MediaDeleteDialogProps) {
    const { t, locale } = useI18n();
    const safe = useRef<HTMLButtonElement>(null);

    if (props.state === 'blocked') {
        const { open, onClose, filename, usages, onReplace } = props;
        return (
            <ModalDialog
                open={open}
                onClose={onClose}
                initialFocus={safe}
                icon={
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-warning-subtle text-feedback-warning">
                        <LockSimpleIcon size={22} />
                    </span>
                }
                title={t('media.delete.blockedTitle', { name: filename })}
                footer={
                    <>
                        <Button ref={safe} variant="ghost" onClick={onClose}>
                            {t('common.close')}
                        </Button>
                        {onReplace && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    onClose();
                                    onReplace();
                                }}
                            >
                                <ArrowClockwiseIcon size={16} aria-hidden />
                                {t('media.detail.replace')}
                            </Button>
                        )}
                    </>
                }
            >
                <p className="t-admin-body text-fg-secondary">{usages.length === 1 ? t('media.delete.blockedBodyOne') : t('media.delete.blockedBody', { count: usages.length })}</p>
                <ul className="mt-1.5 flex w-full flex-col gap-2 rounded-sm bg-subtle p-3">
                    {usages.map((usage) => (
                        <li key={`${usage.type}-${usage.subject_id}-${usage.position ?? ''}`} className="flex items-center gap-2 t-admin-body text-fg-primary">
                            <UsageIcon usage={usage} />
                            <span className="min-w-0 flex-1 truncate">
                                {usageTitle(usage, locale)} — {usageContext(usage, t)}
                            </span>
                            <Link to={adminRoute(usage.admin_path)} onClick={onClose} className="shrink-0 text-fg-link" aria-label={t('media.detail.open', { title: usageTitle(usage, locale) })}>
                                <ArrowSquareOutIcon size={16} aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            </ModalDialog>
        );
    }

    const { open, onClose, filenames, skipped = 0, pending, onConfirm } = props;
    const count = filenames.length;
    return (
        <ModalDialog
            open={open}
            onClose={onClose}
            initialFocus={safe}
            icon={
                <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-danger-subtle text-feedback-danger">
                    <WarningIcon size={22} />
                </span>
            }
            title={count === 1 ? t('media.delete.confirmTitleOne', { name: filenames[0] ?? '' }) : t('media.delete.confirmTitle', { count })}
            footer={
                <>
                    <Button ref={safe} variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button variant="danger" disabled={pending || count === 0} onClick={onConfirm}>
                        <TrashIcon size={16} aria-hidden />
                        {count === 1 ? t('media.delete.confirmOne') : t('media.delete.confirm', { count })}
                    </Button>
                </>
            }
        >
            <p className="t-admin-body text-fg-secondary">{t('media.delete.confirmBody')}</p>
            {skipped > 0 && <p className="t-admin-body text-fg-primary">{t('media.delete.skipNote', { count: skipped })}</p>}
            <ul className="mt-1.5 flex w-full flex-col gap-2 rounded-sm bg-subtle p-3 t-admin-body text-fg-primary">
                <li className="flex items-start gap-2">
                    <CheckIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
                    <span className="min-w-0 break-words">{filenames.join(', ')}</span>
                </li>
                <li className="flex items-start gap-2">
                    <TrashIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
                    {t('media.delete.removed')}
                </li>
                <li className="flex items-start gap-2">
                    <LinkSimpleIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-fg-secondary" />
                    {t('media.delete.urls')}
                </li>
            </ul>
        </ModalDialog>
    );
}
