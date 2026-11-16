import { useEffect, useId, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { BuildingsIcon, GitBranchIcon, StorefrontIcon, WarningCircleIcon, type Icon } from '@phosphor-icons/react';
import { updateBranchModeRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button, buttonClass } from '@/components/ui/Button';
import { useToast } from '@/components/ui/Toast';
import { BrandingCard } from './brandingParts';
import { blockersOf, useSaveBranchMode, type BranchMode, type BranchModeLocation } from './branchModeApi';
import { StickySaveBar, UnsavedChangesGuard, useChangeSummary, useSavedFlash } from './parts';

const FORM_ID = 'branch-mode-form';

type Mode = 'single' | 'multi';

const options: readonly { value: Mode; icon: Icon; title: MessageKey; body: MessageKey }[] = [
    { value: 'single', icon: StorefrontIcon, title: 'branchMode.single.title', body: 'branchMode.single.body' },
    { value: 'multi', icon: BuildingsIcon, title: 'branchMode.multi.title', body: 'branchMode.multi.body' },
];

/** `n` units in the reader's numerals-free format (rule 36: Western digits in both locales). */
const units = (n: number) => n.toLocaleString('en-US');

/**
 * Store settings › Branches & warehouses › Branch setup (Figma 410:69597 multi, 410:69706
 * single, 410:69769 blocked): a single shop or multiple branches. Two radio cards — radio,
 * title and body, an icon tile on the right; the chosen one bg/brand-subtle with a border/brand
 * edge. Saves through the sticky save bar ("Unsaved changes in {section}"). The server refuses
 * a single shop while other branches are active (409 `branch-mode-blocked`): the card then
 * shows the danger alert naming them with the units still on hand, a Transfer stock link and
 * "Keep multiple branches", which puts the choice back.
 */
export function BranchModeCard({ mode, editable, section }: { mode: BranchMode; editable: boolean; section: string }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const save = useSaveBranchMode();
    const groupId = useId();
    const saved: Mode = mode.multi_branch ? 'multi' : 'single';
    const [value, setValue] = useState<Mode>(saved);
    const [blockers, setBlockers] = useState<BranchModeLocation[] | null>(null);
    const [justSaved, flashSaved] = useSavedFlash();

    useEffect(() => setValue(saved), [saved]);

    const dirty = value !== saved;
    const summary = useChangeSummary(dirty ? [t('branchMode.changed')] : []);
    const nameOf = (l: { name: string; name_bn: string | null }) => (locale === 'bn' && l.name_bn ? l.name_bn : l.name);
    const home = mode.default_location ? nameOf(mode.default_location) : t('branchMode.blocked.home');

    const choose = (next: Mode) => {
        setValue(next);
        setBlockers(null);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const parsed = updateBranchModeRequestSchema.safeParse({ multi_branch: value === 'multi' });
        if (!parsed.success) return;
        save.mutate(parsed.data, {
            onSuccess: () => {
                setBlockers(null);
                flashSaved();
            },
            onError: (e) => {
                const list = blockersOf(e);
                if (list) setBlockers(list);
                else notify(e instanceof ApiError ? (e.problem.detail ?? e.problem.title) : t('error.title'), 'danger');
            },
        });
    };

    // A transfer moves a blocker's stock to the default location (the inventory screen opens its dialog).
    const transferHref = blockers?.find((b) => b.units > 0) ? `/inventory?transfer=${blockers.find((b) => b.units > 0)?.id ?? ''}` : '/inventory';

    return (
        <form id={FORM_ID} onSubmit={submit} noValidate aria-label={t('branchMode.title')}>
            {editable && <UnsavedChangesGuard when={dirty} />}
            {editable && <StickySaveBar section={section} dirty={dirty} saved={justSaved} summary={summary} saving={save.isPending} onDiscard={() => choose(saved)} form={FORM_ID} />}
            <BrandingCard icon={GitBranchIcon} tone="brand" title={t('branchMode.title')} description={t('branchMode.description')}>
                <fieldset aria-describedby={blockers ? `${groupId}-blocked` : undefined} className="grid gap-3 sm:grid-cols-2">
                    <legend className="sr-only">{t('branchMode.title')}</legend>
                    {options.map((o) => {
                        const checked = value === o.value;
                        return (
                            <label
                                key={o.value}
                                className={`flex min-h-11 items-start gap-3 rounded-sm p-3.5 transition-colors duration-150 ease-standard has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-line-focus ${
                                    checked ? 'border-[1.5px] border-line-brand bg-brand-subtle' : 'border border-line-default bg-surface hover:bg-subtle'
                                } ${editable ? 'cursor-pointer' : 'cursor-default'}`}
                            >
                                <input
                                    type="radio"
                                    name={`${groupId}-mode`}
                                    value={o.value}
                                    checked={checked}
                                    disabled={!editable || save.isPending}
                                    onChange={() => choose(o.value)}
                                    className="sr-only"
                                />
                                {/* Figma Radio · on / off: an 18 disc, brand with a surface dot when chosen. */}
                                <span
                                    aria-hidden
                                    className={`mt-0.5 grid size-4.5 shrink-0 place-items-center rounded-full ${checked ? 'bg-brand' : 'border-[1.5px] border-line-strong bg-surface'}`}
                                >
                                    {checked && <span className="size-1.75 rounded-full bg-surface" />}
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="t-admin-body-strong text-fg-primary">{t(o.title)}</span>
                                    <span className="t-admin-caption text-fg-secondary">{t(o.body)}</span>
                                </span>
                                <span aria-hidden className={`grid size-8 shrink-0 place-items-center rounded-xs-plus ${checked ? 'bg-surface text-fg-brand' : 'bg-subtle text-fg-secondary'}`}>
                                    <o.icon size={16} />
                                </span>
                            </label>
                        );
                    })}
                </fieldset>
                {blockers && (
                    <div id={`${groupId}-blocked`} role="alert" className="flex items-start gap-3 rounded-sm bg-danger-subtle px-3.5 py-3">
                        <WarningCircleIcon size={20} aria-hidden className="shrink-0 text-feedback-danger" />
                        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                            <p className="t-admin-body-strong text-fg-primary">{t('branchMode.blocked.title')}</p>
                            <p className="t-admin-body text-fg-primary">{t('branchMode.blocked.body', { home })}</p>
                            {blockers.length > 0 && (
                                <ul className="list-disc ps-5 t-admin-body-strong text-fg-primary">
                                    {blockers.map((b) => (
                                        <li key={b.id}>{t('branchMode.blocked.item', { name: nameOf(b), units: units(b.units) })}</li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap gap-2 pt-0.5">
                                <Link to={transferHref} className={`${buttonClass('outline', 'sm')} max-lg:h-11`}>
                                    {t('branchMode.blocked.transfer')}
                                </Link>
                                <Button variant="ghost" size="sm" className="max-lg:h-11" onClick={() => choose('multi')}>
                                    {t('branchMode.blocked.keep')}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </BrandingCard>
        </form>
    );
}
