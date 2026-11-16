import { useId, useState, type FormEvent } from 'react';
import { CashRegisterIcon } from '@phosphor-icons/react';
import { openPosSessionRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useOpenSession, type PosContext } from './api';
import { TAKA } from './format';
import { useWorkingBranchIn } from '@/components/shell/workingBranch';

/**
 * No open drawer: pick the branch, name the register, enter the float counted into the
 * drawer. Not drawn in Figma (B · 33 shows an open drawer); built from AdminField 185:151,
 * AdminInput 184:338 and AdminSelect 184:369 on a surface card.
 */
export function OpenDrawer({ context }: { context: PosContext }) {
    const { t, locale } = useI18n();
    const open = useOpenSession();
    const headingId = useId();
    // The sidebar's working branch is the default branch for a new drawer (else the first one).
    const working = useWorkingBranchIn(context.locations.map((l) => l.id));
    const [locationId, setLocationId] = useState(String(working ?? context.locations[0]?.id ?? ''));
    const [register, setRegister] = useState(t('pos.open.registerDefault'));
    const [float, setFloat] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    if (context.locations.length === 0) {
        return (
            <div className="grid flex-1 place-items-center p-6">
                <EmptyState reason="noDataYet" title={t('pos.open.noBranch')} titleAs="h1" />
            </div>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input = { location_id: Number(locationId), register: register.trim(), opening_float: float.trim() };
        const parsed = openPosSessionRequestSchema.safeParse(input);
        if (!parsed.success) {
            const next: Record<string, string> = {};
            for (const issue of parsed.error.issues) next[String(issue.path[0])] ??= t('pos.open.invalid');
            setErrors(next);
            return;
        }
        setErrors({});
        open.mutate(parsed.data, {
            onError: (error) => {
                if (error instanceof ApiError) {
                    const next: Record<string, string> = {};
                    for (const e of error.problem.errors ?? []) next[e.field] ??= e.message;
                    setErrors(Object.keys(next).length > 0 ? next : { form: error.problem.detail ?? error.problem.title });
                }
            },
        });
    };

    return (
        <div className="grid flex-1 place-items-center p-6">
            <form onSubmit={submit} aria-labelledby={headingId} className="flex w-full max-w-md flex-col gap-5 rounded-lg border border-line-default bg-surface p-6 shadow-e1">
                <div className="flex items-start gap-3">
                    <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-md bg-brand-subtle text-fg-brand">
                        <CashRegisterIcon size={20} />
                    </span>
                    <div className="flex flex-col gap-1">
                        <h1 id={headingId} className="t-admin-section text-fg-primary">
                            {t('pos.open.title')}
                        </h1>
                        <p className="t-admin-body text-fg-secondary">{t('pos.open.body')}</p>
                    </div>
                </div>
                {/* One shop (Branch setup: single shop, or one active location): no branch to pick. */}
                {context.locations.length > 1 && (
                    <Field label={t('pos.open.branch')} error={errors.location_id}>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={locationId} onChange={(e) => setLocationId(e.target.value)}>
                                {context.locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {locale === 'bn' && l.name_bn ? l.name_bn : l.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                )}
                <Field label={t('pos.open.register')} error={errors.register}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} value={register} maxLength={40} onChange={(e) => setRegister(e.target.value)} />
                    )}
                </Field>
                <Field label={t('pos.open.float')} hint={t('pos.open.floatHint')} error={errors.opening_float}>
                    {({ id, describedBy, invalid }) => (
                        <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} prefix={TAKA} inputMode="decimal" value={float} onChange={(e) => setFloat(e.target.value)} />
                    )}
                </Field>
                {errors.form && (
                    <p role="alert" className="t-admin-body text-fg-primary">
                        {errors.form}
                    </p>
                )}
                <Button type="submit" size="lg" disabled={open.isPending}>
                    {t('pos.open.submit')}
                </Button>
            </form>
        </div>
    );
}
