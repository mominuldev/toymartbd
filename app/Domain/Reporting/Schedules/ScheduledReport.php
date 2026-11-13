<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Schedules;

use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Fulfilment\Reports\CourierPerformanceReport;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Exports\ReportSheets;
use App\Domain\Reporting\Exports\Sheet;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Builds one scheduled report for one recipient from the SAME query classes the admin
 * screens and their exports use — never a second implementation. Visibility follows the
 * recipient's own role: the sales report includes cost and profit only for a recipient
 * holding finance.view + catalog.view_cost (the profitability report's rule). The reports
 * here are aggregates; none carries a customer's name, phone or address (rule 20).
 */
final readonly class ScheduledReport
{
    public function __construct(
        private SalesAnalytics $sales,
        private ProfitAndLoss $pnl,
        private CourierPerformanceReport $couriers,
    ) {}

    public static function canSeeProfit(User $user): bool
    {
        return $user->checkPermissionTo(Permission::FinanceView->value) && $user->checkPermissionTo(Permission::CatalogViewCost->value);
    }

    /**
     * @return array{headline: list<array{label: string, value: string}>, sheets: list<Sheet>, profit_hidden: bool}
     */
    public function build(ReportType $type, DateRange $range, bool $withProfit, string $locale): array
    {
        $line = static fn (string $key, string $value): array => ['label' => (string) __('analytics.mail.line.'.$key, [], $locale), 'value' => $value];

        return match ($type) {
            ReportType::Sales => (function () use ($range, $withProfit, $locale, $line): array {
                $report = $this->sales->report($range, SalesAnalytics::defaultGranularity($range), $withProfit);
                $s = $report['summary'];
                $headline = [
                    $line('net_value', self::taka($s['placed']['net_value'])),
                    $line('net_orders', self::count($s['placed']['net_orders'])),
                    $line('aov', self::taka($s['placed']['aov'])),
                    $line('net_revenue', self::taka($s['recognised']['net_revenue'])),
                ];
                if ($withProfit) {
                    $headline[] = $line('gross_profit', self::taka($s['recognised']['gross_profit']));
                }
                $headline[] = $line('repeat_rate', self::percent($s['customers']['repeat_rate']));

                return ['headline' => $headline, 'sheets' => ReportSheets::sales($report, $locale), 'profit_hidden' => ! $withProfit];
            })(),
            ReportType::Pnl => (function () use ($range, $locale, $line): array {
                $statement = $this->pnl->statement($range->period(), 'none');

                return ['headline' => [
                    $line('net_revenue', self::taka($statement['totals']['net_revenue'])),
                    $line('gross_profit', self::taka($statement['totals']['gross_profit'])),
                    $line('net_profit', self::taka($statement['totals']['net_profit'])),
                    $line('cash_net', self::taka($statement['cash']['net'])),
                ], 'sheets' => ReportSheets::pnl($statement, $locale), 'profit_hidden' => false];
            })(),
            ReportType::Couriers => (function () use ($range, $locale, $line): array {
                $data = $this->couriers->report($range->fromDate(), $range->toDate());

                return ['headline' => [
                    $line('booked', self::count((int) $data['totals']['booked'])),
                    $line('delivery_rate', self::percent($data['totals']['delivery_rate'] ?? null)),
                    $line('cod_outstanding', self::taka($data['totals']['cod_outstanding'] ?? null)),
                ], 'sheets' => ReportSheets::couriers($data, $locale), 'profit_hidden' => false];
            })(),
        };
    }

    /** "৳12,345.5" — Western digits in both locales (rule 36), the server's figure (rule 1). */
    public static function taka(mixed $amount): string
    {
        $decimal = ReportSheets::cell($amount);
        if ($decimal === '') {
            return '—';
        }
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '');
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $fraction = rtrim($fraction, '0');

        return ($negative ? '−' : '').'৳'.$grouped.($fraction !== '' ? '.'.$fraction : '');
    }

    public static function count(int $value): string
    {
        return number_format($value);
    }

    public static function percent(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : ReportSheets::cell($value).'%';
    }
}
