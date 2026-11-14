import { useMemo, useState } from 'react';
import { DotsThreeIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Avatar } from '@/components/shell/Avatar';
import { Button } from '@/components/ui/Button';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { Tag } from '@/components/ui/Tag';
import { timeAgo } from '@/lib/format';
import { roleOf, useStaff, type StaffMember } from './api';
import { CellLabel, Th } from './parts';
import { StaffDialog } from './StaffDialog';

/**
 * People tab (B · 35 table 241:48916): avatar initials + name + email, role, status, last
 * sign-in, and the row's "…" which opens the manage dialog (rename, role, password, status).
 * Status replaces the frame's Two-factor column; there is no 2FA yet.
 */
export function PeopleTab() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const list = useStaff(can(me, 'staff.view'));
    const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
    const manage = can(me, 'staff.manage');
    const [editing, setEditing] = useState<StaffMember | null>(null);
    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;

    if (list.isError) return <QueryError error={list.error} onRetry={() => void list.refetch()} />;
    if (list.isPending) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }
    if (rows.length === 0) return <EmptyState title={t('staff.empty')} body={t('staff.emptyBody')} />;

    return (
        <div className={dataTable.card}>
            <table className={dataTable.table}>
                <thead className={`hidden lg:table-header-group ${dataTable.head}`}>
                    <tr className="border-b border-line-strong">
                        <Th>{t('staff.col.person')}</Th>
                        <Th>{t('staff.col.role')}</Th>
                        <Th>{t('staff.col.status')}</Th>
                        <Th>{t('staff.col.lastActive')}</Th>
                        {manage && (
                            <th scope="col" className={dataTable.th}>
                                <span className="sr-only">{t('common.actions')}</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((member) => {
                        const role = roleOf(member);
                        return (
                            <tr key={member.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 first:border-t-0 lg:table-row lg:p-0 lg:first:border-t ${dataTable.row}`}>
                                <td className={`${cell} basis-full lg:basis-auto`}>
                                    <div className="flex items-center gap-3">
                                        <Avatar name={member.name} />
                                        <div className="flex min-w-0 flex-col">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="t-admin-body-strong text-fg-primary">{member.name}</span>
                                                {member.is_self && <Tag tone="sky" label={t('staff.you')} />}
                                            </span>
                                            <span className="t-admin-caption truncate text-fg-tertiary">{member.email}</span>
                                        </div>
                                    </div>
                                </td>
                                <td className={`${cell} t-admin-body text-fg-primary`}>
                                    <CellLabel text={t('staff.col.role')} />
                                    {role ? t(`role.${role}` as MessageKey) : t('staff.noRole')}
                                </td>
                                <td className={cell}>
                                    <StatusChip label={t(member.is_active ? 'staff.status.active' : 'staff.status.inactive')} tone={member.is_active ? 'success' : 'neutral'} />
                                </td>
                                <td className={`${cell} t-admin-caption text-fg-secondary`}>
                                    <CellLabel text={t('staff.col.lastActive')} />
                                    {member.last_login_at ? <time dateTime={member.last_login_at}>{timeAgo(member.last_login_at, locale)}</time> : t('staff.never')}
                                </td>
                                {manage && (
                                    <td className={`${cell} lg:w-12 max-lg:ms-auto`}>
                                        <IconButton size="sm" aria-label={t('staff.manage', { name: member.name })} onClick={() => setEditing(member)}>
                                            <DotsThreeIcon size={16} aria-hidden />
                                        </IconButton>
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            {list.hasNextPage && (
                <DataTableFooter summary={t('staff.count', { count: String(rows.length) })}>
                    <Button variant="outline" size="sm" onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>
                        {list.isFetchingNextPage ? t('common.loading') : t('common.loadMore')}
                    </Button>
                </DataTableFooter>
            )}
            <StaffDialog open={editing !== null} member={editing} onClose={() => setEditing(null)} />
        </div>
    );
}
