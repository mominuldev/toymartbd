import { useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { ImagesIcon, LockSimpleIcon, ShieldIcon, UserCircleIcon, type Icon } from '@phosphor-icons/react';
import { updateOwnPasswordRequestSchema, updateOwnProfileRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { en, type MessageKey } from '@/i18n/messages';
import { Avatar } from '@/components/shell/Avatar';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { StatusChip } from '@/components/ui/StatusChip';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import type { Media } from '@/features/media/api';
import { useToast } from '@/components/ui/Toast';
import { useMe, type Me } from './api';
import { useOwnAvatar, useUpdateOwnPassword, useUpdateOwnProfile } from './accountApi';

type Errors = Partial<Record<string, string>>;

/** First message per field from a 422 problem; null for any other failure. */
function problemErrors(error: unknown): Errors | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    const next: Errors = {};
    for (const { field, message } of error.problem.errors ?? []) next[field] ??= message;
    return next;
}

/** First message per field from a failed generated-schema parse (rule 27). */
function issuesOf(issues: readonly { path: readonly PropertyKey[]; message: string }[]): Errors {
    const next: Errors = {};
    for (const issue of issues) next[String(issue.path[0])] ??= issue.message;
    return next;
}

/** Settings card (B · 40): icon tile, title and description, then the body. */
function Card({ icon: IconGlyph, title, description, children }: { icon: Icon; title: string; description: string; children: ReactNode }) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex min-w-0 flex-col gap-4.5 rounded-md border border-line-default bg-surface p-4 shadow-e1 md:p-5">
            <div className="flex items-start gap-3">
                <span aria-hidden className="grid size-9 shrink-0 place-items-center rounded-xs-plus bg-brand-subtle text-fg-brand">
                    <IconGlyph size={18} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 id={headingId} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-caption text-fg-secondary">{description}</p>
                </div>
            </div>
            {children}
        </section>
    );
}

/**
 * My account (Figma B · 40 `547:83811`): the signed-in person's own name, sign-in email and
 * password, and where their two-factor sign-in stands. Each card saves on its own — a
 * password change never rides along with a rename. Other people's accounts stay in Staff & roles.
 */
export function AccountPage() {
    const { t } = useI18n();
    const me = useMe().data;
    if (!me) return null; // RequireAuth guarantees a user.

    const roleKey = `role.${me.roles[0] ?? ''}`;
    const roleLabel = roleKey in en ? t(roleKey as MessageKey) : (me.roles[0] ?? '');

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader title={t('account.title')} breadcrumb={[{ label: t('account.crumb') }]} subtitle={t('account.subtitle')} />
            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-5">
                    <ProfileCard me={me} />
                    <PasswordCard />
                    <SecurityCard me={me} />
                </div>
                <SummaryCard me={me} roleLabel={roleLabel} />
            </div>
        </div>
    );
}

