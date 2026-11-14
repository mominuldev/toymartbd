import { Fragment, useMemo, useState } from 'react';
import { ArrowCounterClockwiseIcon, CheckSquareIcon, CurrencyCircleDollarIcon, InfoIcon, LockSimpleIcon, SquareIcon, WarningIcon } from '@phosphor-icons/react';
import { updateRolePermissionsRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { dataTable } from '@/components/ui/DataTable';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { useStaffRoles, useUpdateRole, type PermissionInfo, type RoleMatrix, type StaffRole } from './api';
import { groupPermissions } from './parts';

/** Role key → its edited permission list; only roles that differ from the saved matrix. */
type Draft = Record<string, string[]>;

const sameSet = (a: readonly string[], b: readonly string[]) => a.length === b.length && a.every((p) => b.includes(p));

/**
 * Roles & permissions (Figma PermissionMatrix 203:1610 under B · 35): a role per column with
 * its active holders, permissions grouped in rows with the label over the mono code.
 *
 * Editable by roles.manage (owner decision 2026-11-14): ticking marks the column changed; the
 * save bar saves every changed role after a confirm, one PUT each. Fixed cells: the owner
 * column (every permission, always) and the owner-only rows (staff.manage, roles.manage).
 * Money rows carry a coin mark: holders must use two-factor sign-in. A role holding a
 * separation-of-duties pair its default does not shows the Figma warning banner.
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

    return <Matrix matrix={roles.data} editable={can(me, 'roles.manage')} />;
}

function Matrix({ matrix, editable }: { matrix: RoleMatrix; editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const update = useUpdateRole();
    const [draft, setDraft] = useState<Draft>({});
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);

    const info = useMemo(() => {
        const byKey: Record<string, PermissionInfo> = {};
        for (const p of matrix.permissions) byKey[p.key] = p;
        return byKey;
    }, [matrix.permissions]);
    const groups = groupPermissions(matrix.permissions.map((p) => p.key));
    const held = (role: StaffRole) => draft[role.key] ?? role.permissions;
    const changed = Object.keys(draft);

    function toggle(role: StaffRole, code: string) {
        const current = held(role);
        const next = current.includes(code) ? current.filter((p) => p !== code) : [...current, code];
        setDraft((d) => {
            const copy = { ...d };
            if (sameSet(next, role.permissions)) delete copy[role.key];
            else copy[role.key] = next;
            return copy;
        });
    }

    function restoreDefaults(role: StaffRole) {
        setDraft((d) => {
            const copy = { ...d };
            if (sameSet(role.default_permissions, role.permissions)) delete copy[role.key];
            else copy[role.key] = [...role.default_permissions];
            return copy;
        });
    }

    async function save() {
        setSaving(true);
        try {
            for (const key of changed) {
                // Server order is the enum order; send the list in that order, through the
                // generated schema (rule 27), which also drops nothing silently: an owner-only
                // permission can never be in a draft (those cells are not editable).
                const input = updateRolePermissionsRequestSchema.parse({ permissions: matrix.permissions.map((p) => p.key).filter((p) => draft[key]?.includes(p)) });
                await update.mutateAsync({ role: key, input });
            }
            setDraft({});
            notify(t('roles.saved', { count: String(changed.length) }));
        } catch (error) {
            notify(error instanceof ApiError ? (error.problem.errors?.[0]?.message ?? error.problem.detail ?? error.problem.title) : t('error.title'), 'danger');
        } finally {
            setSaving(false);
            setConfirming(false);
        }
    }

    const warnings = matrix.roles.flatMap((role) => role.warnings.map(([a, b]) => ({ role, a, b })));

    return (
        <div className="flex flex-col gap-4">
            <div className={dataTable.card}>
                {warnings.map(({ role, a, b }) => (
                    <p key={`${role.key}-${a}-${b}`} className="flex items-start gap-2.5 border-s-2 border-feedback-warning px-4 py-3 t-admin-body text-fg-primary">
                        <WarningIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-warning" />
                        {t('roles.sodWarning', { role: t(`role.${role.key}` as MessageKey), a: t(`perm.${a}` as MessageKey), b: t(`perm.${b}` as MessageKey) })}
                    </p>
                ))}
                <p className="flex items-start gap-2.5 border-s-2 border-feedback-info px-4 py-3 t-admin-body text-fg-primary">
                    <InfoIcon size={16} aria-hidden className="mt-0.5 shrink-0 text-feedback-info" />
                    {t(editable ? 'roles.editHelp' : 'roles.viewOnly')}
                </p>
                <div className="overflow-x-auto border-t border-line-default">
                    <table className={`${dataTable.table} min-w-[56rem]`}>
                        <caption className="sr-only">{t('roles.matrix')}</caption>
                        <thead>
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={`${dataTable.th} sticky start-0 z-10 bg-surface ps-4`}>
                                    {t('roles.col.permission')}
                                </th>
                                {matrix.roles.map((role) => (
                                    <th key={role.key} scope="col" className={`h-18 px-2 text-center align-middle ${draft[role.key] ? 'bg-brand-subtle' : ''}`}>
                                        <span className="flex flex-col items-center gap-0.5">
                                            <span className="inline-flex items-center gap-1 t-admin-body-strong text-fg-primary">
                                                {role.bypasses_all && <LockSimpleIcon size={14} aria-hidden />}
                                                {t(`role.${role.key}` as MessageKey)}
                                            </span>
                                            <span className="t-admin-caption font-normal text-fg-tertiary">{t('roles.users', { count: String(role.users_count) })}</span>
                                            {editable && role.editable && !sameSet(held(role), role.default_permissions) && (
                                                <IconButton size="sm" aria-label={t('roles.restore', { role: t(`role.${role.key}` as MessageKey) })} title={t('roles.restoreShort')} onClick={() => restoreDefaults(role)}>
                                                    <ArrowCounterClockwiseIcon size={14} aria-hidden />
                                                </IconButton>
                                            )}
                                        </span>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <Fragment key={group.key}>
                                    <tr className="border-t border-line-default bg-subtle">
                                        <th scope="colgroup" colSpan={matrix.roles.length + 1} className="sticky start-0 h-11 px-4 text-start t-admin-body-strong text-fg-primary">
                                            {t(`perm.group.${group.key}` as MessageKey)}
                                        </th>
                                    </tr>
                                    {group.codes.map((code) => (
                                        <Row key={code} permission={info[code]} code={code} roles={matrix.roles} held={held} editable={editable} draft={draft} onToggle={toggle} />
                                    ))}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {changed.length > 0 && (
                <div role="region" aria-label={t('roles.unsaved', { count: String(changed.length) })} className="sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-md border border-line-default bg-surface px-4 py-3 shadow-e3">
                    <p className="t-admin-body text-fg-primary">{t('roles.unsaved', { count: String(changed.length) })}</p>
                    <div className="flex gap-3">
                        <Button variant="ghost" onClick={() => setDraft({})} disabled={saving}>
                            {t('roles.discard')}
                        </Button>
                        <Button onClick={() => setConfirming(true)} disabled={saving}>
                            {t('roles.save')}
                        </Button>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirming}
                title={t('roles.confirmTitle', { count: String(changed.length) })}
                body={t('roles.confirmBody', { roles: changed.map((key) => t(`role.${key}` as MessageKey)).join(', ') })}
                confirmLabel={t('roles.save')}
                pending={saving}
                onConfirm={() => void save()}
                onClose={() => setConfirming(false)}
            />
        </div>
    );
}

function Row({
    permission,
    code,
    roles,
    held,
    editable,
    draft,
    onToggle,
}: {
    permission: PermissionInfo | undefined;
    code: string;
    roles: StaffRole[];
    held: (role: StaffRole) => string[];
    editable: boolean;
    draft: Draft;
    onToggle: (role: StaffRole, code: string) => void;
}) {
    const { t } = useI18n();
    const label = t(`perm.${code}` as MessageKey);
    const ownerOnly = permission?.owner_only ?? false;

    return (
        <tr className="border-t border-line-default">
            <th scope="row" className="sticky start-0 z-10 bg-surface px-4 py-2 text-start font-normal">
                <span className="flex items-center gap-1.5 t-admin-body text-fg-primary">
                    {label}
                    {permission?.reveals_money && (
                        <span role="img" aria-label={t('roles.money')} title={t('roles.money')} className="inline-grid text-fg-tertiary">
                            <CurrencyCircleDollarIcon size={14} aria-hidden />
                        </span>
                    )}
                </span>
                <code className="block t-admin-mono-sm text-fg-tertiary">{code}</code>
            </th>
            {roles.map((role) => {
                const allowed = held(role).includes(code);
                const fixed = role.bypasses_all || ownerOnly || !editable || !role.editable;
                const roleName = t(`role.${role.key}` as MessageKey);
                const tint = draft[role.key] ? 'bg-brand-subtle' : role.bypasses_all ? 'bg-subtle' : '';

                if (role.bypasses_all || ownerOnly) {
                    return (
                        <td key={role.key} className={`h-12 px-2 text-center align-middle ${tint}`}>
                            <span role="img" aria-label={t(role.bypasses_all ? 'roles.bypass' : 'roles.ownerOnly')} title={t(role.bypasses_all ? 'roles.bypass' : 'roles.ownerOnly')} className="inline-grid text-fg-tertiary">
                                <LockSimpleIcon size={18} aria-hidden />
                            </span>
                        </td>
                    );
                }

                return (
                    <td key={role.key} className={`h-12 px-2 text-center align-middle ${tint}`}>
                        {fixed ? (
                            <span role="img" aria-label={t(allowed ? 'roles.allowed' : 'roles.denied')} className={`inline-grid ${allowed ? 'text-fg-brand' : 'text-fg-disabled'}`}>
                                {allowed ? <CheckSquareIcon size={22} weight="fill" aria-hidden /> : <SquareIcon size={22} aria-hidden />}
                            </span>
                        ) : (
                            <label className="inline-grid size-8 cursor-pointer place-items-center rounded-xs hover:bg-subtle">
                                <input
                                    type="checkbox"
                                    className="size-4.5 cursor-pointer accent-brand"
                                    checked={allowed}
                                    onChange={() => onToggle(role, code)}
                                    aria-label={t('roles.toggle', { permission: label, role: roleName })}
                                />
                            </label>
                        )}
                    </td>
                );
            })}
        </tr>
    );
}
