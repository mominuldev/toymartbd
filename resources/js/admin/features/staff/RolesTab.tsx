import { Fragment } from 'react';
import { CheckSquareIcon, InfoIcon, LockSimpleIcon, SquareIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { dataTable } from '@/components/ui/DataTable';
import { QueryError } from '@/components/ui/QueryError';
import { useStaffRoles } from './api';
import { groupPermissions } from './parts';

/**
 * Roles & permissions (Figma PermissionMatrix 203:1610, as drawn under B · 35's People table):
 * a role per column with its active-holder count, permissions grouped in rows with the human
 * label over the mono code. READ-ONLY: roles are defined in code (App\Models\Enums\Role) and
 * seeded, so the frame's checkboxes render as allowed / not-allowed marks and the banner says
 * how a change is made. The owner column is locked: super_admin passes every check.
 */
export function RolesTab() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const roles = useStaffRoles(can(me, 'staff.view'));

    if (roles.isError) return <QueryError error={roles.error} onRetry={() => void roles.refetch()} />;
    if (!roles.data) {
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    }

    const all = roles.data.find((role) => role.bypasses_all)?.permissions ?? roles.data[0]?.permissions ?? [];
    const groups = groupPermissions(all);

    return (
        <div className={dataTable.card}>
            <p className="flex items-start gap-2.5 border-s-2 border-feedback-info px-4 py-3 t-admin-body text-fg-primary">
                <InfoIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-info" />
                {t('roles.readOnly')}
            </p>
            <div className="overflow-x-auto border-t border-line-default">
                <table className={`${dataTable.table} min-w-[56rem]`}>
                    <caption className="sr-only">{t('roles.matrix')}</caption>
                    <thead>
                        <tr className="border-b border-line-strong">
                            <th scope="col" className={`${dataTable.th} sticky start-0 z-10 bg-surface ps-4`}>
                                {t('roles.col.permission')}
                            </th>
                            {roles.data.map((role) => (
                                <th key={role.key} scope="col" className="h-16 px-3 text-center align-middle">
                                    <span className="flex flex-col items-center gap-0.5">
                                        <span className="inline-flex items-center gap-1 t-admin-body-strong text-fg-primary">
                                            {role.bypasses_all && <LockSimpleIcon size={14} aria-hidden />}
                                            {t(`role.${role.key}` as MessageKey)}
                                        </span>
                                        <span className="t-admin-caption font-normal text-fg-tertiary">{t('roles.users', { count: String(role.users_count) })}</span>
                                    </span>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {groups.map((group) => (
                            <Fragment key={group.key}>
                                <tr className="border-t border-line-default bg-subtle">
                                    <th scope="colgroup" colSpan={roles.data.length + 1} className="sticky start-0 h-11 px-4 text-start t-admin-body-strong text-fg-primary">
                                        {t(`perm.group.${group.key}` as MessageKey)}
                                    </th>
                                </tr>
                                {group.codes.map((code) => (
                                    <tr key={code} className="border-t border-line-default">
                                        <th scope="row" className="sticky start-0 z-10 bg-surface px-4 py-2 text-start font-normal">
                                            <span className="block t-admin-body text-fg-primary">{t(`perm.${code}` as MessageKey)}</span>
                                            <code className="block t-admin-mono-sm text-fg-tertiary">{code}</code>
                                        </th>
                                        {roles.data.map((role) => (
                                            <td key={role.key} className={`h-12 px-3 text-center align-middle ${role.bypasses_all ? 'bg-subtle' : ''}`}>
                                                <Mark state={role.bypasses_all ? 'locked' : role.permissions.includes(code) ? 'allowed' : 'denied'} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** A matrix cell: shape and an accessible name, never colour alone. */
function Mark({ state }: { state: 'allowed' | 'denied' | 'locked' }) {
    const { t } = useI18n();
    if (state === 'locked') {
        return (
            <span role="img" aria-label={t('roles.bypass')} title={t('roles.bypass')} className="inline-grid text-fg-tertiary">
                <LockSimpleIcon size={18} aria-hidden />
            </span>
        );
    }
    const allowed = state === 'allowed';
    return (
        <span role="img" aria-label={t(allowed ? 'roles.allowed' : 'roles.denied')} className={`inline-grid ${allowed ? 'text-fg-brand' : 'text-fg-disabled'}`}>
            {allowed ? <CheckSquareIcon size={22} weight="fill" aria-hidden /> : <SquareIcon size={22} aria-hidden />}
        </span>
    );
}
