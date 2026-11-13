import { ArrowDownIcon, ArrowUpIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { Select } from '@/components/ui/Select';
import { DescriptionEditor } from '@/features/catalog/products/DescriptionEditor';
import { ageBands } from './api';
import { emptySection, type PostForm, type SectionForm } from './form';
import { ProductLinksField } from './ProductLinksField';

type Props = {
    form: PostForm;
    set: <K extends keyof PostForm>(key: K, value: PostForm[K]) => void;
    errorOf: (field: string) => string | undefined;
    disabled: boolean;
};

const moneyInput = (props: { id: string; describedBy?: string; invalid: boolean; value: string; onChange: (v: string) => void; disabled: boolean }) => (
    <span className="flex h-10 items-stretch overflow-hidden rounded-sm border border-line-default bg-surface focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger">
        <span aria-hidden className="flex items-center border-e border-line-default bg-subtle px-3 t-admin-currency-num text-fg-tertiary">
            ৳
        </span>
        <input
            id={props.id}
            inputMode="decimal"
            aria-describedby={props.describedBy}
            aria-invalid={props.invalid || undefined}
            disabled={props.disabled}
            className="min-w-0 flex-1 bg-transparent px-3 t-admin-body tabular-nums text-fg-primary outline-none"
            value={props.value}
            onChange={(e) => props.onChange(e.target.value)}
        />
    </span>
);

/**
 * Buying-guide structure (no Figma frame of its own; FormSection 198:901 language): the guide's age
 * band and budget, then ordered sections — heading and intro in both languages (the same TipTap
 * editor as the post body), optional age band and budget, and product picks with a reason.
 */
export function GuideSections({ form, set, errorOf, disabled }: Props) {
    const { t } = useI18n();
    const update = (index: number, patch: Partial<SectionForm>) => set('sections', form.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    const move = (index: number, by: -1 | 1) => {
        const next = [...form.sections];
        const [row] = next.splice(index, 1);
        if (row) next.splice(index + by, 0, row);
        set('sections', next);
    };
    const ageSelect = (id: string, value: string, onChange: (v: SectionForm['age_band']) => void) => (
        <Select id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value as SectionForm['age_band'])}>
            <option value="">{t('blog.guide.anyAge')}</option>
            {ageBands.map((band) => (
                <option key={band} value={band}>
                    {t(`blog.age.${band}`)}
                </option>
            ))}
        </Select>
    );

    return (
        <FormSection
            title={t('blog.guide.title')}
            description={t('blog.guide.hint')}
            aside={
                !disabled && (
                    <Button variant="outline" size="sm" onClick={() => set('sections', [...form.sections, emptySection()])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('blog.guide.addSection')}
                    </Button>
                )
            }
        >
            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                <Field label={t('blog.guide.ageBand')} error={errorOf('guide_age_band')} optional>
                    {({ id }) => ageSelect(id, form.guide_age_band, (v) => set('guide_age_band', v))}
                </Field>
                <Field label={t('blog.guide.budgetMin')} error={errorOf('guide_budget_min')} optional>
                    {({ id, describedBy, invalid }) => moneyInput({ id, describedBy, invalid, value: form.guide_budget_min, onChange: (v) => set('guide_budget_min', v), disabled })}
                </Field>
                <Field label={t('blog.guide.budgetMax')} error={errorOf('guide_budget_max')} optional>
                    {({ id, describedBy, invalid }) => moneyInput({ id, describedBy, invalid, value: form.guide_budget_max, onChange: (v) => set('guide_budget_max', v), disabled })}
                </Field>
            </div>

            {form.sections.length === 0 && <p className="t-admin-body text-fg-secondary">{t('blog.guide.noSections')}</p>}
            <ol className="flex flex-col gap-4">
                {form.sections.map((section, index) => {
                    const n = index + 1;
                    const err = (field: string) => errorOf(`sections.${index}.${field}`);
                    return (
                        <li key={section.uid} className="flex flex-col gap-3.5 rounded-sm border border-line-default p-4">
                            <div className="flex items-center gap-2">
                                <h3 className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t('blog.guide.section', { n })}</h3>
                                {!disabled && (
                                    <>
                                        <IconButton size="sm" aria-label={t('blog.guide.moveUp', { n })} disabled={index === 0} onClick={() => move(index, -1)}>
                                            <ArrowUpIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton size="sm" aria-label={t('blog.guide.moveDown', { n })} disabled={index === form.sections.length - 1} onClick={() => move(index, 1)}>
                                            <ArrowDownIcon size={16} aria-hidden />
                                        </IconButton>
                                        <IconButton size="sm" aria-label={t('blog.guide.removeSection', { n })} onClick={() => set('sections', form.sections.filter((_, i) => i !== index))}>
                                            <TrashIcon size={16} aria-hidden />
                                        </IconButton>
                                    </>
                                )}
                            </div>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2">
                                <Field label={t('blog.guide.heading')} error={err('heading')} required>
                                    {({ id, describedBy, invalid }) => (
                                        <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} disabled={disabled} className={`h-10 ${inputClass}`} value={section.heading} onChange={(e) => update(index, { heading: e.target.value })} />
                                    )}
                                </Field>
                                <Field label={t('blog.guide.headingBn')} error={err('heading_bn')} optional>
                                    {({ id, describedBy, invalid }) => (
                                        <input id={id} lang="bn" aria-describedby={describedBy} aria-invalid={invalid || undefined} disabled={disabled} className={`h-10 ${inputClass}`} value={section.heading_bn} onChange={(e) => update(index, { heading_bn: e.target.value })} />
                                    )}
                                </Field>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <span className="t-admin-body-strong text-fg-primary">{t('blog.guide.intro')}</span>
                                <DescriptionEditor
                                    value={{ en: section.body, bn: section.body_bn }}
                                    onChange={(language, value) => update(index, language === 'en' ? { body: value } : { body_bn: value })}
                                    error={err('body') ?? err('body_bn')}
                                    invalid={{ en: Boolean(err('body')), bn: Boolean(err('body_bn')) }}
                                    disabled={disabled}
                                />
                            </div>
                            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-3">
                                <Field label={t('blog.guide.ageBand')} error={err('age_band')} optional>
                                    {({ id }) => ageSelect(id, section.age_band, (v) => update(index, { age_band: v }))}
                                </Field>
                                <Field label={t('blog.guide.budgetMin')} error={err('budget_min')} optional>
                                    {({ id, describedBy, invalid }) => moneyInput({ id, describedBy, invalid, value: section.budget_min, onChange: (v) => update(index, { budget_min: v }), disabled })}
                                </Field>
                                <Field label={t('blog.guide.budgetMax')} error={err('budget_max')} optional>
                                    {({ id, describedBy, invalid }) => moneyInput({ id, describedBy, invalid, value: section.budget_max, onChange: (v) => update(index, { budget_max: v }), disabled })}
                                </Field>
                            </div>
                            <ProductLinksField label={t('blog.guide.picks')} value={section.picks} onChange={(picks) => update(index, { picks })} max={12} notes disabled={disabled} />
                        </li>
                    );
                })}
            </ol>
        </FormSection>
    );
}
