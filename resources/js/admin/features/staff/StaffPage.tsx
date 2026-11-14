import { useState } from 'react';
import { Outlet } from 'react-router';
import { UserPlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { Button } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { useStaff, useStaffRoles } from './api';
import { StaffDialog } from './StaffDialog';

/**
 * Settings → Staff & roles (Figma B · 35 241:48916): ContentHeader with the primary "Invite
 * staff" (here "Add staff": there is no email invite, the owner hands over a temporary
 * password), then the People and Roles & permissions tabs. The frame's Sessions and Shifts
 * tabs, Branch and Two-factor columns and the Audit log button are not built: the system
 * has no per-staff branch, 2FA, session list, shifts or activity-log screen yet.
 */
export function StaffPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, 'staff.view');
    const people = useStaff(allowed);
    const roles = useStaffRoles(allowed);
    const [adding, setAdding] = useState(false);

    if (me && !allowed) {
        return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;
    }

    const loaded = people.data && !people.hasNextPage ? people.data.pages.reduce((n, p) => n + p.data.length, 0) : undefined;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('staff.crumb.settings'), to: '/settings' }, { label: t('staff.title') }]}
                title={t('staff.title')}
                subtitle={
                    loaded !== undefined && roles.data
                        ? loaded === 1
                            ? t('staff.subtitleOne', { roles: String(roles.data.length) })
                            : t('staff.subtitle', { people: String(loaded), roles: String(roles.data.length) })
                        : undefined
                }
                actions={
                    can(me, 'staff.manage') && (
                        <Button onClick={() => setAdding(true)}>
                            <UserPlusIcon size={16} aria-hidden />
                            {t('staff.add')}
                        </Button>
                    )
                }
            />

            <TabList label={t('staff.tabs')}>
                <TabLink to="/staff" end label={t('staff.tab.people')} count={loaded} />
                <TabLink to="/staff/roles" label={t('staff.tab.roles')} count={roles.data?.length} />
            </TabList>

            <Outlet />

            <StaffDialog open={adding} member={null} onClose={() => setAdding(false)} />
        </section>
    );
}
