<?php

declare(strict_types=1);

namespace App\Domain\Payment\Jobs;

use App\Domain\Payment\Actions\HandleSslcommerzNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-query and apply a verified SSLCommerz IPN (P4-PAY-03) off the request, so the
 * listener answers 200 fast. On the `critical` queue: a paid customer is waiting.
 */
final class ProcessSslcommerzReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('critical');
    }

    public function handle(HandleSslcommerzNotification $handler): void
    {
        if ($handler->process($this->receiptId) === 'deferred' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }
}
