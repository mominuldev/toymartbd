<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Bkash;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful stand-in for bKash's documented Tokenized Checkout calls, answering with the
 * hand-authored fixtures (see {@see Fixtures}). Each created paymentId starts `Initiated`; a
 * successful Execute moves it to `Completed`. Knobs let a test make Execute fail or time out,
 * the query lie about the amount, or the grant refuse the keys.
 */
final class BkashSandbox
{
    /** @var array<string, array{invoice: string, amount: string, reference: string, status: string, trx_id: string|null}> */
    public array $payments = [];

    /** `success` | `insufficient_balance` | `already_completed` | `timeout` | `timeout_but_completed` */
    public string $execute = 'success';

    /** Overrides the amount the query reports (a tampered or mismatched amount). */
    public ?string $queryAmount = null;

    /** `ok` | `refused` | `down` */
    public string $grant = 'ok';

    /** `ok` | `timeout` | `refused` */
    public string $refund = 'ok';

    /** Answer the next N authorised calls with HTTP 401 (a revoked token). */
    public int $unauthorised = 0;

    /** @var array<string, int> */
    public array $calls = ['grant' => 0, 'refresh' => 0, 'create' => 0, 'execute' => 0, 'query' => 0, 'refund' => 0, 'refund_status' => 0];

    public function __construct(private readonly string $base) {}

    public function handle(Request $request): PromiseInterface
    {
        $path = substr($request->url(), strlen($this->base));

        if ($path === '/auth/grant-token' || $path === '/auth/refresh-token') {
            $kind = $path === '/auth/grant-token' ? 'grant' : 'refresh';
            $this->calls[$kind]++;

            return match ($this->grant) {
                'refused' => Http::response(Fixtures::load('grant.refused')),
                'down' => Http::response('Bad gateway', 502),
                default => Http::response(Fixtures::load('grant.success', [
                    'id_token' => "tok-{$kind}-".$this->calls[$kind],
                    'refresh_token' => 'refresh-'.$this->calls['grant'],
                ])),
            };
        }

        if ($this->unauthorised > 0) {
            $this->unauthorised--;

            return Http::response(['message' => 'Unauthorized'], 401);
        }

        return match ($path) {
            '/payment/create' => $this->create($request),
            '/payment/execute' => $this->executePayment($request),
            '/query/payment' => $this->query($request),
            '/refund/payment/transaction' => $this->refundPayment($request),
            '/refund/payment/status' => $this->refundStatus($request),
            default => Http::response(['externalCode' => '404'], 404),
        };
    }

    public function complete(string $paymentId): void
    {
        $this->payments[$paymentId]['status'] = 'Completed';
        $this->payments[$paymentId]['trx_id'] = 'DIK'.substr(md5($paymentId), 0, 7);
    }

    private function create(Request $request): PromiseInterface
    {
        $this->calls['create']++;
        $id = 'TR0011FX'.str_pad((string) $this->calls['create'], 4, '0', STR_PAD_LEFT).'Z1789885888970';
        $this->payments[$id] = [
            'invoice' => (string) $request['merchantInvoiceNumber'],
            'amount' => (string) $request['amount'],
            'reference' => (string) $request['payerReference'],
            'status' => 'Initiated',
            'trx_id' => null,
        ];

        return Http::response(Fixtures::load('create.success', [
            'payment_id' => $id,
            'callback' => (string) $request['callbackURL'],
            'amount' => (string) $request['amount'],
            'invoice' => (string) $request['merchantInvoiceNumber'],
        ]));
    }

    private function executePayment(Request $request): PromiseInterface
    {
        $this->calls['execute']++;
        $id = (string) $request['paymentId'];
        if (! isset($this->payments[$id])) {
            return Http::response(Fixtures::load('query.invalid_payment_id'));
        }
        if ($this->payments[$id]['status'] === 'Completed') {
            return Http::response(Fixtures::load('execute.already_completed'));
        }

        switch ($this->execute) {
            case 'insufficient_balance':
                return Http::response(Fixtures::load('execute.insufficient_balance'));
            case 'already_completed':
                return Http::response(Fixtures::load('execute.already_completed'));
            case 'timeout':
                return Http::failedConnection();
            case 'timeout_but_completed':
                $this->complete($id);

                return Http::failedConnection();
        }

        $this->complete($id);

        return Http::response(Fixtures::load('execute.success', $this->values($id)));
    }

    private function query(Request $request): PromiseInterface
    {
        $this->calls['query']++;
        $id = (string) $request['paymentId'];
        if (! isset($this->payments[$id])) {
            return Http::response(Fixtures::load('query.invalid_payment_id'));
        }
        $name = $this->payments[$id]['status'] === 'Completed' ? 'query.completed' : 'query.initiated';

        return Http::response(Fixtures::load($name, $this->values($id, $this->queryAmount)));
    }

    private function refundPayment(Request $request): PromiseInterface
    {
        $this->calls['refund']++;

        return match ($this->refund) {
            'timeout' => Http::failedConnection(),
            'refused' => Http::response(['internalCode' => 'refund_not_allowed', 'externalCode' => '2071', 'errorMessageEn' => 'Refund not allowed', 'errorMessageBn' => null]),
            default => Http::response(Fixtures::load('refund.success', $this->refundValues($request))),
        };
    }

    private function refundStatus(Request $request): PromiseInterface
    {
        $this->calls['refund_status']++;

        return Http::response(Fixtures::load('refund_status.success', $this->refundValues($request, (string) ($this->lastRefundAmount ?? '0.00'))));
    }

    /** The amount of the last refund asked for (what Refund Status reports as completed). */
    public ?string $lastRefundAmount = null;

    /** @return array<string, string> */
    private function refundValues(Request $request, ?string $amount = null): array
    {
        $id = (string) $request['paymentId'];
        if ($amount === null) {
            $this->lastRefundAmount = (string) $request['refundAmount'];
        }

        return [
            'trx_id' => (string) $request['trxId'],
            'refund_trx_id' => 'RFD'.substr(md5($id), 0, 7),
            'original_amount' => $this->payments[$id]['amount'] ?? '0.00',
            'amount' => $amount ?? (string) $request['refundAmount'],
            'invoice' => $this->payments[$id]['invoice'] ?? '',
        ];
    }

    /** @return array<string, string> */
    private function values(string $id, ?string $amount = null): array
    {
        $p = $this->payments[$id];

        return [
            'payment_id' => $id,
            'trx_id' => (string) $p['trx_id'],
            'amount' => $amount ?? $p['amount'],
            'invoice' => $p['invoice'],
            'reference' => $p['reference'],
        ];
    }
}
