import { useState } from 'react';
import { BuildingsIcon, PlusIcon, StorefrontIcon, WarehouseIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { FormSection } from '@/components/ui/FormSection';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useStoreLocations, type LocationType, type StoreLocation } from './api';
import { BranchDialog } from './BranchDialog';
import { useSettingsAccess } from './parts';

const typeIcon: Record<LocationType, Icon> = { warehouse: WarehouseIcon, retail: StorefrontIcon, pickup: BuildingsIcon };

/** `House 7, Road 2, Mirpur, Dhaka` — line, area, upazila, district, in the reader's script where we have it. */
export function addressOf(l: StoreLocation, locale: string): string {
    const place = (p: { name: string; name_bn: string } | null) => (p ? (locale === 'bn' ? p.name_bn : p.name) : null);
    return [l.address_line, l.area, place(l.upazila), place(l.district)].filter((part): part is string => Boolean(part && part.trim())).join(', ');
}

/**
 * Store settings › Branches & warehouses (Figma B · 38 241:50827): one row per stock location
 * — icon tile, name, type badge, address, facts line, Edit — and "Add branch". Locations are
 * never deleted; the dialog switches one off. Read with settings.view, change with settings.update.
 */
export function BranchesSection() {
    const { t, locale } = useI18n();
    const { canView, canEdit } = useSettingsAccess();
    const locations = useStoreLocations(canView);
    const [editing, setEditing] = useState<StoreLocation | null>(null);
    const [open, setOpen] = useState(false);

    if (!canView) return null;
    if (locations.isError) return <QueryError error={locations.error} onRetry={() => void locations.refetch()} />;
    if (!locations.data) return <p aria-busy="true" className="sr-only" />;

    const openDialog = (location: StoreLocation | null) => {
        setEditing(location);
        setOpen(true);
    };

    return (
        <FormSection
            title={t('storeSettings.branches.title')}
            description={t('storeSettings.branches.subtitle')}
            aside={
                canEdit && (
                    <Button variant="outline" size="sm" onClick={() => openDialog(null)}>
                        <PlusIcon size={16} aria-hidden />
                        {t('storeSettings.branches.add')}
                    </Button>
                )
            }
        >
            {locations.data.length === 0 ? (
                <p className="t-admin-body text-fg-secondary">{t('storeSettings.branches.empty')}</p>
            ) : (
                <ul className="flex flex-col gap-2.5">
                    {locations.data.map((l) => {
                        const TypeIcon = l.type ? typeIcon[l.type] : BuildingsIcon;
                        const name = locale === 'bn' && l.name_bn ? l.name_bn : l.name;
                        const address = addressOf(l, locale);
                        return (
                            <li key={l.id} className={`flex items-center gap-3.5 rounded-md border border-line-default px-3.5 py-3.5 ${l.is_active ? '' : 'bg-subtle'}`}>
                                <span aria-hidden className="grid size-10 shrink-0 place-items-center rounded-sm bg-subtle text-fg-secondary">
                                    <TypeIcon size={20} />
                                </span>
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="t-admin-body-strong text-fg-primary">{name}</span>
                                        <StatusChip label={t(`storeSettings.branches.type.${l.type ?? 'none'}` as MessageKey)} showIcon={false} />
                                        {l.is_default && <StatusChip label={t('storeSettings.branches.default')} tone="brand" />}
                                        {!l.is_active && <StatusChip label={t('storeSettings.branches.inactive')} tone="neutral" />}
                                    </div>
                                    <p className="t-admin-caption text-fg-secondary">{address || t('storeSettings.branches.noAddress')}</p>
                                    {l.landmark && <p className="t-admin-caption text-fg-secondary">{t('storeSettings.branches.landmark', { landmark: l.landmark })}</p>}
                                    {l.notes && <p className="t-admin-caption text-fg-secondary">{l.notes}</p>}
                                </div>
                                {canEdit && (
                                    <Button variant="ghost" size="sm" aria-label={t('storeSettings.branches.editLabel', { name })} onClick={() => openDialog(l)}>
                                        {t('storeSettings.branches.edit')}
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {canEdit && <BranchDialog open={open} location={editing} onClose={() => setOpen(false)} />}
        </FormSection>
    );
}
