import {
    ArrowUUpLeftIcon,
    CashRegisterIcon,
    ChartLineIcon,
    ChatsIcon,
    GearIcon,
    GlobeIcon,
    SquaresFourIcon,
    LightningIcon,
    PackageIcon,
    PlugsConnectedIcon,
    ArticleIcon,
    ShieldWarningIcon,
    FileTextIcon,
    BrowserIcon,
    TruckIcon,
    UserGearIcon,
    UsersIcon,
    WalletIcon,
    StackIcon,
    MegaphoneSimpleIcon,
    ReceiptIcon,
    type Icon,
} from '@phosphor-icons/react';
import type { MessageKey } from '@/i18n/messages';

export type NavItem = {
    key: MessageKey;
    path: string;
    icon: Icon;
    /** Hidden unless the signed-in user holds this permission (App\Models\Enums\Permission). */
    permission: string;
    /**
     * Second-level links shown under the item while the route is inside it (Figma `Sidebar`
     * `storeSubmenu`, `SidebarSubItem` 321:59233). They inherit the item's permission.
     */
    children?: NavChild[];
};

export type NavChild = { key: MessageKey; path: string };

export type NavGroup = { label: MessageKey | null; items: NavItem[] };

/**
 * Sidebar information architecture — docs/_design/06-admin-feature-checklist.md §1.
 * Glyphs follow Figma Sidebar 187:473 (Phosphor, regular weight).
 */
export const navigation: NavGroup[] = [
    {
        label: null,
        items: [
            { key: 'nav.overview', path: '/', icon: SquaresFourIcon, permission: 'dashboard.view' },
            { key: 'nav.inbox', path: '/inbox', icon: ChatsIcon, permission: 'inbox.view' },
        ],
    },
    {
        label: 'nav.group.sell',
        items: [
            { key: 'nav.orders', path: '/orders', icon: ArticleIcon, permission: 'orders.view' },
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
            { key: 'nav.inventory', path: '/inventory', icon: StackIcon, permission: 'inventory.view' },
            { key: 'nav.purchasing', path: '/purchasing', icon: FileTextIcon, permission: 'purchasing.view' },
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
            { key: 'nav.marketing', path: '/marketing', icon: MegaphoneSimpleIcon, permission: 'marketing.view' },
            { key: 'nav.analytics', path: '/analytics', icon: ChartLineIcon, permission: 'reports.view' },
            { key: 'nav.seo', path: '/seo', icon: GlobeIcon, permission: 'seo.view' },
        ],
    },
    {
        label: 'nav.group.store',
        items: [
            {
                key: 'nav.store',
                path: '/store',
                icon: BrowserIcon,
                permission: 'content.view',
                children: [
                    { key: 'nav.store.home', path: '/store/home' },
                    { key: 'nav.store.pages', path: '/store/pages' },
                    { key: 'nav.store.navigation', path: '/store/navigation' },
                    { key: 'nav.store.blog', path: '/store/blog' },
                    { key: 'nav.store.banners', path: '/store/banners' },
                    { key: 'nav.store.testimonials', path: '/store/testimonials' },
                    { key: 'nav.store.media', path: '/store/media' },
                ],
            },
        ],
    },
    {
        label: 'nav.group.money',
        items: [
            { key: 'nav.finance', path: '/finance', icon: WalletIcon, permission: 'finance.view' },
            // P8 invoices: customer invoices need orders.view (supplier ones purchasing.view + view_cost, filtered server-side).
            { key: 'nav.invoices', path: '/invoices', icon: ReceiptIcon, permission: 'orders.view' },
        ],
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
