<?php

declare(strict_types=1);

namespace App\Models\Enums;

use App\Models\Enums\Permission as P;

/**
 * The 7 launch roles (DATABASE-DESIGN §3.10, PRD P-01) and the single source of truth for
 * the role → permission matrix. The seeder and the tests both read {@see self::permissions()}.
 *
 * super_admin is seeded with every permission AND bypasses all gates via Gate::before
 * (App\Providers\AuthServiceProvider); it is the only role that bypasses.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case OrderOperator = 'order_operator';
    case InventoryStaff = 'inventory_staff';
    case Accountant = 'accountant';
    case ContentEditor = 'content_editor';
    case Viewer = 'viewer';

    /**
     * @return list<P>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => P::cases(),

            // Runs the business day to day; cannot manage staff, roles or credentials.
            self::Manager => array_values(array_filter(
                P::cases(),
                static fn (P $p): bool => ! in_array($p, [P::StaffManage, P::RolesManage, P::SettingsManageIntegrations], true),
            )),

            // Confirms orders, calls customers (needs full phone), books couriers.
            self::OrderOperator => [
                P::DashboardView,
                P::OrdersView, P::OrdersCreate, P::OrdersUpdate, P::OrdersConfirm, P::OrdersCancel,
                P::ReturnsView, P::ReturnsManage,
                P::RefundsView, P::RefundsCreate,
                P::ShipmentsView, P::ShipmentsBook, P::ShipmentsCancel,
                P::RiskView, P::RiskVerify,
                P::CustomersView, P::CustomersCreate, P::CustomersUpdate, P::CustomersViewPii,
                P::InboxView, P::InboxReply,
                P::CatalogView,
                P::InventoryView,
            ],

            // Receives stock, counts, adjusts. Enters landed cost on receipt, so sees cost.
            self::InventoryStaff => [
                P::DashboardView,
                P::OrdersView,
                P::ReturnsView,
                P::CatalogView, P::CatalogViewCost,
                P::InventoryView, P::InventoryAdjust, P::InventoryTransfer,
                P::PurchasingView, P::PurchasingCreate, P::PurchasingReceive, P::PurchasingManageSuppliers,
            ],

            // Monthly close: P&L, expenses, COD receivables, supplier payables, exports.
            self::Accountant => [
                P::DashboardView,
                P::OrdersView, P::OrdersExport,
                P::ReturnsView,
                P::RefundsView, P::RefundsApprove,
                P::ShipmentsView,
                P::CustomersView,
                P::CatalogView, P::CatalogViewCost,
                P::InventoryView,
                P::PurchasingView,
                P::FinanceView, P::FinanceManageExpenses, P::FinanceReconcileCod,
                P::FinanceRecordSupplierPayments, P::FinanceExport,
                P::ReportsView, P::ReportsExport,
                P::AuditView,
            ],

            // Banners, blog, pages, product copy and SEO — never price, cost or PII.
            self::ContentEditor => [
                P::DashboardView,
                P::CatalogView, P::CatalogUpdate, P::ReviewsModerate,
                P::ContentView, P::ContentManage, P::MediaManage,
                P::SeoView, P::SeoManage,
                P::MarketingView,
            ],

            // Read-only over operational screens; no PII, cost, finance, settings or audit.
            self::Viewer => [
                P::DashboardView,
                P::OrdersView,
                P::ReturnsView,
                P::ShipmentsView,
                P::RiskView,
                P::CustomersView,
                P::CatalogView,
                P::InventoryView,
                P::PurchasingView,
                P::MarketingView,
                P::ContentView,
                P::SeoView,
                P::ReportsView,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_map(static fn (P $p): string => $p->value, $this->permissions());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
