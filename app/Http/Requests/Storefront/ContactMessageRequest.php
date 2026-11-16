<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Inbox\Enums\ContactTopic;
use App\Http\Requests\ApiFormRequest;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;

/**
 * `POST /storefront/contact-messages` — the Contact page's "Send us a message" form (owner
 * decision 2026-11-16). The phone is a Bangladeshi mobile, 013–019 only (rule 30), and is
 * stored as E.164. The order number is optional and as forgiving as order tracking's
 * ("26-00042", Bengali digits), stored in its canonical form.
 */
final class ContactMessageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            /** A Bangladeshi mobile, 013–019 (rule 30), e.g. "01712345678" or "+8801712345678". */
            'phone' => ['required', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                    $fail((string) __('checkout.phone.invalid'));
                }
            }],
            'topic' => ['required', 'string', Rule::enum(ContactTopic::class)],
            /** e.g. "TMB-26-00042" (also "26-00042"). */
            'order_number' => ['nullable', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && OrderTrackRequest::canonicalNumber($value) === null) {
                    $fail((string) __('checkout.track.number'));
                }
            }],
            'message' => ['required', 'string', 'min:10', 'max:500'],
            'locale' => ['sometimes', 'nullable', 'in:en,bn'],
        ];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }

    public function phoneE164(): string
    {
        return PhoneNumber::parse($this->string('phone')->toString())->e164();
    }

    public function topic(): ContactTopic
    {
        return ContactTopic::from($this->string('topic')->toString());
    }

    public function orderNumber(): ?string
    {
        $raw = trim($this->string('order_number')->toString());

        return $raw === '' ? null : OrderTrackRequest::canonicalNumber($raw);
    }

    public function message(): string
    {
        return trim($this->string('message')->toString());
    }
}
