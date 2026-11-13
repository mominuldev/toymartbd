import type { ReactNode } from 'react';
import { DotsSixVerticalIcon, EyeSlashIcon, WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { StatusChip } from '@/components/ui/StatusChip';
import type { MenuNode } from './tree';

/** The second line under a label: "/eid-collection", "Mega menu · from category tree", "Heading · 3 links". */
export function useMenuNodeMeta() {
    const { t } = useI18n();
    return (node: MenuNode): string => {
        switch (node.link_type) {
            case 'category_tree':
                return t('content.menus.categoryTree');
            case 'none':
                return t('content.menus.heading', { count: node.children.length });
            case 'custom':
                return node.url ?? '';
            default:
                return node.target?.path || t(`content.menus.type.${node.link_type}` as MessageKey);
        }
    };
}

type Props = {
    node: MenuNode;
    depth: number;
    controls?: ReactNode;
    invalid?: boolean;
    errorText?: string;
};

/**
 * Figma B · 27 menu rows (241:45618 …): a top-level item on bg/subtle, a nested one on
 * bg/surface indented 28 per level; drag grip, EN label + বাংলা label, a caption line, and the
 * row's icon buttons on the right.
 */
export function MenuRow({ node, depth, controls, invalid, errorText }: Props) {
    const { t } = useI18n();
    const meta = useMenuNodeMeta();
    const indent = depth === 1 ? 'ps-2.5' : depth === 2 ? 'ps-9.5' : 'ps-16.5';
    const dead = node.target !== null && !node.target.live;

    return (
        <div
            className={`flex w-full items-center gap-2.5 rounded-sm border py-2.5 pe-2.5 ${indent} ${depth === 1 ? 'bg-subtle' : 'bg-surface'} ${
                invalid ? 'border-line-danger' : 'border-line-default'
            }`}
        >
            <DotsSixVerticalIcon size={14} aria-hidden className="shrink-0 text-fg-tertiary" />
            <div className="flex min-w-0 flex-1 flex-col">
                <div className="flex min-w-0 flex-wrap items-baseline gap-x-2">
                    <span className="t-admin-body-strong truncate text-fg-primary">{node.label}</span>
                    {node.label_bn && (
                        <span lang="bn" className="t-admin-body truncate text-fg-secondary">
                            {node.label_bn}
                        </span>
                    )}
                </div>
                <span className="t-admin-caption truncate text-fg-tertiary">{meta(node)}</span>
                {errorText && <span className="t-admin-caption text-fg-primary">{errorText}</span>}
            </div>
            {!node.is_visible && <StatusChip tone="neutral" icon={EyeSlashIcon} label={t('content.menus.hidden')} />}
            {dead && <StatusChip tone="warning" icon={WarningIcon} label={t('content.menus.deadTarget')} />}
            {controls}
        </div>
    );
}
