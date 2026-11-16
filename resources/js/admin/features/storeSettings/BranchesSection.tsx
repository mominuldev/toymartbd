import { useState, type ReactNode } from 'react';
import { BuildingsIcon, CaretUpDownIcon, PlusIcon, StorefrontIcon, WarehouseIcon, type Icon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip } from '@/components/ui/StatusChip';
import { useStoreLocations, type LocationType, type StoreLocation } from './api';
import { BranchDialog } from './BranchDialog';
import { BranchModeCard } from './BranchModeCard';
import { useBranchMode } from './branchModeApi';
import { useSettingsAccess } from './parts';

/** Figma 410:69597: a warehouse tile for warehouses, a storefront for shops and pickup points. */
const typeIcon: Record<LocationType, Icon> = { warehouse: WarehouseIcon, retail: StorefrontIcon, pickup: StorefrontIcon };

/** `House 7, Road 2, Mirpur, Dhaka` — line, area, upazila, district, in the reader's script where we have it. */
export function addressOf(l: StoreLocation, locale: string): string {
    const place = (p: { name: string; name_bn: string } | null) => (p ? (locale === 'bn' ? p.name_bn : p.name) : null);
    return [l.address_line, l.area, place(l.upazila), place(l.district)].filter((part): part is string => Boolean(part && part.trim())).join(', ');
}

/**
 * Store settings › Branches & warehouses (Figma 410:69597 multi, 410:69706 single, 410:69769
 * blocked): the section header (title, description, "Add branch"), the Branch setup card, then
 * the locations card — one row per location: a 40 icon tile, name with Default / Switched off
 * chips, "type · address" caption, Edit. Locations are never deleted; the dialog switches one off.
 * Read with settings.view, change with settings.update.
 *
 * A single shop is titled "Shop & warehouse", shows only its default location and no "Add
 * branch"; switched-off locations sit behind a disclosure, read-only (the server refuses to
 * switch one on while single).
 */
export function BranchesSection() {
    const { t, locale } = useI18n();
    const { canView, canEdit } = useSettingsAccess();
    const locations = useStoreLocations(canView);
    const mode = useBranchMode(canView);
    const [editing, setEditing] = useState<StoreLocation | null>(null);
    const [open, setOpen] = useState(false);

    if (!canView) return null;
    if (locations.isError) return <QueryError error={locations.error} onRetry={() => void locations.refetch()} />;
    if (mode.isError) return <QueryError error={mode.error} onRetry={() => void mode.refetch()} />;
    if (!locations.data || !mode.data) return <p aria-busy="true" className="sr-only" />;

    const single = !mode.data.multi_branch;
    const title = t(single ? 'branchMode.shop.title' : 'storeSettings.branches.title');
    const openDialog = (location: StoreLocation | null) => {
        setEditing(location);
        setOpen(true);
    };

    const row = (l: StoreLocation, editableRow: boolean): ReactNode => {
        const TypeIcon = l.type ? typeIcon[l.type] : BuildingsIcon;
        const name = locale === 'bn' && l.name_bn ? l.name_bn : l.name;
        const address = addressOf(l, locale);
        const facts = [t(`storeSettings.branches.type.${l.type ?? 'none'}` as MessageKey), address || t('storeSettings.branches.noAddress')].join(' · ');
        return (
            <li key={l.id} className="flex items-center gap-3.5 border-b border-line-default py-3.5 last:border-b-0">
                <span aria-hidden className={`grid size-10 shrink-0 place-items-center rounded-xs-plus ${l.is_active ? 'bg-brand-subtle text-fg-brand' : 'bg-subtle text-fg-tertiary'}`}>
                    <TypeIcon size={20} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className={`t-admin-body-strong ${l.is_active ? 'text-fg-primary' : 'text-fg-secondary'}`}>{name}</span>
                        {l.is_default && <StatusChip label={t('storeSettings.branches.default')} showIcon={false} />}
                        {!l.is_active && <StatusChip label={t('storeSettings.branches.inactive')} showIcon={false} />}
                    </div>
                    <p className="t-admin-caption text-fg-secondary">{facts}</p>
                    {l.landmark && <p className="t-admin-caption text-fg-secondary">{t('storeSettings.branches.landmark', { landmark: l.landmark })}</p>}
                    {l.notes && <p className="t-admin-caption text-fg-secondary">{l.notes}</p>}
                </div>
                {editableRow && (
                    <Button variant="ghost" size="sm" className="max-lg:h-11" aria-label={t('storeSettings.branches.editLabel', { name })} onClick={() => openDialog(l)}>
                        {t('storeSettings.branches.edit')}
                    </Button>
                )}
            </li>
        );
    };

    // Single shop: the shop itself (the default, or the one active location), the rest switched off.
    const shop = single ? (locations.data.find((l) => l.is_default) ?? locations.data.find((l) => l.is_active) ?? null) : null;
    const others = single ? locations.data.filter((l) => l.id !== shop?.id) : [];
    const card = 'flex flex-col rounded-md border border-line-default bg-surface px-5 py-1.5 shadow-e1';

    return (
        <section aria-labelledby="branches-title" className="flex min-w-0 flex-col gap-5">
            <header className="flex items-start gap-4">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id="branches-title" className="t-admin-card-title text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{t(single ? 'branchMode.shop.subtitle' : 'storeSettings.branches.subtitle')}</p>
                </div>
                {canEdit && !single && (
                    <Button variant="outline" size="sm" className="shrink-0 max-lg:h-11" onClick={() => openDialog(null)}>
                        <PlusIcon size={16} aria-hidden />
                        {t('storeSettings.branches.add')}
                    </Button>
                )}
            </header>

            <BranchModeCard mode={mode.data} editable={canEdit} section={title} />

            {locations.data.length === 0 ? (
                <div className={`${card} py-5`}>
                    <p className="t-admin-body text-fg-secondary">{t('storeSettings.branches.empty')}</p>
                </div>
            ) : single ? (
                <div className={card}>
                    {shop && <ul>{row(shop, canEdit)}</ul>}
                    {others.length > 0 && (
                        <details className="group border-t border-line-default">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 pt-3 pb-2 lg:min-h-0 [&::-webkit-details-marker]:hidden">
                                <CaretUpDownIcon size={16} aria-hidden className="shrink-0 text-fg-secondary" />
                                <span className="min-w-0 flex-1 t-admin-body-strong text-fg-secondary group-hover:text-fg-primary">{t('branchMode.shop.switchedOff', { count: String(others.length) })}</span>
                                <span className="t-admin-caption text-fg-tertiary">{t('branchMode.shop.switchedOffHint')}</span>
                            </summary>
                            <ul>{others.map((l) => row(l, false))}</ul>
                        </details>
                    )}
                </div>
            ) : (
                <ul className={card}>{locations.data.map((l) => row(l, canEdit))}</ul>
            )}
            {canEdit && <BranchDialog open={open} location={editing} singleShop={single} onClose={() => setOpen(false)} />}
        </section>
    );
}
