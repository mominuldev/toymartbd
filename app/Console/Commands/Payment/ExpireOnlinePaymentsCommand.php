<?php

declare(strict_types=1);

namespace App\Console\Commands\Payment;

use App\Domain\Ordering\Actions\ExpireOnlinePayment;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The online-payment expiry sweeper (P4-PAY-04): every unpaid online order whose latest
 * attempt's window has passed is re-queried, then expired — payment `expired`, stock
 * released, order cancelled (ExpireOnlinePayment). Scheduled every five minutes.
 */
final class ExpireOnlinePaymentsCommand extends Command
{
    protected $signature = 'toymart:payments:expire {--limit=200 : Orders to examine in one run}';

    protected $description = 'Expire unpaid online payments past their window and release their stock';

    public function handle(ExpireOnlinePayment $expire): int
    {
        $online = array_values(array_map(
            static fn (PaymentMethod $m): string => $m->value,
            array_filter(PaymentMethod::cases(), static fn (PaymentMethod $m): bool => $m->isOnline()),
        ));

        $orders = Order::query()
            ->whereIn('payment_method', $online)
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::OnHold->value])
            ->whereHas('payments', fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $counts = [ExpireOnlinePayment::EXPIRED => 0, ExpireOnlinePayment::PAID => 0, ExpireOnlinePayment::SKIPPED => 0];
        $failed = 0;
        foreach ($orders as $order) {
            try {
                $counts[$expire->handle($order)]++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('payment.expiry_failed', ['order_id' => $order->id, 'exception' => $e::class]);
            }
        }

        $this->info("Expired {$counts['expired']}, found paid {$counts['paid']}, skipped {$counts['skipped']}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
