import {
    ArrowUUpLeftIcon,
    CashRegisterIcon,
    ChartLineIcon,
    ChatsCircleIcon,
    GearIcon,
    GlobeIcon,
    HouseIcon,
    LightningIcon,
    PackageIcon,
    PlugsConnectedIcon,
    ReceiptIcon,
    ShieldWarningIcon,
    ShoppingCartIcon,
    StorefrontIcon,
    TruckIcon,
    UserGearIcon,
    UsersIcon,
    WalletIcon,
    WarehouseIcon,
    MegaphoneIcon,
    type Icon,
} from '@phosphor-icons/react';
import type { MessageKey } from '@/i18n/messages';

export type NavItem = {
    key: MessageKey;
    path: string;
    icon: Icon;
    /** Hidden unless the signed-in user holds this permission (App\Models\Enums\Permission). */
    permission: string;
};

export type NavGroup = { label: MessageKey | null; items: NavItem[] };

/** Sidebar information architecture — docs/_design/06-admin-feature-checklist.md §1. */
export const navigation: NavGroup[] = [
    {
        label: null,
        items: [
            { key: 'nav.overview', path: '/', icon: HouseIcon, permission: 'dashboard.view' },
            { key: 'nav.inbox', path: '/inbox', icon: ChatsCircleIcon, permission: 'inbox.view' },
        ],
    },
    {
        label: 'nav.group.sell',
        items: [
            { key: 'nav.orders', path: '/orders', icon: ReceiptIcon, permission: 'orders.view' },
            // No POS permission exists yet; selling at the till is creating an order.
            { key: 'nav.pos', path: '/pos', icon: CashRegisterIcon, permission: 'orders.create' },
            { key: 'nav.returns', path: '/returns', icon: ArrowUUpLeftIcon, permission: 'returns.view' },
            { key: 'nav.customers', path: '/customers', icon: UsersIcon, permission: 'customers.view' },
        ],
    },
    {
        label: 'nav.group.catalog',
        items: [
            { key: 'nav.products', path: '/products', icon: PackageIcon, permission: 'catalog.view' },
            { key: 'nav.inventory', path: '/inventory', icon: WarehouseIcon, permission: 'inventory.view' },
            { key: 'nav.purchasing', path: '/purchasing', icon: ShoppingCartIcon, permission: 'purchasing.view' },
        ],
    },
    {
        label: 'nav.group.deliver',
        items: [
            { key: 'nav.shipments', path: '/shipments', icon: TruckIcon, permission: 'shipments.view' },
            { key: 'nav.risk', path: '/risk', icon: ShieldWarningIcon, permission: 'risk.view' },
        ],
    },
    {
        label: 'nav.group.grow',
        items: [
            { key: 'nav.marketing', path: '/marketing', icon: MegaphoneIcon, permission: 'marketing.view' },
            { key: 'nav.analytics', path: '/analytics', icon: ChartLineIcon, permission: 'reports.view' },
            { key: 'nav.seo', path: '/seo', icon: GlobeIcon, permission: 'seo.view' },
        ],
    },
    {
        label: 'nav.group.store',
        items: [{ key: 'nav.store', path: '/store', icon: StorefrontIcon, permission: 'content.view' }],
    },
    {
        label: 'nav.group.money',
        items: [{ key: 'nav.finance', path: '/finance', icon: WalletIcon, permission: 'finance.view' }],
    },
    {
        label: 'nav.group.settings',
        items: [
            { key: 'nav.staff', path: '/staff', icon: UserGearIcon, permission: 'staff.view' },
            { key: 'nav.automations', path: '/automations', icon: LightningIcon, permission: 'settings.view' },
            { key: 'nav.integrations', path: '/integrations', icon: PlugsConnectedIcon, permission: 'settings.manage_integrations' },
            { key: 'nav.settings', path: '/settings', icon: GearIcon, permission: 'settings.view' },
        ],
    },
];

/** Groups with only the items this user may open; empty groups disappear. */
export function visibleNavigation(permissions: readonly string[]): NavGroup[] {
    return navigation
        .map((group) => ({ ...group, items: group.items.filter((item) => permissions.includes(item.permission)) }))
        .filter((group) => group.items.length > 0);
}

/** Every section path except the dashboard, for the placeholder routes. */
export const sectionPaths = navigation.flatMap((g) => g.items).filter((i) => i.path !== '/');
