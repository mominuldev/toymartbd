import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import {
    ArrowDownIcon,
    ArrowLineLeftIcon,
    ArrowLineRightIcon,
    ArrowUpIcon,
    CaretDownIcon,
    CaretRightIcon,
    EyeSlashIcon,
    PlusIcon,
    StarIcon,
} from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { StatusChip } from '@/components/ui/StatusChip';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { MAX_DEPTH, heightOf, useCategoryTree, useMoveCategory, type Category, type MoveInput } from './api';

/** Indentation per tree level (Tailwind spacing tokens). */
const indents = ['ps-2', 'ps-8', 'ps-14', 'ps-20'];

type Action = 'up' | 'down' | 'indent' | 'outdent';

/** Where each tree button would move a node, or null when the move is not possible. */
function moveFor(action: Action, siblings: Category[], index: number, parent: Category | null, grandSiblings: Category[] | null, depth: number): Omit<MoveInput, 'id'> | null {
    const node = siblings[index]!;
    const parentId = parent?.id ?? null;

    switch (action) {
        case 'up':
            return index > 0 ? { parent_id: parentId, position: index - 1 } : null;
        case 'down':
            return index < siblings.length - 1 ? { parent_id: parentId, position: index + 1 } : null;
        case 'indent': {
            const above = siblings[index - 1];
            if (!above || depth + 1 + heightOf(node) > MAX_DEPTH) return null;
            return { parent_id: above.id, position: (above.children ?? []).length };
        }
        case 'outdent': {
            if (!parent || !grandSiblings) return null;
            return { parent_id: parent.parent_id, position: grandSiblings.findIndex((c) => c.id === parent.id) + 1 };
        }
    }
}

/**
 * Category tree (P4-CAT-08, 06-admin-feature-checklist §Catalog). Reorder, indent and
 * outdent with buttons on every row — fully keyboard operable; focus stays on the
 * button that was pressed after the tree reloads. The server re-checks every move
 * (cycles, three-level limit, archived parents).
 */
