import { useState } from 'react';
import { UserPlusIcon } from '@phosphor-icons/react';
import { capitalHolderRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { Select } from '@/components/ui/Select';
import { useToast } from '@/components/ui/Toast';
import { holderRoles, useCapitalStaff, useSaveCapitalHolder, type CapitalHolder, type HolderInput, type HolderRole } from './api';
import { CapitalDialog, roleKey } from './parts';

type FieldName = 'name' | 'name_bn' | 'role' | 'user_id';

/**
 * "Add an owner or partner" — a small separate dialog (lead decision 2026-11-16, not inline):
 * name, Bangla name, owner/partner, and an optional staff login. No Figma frame of its own;
 * built from the capital dialog shell and AdminField / AdminSelect / SegmentedControl.
 */
export function HolderDialog({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: (holder: CapitalHolder) => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveCapitalHolder();
    const staff = useCapitalStaff(open);
    const [name, setName] = useState('');
    const [nameBn, setNameBn] = useState('');
    const [role, setRole] = useState<HolderRole>('owner');
    const [userId, setUserId] = useState('');
    const [errors, setErrors] = useState<Partial<Record<FieldName, string>>>({});

    const reset = () => {
        setName('');
        setNameBn('');
        setRole('owner');
        setUserId('');
        setErrors({});
        save.reset();
    };

    function submit() {
        const input: HolderInput = { name: name.trim(), name_bn: nameBn.trim() === '' ? null : nameBn.trim(), role, user_id: userId === '' ? null : Number(userId) };
        const parsed = capitalHolderRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Partial<Record<FieldName, string>> = {};
            for (const issue of parsed.error.issues) next[issue.path[0] as FieldName] ??= issue.message;
            if (input.name === '') next.name = t('capital.field.required', { label: t('capital.holderDialog.name') });
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { input },
            {
                onSuccess: (holder) => {
                    notify(t('capital.holderDialog.saved', { name: holder.name }));
                    onSaved(holder);
                    onClose();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        const next: Partial<Record<FieldName, string>> = {};
                        for (const { field, message } of error.problem.errors ?? []) next[field as FieldName] ??= message;
                        setErrors(next);
                    } else {
                        notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
                    }
                },
            },
        );
    }

    return (
        <CapitalDialog
            open={open}
            onOpen={reset}
            onClose={onClose}
            width="narrow"
            title={t('capital.holderDialog.title')}
            subtitle={t('capital.holderDialog.subtitle')}
            pending={save.isPending}
            submitIcon={<UserPlusIcon size={16} aria-hidden />}
            submitLabel={t('capital.holderDialog.submit')}
            onSubmit={submit}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('capital.holderDialog.name')} error={errors.name} required>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />
                    )}
                </Field>
                <Field label={t('capital.holderDialog.nameBn')} error={errors.name_bn} optional>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={120} autoComplete="off" value={nameBn} onChange={(e) => setNameBn(e.target.value)} />
                    )}
                </Field>
            </div>
            <div className="flex flex-col gap-1.5">
                <p aria-hidden className="t-admin-body-strong text-fg-primary">
                    {t('capital.holderDialog.role')}
                </p>
                <SegmentedControl
                    label={t('capital.holderDialog.role')}
                    className="h-9 w-full p-1 [&>button]:flex-1"
                    options={holderRoles.map((r) => ({ value: r, label: t(roleKey(r)) }))}
                    value={role}
                    onChange={setRole}
                />
            </div>
            <Field label={t('capital.holderDialog.login')} hint={t('capital.holderDialog.loginHint')} error={errors.user_id} optional>
                {({ id, describedBy, invalid }) => (
                    <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={userId} onChange={(e) => setUserId(e.target.value)}>
                        <option value="">{t('capital.holderDialog.loginNone')}</option>
                        {(staff.data ?? []).map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>
        </CapitalDialog>
    );
}
