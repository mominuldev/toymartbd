<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Actions\SyncOrderPaymentStatus;
use App\Domain\Payment\Services\PaymentTransitions;
use App\Domain\Pos\DTOs\DueCollectionData;
use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Jobs\RecordPosDueCollectionInLedger;
use App\Domain\Pos\Models\PosDueCollection;
use App\Domain\Pos\Models\PosDueCollectionAllocation;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Services\PosDueCollections;
use App\Domain\Pos\Tenders\TenderRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collect due at the counter (owner decision 2026-11-15) — ONE transaction (rule 3):
 *
 *   1. lock the drawer session; it must be open; a collection already taken with this
 *      Idempotency-Key on this drawer is returned as is;
 *   2. lock the customer row (the same lock a due sale takes, so a sale and a collection for
 *      one customer serialise), then their open POS orders FOR UPDATE in ascending id;
 *   3. plan (PosDueCollections): FIFO over those orders, change from cash only, non-cash never
 *      above the open due; refuse on any error or when nothing would be applied;
 *   4. per slice: a payment on that order through PaymentTransitions (pending → paid by the
 *      tender's driver, rule 5) and its allocation row; then each touched order's
 *      payment_status re-derived (SyncOrderPaymentStatus) — due_total falls by what was paid;
 *   5. the pos_due_collections row. After commit, the payments are booked as cash
 *      (`pos_receipt`, exactly like a sale's tenders — rule 10) by a queued job. Revenue was
 *      recognised when the order was handed over; nothing here touches the P&L.
 *
 * No external call (rule 4).
 *
 * @throws ValidationException field-level (422)
 * @throws PosRefused the drawer is closed (409)
 */
final readonly class CollectDue
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private PosDueCollections $collections,
        private PaymentTransitions $payments,
        private SyncOrderPaymentStatus $syncPayment,
        private TenderRegistry $tenders,
    ) {}

    public function handle(DueCollectionData $data): PosDueCollection
    {
        try {
            return DB::transaction(fn (): PosDueCollection => $this->collect($data), self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $data->idempotencyKey === null ? null : PosDueCollection::query()
                ->where('session_id', $data->sessionId)->where('idempotency_key', $data->idempotencyKey)->first();

            return $existing ?? throw $e;
        }
    }

    private function collect(DueCollectionData $data): PosDueCollection
    {
        /** @var PosSession $session */
        $session = PosSession::query()->lockForUpdate()->findOrFail($data->sessionId);
        if (! $session->isOpen()) {
            throw PosRefused::sessionClosed();
        }
        if ($data->idempotencyKey !== null) {
            $existing = PosDueCollection::query()->where('session_id', $session->id)->where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $customer = Customer::query()->where('phone', $data->phone->e164())->lockForUpdate()->first();
        $plan = $this->collections->plan($customer, $data->tenders, lock: true);
        if ($plan->errors !== []) {
            throw self::invalid($plan->errors);
        }
        if (! $plan->chargeable() || $plan->customer === null) {
            throw self::invalid([['field' => 'tenders', 'message' => (string) __('pos.tender.short')]]);
        }

        $collection = PosDueCollection::query()->create([
            'session_id' => $session->id,
            'customer_id' => $plan->customer->id,
            'idempotency_key' => $data->idempotencyKey,
            'cashier_id' => $data->cashierId,
            'collected' => $plan->applied,
            'tendered_total' => $plan->tendered,
            'cash_tendered' => $plan->cashTendered,
            'change_due' => $plan->change,
            'remaining_due' => $plan->remaining,
        ]);

        $touched = [];
        foreach ($plan->slices as $slice) {
            $payment = $this->payments->open($slice['order_id'], $slice['method'], $slice['amount']);
            $result = $this->tenders->for($slice['tender'])->capture($payment, $slice['amount'], $slice['reference'], $data->cashierId);
            $this->payments->apply($payment, $result, $data->cashierId);
            PosDueCollectionAllocation::query()->create([
                'collection_id' => $collection->id,
                'order_id' => $slice['order_id'],
                'payment_id' => $payment->id,
                'method' => $slice['method'],
                'amount' => $slice['amount'],
            ]);
            $touched[$slice['order_id']] = true;
        }
        $ids = array_keys($touched);
        sort($ids);
        foreach ($ids as $orderId) {
            $this->syncPayment->handle($orderId);
        }

        RecordPosDueCollectionInLedger::dispatch($collection->id)->afterCommit();

        return $collection;
    }

    /** @param  list<array{field: string, message: string}>  $errors */
    private static function invalid(array $errors): ValidationException
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error['field']][] = $error['message'];
        }

        return ValidationException::withMessages($messages);
    }
}
