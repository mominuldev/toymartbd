import { useEffect, useState } from 'react';
import { updateAutomationSettingsRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { useToast } from '@/components/ui/Toast';
import { Select } from '@/components/ui/Select';
import { scheduledAutomations, useAutomationSettings, useSaveAutomationSettings, type AutomationSettingsInput } from './automationsApi';
import { Panel } from './parts';

/**
 * Schedule + consent card for the two scheduled automations (P8): review request (days after
 * delivery, Dhaka quiet hours) and birthday reminder (P8 batch 3: to consenting parents, lead days
 * before each child's birthday, from 10:00 Dhaka, plus the personal-coupon offer — off, with
 * placeholder values, until the owner saves it).
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
    // Birthday reminder (P8 batch 3): lead days + the personal-coupon offer.
    const [lead, setLead] = useState('');
    const [offerOn, setOfferOn] = useState(false);
    const [offerType, setOfferType] = useState<'percent' | 'fixed'>('percent');
    const [offerValue, setOfferValue] = useState('');
    const [offerMax, setOfferMax] = useState('');
    const [offerMin, setOfferMin] = useState('');
    const [offerDays, setOfferDays] = useState('');

    useEffect(() => {
        if (!settings.data) return;
        setDelay(String(settings.data.review_delay_days));
        setFrom(settings.data.quiet_from);
        setTo(settings.data.quiet_to);
        const o = settings.data.birthday_offer;
        setLead(String(settings.data.birthday_lead_days));
        setOfferOn(o.enabled);
        setOfferType(o.type);
        setOfferValue(o.value);
        setOfferMax(o.max_discount ?? '');
        setOfferMin(o.min_order_amount);
        setOfferDays(String(o.valid_days));
    }, [settings.data]);

    if (!automation || !settings.data) return null;
    const stats = settings.data.stats[automation];
    const skipped = stats ? Object.entries(stats.skip_reasons).map(([reason, count]) => t(`automations.skip.${reason}` as MessageKey, { count })) : [];

    const submit = () => {
        const input: AutomationSettingsInput =
            automation === 'birthday'
                ? {
                      review_delay_days: Number(delay),
                      quiet_from: from,
                      quiet_to: to,
                      birthday_lead_days: Number(lead),
                      birthday_offer: {
                          enabled: offerOn,
                          type: offerType,
                          value: offerValue.trim(),
                          max_discount: offerType === 'percent' && offerMax.trim() !== '' ? offerMax.trim() : null,
                          min_order_amount: offerMin.trim() === '' ? '0' : offerMin.trim(),
                          valid_days: Number(offerDays),
                      },
                  }
                : { review_delay_days: Number(delay), quiet_from: from, quiet_to: to };
        if (!updateAutomationSettingsRequestSchema.safeParse(input).success) {
            setError(t(automation === 'birthday' ? 'automations.invalidBirthday' : 'automations.invalid'));
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
                <div className="flex flex-col gap-3.5">
                    <p className="t-admin-body text-fg-primary">{t('automations.birthdayAt', { time: settings.data.birthday_send_at })}</p>
                    <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                        <Field label={t('automations.lead')} hint={t('automations.leadHint')} error={error}>
                            {({ id, describedBy, invalid }) => (
                                <input id={id} type="number" min={0} max={30} inputMode="numeric" disabled={!editable} value={lead} onChange={(e) => setLead(e.target.value)} aria-describedby={describedBy} aria-invalid={invalid || undefined} className={`h-10 tabular ${inputClass}`} />
                            )}
                        </Field>
                    </div>
                    <fieldset className="flex flex-col gap-3.5 rounded-sm border border-line-default p-3.5">
                        <legend className="px-1 t-admin-body-strong text-fg-primary">{t('automations.offer.title')}</legend>
                        <label className="inline-flex items-start gap-2 t-admin-body text-fg-primary">
                            <input type="checkbox" checked={offerOn} disabled={!editable} onChange={(e) => setOfferOn(e.target.checked)} className="mt-0.5 size-4 shrink-0 accent-brand" />
                            {t('automations.offer.enabled')}
                        </label>
                        {settings.data.birthday_offer.is_placeholder && (
                            <p role="note" className="rounded-sm bg-subtle px-3 py-2 t-admin-caption text-fg-primary">
                                {t('automations.offer.placeholder')}
                            </p>
                        )}
                        <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                            <Field label={t('automations.offer.type')}>
                                {({ id }) => (
                                    <Select id={id} value={offerType} disabled={!editable} onChange={(e) => setOfferType(e.target.value === 'fixed' ? 'fixed' : 'percent')}>
                                        <option value="percent">{t('automations.offer.percent')}</option>
                                        <option value="fixed">{t('automations.offer.fixed')}</option>
                                    </Select>
                                )}
                            </Field>
                            <Field label={t(offerType === 'percent' ? 'automations.offer.valuePercent' : 'automations.offer.valueFixed')}>
                                {({ id }) => <input id={id} inputMode="decimal" disabled={!editable} value={offerValue} onChange={(e) => setOfferValue(e.target.value)} className={`h-10 tabular ${inputClass}`} />}
                            </Field>
                            {offerType === 'percent' && (
                                <Field label={t('automations.offer.max')} hint={t('automations.offer.maxHint')} optional>
                                    {({ id, describedBy }) => <input id={id} inputMode="decimal" disabled={!editable} value={offerMax} onChange={(e) => setOfferMax(e.target.value)} aria-describedby={describedBy} className={`h-10 tabular ${inputClass}`} />}
                                </Field>
                            )}
                            <Field label={t('automations.offer.min')} hint={t('automations.offer.minHint')}>
                                {({ id, describedBy }) => <input id={id} inputMode="decimal" disabled={!editable} value={offerMin} onChange={(e) => setOfferMin(e.target.value)} aria-describedby={describedBy} className={`h-10 tabular ${inputClass}`} />}
                            </Field>
                            <Field label={t('automations.offer.days')} hint={t('automations.offer.daysHint')}>
                                {({ id, describedBy }) => (
                                    <input id={id} type="number" min={1} max={90} inputMode="numeric" disabled={!editable} value={offerDays} onChange={(e) => setOfferDays(e.target.value)} aria-describedby={describedBy} className={`h-10 tabular ${inputClass}`} />
                                )}
                            </Field>
                        </div>
                        <p className="t-admin-caption text-fg-secondary">{t('automations.offer.note')}</p>
                    </fieldset>
                    {editable && (
                        <div className="flex justify-end">
                            <Button variant="outline" onClick={submit} disabled={save.isPending}>
                                {t('automations.save')}
                            </Button>
                        </div>
                    )}
                </div>
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
