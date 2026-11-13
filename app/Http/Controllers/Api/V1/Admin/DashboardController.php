<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Exports\DashboardSheets;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Domain\Reporting\Queries\Dashboard\DashboardReport;
use App\Domain\Reporting\Queries\Dashboard\DashboardScope;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Dashboard\DashboardExportRequest;
use App\Http\Requests\Admin\Dashboard\DashboardRequest;
use App\Http\Resources\Admin\Reports\DashboardResource;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin dashboard (P8, 04-admin-spec B1). Read-only.
 *
 * `dashboard.view` opens it; each block is then shown only to a viewer who could open the
 * screen behind it (rule 21 — the dashboard is never a back door to a figure a role may not
 * see). Blocks a viewer may not see come back `null`:
 *
 *   block                                         needs
 *   orders placed, AOV, trend, status, best       orders.view
 *     sellers, activity
 *   recognised revenue, gross/net profit, cash,   finance.view
 *     category revenue, trend revenue line
 *   new vs returning customers                    customers.view
 *   low stock                                     inventory.view
 *
 * Needs-attention queues, each only for a viewer who can work it:
 *   verification (risk.view) · failed_bookings, unmapped_areas, submitted (shipments.view) ·
 *   cod_variances (finance.reconcile_cod) · expenses (finance.manage_expenses) ·
 *   refunds (refunds.approve) · low_stock (inventory.view)
 */
final class DashboardController extends ApiController
{
    /** `GET /dashboard` — needs dashboard.view. */
    #[QueryParameter('range', 'Preset Dhaka-date range.', type: "'today'|'7d'|'30d'|'this_month'|'last_month'|'custom'", default: '7d')]
    #[QueryParameter('from', 'First Dhaka day, YYYY-MM-DD (inclusive). range=custom only, and then required.', type: 'string', format: 'date')]
    #[QueryParameter('to', 'Last Dhaka day, YYYY-MM-DD (inclusive). range=custom only, and then required.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unknown parameter, unlisted preset, or an invalid / missing date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing dashboard.view (`forbidden`)')]
    public function show(DashboardRequest $request, DashboardReport $report): DashboardResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DashboardResource($report->build($request->dateRange(), self::scope($user)));
    }

    /**
     * `GET /dashboard/export` — the same report as CSV (sections one after another) or XLSX (one
     * sheet each). Needs dashboard.view; a block the viewer may not see is left out of the file,
     * never written as zero. No activity feed, no needs-attention items, no customer data.
     */
    #[QueryParameter('format', 'csv or xlsx.', type: "'csv'|'xlsx'", default: 'csv')]
    #[QueryParameter('locale', 'Language of the labels and sheet names. Default: the request locale.', type: "'en'|'bn'")]
    #[QueryParameter('range', 'As on GET /dashboard.', type: "'today'|'7d'|'30d'|'this_month'|'last_month'|'custom'", default: '7d')]
    #[QueryParameter('from', 'As on GET /dashboard.', type: 'string', format: 'date')]
    #[QueryParameter('to', 'As on GET /dashboard.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unknown parameter, unlisted preset, format or locale, or an invalid / missing date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing dashboard.view or reports.export (`forbidden`)')]
    public function export(DashboardExportRequest $request, DashboardReport $report): StreamedResponse
    {
        $this->authorize('export', DashboardReport::class);
        /** @var User $user */
        $user = $request->user();
        $range = $request->dateRange();
        $format = $request->exportFormat();
        $locale = $request->exportLocale();

        // Named for the Dhaka dates covered and the Dhaka day it was exported.
        return Spreadsheet::download(
            "dashboard-{$range->fromDate()}-to-{$range->toDate()}-exported-".DhakaDate::today(),
            $format,
            DashboardSheets::build($report->build($range, self::scope($user)), $locale),
        );
    }

    private static function scope(User $user): DashboardScope
    {
        $can = static fn (Permission $p): bool => $user->can($p->value);

        $attention = [];
        if ($can(Permission::RiskView)) {
            $attention[] = 'verification';
        }
        if ($can(Permission::ShipmentsView)) {
            array_push($attention, 'failed_bookings', 'unmapped_areas', 'submitted');
        }
        if ($can(Permission::FinanceReconcileCod)) {
            $attention[] = 'cod_variances';
        }
        if ($can(Permission::FinanceManageExpenses)) {
            $attention[] = 'expenses';
        }
        if ($can(Permission::RefundsApprove)) {
            $attention[] = 'refunds';
        }

        return new DashboardScope(
            orders: $can(Permission::OrdersView),
            finance: $can(Permission::FinanceView),
            customers: $can(Permission::CustomersView),
            inventory: $can(Permission::InventoryView),
            attention: $attention,
        );
    }
}
