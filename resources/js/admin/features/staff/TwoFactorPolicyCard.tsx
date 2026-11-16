import { ShieldCheckIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { StatusChip } from '@/components/ui/StatusChip';
import { useToast } from '@/components/ui/Toast';
import { useSaveTwoFactorSetting, useTwoFactorSetting } from './api';
import { Switch } from './parts';

/**
 * Staff & roles › Two-factor sign-in (owner request 2026-11-16): whether owners and anyone whose
 * role can see money must set up two-factor before using the admin. Off by default. Everyone with
 * staff.view sees it; only an owner (staff.manage) flips it. Who is affected (`staff_without`)
 * comes from the API, never counted client-side.
 */
export function TwoFactorPolicyCard() {
    const { t } = useI18n();
    const { notify } = useToast();
    const { data: me } = useMe();
    const setting = useTwoFactorSetting(can(me, 'staff.view'));
    const save = useSaveTwoFactorSetting();
    const manage = can(me, 'staff.manage');

    if (!setting.data) return null;
    const { required, staff_without: without } = setting.data;
    const selfEnrolled = me?.two_factor.enabled === true;

    const toggle = (next: boolean) => {
        save.mutate(
            { required: next },
            {
                onSuccess: (saved) => notify(t(saved.required ? 'staff.policy.savedOn' : 'staff.policy.savedOff')),
                onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    return (
        <section aria-labelledby="two-factor-policy" className="flex items-start gap-4 rounded-md border border-line-default bg-surface p-4 shadow-e1 md:p-5">
            <span aria-hidden className="hidden size-10 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-fg-brand sm:inline-flex">
                <ShieldCheckIcon size={20} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 id="two-factor-policy" className="t-admin-body-strong text-fg-primary">
                        {t('staff.policy.title')}
                    </h2>
                    <StatusChip tone={required ? 'success' : 'neutral'} label={t(required ? 'staff.policy.on' : 'staff.policy.off')} />
                </div>
                <p className="t-admin-body text-fg-secondary">{t('staff.policy.body')}</p>
                {required && without > 0 && <p className="t-admin-caption text-fg-secondary">{t('staff.policy.pending', { count: String(without) })}</p>}
                {!manage && <p className="t-admin-caption text-fg-tertiary">{t('staff.policy.ownerOnly')}</p>}
                {manage && !required && !selfEnrolled && <p className="t-admin-caption text-fg-tertiary">{t('staff.policy.enrolFirst')}</p>}
            </div>
            {manage && <Switch checked={required} label={t('staff.policy.title')} onChange={toggle} disabled={save.isPending} />}
        </section>
    );
}
