import { useEffect, useState } from 'react';
import { CheckIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Field, inputClass } from '@/components/ui/Field';
import { FormSection } from '@/components/ui/FormSection';
import { SearchField } from '@/components/ui/SearchField';
import { Select } from '@/components/ui/Select';
import { StatusChip } from '@/components/ui/StatusChip';
import { useLinkTargets } from './api';
import { isTargetType, linkTypes, type LinkType, type MenuNode, type NodeError } from './tree';

type Props = {
    node: MenuNode;
    depth: number;
    error?: NodeError;
    onChange: (patch: Partial<Omit<MenuNode, 'key' | 'children'>>) => void;
    onDone: () => void;
    disabled?: boolean;
};

export const nodeErrorKey: Record<NodeError, MessageKey> = {
    label: 'content.menus.error.label',
    target: 'content.menus.error.target',
    url: 'content.menus.error.url',
    treeTop: 'content.menus.error.treeTop',
};

/** The selected item's fields: EN/বাংলা label, what it links to (with a target search), visibility, new tab. */
export function MenuItemPanel({ node, depth, error, onChange, onDone, disabled }: Props) {
    const { t } = useI18n();
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    useEffect(() => {
        const id = window.setTimeout(() => setDebounced(search.trim()), 250);
        return () => window.clearTimeout(id);
    }, [search]);
    const needsTarget = isTargetType(node.link_type);
    const targets = useLinkTargets(node.link_type, debounced, needsTarget && !disabled);
    const typeLabel = (type: LinkType) => t(`content.menus.type.${type}` as MessageKey);

    return (
        <FormSection title={node.label.trim() === '' ? t('content.menus.itemFormNew') : t('content.menus.itemForm')}>
            <div className="grid gap-x-4 gap-y-3.5 md:grid-cols-2 xl:grid-cols-1">
                <Field label={t('content.menus.field.label')} error={error === 'label' ? t(nodeErrorKey.label) : undefined}>
                    {({ id, describedBy, invalid }) => (
                        <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} maxLength={80} className={`h-10 ${inputClass}`} value={node.label} onChange={(e) => onChange({ label: e.target.value })} />
                    )}
                </Field>
                <Field label={t('content.menus.field.labelBn')} optional>
                    {({ id }) => <input id={id} lang="bn" maxLength={80} className={`h-10 ${inputClass}`} value={node.label_bn ?? ''} onChange={(e) => onChange({ label_bn: e.target.value })} />}
                </Field>
            </div>
            <Field label={t('content.menus.field.linkType')} error={error === 'treeTop' ? t(nodeErrorKey.treeTop) : undefined}>
                {({ id }) => (
                    <Select
                        id={id}
                        value={node.link_type}
                        onChange={(e) => onChange({ link_type: e.target.value as LinkType, link_id: null, target: null, url: e.target.value === 'custom' ? (node.url ?? '') : null })}
                    >
                        {linkTypes
                            .filter((type) => type !== 'category_tree' || depth === 1)
                            .map((type) => (
                                <option key={type} value={type}>
                                    {typeLabel(type)}
                                </option>
                            ))}
                    </Select>
                )}
            </Field>

            {needsTarget && (
                <div className="flex flex-col gap-2">
                    <span className="t-admin-body-strong text-fg-primary">{t('content.menus.field.target', { type: typeLabel(node.link_type) })}</span>
                    <div className="flex items-center gap-2 rounded-sm border border-line-default bg-subtle px-3 py-2">
                        {node.target ? (
                            <>
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="t-admin-body-strong truncate text-fg-primary">{node.target.label}</span>
                                    <span className="t-admin-mono-sm truncate text-fg-tertiary">{node.target.path}</span>
                                </span>
                                {!node.target.live && <StatusChip tone="warning" icon={WarningIcon} label={t('content.menus.deadTarget')} />}
                            </>
                        ) : (
                            <span className="t-admin-body text-fg-secondary">{t('content.menus.field.targetNone')}</span>
                        )}
                    </div>
                    {error === 'target' && <p className="t-admin-caption text-fg-primary">{t(nodeErrorKey.target)}</p>}
                    <SearchField label={t('content.menus.field.targetSearch')} value={search} onChange={(e) => setSearch(e.target.value)} className="w-full" disabled={disabled} />
                    <ul className="flex max-h-56 flex-col overflow-y-auto">
                        {(targets.data?.data ?? []).map((target) => (
                            <li key={target.id}>
                                <button
                                    type="button"
                                    disabled={disabled}
                                    aria-label={t('content.menus.field.choose', { label: target.label })}
                                    aria-pressed={node.link_id === target.id}
                                    onClick={() =>
                                        onChange({
                                            link_id: target.id,
                                            target: { label: target.label, label_bn: target.label_bn, path: target.path, live: target.live },
                                            label: node.label.trim() === '' ? target.label : node.label,
                                            label_bn: (node.label_bn ?? '') === '' ? target.label_bn : node.label_bn,
                                        })
                                    }
                                    className="flex min-h-touch-min w-full items-center gap-2 rounded-sm px-2 text-start hover:bg-subtle"
                                >
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="t-admin-body truncate text-fg-primary">{target.label}</span>
                                        <span className="t-admin-mono-sm truncate text-fg-tertiary">{target.path}</span>
                                    </span>
                                    {!target.live && <StatusChip tone="warning" icon={WarningIcon} label={t('content.menus.deadTarget')} />}
                                    {node.link_id === target.id && <CheckIcon size={16} aria-hidden className="text-fg-brand" />}
                                </button>
                            </li>
                        ))}
                        {targets.isSuccess && targets.data.data.length === 0 && <li className="px-2 py-2 t-admin-body text-fg-secondary">{t('content.menus.field.targetEmpty')}</li>}
                    </ul>
                </div>
            )}

            {node.link_type === 'custom' && (
                <Field label={t('content.menus.field.url')} hint={t('content.menus.field.urlHint')} error={error === 'url' ? t(nodeErrorKey.url) : undefined}>
                    {({ id, describedBy, invalid }) => (
                        <input
                            id={id}
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                            spellCheck={false}
                            className={`h-10 ${inputClass.replace('t-admin-body', 't-admin-mono')}`}
                            value={node.url ?? ''}
                            onChange={(e) => onChange({ url: e.target.value })}
                        />
                    )}
                </Field>
            )}

            <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                <input type="checkbox" className="size-4 accent-brand" checked={node.is_visible} onChange={(e) => onChange({ is_visible: e.target.checked })} />
                {t('content.menus.field.visible')}
            </label>
            <label className="flex min-h-touch-min items-center gap-3 t-admin-body text-fg-primary">
                <input type="checkbox" className="size-4 accent-brand" checked={node.open_in_new_tab} onChange={(e) => onChange({ open_in_new_tab: e.target.checked })} />
                {t('content.menus.field.newTab')}
            </label>
            <Button variant="outline" className="self-start" onClick={onDone}>
                {t('content.menus.done')}
            </Button>
        </FormSection>
    );
}
