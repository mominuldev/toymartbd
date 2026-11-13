import { useMemo, useState, type DragEvent } from 'react';
import { Navigate, useParams } from 'react-router';
import { ArrowDownIcon, ArrowLeftIcon, ArrowRightIcon, ArrowUpIcon, PencilSimpleIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { FormActions } from '@/components/ui/FormActions';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { Toggle } from '@/features/catalog/products/parts';
import { StoreHeader } from '../shared/StoreHeader';
import { isValidLink } from '../shared/links';
import { MenuRow } from './MenuRow';
import { MenuItemPanel, nodeErrorKey } from './MenuItemPanel';
import { isMenuLocation, useMenu, useSaveMenu, type Menu, type MenuLocation } from './api';
import {
    addNode,
    canIndent,
    canMoveDown,
    canMoveUp,
    canOutdent,
    countItems,
    emptyNode,
    findNode,
    flatten,
    fromApi,
    indent,
    moveBeside,
    moveSibling,
    outdent,
    removeNode,
    toPayload,
    updateNode,
    validate,
    type MenuNode,
} from './tree';

/**
 * Online store → Header / Footer / Mobile menu (Figma B · 27 241:45525, Header menu card 241:45612,
 * full width on its own tab). Drag a row onto another to place it before/after; every move also
 * has a keyboard path: up/down, indent (under the item above) and outdent. Save sends the tree.
 */
export function MenuEditorPage() {
    const { location } = useParams();
    if (!isMenuLocation(location)) return <Navigate to="/store/navigation/header" replace />;
    return <MenuEditorLoader key={location} location={location} />;
}

function MenuEditorLoader({ location }: { location: MenuLocation }) {
    const menu = useMenu(location);
    if (menu.isError) return <QueryError error={menu.error} onRetry={() => void menu.refetch()} />;
    if (!menu.data) return <p aria-busy="true" className="sr-only" />;
    return <MenuEditor key={menu.data.updated_at ?? 'new'} location={location} menu={menu.data} />;
}

function MenuEditor({ location, menu }: { location: MenuLocation; menu: Menu }) {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const save = useSaveMenu(location);
    const editable = can(me, 'content.manage');
    const [tree, setTree] = useState<MenuNode[]>(() => fromApi(menu.items));
    const [published, setPublished] = useState(menu.is_published);
    const [selected, setSelected] = useState<string | null>(null);
    const [dragKey, setDragKey] = useState<string | null>(null);
    const [showErrors, setShowErrors] = useState(false);
    const maxDepth = menu.max_depth;
    const rows = useMemo(() => flatten(tree), [tree]);
    const errors = useMemo(() => validate(tree, isValidLink), [tree]);
    const count = countItems(tree);
    const title = t(`content.menus.title.${location}`);
    const selectedRow = rows.find((r) => r.node.key === selected) ?? null;

    const add = (parentKey: string | null) => {
        const node = emptyNode();
        setTree((current) => addNode(current, node, parentKey, maxDepth));
        setSelected(node.key);
    };

    const onDrop = (event: DragEvent<HTMLLIElement>, targetKey: string) => {
        event.preventDefault();
        const key = dragKey ?? event.dataTransfer.getData('text/plain');
        if (!key) return;
        const rect = event.currentTarget.getBoundingClientRect();
        const where = event.clientY - rect.top > rect.height / 2 ? 'after' : 'before';
        setTree((current) => moveBeside(current, key, targetKey, where, maxDepth));
        setDragKey(null);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setShowErrors(true);
        if (errors.size > 0 || count > menu.max_items) {
            notify(count > menu.max_items ? t('content.menus.tooMany', { max: menu.max_items }) : t('content.menus.invalid'), 'danger');
            const first = [...errors.keys()][0];
            if (first) setSelected(first);
            return;
        }
        save.mutate(
            { is_published: published, items: toPayload(tree) },
            {
                onSuccess: (saved) => {
                    notify(t('content.menus.saved'));
                    setTree(fromApi(saved.items));
                    setSelected(null);
                    setShowErrors(false);
                },
                onError: (error) => notify(error instanceof ApiError ? (error.problem.errors?.[0]?.message ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    return (
        <section className="flex flex-col gap-6">
            <StoreHeader />

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                    <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <h2 className="t-admin-section text-fg-primary">{title}</h2>
                                <p className="t-admin-caption text-fg-tertiary">{t('content.menus.help', { depth: maxDepth })}</p>
                            </div>
                            {editable && (
                                <Button variant="outline" size="sm" onClick={() => add(null)} disabled={count >= menu.max_items}>
                                    <PlusIcon size={16} aria-hidden />
                                    {t('content.menus.addItem')}
                                </Button>
                            )}
                        </div>
                        {!published && <p className="t-admin-caption text-fg-secondary">{t('content.menus.fallbackNote')}</p>}

                        {rows.length === 0 ? (
                            <div className="flex flex-col gap-1 py-6 text-center">
                                <p className="t-admin-body-strong text-fg-primary">{t('content.menus.empty')}</p>
                                <p className="t-admin-body text-fg-secondary">{t('content.menus.emptyBody')}</p>
                            </div>
                        ) : (
                            <ul aria-label={t('content.menus.tree', { menu: title })} className="flex flex-col gap-1.5">
                                {rows.map(({ node, depth }) => {
                                    const error = showErrors ? errors.get(node.key) : undefined;
                                    return (
                                        <li
                                            key={node.key}
                                            draggable={editable}
                                            aria-label={node.label}
                                            onDragStart={(event) => {
                                                setDragKey(node.key);
                                                event.dataTransfer.setData('text/plain', node.key);
                                                event.dataTransfer.effectAllowed = 'move';
                                            }}
                                            onDragEnd={() => setDragKey(null)}
                                            onDragOver={(event) => event.preventDefault()}
                                            onDrop={(event) => onDrop(event, node.key)}
                                            className={dragKey === node.key ? 'opacity-60' : undefined}
                                        >
                                            <MenuRow
                                                node={node}
                                                depth={depth}
                                                invalid={Boolean(error) || selected === node.key}
                                                errorText={error ? t(nodeErrorKey[error]) : undefined}
                                                controls={
                                                    editable && (
                                                        <span className="flex shrink-0 items-center">
                                                            <IconButton size="sm" aria-label={t('content.menus.moveUp', { label: node.label })} disabled={!canMoveUp(tree, node.key)} onClick={() => setTree(moveSibling(tree, node.key, -1))}>
                                                                <ArrowUpIcon size={16} aria-hidden />
                                                            </IconButton>
                                                            <IconButton size="sm" aria-label={t('content.menus.moveDown', { label: node.label })} disabled={!canMoveDown(tree, node.key)} onClick={() => setTree(moveSibling(tree, node.key, 1))}>
                                                                <ArrowDownIcon size={16} aria-hidden />
                                                            </IconButton>
                                                            <IconButton size="sm" aria-label={t('content.menus.outdent', { label: node.label })} disabled={!canOutdent(tree, node.key)} onClick={() => setTree(outdent(tree, node.key))}>
                                                                <ArrowLeftIcon size={16} aria-hidden />
                                                            </IconButton>
                                                            <IconButton size="sm" aria-label={t('content.menus.indent', { label: node.label })} disabled={!canIndent(tree, node.key, maxDepth)} onClick={() => setTree(indent(tree, node.key, maxDepth))}>
                                                                <ArrowRightIcon size={16} aria-hidden />
                                                            </IconButton>
                                                            {depth < maxDepth && node.link_type !== 'category_tree' && (
                                                                <IconButton size="sm" aria-label={t('content.menus.addChild', { label: node.label })} disabled={count >= menu.max_items} onClick={() => add(node.key)}>
                                                                    <PlusIcon size={16} aria-hidden />
                                                                </IconButton>
                                                            )}
                                                            <IconButton aria-label={t('content.menus.edit', { label: node.label })} aria-pressed={selected === node.key} onClick={() => setSelected(selected === node.key ? null : node.key)}>
                                                                <PencilSimpleIcon size={16} aria-hidden />
                                                            </IconButton>
                                                            <IconButton
                                                                size="sm"
                                                                aria-label={t('content.menus.remove', { label: node.label })}
                                                                onClick={() => {
                                                                    setTree(removeNode(tree, node.key));
                                                                    if (selected && (selected === node.key || findNode(node.children, selected))) setSelected(null);
                                                                }}
                                                            >
                                                                <TrashIcon size={16} aria-hidden />
                                                            </IconButton>
                                                        </span>
                                                    )
                                                }
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <p className="pt-2 t-admin-caption tabular text-fg-tertiary">{t('content.menus.count', { count, max: menu.max_items })}</p>
                    </div>

                    {selectedRow && editable && (
                        <MenuItemPanel
                            node={selectedRow.node}
                            depth={selectedRow.depth}
                            error={showErrors ? errors.get(selectedRow.node.key) : undefined}
                            onChange={(patch) => setTree((current) => updateNode(current, selectedRow.node.key, patch))}
                            onDone={() => setSelected(null)}
                        />
                    )}
                </div>

                {editable && (
                    <FormActions
                        start={
                            <span className="flex items-center gap-2 t-admin-body text-fg-primary">
                                <Toggle checked={published} onChange={setPublished} label={t('content.menus.published')} />
                                <span aria-hidden>{t('content.menus.published')}</span>
                            </span>
                        }
                    >
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? t('common.saving') : t('common.save')}
                        </Button>
                    </FormActions>
                )}
            </form>
        </section>
    );
}

