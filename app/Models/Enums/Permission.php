<?php

declare(strict_types=1);

namespace App\Models\Enums;

/**
 * Every admin permission, named `resource.action`.
 *
 * Derived from the admin information architecture (docs/_design/06-admin-feature-checklist.md),
 * PRD §2 users and §3.8, and SYSTEM-ARCHITECTURE security table. Which role holds which
 * permission is defined once, in {@see Role::permissions()}.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    // Orders
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersUpdate = 'orders.update';
    case OrdersConfirm = 'orders.confirm';
    case OrdersCancel = 'orders.cancel';
    case OrdersExport = 'orders.export';

    // Returns & refunds
    case ReturnsView = 'returns.view';
    case ReturnsManage = 'returns.manage';
    case RefundsView = 'refunds.view';
    case RefundsCreate = 'refunds.create';
    case RefundsApprove = 'refunds.approve';

    // Shipments & courier
    case ShipmentsView = 'shipments.view';
    case ShipmentsBook = 'shipments.book';
    case ShipmentsCancel = 'shipments.cancel';
    case ShipmentsManageLocations = 'shipments.manage_locations';
    case ShipmentsManageRates = 'shipments.manage_rates';

    // Fraud & risk
    case RiskView = 'risk.view';
    case RiskVerify = 'risk.verify';
    case RiskManageLists = 'risk.manage_lists';
    case RiskManageRules = 'risk.manage_rules';

    // Customers
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    /** Full phone and address. Without it, list and detail views show masked values. */
    case CustomersViewPii = 'customers.view_pii';
    case CustomersExport = 'customers.export';

    // Inbox
    case InboxView = 'inbox.view';
    case InboxReply = 'inbox.reply';

    // Catalog
    case CatalogView = 'catalog.view';
    case CatalogCreate = 'catalog.create';
    case CatalogUpdate = 'catalog.update';
    case CatalogArchive = 'catalog.archive';
    case CatalogImport = 'catalog.import';
    /** Selling price changes are audited (audit_sensitive) and separated from content edits. */
    case CatalogUpdatePrice = 'catalog.update_price';
    case CatalogViewCost = 'catalog.view_cost';
    /** Approve, reject and reply to product reviews (P4-CAT-12). Reading them needs only catalog.view. */
    case ReviewsModerate = 'reviews.moderate';

    // Inventory
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryTransfer = 'inventory.transfer';

    // Purchasing
    case PurchasingView = 'purchasing.view';
    case PurchasingCreate = 'purchasing.create';
    case PurchasingReceive = 'purchasing.receive';
    case PurchasingManageSuppliers = 'purchasing.manage_suppliers';

    // Finance
    case FinanceView = 'finance.view';
    case FinanceManageExpenses = 'finance.manage_expenses';
    case FinanceReconcileCod = 'finance.reconcile_cod';
    case FinanceRecordSupplierPayments = 'finance.record_supplier_payments';
    case FinanceExport = 'finance.export';

    // Marketing
    case MarketingView = 'marketing.view';
    case MarketingManage = 'marketing.manage';
    case MarketingSendCampaigns = 'marketing.send_campaigns';

    // Online store / CMS
    case ContentView = 'content.view';
    case ContentManage = 'content.manage';
    case MediaManage = 'media.manage';
    /** Delete library files and bulk-download originals: owner and admin roles only (docs/api/media-library.md, decision 7). */
    case MediaDelete = 'media.delete';

    // SEO
    case SeoView = 'seo.view';
    case SeoManage = 'seo.manage';

    // Analytics & reports
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // Settings & integrations
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';
    /** Courier, gateway, tracking and messaging credentials (rule 20; audited). */
    case SettingsManageIntegrations = 'settings.manage_integrations';

    // Staff, roles & audit
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
