<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\DTOs\TenderInput;
use App\Domain\Pos\Enums\CounterTender;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Money a customer brings against their due (quote and collect share it): whose due — by the
 * phone (rule 30) — and the tenders. Money tenders only: due cannot pay off due. Works one's
 * own open drawer (PosSessionPolicy::operate, `pos.use`).
 */
abstract class PosDueCollectionRequest extends ApiFormRequest
{
    protected const string MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof PosSession && ($this->user()?->can('operate', $session) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The customer's mobile, as typed (013–019, rule 30). */
            'customer_phone' => ['required', 'string', 'max:32'],
            'tenders' => ['present', 'array', 'max:8'],
            'tenders.*.method' => ['required', 'string', Rule::in(TenderRegistry::moneyMethods())],
            /** What the customer handed over in this tender; cash may exceed the due (change). */
            'tenders.*.amount' => ['required', 'string', 'regex:'.self::MONEY],
            /** bKash / Nagad TrxID or the card slip's approval code. Required for every non-cash tender. */
            'tenders.*.reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('customer_phone') && ! PhoneNumber::isValid($this->string('customer_phone')->toString())) {
                $validator->errors()->add('customer_phone', (string) __('checkout.phone.invalid'));
            }
        }];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::parse($this->string('customer_phone')->toString());
    }

    /** @return list<TenderInput> */
    public function tenders(): array
    {
        $raw = $this->input('tenders');
        $tenders = [];
        foreach (is_array($raw) ? $raw : [] as $tender) {
            if (! is_array($tender) || ! is_string($tender['method'] ?? null) || ! is_string($tender['amount'] ?? null)) {
                continue;
            }
            $reference = $tender['reference'] ?? null;
            $tenders[] = new TenderInput(CounterTender::from($tender['method']), Money::of($tender['amount']), is_string($reference) ? $reference : null);
        }

        return $tenders;
    }

    public function drawer(): PosSession
    {
        /** @var PosSession */
        return $this->route('session');
    }
}