function ProfileCard({ me }: { me: Me }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useUpdateOwnProfile();
    const [name, setName] = useState(me.name);
    const [email, setEmail] = useState(me.email);
    const [currentPassword, setCurrentPassword] = useState('');
    const [errors, setErrors] = useState<Errors>({});

    // A save elsewhere (or another tab) refreshes /auth/me; follow it.
    useEffect(() => {
        setName(me.name);
        setEmail(me.email);
    }, [me.name, me.email]);

    const emailChanged = email.trim().toLowerCase() !== me.email.toLowerCase();
    const dirty = name.trim() !== me.name || emailChanged;

    function discard() {
        setName(me.name);
        setEmail(me.email);
        setCurrentPassword('');
        setErrors({});
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const input = { name: name.trim(), email: email.trim(), ...(emailChanged ? { current_password: currentPassword } : {}) };
        const parsed = updateOwnProfileRequestSchema.safeParse(input);
        if (!parsed.success) return setErrors(issuesOf(parsed.error.issues));
        setErrors({});
        save.mutate(input, {
            onSuccess: () => {
                setCurrentPassword('');
                notify(t('account.profile.saved'));
            },
            onError: (error) => {
                const fields = problemErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
            },
        });
    }

    return (
        <Card icon={UserCircleIcon} title={t('account.profile.title')} description={t('account.profile.body')}>
            <form noValidate onSubmit={submit} className="flex flex-col gap-4.5">
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label={t('account.profile.name')} hint={t('account.profile.nameHint')} error={errors.name}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} autoComplete="name" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
                        )}
                    </Field>
                    <Field label={t('account.profile.email')} hint={t('account.profile.emailHint')} error={errors.email}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="email" aria-describedby={describedBy} aria-invalid={invalid} autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                        )}
                    </Field>
                    {emailChanged && (
                        <Field label={t('account.profile.currentPassword')} hint={t('account.profile.currentPasswordHint')} error={errors.current_password}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    type="password"
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid}
                                    autoComplete="current-password"
                                    value={currentPassword}
                                    onChange={(e) => setCurrentPassword(e.target.value)}
                                />
                            )}
                        </Field>
                    )}
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" disabled={!dirty || save.isPending} onClick={discard}>
                        {t('account.profile.discard')}
                    </Button>
                    <Button type="submit" disabled={!dirty || save.isPending}>
                        {t('account.profile.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function PasswordCard() {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useUpdateOwnPassword();
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirm, setConfirm] = useState('');
    const [errors, setErrors] = useState<Errors>({});

    function submit(event: FormEvent) {
        event.preventDefault();
        const input = { current_password: current, password: next, password_confirmation: confirm };
        const parsed = updateOwnPasswordRequestSchema.safeParse(input);
        if (!parsed.success) return setErrors(issuesOf(parsed.error.issues));
        if (next !== confirm) return setErrors({ password_confirmation: t('account.password.mismatch') });
        setErrors({});
        save.mutate(input, {
            onSuccess: () => {
                setCurrent('');
                setNext('');
                setConfirm('');
                notify(t('account.password.saved'));
            },
            onError: (error) => {
                const fields = problemErrors(error);
                if (fields) setErrors(fields);
                else notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
            },
        });
    }

    return (
        <Card icon={LockSimpleIcon} title={t('account.password.title')} description={t('account.password.body')}>
            <form noValidate onSubmit={submit} className="flex flex-col gap-4.5">
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label={t('account.password.current')} hint={t('account.password.currentHint')} error={errors.current_password}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="password" aria-describedby={describedBy} aria-invalid={invalid} autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
                        )}
                    </Field>
                    <div aria-hidden className="hidden md:block" />
                    <Field label={t('account.password.new')} hint={t('account.password.newHint')} error={errors.password}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="password" aria-describedby={describedBy} aria-invalid={invalid} autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} />
                        )}
                    </Field>
                    <Field label={t('account.password.confirm')} hint={t('account.password.confirmHint')} error={errors.password_confirmation}>
                        {({ id, describedBy, invalid }) => (
                            <Input id={id} type="password" aria-describedby={describedBy} aria-invalid={invalid} autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
                        )}
                    </Field>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={save.isPending || !current || !next || !confirm}>
                        {t('account.password.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function SecurityCard({ me }: { me: Me }) {
    const { t } = useI18n();
    const on = me.two_factor.enabled;

    return (
        <Card icon={ShieldIcon} title={t('account.security.title')} description={t('account.security.body')}>
            <div className="flex flex-wrap items-center gap-4">
                <div className="flex min-w-0 flex-1 basis-64 flex-col gap-0.5">
                    <p className="t-admin-body-strong text-fg-primary">{t('account.security.twoFactor')}</p>
                    <p className="t-admin-caption text-fg-secondary">
                        {on ? t('account.security.twoFactorOn') : t('account.security.twoFactorOff')}
                        {me.two_factor.required && ` ${t('account.security.required')}.`}
                    </p>
                </div>
                <StatusChip tone={on ? 'success' : 'neutral'} label={on ? t('account.security.on') : t('account.security.off')} />
                <Link to="/account/two-factor" className={buttonClass('secondary')}>
                    {on ? t('account.security.manage') : t('account.security.setUp')}
                </Link>
            </div>
        </Card>
    );
}

/** Right rail: photo (chosen from the media library), name, email, role. */
function SummaryCard({ me, roleLabel }: { me: Me; roleLabel: string }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const { choose } = useOwnAvatar();
    const [picking, setPicking] = useState(false);
    const hintId = useId();

    /** A library pick: one ready image; the API cuts the square from its master. */
    const insert = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('account.photo.notReady') }];
        try {
            await choose.mutateAsync(media.id);
        } catch (failure) {
            const fields = problemErrors(failure);
            return [{ media, reason: fields?.media_id ?? (failure instanceof ApiError ? (failure.problem.detail ?? failure.problem.title) : t('error.title')) }];
        }
        setPicking(false);
        notify(t('account.photo.saved'));
        return [];
    };

    return (
        <aside className="order-first flex flex-col gap-4 rounded-md border border-line-default bg-surface p-4 shadow-e1 md:p-5 xl:order-none">
            <div className="flex items-center gap-3">
                <Avatar name={me.name} src={me.avatar_url} size="lg" />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="truncate t-admin-section text-fg-primary">{me.name}</p>
                    <p className="truncate t-admin-caption text-fg-secondary">{me.email}</p>
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <StatusChip tone="brand" label={roleLabel} />
                <StatusChip tone="success" label={t('account.summary.active')} />
            </div>
            <div className="flex flex-col gap-2 border-t border-line-default pt-4">
                <Button variant="secondary" className="self-start" disabled={choose.isPending} aria-describedby={hintId} onClick={() => setPicking(true)}>
                    <ImagesIcon size={16} aria-hidden className="me-1.5" />
                    {t('account.photo.library')}
                </Button>
                <p id={hintId} className="t-admin-caption text-fg-secondary">
                    {t('account.photo.hint')}
                </p>
            </div>
            {picking && (
                <MediaPickerModal
                    context={{ title: t('account.photo.pickerTitle'), hint: t('account.photo.hint'), multiple: false, max: 1, kinds: ['image'], ratio: [1, 1], minSize: 256 }}
                    onInsert={insert}
                    onClose={() => setPicking(false)}
                />
            )}
        </aside>
    );
}
