<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

/**
 * Which dashboard blocks the viewer may see. Built by the HTTP layer from the viewer's
 * permissions; the report computes nothing it would then have to hide. A block the viewer may
 * not see is omitted (null), never zeroed — a zero would be a false statement.
 *
 *   orders      orders.view            orders placed (count + value), AOV, sales trend, status
 *                                      strip, best sellers, activity feed
 *   finance     finance.view           recognised revenue, gross/net profit, cash collected,
 *                                      the trend's revenue line, category revenue
 *   customers   customers.view         new vs returning customers
 *   inventory   inventory.view         low stock card, list and attention item
 *   attention   the queues the viewer can act on or open (see DashboardController)
 */
final readonly class DashboardScope
{
    /**
     * @param  list<string>  $attention  WorkQueues::ATTENTION keys
     */
    public function __construct(
        public bool $orders,
        public bool $finance,
        public bool $customers,
        public bool $inventory,
        public array $attention,
    ) {}

    public static function everything(): self
    {
        return new self(true, true, true, true, WorkQueues::ATTENTION);
    }
}
