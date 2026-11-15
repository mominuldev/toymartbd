<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\DTOs\TenderInput;
use App\Domain\Pos\Enums\CounterTender;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Tenders\TenderRegistry;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The register cart a quote, a charge and a hold share: lines, coupon, customer phone and
 * (quote / charge) tenders. Works one's own open drawer — PosSessionPolicy::operate.
 */
abstract class PosCartRequest extends ApiFormRequest
{
    protected const string MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof PosSession && ($this->user()?->can('operate', $session) ?? false);
    }

    /** @return array<string, mixed> */
    protected function cartRules(bool $requireLines): array
    {
        return [
            'lines' => [$requireLines ? 'required' : 'present', 'array', $requireLines ? 'min:1' : 'min:0', 'max:100'],
            'lines.*.variant_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            /** A storefront coupon; applied by the same rules as checkout. Needs `customer_phone`. */
            'coupon_code' => ['nullable', 'string', 'max:64'],
            /** Bangladeshi mobile (013–019, rule 30). Optional — an anonymous walk-in has none. */
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** @return array<string, mixed> */
    protected function tenderRules(bool $required): array
    {
        return [
            'tenders' => [$required ? 'present' : 'nullable', 'array', 'max:8'],
            /** `due` puts that amount on the customer's due (needs `pos.sell_on_due`, a phone and a name). */
            'tenders.*.method' => ['required', 'string', Rule::in(TenderRegistry::methods())],
            /** What the customer handed over in this tender, e.g. `4000.00` cash for a ৳3,455 total. */
            'tenders.*.amount' => ['required', 'string', 'regex:'.self::MONEY],
            /** bKash / Nagad TrxID or the card slip's approval code. Required for every non-cash tender. */
            'tenders.*.reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $phone = $this->input('customer_phone');
            if (is_string($phone) && trim($phone) !== '' && ! PhoneNumber::isValid($phone)) {
                $validator->errors()->add('customer_phone', (string) __('checkout.phone.invalid'));
            }
        }];
    }

    /** @return array<int, int> variant id → quantity */
    public function lines(): array
    {
        $lines = [];
        $raw = $this->input('lines');
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (is_array($line) && is_numeric($line['variant_id'] ?? null) && is_numeric($line['quantity'] ?? null)) {
                $lines[(int) $line['variant_id']] = (int) $line['quantity'];
            }
        }
        ksort($lines);

        return $lines;
    }

    public function phone(): ?PhoneNumber
    {
        $phone = $this->input('customer_phone');

        return is_string($phone) && trim($phone) !== '' ? PhoneNumber::tryParse($phone) : null;
    }

    public function customerName(): ?string
    {
        $name = $this->input('customer_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** The signed-in cashier may put a sale on due (`pos.sell_on_due`). */
    public function canSellOnDue(): bool
    {
        return $this->user()?->can(Permission::PosSellOnDue->value) ?? false;
    }

    public function couponCode(): ?string
    {
        $code = $this->input('coupon_code');

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /** @return list<TenderInput>|null */
    public function tenders(): ?array
    {
        $raw = $this->input('tenders');
        if (! is_array($raw)) {
            return null;
        }
        $tenders = [];
        foreach ($raw as $tender) {
            if (! is_array($tender) || ! is_string($tender['method'] ?? null) || ! is_string($tender['amount'] ?? null)) {
                continue;
            }
            $reference = $tender['reference'] ?? null;
            $tenders[] = new TenderInput(
                CounterTender::from($tender['method']),
                Money::of($tender['amount']),
                is_string($reference) ? $reference : null,
            );
        }

        return $tenders;
    }

    public function drawer(): PosSession
    {
        /** @var PosSession */
        return $this->route('session');
    }
}