export function CategoriesPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const tree = useCategoryTree();
    const move = useMoveCategory();
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
    const [announcement, setAnnouncement] = useState('');
    const refocus = useRef<string | null>(null);
    const container = useRef<HTMLDivElement>(null);
    const editable = can(me, 'catalog.update');

    useEffect(() => {
        if (!refocus.current || !container.current) return;
        const target = container.current.querySelector<HTMLButtonElement>(`[data-focus-key="${refocus.current}"]`);
        if (target && !target.disabled) target.focus();
        else container.current.querySelector<HTMLAnchorElement>(`[data-focus-key="${refocus.current.split(':')[0]}:link"]`)?.focus();
        refocus.current = null;
    }, [tree.data]);

    const runMove = (node: Category, action: Action, target: Omit<MoveInput, 'id'>) => {
        move.mutate(
            { id: node.id, ...target },
            {
                onSuccess: () => {
                    refocus.current = `${node.id}:${action}`;
                    if (target.parent_id !== null) setCollapsed((prev) => { const next = new Set(prev); next.delete(target.parent_id!); return next; });
                    setAnnouncement(t('categories.moved', { name: node.name }));
                },
                onError: (error) => notify(error instanceof ApiError ? (error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    const toggle = (id: number) =>
        setCollapsed((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });

    const renderLevel = (siblings: Category[], parent: Category | null, grandSiblings: Category[] | null, depth: number) => (
        <ul role={depth === 0 ? undefined : 'group'} className={depth === 0 ? 'divide-y divide-line-default' : 'border-t border-line-default'}>
            {siblings.map((node, index) => {
                const children = node.children ?? [];
                const open = !collapsed.has(node.id);
                const actions: { action: Action; icon: typeof ArrowUpIcon; label: string }[] = [
                    { action: 'up', icon: ArrowUpIcon, label: t('categories.moveUp', { name: node.name }) },
                    { action: 'down', icon: ArrowDownIcon, label: t('categories.moveDown', { name: node.name }) },
                    { action: 'outdent', icon: ArrowLineLeftIcon, label: t('categories.outdent', { name: node.name }) },
                    { action: 'indent', icon: ArrowLineRightIcon, label: t('categories.indent', { name: node.name }) },
                ];

                return (
                    <li key={node.id} className={depth > 0 ? 'border-t border-line-default first:border-t-0' : undefined}>
                        <div className={`flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 pe-3 hover:bg-subtle ${indents[Math.min(depth, indents.length - 1)]}`}>
                            {children.length > 0 ? (
                                <button
                                    type="button"
                                    aria-expanded={open}
                                    aria-label={t(open ? 'categories.collapse' : 'categories.expand', { name: node.name })}
                                    onClick={() => toggle(node.id)}
                                    className="inline-flex size-control-sm items-center justify-center rounded-sm text-fg-secondary hover:bg-surface hover:text-fg-primary"
                                >
                                    {open ? <CaretDownIcon size={16} aria-hidden /> : <CaretRightIcon size={16} aria-hidden />}
                                </button>
                            ) : (
                                <span className="inline-block size-control-sm" aria-hidden />
                            )}
                            <div className="min-w-0 flex-1">
                                <Link data-focus-key={`${node.id}:link`} to={`/products/categories/${node.id}`} className="t-admin-body-strong text-fg-primary hover:underline">
                                    {node.name}
                                </Link>
                                <p className="flex flex-wrap items-center gap-x-2 t-admin-caption text-fg-tertiary">
                                    {node.name_bn && <span lang="bn">{node.name_bn}</span>}
                                    <span className="t-admin-mono-sm">{node.slug}</span>
                                    {children.length > 0 && <span>{t('categories.childCount', { count: children.length })}</span>}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {node.is_featured && (
                                    <span className="inline-flex h-6 items-center gap-1 rounded-xs bg-brand-subtle px-2 t-admin-caption-strong text-fg-brand">
                                        <StarIcon size={14} weight="fill" aria-hidden />
                                        {t('categories.featured')}
                                    </span>
                                )}
                                {!node.show_in_menu && (
                                    <span className="inline-flex h-6 items-center gap-1 rounded-xs bg-subtle px-2 t-admin-caption-strong text-fg-secondary">
                                        <EyeSlashIcon size={14} aria-hidden />
                                        {t('categories.hiddenFromMenu')}
                                    </span>
                                )}
                                <StatusChip active={node.is_active} label={t(node.is_active ? 'common.active' : 'common.archived')} />
                            </div>
                            {editable && (
                                <div className="flex items-center gap-0.5">
                                    {actions.map(({ action, icon: Icon, label }) => {
                                        const target = moveFor(action, siblings, index, parent, grandSiblings, depth);
                                        return (
                                            <button
                                                key={action}
                                                type="button"
                                                data-focus-key={`${node.id}:${action}`}
                                                aria-label={label}
                                                title={label}
                                                disabled={target === null || move.isPending}
                                                onClick={() => target && runMove(node, action, target)}
                                                className="inline-flex size-control-sm items-center justify-center rounded-sm text-fg-secondary hover:bg-surface hover:text-fg-primary disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                                <Icon size={16} aria-hidden />
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                        {children.length > 0 && open && renderLevel(children, node, siblings, depth + 1)}
                    </li>
                );
            })}
        </ul>
    );

    return (
        <section className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="t-admin-page-title text-fg-primary">{t('categories.title')}</h1>
                    {editable && <p className="t-admin-caption text-fg-tertiary">{t('categories.treeHint')}</p>}
                </div>
                {can(me, 'catalog.create') && (
                    <Link to="/products/categories/new" className="inline-flex h-control-md items-center gap-2 rounded-sm bg-brand px-4 t-admin-button text-fg-on-brand hover:bg-brand-shelf">
                        <PlusIcon size={18} aria-hidden />
                        {t('categories.new')}
                    </Link>
                )}
            </div>

            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>

            {tree.isError ? (
                <QueryError error={tree.error} onRetry={() => void tree.refetch()} />
            ) : tree.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : tree.data.length === 0 ? (
                <div className="rounded-md border border-line-default bg-surface p-8 text-center shadow-e1">
                    <p className="t-admin-section text-fg-primary">{t('categories.empty')}</p>
                    <p className="t-admin-body text-fg-secondary">{t('categories.emptyBody')}</p>
                </div>
            ) : (
                <div ref={container} role="region" aria-label={t('categories.treeLabel')} className="overflow-hidden rounded-md border border-line-default bg-surface shadow-e1">
                    {renderLevel(tree.data, null, null, 0)}
                </div>
            )}
        </section>
    );
}
