import { useRef, useState } from 'react';
import { FolderSimplePlusIcon, FolderSimpleIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { problemMessage, useCreateFolder, type MediaFolder } from './api';
import { ModalDialog } from './parts/ModalDialog';
import { folderLabel } from './parts/display';

/** New folder: one name (Bangla is fine); the server's 422 on `name` shows under the field. */
export function NewFolderDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (folder: MediaFolder) => void }) {
    const { t } = useI18n();
    const create = useCreateFolder();
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    const close = () => {
        setName('');
        setError(null);
        onClose();
    };
    const submit = () => {
        setError(null);
        create.mutate(name, {
            onSuccess: (folder) => {
                setName('');
                onCreated(folder);
            },
            onError: (e) => setError(e instanceof ApiError ? (e.fieldError('name') ?? problemMessage(e, 'name')) : null),
        });
    };

    return (
        <ModalDialog
            open={open}
            onClose={close}
            initialFocus={input}
            icon={
                <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                    <FolderSimplePlusIcon size={22} />
                </span>
            }
            title={t('media.folder.dialogTitle')}
            footer={
                <>
                    <Button variant="ghost" onClick={close}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={create.isPending || name.trim() === ''}>
                        {t('media.folder.create')}
                    </Button>
                </>
            }
        >
            <div className="w-full pt-1">
                <Field label={t('media.folder.name')} required error={error ?? undefined}>
                    {({ id, describedBy, invalid }) => (
                        <Input
                            ref={input}
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            maxLength={60}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && name.trim() !== '') {
                                    e.preventDefault();
                                    submit();
                                }
                            }}
                        />
                    )}
                </Field>
            </div>
        </ModalDialog>
    );
}

/** Move to folder: a folder select (or "Not filed") for the bulk selection. */
export function MoveDialog({
    open,
    count,
    folders,
    pending,
    onClose,
    onMove,
}: {
    open: boolean;
    count: number;
    folders: MediaFolder[];
    pending: boolean;
    onClose: () => void;
    onMove: (folderId: number | null) => void;
}) {
    const { t } = useI18n();
    const [target, setTarget] = useState<string>(folders[0] ? String(folders[0].id) : '');
    const select = useRef<HTMLSelectElement>(null);

    return (
        <ModalDialog
            open={open}
            onClose={onClose}
            initialFocus={select}
            icon={
                <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                    <FolderSimpleIcon size={22} />
                </span>
            }
            title={t('media.bulk.move')}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={() => onMove(target ? Number(target) : null)} disabled={pending}>
                        {t('media.bulk.moveConfirm')}
                    </Button>
                </>
            }
        >
            <div className="w-full pt-1">
                <Field label={t('media.bulk.moveTo', { count })}>
                    {({ id }) => (
                        <Select ref={select} id={id} value={target} onChange={(e) => setTarget(e.target.value)}>
                            {folders.map((f) => (
                                <option key={f.id} value={f.id}>
                                    {folderLabel(f, t)}
                                </option>
                            ))}
                            <option value="">{t('media.folder.none')}</option>
                        </Select>
                    )}
                </Field>
            </div>
        </ModalDialog>
    );
}
