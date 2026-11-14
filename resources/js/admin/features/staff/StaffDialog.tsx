import { useId, useState } from 'react';
import { KeyIcon, UserGearIcon, UserPlusIcon } from '@phosphor-icons/react';
import { resetStaffPasswordRequestSchema, storeStaffRequestSchema, updateStaffRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { roleKeys, roleOf, useAddStaff, useResetStaffPassword, useResetStaffTwoFactor, useSetStaffActive, useUpdateStaff, type RoleKey, type StaffMember } from './api';
import { TwoFactorChip } from './PeopleTab';
import { useDialog } from './parts';

type FieldName = 'name' | 'email' | 'role' | 'password';
type Errors = Partial<Record<FieldName, string>>;

type StaffDialogProps = {
    open: boolean;
    /** null adds a new person; a member opens the manage view for them. */
    member: StaffMember | null;
    onClose: () => void;
};

/** Field errors from a 422 problem, first message per field. */
function problemErrors(error: unknown): Errors | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    const next: Errors = {};
    for (const { field, message } of error.problem.errors ?? []) next[field as FieldName] ??= message;
    return next;
}

/** First message per field from a failed generated-schema parse (rule 27). */
function issuesOf(issues: readonly { path: readonly PropertyKey[]; message: string }[]): Errors {
    const next: Errors = {};
    for (const issue of issues) next[issue.path[0] as FieldName] ??= issue.message;
    return next;
}

/**
 * Add staff / manage one person. No frame of its own — built from ConfirmDialog 203:1387's
 * frame, AdminField 185:151, AdminInput 184:338 and AdminSelect 184:369, like the supplier
 * payment dialog. Manage = rename + role, a new temporary password, and deactivate /
 * reactivate (destructive, so it confirms). On your own account only the name is editable:
 * the API refuses the rest (409), and so the controls are not offered.
 */
