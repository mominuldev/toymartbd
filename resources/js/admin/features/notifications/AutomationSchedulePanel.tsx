import { useEffect, useState } from 'react';
import { updateAutomationSettingsRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { useToast } from '@/components/ui/Toast';
import { scheduledAutomations, useAutomationSettings, useSaveAutomationSettings } from './automationsApi';
import { Panel } from './parts';

/**
 * Schedule + consent card for the two scheduled automations (P8): review request (days after
 * delivery, Dhaka quiet hours) and birthday (fixed 10:00 Dhaka, consented customers only).
 * Not drawn in Figma (B · 36 241:49903 shows event-driven cards only); built from the same
 * Panel / AdminField parts as the template editor beside it. The last-30-days counts come
 * from the server, including how many were skipped for consent.
 */
export function AutomationSchedulePanel({ templateKey }: { templateKey: string }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const automation = scheduledAutomations[templateKey];
    const settings = useAutomationSettings(automation !== undefined);
    const save = useSaveAutomationSettings();
    const editable = can(me, 'settings.update');
    const [delay, setDelay] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [error, setError] = useState<string | undefined>();

    useEffect(() => {
        if (!settings.data) return;
        setDelay(String(settings.data.review_delay_days));
        setFrom(settings.data.quiet_from);
        setTo(settings.data.quiet_to);
    }, [settings.data]);

    if (!automation || !settings.data) return null;
    const stats = settings.data.stats[automation];
    const skipped = stats ? Object.entries(stats.skip_reasons).map(([reason, count]) => t(`automations.skip.${reason}` as MessageKey, { count })) : [];

    const submit = () => {
        const input = { review_delay_days: Number(delay), quiet_from: from, quiet_to: to };
        if (!updateAutomationSettingsRequestSchema.safeParse(input).success) {
            setError(t('automations.invalid'));
            return;
        }
        setError(undefined);
        save.mutate(input, {
            onSuccess: () => notify(t('automations.saved')),
            onError: (e) => notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger'),
        });
    };

    return (
        <Panel title={t('automations.schedule.title')} subtitle={t(`automations.schedule.${automation}` as MessageKey)}>
            {automation === 'review_request' ? (
                <div className="flex flex-col gap-3.5">
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                        <Field label={t('automations.delay')} hint={t('automations.delayHint')} error={error}>
                            {({ id, describedBy, invalid }) => (
                                <input id={id} type="number" min={1} max={30} inputMode="numeric" disabled={!editable} value={delay} onChange={(e) => setDelay(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 tabular ${inputClass}`} />
                            )}
                        </Field>
                        <Field label={t('automations.quietFrom')}>
                            {({ id }) => <input id={id} type="time" disabled={!editable} value={from} onChange={(e) => setFrom(e.target.value)} className={`h-10 tabular ${inputClass}`} />}
                        </Field>
                        <Field label={t('automations.quietTo')} hint={t('automations.quietHint')}>
                            {({ id, describedBy }) => <input id={id} type="time" disabled={!editable} value={to} onChange={(e) => setTo(e.target.value)} aria-describedby={describedBy} className={`h-10 tabular ${inputClass}`} />}
                        </Field>
                    </div>
                    {editable && (
                        <div className="flex justify-end">
                            <Button variant="outline" onClick={submit} disabled={save.isPending}>
                                {t('automations.save')}
                            </Button>
                        </div>
                    )}
                </div>
            ) : (
                <p className="t-admin-body text-fg-primary">{t('automations.birthdayAt', { time: settings.data.birthday_send_at })}</p>
            )}
            <p className="rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary">{t(`automations.consent.${automation}` as MessageKey)}</p>
            {stats && (
                <p className="t-admin-caption tabular text-fg-secondary">
                    {t('automations.stats', { queued: stats.queued, skipped: stats.skipped })}
                    {skipped.length > 0 && ` · ${skipped.join(' · ')}`}
                </p>
            )}
        </Panel>
    );
}
