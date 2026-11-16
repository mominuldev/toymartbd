import { useEffect, useState, type FormEvent } from 'react';
import { updatePosSettingsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Field } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash, useSettingsAccess } from './parts';

const FORM_ID = 'pos-settings-form';
import { usePosSettings, useSavePosSettings, type PosSettings } from './posSettingsApi';

/**
 * Store settings › Point of sale: the most a cashier may take off a sale, as a percent of the
 * goods after promotions and the coupon (rule 33d), and the due limit per customer (rule 33c).
 * Read with settings.view, save with settings.update — both in one PUT. The register's next
 * quote uses the saved values.
 */
/** The taka sign — a currency symbol, not translatable copy. */
const TAKA = '৳';

/** "5000.00" → "5000" for editing; display only, never arithmetic (rule 1). */
const dueDraft = (decimal: string) => decimal.replace(/\.00$/, '');

export function PosSection() {
    const { t } = useI18n();
    const { me, canView, canEdit } = useSettingsAccess();
    const settings = usePosSettings(canView);

    if (me && !canView) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    if (settings.isError) return <QueryError error={settings.error} onRetry={() => void settings.refetch()} />;
    if (!settings.data) return <p aria-busy="true" className="sr-only" />;

    return <PosCard settings={settings.data} editable={canEdit} />;
}

function PosCard({ settings, editable }: { settings: PosSettings; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSavePosSettings();
    const [cap, setCap] = useState(settings.discount_max_percent);
    const [due, setDue] = useState(dueDraft(settings.due_limit));
    const [errors, setErrors] = useState<{ cap?: string; due?: string }>({});

    useEffect(() => setCap(settings.discount_max_percent), [settings.discount_max_percent]);
    useEffect(() => setDue(dueDraft(settings.due_limit)), [settings.due_limit]);

    const [justSaved, flashSaved] = useSavedFlash();
    const capChanged = cap.trim() !== settings.discount_max_percent;
    const dueChanged = due.trim() !== dueDraft(settings.due_limit);
    const dirty = capChanged || dueChanged;
    const summary = useChangeSummary([capChanged ? t('storeSettings.changed.posDiscount') : null, dueChanged ? t('storeSettings.changed.posDue') : null].filter((x): x is string => x !== null));

    const discard = () => {
        setCap(settings.discount_max_percent);
        setDue(dueDraft(settings.due_limit));
        setErrors({});
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = { discount_max_percent: cap.trim(), due_limit: due.trim() };
        // The API's own schema (rule 27) plus the shapes it accepts; the range is the server's, its message shown.
        const parsed = updatePosSettingsRequestSchema.safeParse(input);
        const local = {
            cap: /^\d{1,3}(\.\d{1,2})?$/.test(input.discount_max_percent) ? undefined : t('posSettings.discount.invalid'),
            due: /^\d{1,12}(\.\d{1,2})?$/.test(input.due_limit) ? undefined : t('posSettings.due.invalid'),
        };
        if (!parsed.success || local.cap || local.due) {
            setErrors(local);
            return;
        }
        setErrors({});
        save.mutate(parsed.data, {
            onSuccess: () => flashSaved(),
            onError: (e) => {
                if (e instanceof ApiError && e.status === 422) setErrors({ cap: e.fieldError('discount_max_percent'), due: e.fieldError('due_limit') });
                else notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger');
            },
        });
    };

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('posSettings.title')}>
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && (
                <StickySaveBar section={t('storeSettings.nav.pos')} dirty={dirty} saved={justSaved} savedLabel={t('posSettings.saved')} summary={summary} saving={save.isPending} onDiscard={discard} form={FORM_ID} />
            )}
            <FormSection title={t('posSettings.title')} description={t('posSettings.description')}>
                <Field
                    label={t('posSettings.discount.label')}
                    hint={settings.discount_saved ? t('posSettings.discount.hint') : t('posSettings.discount.hintDefault', { percent: settings.discount_max_percent_default })}
                    error={errors.cap}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid}
                            className="max-w-40"
                            inputMode="decimal"
                            suffix="%"
                            disabled={!editable || save.isPending}
                            value={cap}
                            onChange={(e) => setCap(e.target.value)}
                        />
                    )}
                </Field>
                <Field
                    label={t('posSettings.due.label')}
                    hint={settings.due_saved ? t('posSettings.due.hint') : t('posSettings.due.hintDefault', { amount: dueDraft(settings.due_limit_default) })}
                    error={errors.due}
                >
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid}
                            className="max-w-48"
                            inputMode="decimal"
                            prefix={TAKA}
                            disabled={!editable || save.isPending}
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                        />
                    )}
                </Field>
            </FormSection>
        </form>
    );
}