export function StaffDialog({ open, member, onClose }: StaffDialogProps) {
    const { t } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const add = useAddStaff();
    const update = useUpdateStaff();
    const reset = useResetStaffPassword();
    const setActive = useSetStaffActive();
    const resetTwoFactor = useResetStaffTwoFactor();

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [role, setRole] = useState<RoleKey>('viewer');
    const [password, setPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [passwordError, setPasswordError] = useState<string | undefined>();
    const [confirming, setConfirming] = useState(false);
    const [confirmingReset, setConfirmingReset] = useState(false);

    const ref = useDialog(open, () => {
        setName(member?.name ?? '');
        setEmail('');
        setRole((member && roleOf(member)) ?? 'viewer');
        setPassword('');
        setNewPassword('');
        setErrors({});
        setPasswordError(undefined);
        setConfirming(false);
        setConfirmingReset(false);
        resetTwoFactor.reset();
        add.reset();
        update.reset();
        reset.reset();
        setActive.reset();
    });

    function failed(error: unknown) {
        const fields = problemErrors(error);
        if (fields) setErrors(fields);
        else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
    }

    function submit() {
        if (member === null) {
            const input = { name: name.trim(), email: email.trim(), role, password };
            const parsed = storeStaffRequestSchema.safeParse(input);
            if (!parsed.success) return setErrors(issuesOf(parsed.error.issues));
            setErrors({});
            add.mutate(input, {
                onSuccess: (saved) => {
                    notify(t('staff.form.added', { name: saved.name }));
                    onClose();
                },
                onError: failed,
            });
            return;
        }

        // Send only what changed, so a rename never touches the role (or its audit trail).
        const input: { name?: string; role?: RoleKey } = {};
        if (name.trim() !== member.name) input.name = name.trim();
        if (!member.is_self && role !== roleOf(member)) input.role = role;
        if (Object.keys(input).length === 0) return onClose();
        const parsed = updateStaffRequestSchema.safeParse(input);
        if (!parsed.success) return setErrors(issuesOf(parsed.error.issues));
        setErrors({});
        update.mutate(
            { id: member.id, input },
            {
                onSuccess: () => {
                    notify(t('staff.form.saved'));
                    onClose();
                },
                onError: failed,
            },
        );
    }

    function submitPassword() {
        if (member === null) return;
        const parsed = resetStaffPasswordRequestSchema.safeParse({ password: newPassword });
        if (!parsed.success) return setPasswordError(parsed.error.issues[0]?.message);
        setPasswordError(undefined);
        reset.mutate(
            { id: member.id, input: { password: newPassword } },
            {
                onSuccess: () => {
                    notify(t('staff.password.saved', { name: member.name }));
                    setNewPassword('');
                },
                onError: (error) => {
                    const fields = problemErrors(error);
                    if (fields) setPasswordError(fields.password);
                    else failed(error);
                },
            },
        );
    }

    function changeStatus(active: boolean) {
        if (member === null) return;
        setActive.mutate(
            { id: member.id, active },
            {
                onSuccess: (saved) => {
                    setConfirming(false);
                    notify(t(active ? 'staff.reactivated' : 'staff.deactivated', { name: saved.name }));
                    onClose();
                },
                onError: (error) => {
                    setConfirming(false);
                    failed(error);
                },
            },
        );
    }

    function resetSecondFactor() {
        if (member === null) return;
        resetTwoFactor.mutate(member.id, {
            onSuccess: (saved) => {
                setConfirmingReset(false);
                notify(t('staff.twoFactor.resetDone', { name: saved.name }));
                onClose();
            },
            onError: (error) => {
                setConfirmingReset(false);
                failed(error);
            },
        });
    }

    const adding = member === null;
    const busy = add.isPending || update.isPending;
    const Icon = adding ? UserPlusIcon : UserGearIcon;

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                        <Icon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {adding ? t('staff.form.addTitle') : t('staff.form.editTitle', { name: member.name })}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{adding ? t('staff.form.addBody') : t('staff.form.editBody', { email: member.email })}</p>
                </div>

                <fieldset disabled={busy} className="flex min-w-0 flex-col gap-3.5 px-6 pb-5">
                    <Field label={t('staff.form.name')} error={errors.name} required>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />
                        )}
                    </Field>
                    {adding && (
                        <Field label={t('staff.form.email')} error={errors.email} required>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="email" aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
                            )}
                        </Field>
                    )}
                    {member?.is_self ? (
                        <p className="t-admin-caption text-fg-secondary">{t('staff.form.selfNote')}</p>
                    ) : (
                        <Field label={t('staff.form.role')} hint={t('staff.form.roleHint')} error={errors.role} required>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={role} onChange={(e) => setRole(e.target.value as RoleKey)}>
                                    {roleKeys.map((key) => (
                                        <option key={key} value={key}>
                                            {t(`role.${key}` as MessageKey)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    )}
                    {adding && (
                        <Field label={t('staff.form.password')} hint={t('staff.form.passwordHint')} error={errors.password} required>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="password" aria-describedby={describedBy} aria-invalid={invalid || undefined} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                            )}
                        </Field>
                    )}
                </fieldset>

                {member && !member.is_self && (
                    <>
                        <div className="flex flex-col gap-3 border-t border-line-default px-6 py-4">
                            <div>
                                <h3 className="t-admin-body-strong text-fg-primary">{t('staff.password.title')}</h3>
                                <p className="t-admin-caption text-fg-secondary">{t('staff.password.body')}</p>
                            </div>
                            <div className="flex flex-wrap items-start gap-3">
                                <div className="min-w-0 flex-1 basis-56">
                                    <Field label={t('staff.form.password')} hint={t('staff.form.passwordHint')} error={passwordError}>
                                        {({ id, describedBy, invalid }) => (
                                            <Input
                                                id={id}
                                                type="password"
                                                aria-describedby={describedBy}
                                                aria-invalid={invalid || undefined}
                                                autoComplete="new-password"
                                                value={newPassword}
                                                onChange={(e) => setNewPassword(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        submitPassword();
                                                    }
                                                }}
                                            />
                                        )}
                                    </Field>
                                </div>
                                <Button variant="outline" className="mt-6" onClick={submitPassword} disabled={reset.isPending}>
                                    <KeyIcon size={16} aria-hidden />
                                    {t('staff.password.submit')}
                                </Button>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3 border-t border-line-default px-6 py-4">
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="flex items-center gap-2">
                                    <h3 className="t-admin-body-strong text-fg-primary">{t('staff.twoFactor.section')}</h3>
                                    <TwoFactorChip member={member} />
                                </span>
                                <p className="t-admin-caption text-fg-secondary">
                                    {t(member.two_factor_enabled ? 'staff.twoFactor.resetBody' : member.two_factor_required ? 'staff.twoFactor.missing' : 'staff.twoFactor.optional')}
                                </p>
                            </div>
                            {member.two_factor_enabled && (
                                <Button variant="outline" onClick={() => setConfirmingReset(true)} disabled={resetTwoFactor.isPending}>
                                    {t('staff.twoFactor.reset')}
                                </Button>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-3 border-t border-line-default px-6 py-4">
                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="flex items-center gap-2">
                                    <h3 className="t-admin-body-strong text-fg-primary">{t('staff.status.section')}</h3>
                                    <StatusChip label={t(member.is_active ? 'staff.status.active' : 'staff.status.inactive')} tone={member.is_active ? 'success' : 'neutral'} />
                                </span>
                                <p className="t-admin-caption text-fg-secondary">{t(member.is_active ? 'staff.status.activeBody' : 'staff.status.inactiveBody')}</p>
                            </div>
                            {member.is_active ? (
                                <Button variant="danger" onClick={() => setConfirming(true)} disabled={setActive.isPending}>
                                    {t('staff.deactivate')}
                                </Button>
                            ) : (
                                <Button variant="outline" onClick={() => changeStatus(true)} disabled={setActive.isPending}>
                                    {t('staff.reactivate')}
                                </Button>
                            )}
                        </div>
                    </>
                )}

                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={busy}>
                        {adding ? t('staff.form.submitAdd') : t('staff.form.submitSave')}
                    </Button>
                </div>
            </form>

            {member && (
                <ConfirmDialog
                    open={confirming}
                    title={t('staff.deactivate.title', { name: member.name })}
                    body={t('staff.deactivate.body')}
                    confirmLabel={t('staff.deactivate')}
                    pending={setActive.isPending}
                    onConfirm={() => changeStatus(false)}
                    onClose={() => setConfirming(false)}
                />
            )}
            {member && (
                <ConfirmDialog
                    open={confirmingReset}
                    title={t('staff.twoFactor.resetTitle', { name: member.name })}
                    body={t('staff.twoFactor.resetConfirm')}
                    confirmLabel={t('staff.twoFactor.reset')}
                    pending={resetTwoFactor.isPending}
                    onConfirm={resetSecondFactor}
                    onClose={() => setConfirmingReset(false)}
                />
            )}
        </dialog>
    );
}
