import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import {
    ArrowDownIcon,
    ArrowLineLeftIcon,
    ArrowLineRightIcon,
    ArrowUpIcon,
    CaretDownIcon,
    CaretRightIcon,
    PlusIcon,
    SlidersHorizontalIcon,
    TagIcon,
} from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { StatusChip } from '@/components/ui/StatusChip';
import { buttonClass } from '@/components/ui/Button';
import { IconButton, iconButtonClass } from '@/components/ui/IconButton';
import { EmptyState } from '@/components/ui/EmptyState';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { CategoryPanel } from './CategoryPanel';
import { MAX_DEPTH, heightOf, useCategoryTree, useMoveCategory, type Category, type MoveInput } from './api';

/** Indentation per tree level (Tailwind spacing tokens). */
const indents = ['ps-2.5', 'ps-8.5', 'ps-14.5', 'ps-20.5'];

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
    const navigate = useNavigate();
    const params = useParams();
    const { pathname } = useLocation();
    const [search] = useSearchParams();
    // The URL is the selection: /categories/:id edits, /categories/new creates (?parent= prefills).
    const creating = pathname.endsWith('/categories/new');
    const selectedId = params.categoryId && /^\d+$/.test(params.categoryId) ? Number(params.categoryId) : null;
    const parentParam = search.get('parent');
    const newParent = creating && parentParam && /^\d+$/.test(parentParam) ? Number(parentParam) : null;
    const panelOpen = creating || selectedId !== null;
    const lastOpened = useRef<number | null>(null);

    // Closing the panel returns focus to the row it was opened from.
    const closePanel = () => {
        lastOpened.current = selectedId ?? newParent;
        navigate('/products/categories');
    };
    useEffect(() => {
        if (panelOpen || lastOpened.current === null) return;
        container.current?.querySelector<HTMLAnchorElement>(`[data-focus-key="${lastOpened.current}:link"]`)?.focus();
        lastOpened.current = null;
    }, [panelOpen]);

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
        <ul role={depth === 0 ? undefined : 'group'} className="flex flex-col gap-0.5">
            {siblings.map((node, index) => {
                const children = node.children ?? [];
                const open = !collapsed.has(node.id);
                const selected = node.id === selectedId;
                const actions: { action: Action; icon: typeof ArrowUpIcon; label: string }[] = [
                    { action: 'up', icon: ArrowUpIcon, label: t('categories.moveUp', { name: node.name }) },
                    { action: 'down', icon: ArrowDownIcon, label: t('categories.moveDown', { name: node.name }) },
                    { action: 'outdent', icon: ArrowLineLeftIcon, label: t('categories.outdent', { name: node.name }) },
                    { action: 'indent', icon: ArrowLineRightIcon, label: t('categories.indent', { name: node.name }) },
                ];

                return (
                    <li key={node.id} className="flex flex-col gap-0.5">
                        {/* Figma Categories 212:7132 tree row: radius/sm, 8 gap, 24 indent per level; the
                            open row is bg/brand-subtle. Move buttons show on hover, focus and the open row. */}
                        <div
                            className={`group flex min-h-9.5 items-center gap-2 rounded-sm pe-1 ${indents[Math.min(depth, indents.length - 1)]} ${
                                selected ? 'bg-brand-subtle' : 'hover:bg-subtle'
                            }`}
                        >
                            {children.length > 0 ? (
                                <IconButton
                                    size="sm"
                                    aria-expanded={open}
                                    aria-label={t(open ? 'categories.collapse' : 'categories.expand', { name: node.name })}
                                    onClick={() => toggle(node.id)}
                                    className="-ms-2 size-8"
                                >
                                    {open ? <CaretDownIcon size={14} aria-hidden /> : <CaretRightIcon size={14} aria-hidden />}
                                </IconButton>
                            ) : (
                                <span className="-ms-2 inline-block size-8 shrink-0" aria-hidden />
                            )}
                            <div className="flex min-w-0 flex-1 items-baseline gap-x-2 overflow-hidden py-2">
                                <Link
                                    data-focus-key={`${node.id}:link`}
                                    to={`/products/categories/${node.id}`}
                                    aria-current={selected ? 'page' : undefined}
                                    className={`shrink-0 ${depth === 0 ? 't-admin-body-strong' : 't-admin-body'} max-w-full truncate text-fg-primary hover:underline`}
                                >
                                    {node.name}
                                </Link>
                                {node.name_bn && (
                                    <span lang="bn" className="min-w-0 truncate t-admin-caption text-fg-tertiary">
                                        {node.name_bn}
                                    </span>
                                )}
                            </div>
                            {!node.is_active && <StatusChip tone="neutral" label={t('common.archived')} />}
                            {node.is_active && !node.show_in_menu && <StatusChip tone="neutral" label={t('categories.hiddenFromMenu')} />}
                            {editable && (
                                <div
                                    className={`items-center ${selected ? 'flex' : 'hidden group-focus-within:flex group-hover:flex pointer-coarse:flex'}`}
                                >
                                    {can(me, 'catalog.create') && (
                                        <Link
                                            to={`/products/categories/new?parent=${node.id}`}
                                            aria-label={t('categories.addChild', { name: node.name })}
                                            title={t('categories.addChild', { name: node.name })}
                                            aria-disabled={depth + 1 > MAX_DEPTH || undefined}
                                            onClick={(event) => depth + 1 > MAX_DEPTH && event.preventDefault()}
                                            className={`${iconButtonClass('ghost', 'sm')} size-8 aria-disabled:cursor-not-allowed aria-disabled:text-fg-disabled`}
                                        >
                                            <PlusIcon size={14} aria-hidden />
                                        </Link>
                                    )}
                                    {actions.map(({ action, icon: Icon, label }) => {
                                        const target = moveFor(action, siblings, index, parent, grandSiblings, depth);
                                        return (
                                            <IconButton
                                                key={action}
                                                size="sm"
                                                data-focus-key={`${node.id}:${action}`}
                                                aria-label={label}
                                                title={label}
                                                disabled={target === null || move.isPending}
                                                onClick={() => target && runMove(node, action, target)}
                                                className=""
                                            >
                                                <Icon size={14} aria-hidden />
                                            </IconButton>
                                        );
                                    })}
                                </div>
                            )}
                            {children.length > 0 && (
                                <span className="min-w-5 text-end t-admin-caption text-fg-tertiary tabular" title={t('categories.childCount', { count: children.length })}>
                                    <span aria-hidden>{children.length}</span>
                                    <span className="sr-only">{t('categories.childCount', { count: children.length })}</span>
                                </span>
                            )}
                        </div>
                        {children.length > 0 && open && renderLevel(children, node, siblings, depth + 1)}
                    </li>
                );
            })}
        </ul>
    );

    const total = (nodes: Category[]): number => nodes.reduce((sum, node) => sum + 1 + total(node.children ?? []), 0);

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('catalog.crumb') }, { label: t('nav.products'), to: '/products' }, { label: t('categories.title') }]}
                title={t('categories.title')}
                subtitle={t('categories.subtitle')}
                actions={
                    <>
                        <Link to="/products/brands" className={buttonClass('outline')}>
                            <TagIcon size={16} aria-hidden />
                            {t('brands.title')}
                        </Link>
                        <Link to="/products/attributes" className={buttonClass('outline')}>
                            <SlidersHorizontalIcon size={16} aria-hidden />
                            {t('attributes.title')}
                        </Link>
                        {can(me, 'catalog.create') && (
                            <Link to="/products/categories/new" className={buttonClass('primary')}>
                                <PlusIcon size={16} aria-hidden />
                                {t('categories.new')}
                            </Link>
                        )}
                    </>
                }
            />

            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>

            {tree.isError ? (
                <QueryError error={tree.error} onRetry={() => void tree.refetch()} />
            ) : tree.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t('common.loading')}
                </p>
            ) : tree.data.length === 0 && !panelOpen ? (
                <EmptyState
                    title={t('categories.empty')}
                    body={t('categories.emptyBody')}
                    actions={
                        can(me, 'catalog.create') && (
                            <Link to="/products/categories/new" className={buttonClass('primary')}>
                                <PlusIcon size={16} aria-hidden />
                                {t('categories.new')}
                            </Link>
                        )
                    }
                />
            ) : (
                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] xl:grid-cols-[minmax(0,25rem)_minmax(0,1fr)]">
                    <div ref={container} role="region" aria-label={t('categories.treeLabel')} className="flex min-w-0 flex-col gap-0.5 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                        <div className="mb-2 flex flex-col gap-0.5">
                            <h2 className="t-admin-section text-fg-primary">{t('categories.treeLabel')}</h2>
                            <p className="t-admin-caption text-fg-tertiary">{t('categories.treeCount', { top: tree.data.length, total: total(tree.data) })}</p>
                            {editable && <p className="t-admin-caption text-fg-tertiary">{t('categories.treeHint')}</p>}
                        </div>
                        {renderLevel(tree.data, null, null, 0)}
                    </div>
                    {/* The panel stacks under the tree below 1024 (Figma has no narrow Categories frame). */}
                    {panelOpen ? (
                        <CategoryPanel key={creating ? `new-${newParent ?? ''}` : selectedId} id={creating ? null : selectedId} parentId={newParent} tree={tree.data} onClose={closePanel} />
                    ) : (
                        <EmptyState reason="noDataYet" title={t('categories.pickTitle')} body={t('categories.pickBody')} className="hidden lg:flex" />
                    )}
                </div>
            )}
        </section>
    );
}
