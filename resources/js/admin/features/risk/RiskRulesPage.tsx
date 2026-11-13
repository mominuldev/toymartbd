import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { ApiError } from '@/lib/api';
import { factorCodes, useRiskRules, useSaveRules, type RulesInput } from './api';
import { RiskTabs, crumbs } from './parts';

type Draft = Record<string, string>;

/**
 * Risk rules (checklist §2.4: "settings form pattern"; no Figma frame). Weights per factor and
 * the thresholds of our score. The server validates (weights total ≤ 100, medium < high) and
 * only future orders are scored with the new values. PROVISIONAL defaults until the owner sets them.
 */
export function RiskRulesPage() {
    const { t } = useI18n();
    const { notify } = useToast();
    const rules = useRiskRules();
    const save = useSaveRules();
    const [draft, setDraft] = useState<Draft>({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!rules.data) return;
        const next: Draft = {
            medium_threshold: String(rules.data.medium_threshold),
            high_threshold: String(rules.data.high_threshold),
            first_order_hold_threshold: rules.data.first_order_hold_threshold,
            value_norm_multiplier: rules.data.value_norm_multiplier,
            rapid_repeat_minutes: String(rules.data.rapid_repeat_minutes),
        };
        for (const code of factorCodes) next[`w.${code}`] = String(rules.data.weights[code] ?? 0);
        setDraft(next);
    }, [rules.data]);

    if (rules.isError) return <QueryError error={rules.error} onRetry={() => void rules.refetch()} />;
    const editable = rules.data?.can_edit === true;
    const set = (key: string) => (e: React.ChangeEvent<HTMLInputElement>) => setDraft((d) => ({ ...d, [key]: e.target.value }));
    const err = (key: string) => errors[key];

    const submit = () => {
        const input: RulesInput = {
            weights: Object.fromEntries(factorCodes.map((c) => [c, Number(draft[`w.${c}`] ?? 0)])) as RulesInput['weights'],
            medium_threshold: Number(draft.medium_threshold),
            high_threshold: Number(draft.high_threshold),
            first_order_hold_threshold: draft.first_order_hold_threshold ?? '',
            value_norm_multiplier: Number(draft.value_norm_multiplier),
            rapid_repeat_minutes: Number(draft.rapid_repeat_minutes),
        };
        save.mutate(input, {
            onSuccess: () => (setErrors({}), notify(t('risk.rules.saved'))),
            onError: (e) => {
                if (e instanceof ApiError && e.problem.errors) setErrors(Object.fromEntries(e.problem.errors.map((x) => [x.field, x.message])));
                else notify(t('risk.error'), 'danger');
            },
        });
    };

    return (
        <div className="flex flex-col gap-6">
            <ContentHeader title={t('risk.rules.title')} breadcrumb={crumbs(t, 'risk.tab.rules')} subtitle={t('risk.rules.subtitle')} />
            <RiskTabs />
            <p className="rounded-sm border border-line-default border-l-2 border-l-feedback-success bg-subtle px-3 py-2 t-admin-caption text-fg-primary">
                {t('risk.rules.provisional')} {!editable && rules.data ? t('risk.rules.readOnly') : ''}
            </p>
            <form
                className="flex flex-col gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <FormSection title={t('risk.rules.weights')} description={t('risk.rules.weightsHint')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {factorCodes.map((code) => (
                            <Field key={code} label={t(`risk.factor.${code}` as MessageKey)} error={err(`weights.${code}`) ?? (code === 'cancellation_rate' ? err('weights') : undefined)}>
                                {({ id, describedBy, invalid }) => (
                                    <Input
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={draft[`w.${code}`] ?? ''}
                                        onChange={set(`w.${code}`)}
                                        disabled={!editable}
                                        className="tabular"
                                    />
                                )}
                            </Field>
                        ))}
                    </div>
                </FormSection>
                <FormSection title={t('risk.rules.thresholds')} description={t('risk.rules.thresholdsHint')}>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('risk.rules.medium')} error={err('medium_threshold')}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    type="number"
                                    min={1}
                                    max={99}
                                    value={draft.medium_threshold ?? ''}
                                    onChange={set('medium_threshold')}
                                    disabled={!editable}
                                    className="tabular"
                                />
                            )}
                        </Field>
                        <Field label={t('risk.rules.high')} error={err('high_threshold')}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    type="number"
                                    min={2}
                                    max={100}
                                    value={draft.high_threshold ?? ''}
                                    onChange={set('high_threshold')}
                                    disabled={!editable}
                                    className="tabular"
                                />
                            )}
                        </Field>
                        <Field label={t('risk.rules.firstOrder')} hint={t('risk.rules.firstOrderHint')} error={err('first_order_hold_threshold')}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    inputMode="decimal"
                                    value={draft.first_order_hold_threshold ?? ''}
                                    onChange={set('first_order_hold_threshold')}
                                    disabled={!editable}
                                    className="tabular"
                                />
                            )}
                        </Field>
                    </div>
                </FormSection>
                <FormSection title={t('risk.rules.factorParams')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('risk.rules.multiplier')} hint={t('risk.rules.multiplierHint')} error={err('value_norm_multiplier')}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    inputMode="decimal"
                                    value={draft.value_norm_multiplier ?? ''}
                                    onChange={set('value_norm_multiplier')}
                                    disabled={!editable}
                                    className="tabular"
                                />
                            )}
                        </Field>
                        <Field label={t('risk.rules.rapid')} error={err('rapid_repeat_minutes')}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    type="number"
                                    min={1}
                                    max={1440}
                                    value={draft.rapid_repeat_minutes ?? ''}
                                    onChange={set('rapid_repeat_minutes')}
                                    disabled={!editable}
                                    className="tabular"
                                />
                            )}
                        </Field>
                    </div>
                </FormSection>
                {editable && (
                    <div className="flex justify-end">
                        <Button type="submit" disabled={save.isPending}>
                            {t('risk.rules.save')}
                        </Button>
                    </div>
                )}
            </form>
        </div>
    );
}
