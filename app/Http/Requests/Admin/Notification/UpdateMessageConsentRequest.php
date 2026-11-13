<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Enums\ConsentConfirmationMethod;
use App\Domain\Notification\Models\NotificationPreference;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /notifications/customers/{customer}/consents` — records the customer's consent to birthday
 * reminders with offers (before each child's birthday, P8 batch 3) as told to staff; needs
 * customers.update, like the channel preferences. Recording a "yes" needs `customer_confirmed:
 * true` AND how the customer agreed (`confirmation_method`, owner decision 2026-11-13) — staff
 * never opt a customer in on a guess.
 */
final class UpdateMessageConsentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', NotificationPreference::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The customer agreed to birthday reminders with a gift offer, before each child's birthday. */
            'birthday' => ['required', 'boolean'],
            /**
             * Required (and must be true) when recording consent: staff confirm the customer
             * agreed — e.g. on a phone call. Not needed to withdraw.
             */
            'customer_confirmed' => ['required_if:birthday,true', 'boolean', 'accepted_if:birthday,true'],
            /**
             * Required when recording consent: how the customer agreed. Ignored on a withdrawal.
             *
             * @var 'phone_call'|'in_person'|'written'|'other'|null
             */
            'confirmation_method' => ['required_if:birthday,true', 'nullable', Rule::enum(ConsentConfirmationMethod::class)],
            /** Optional short note, e.g. "agreed on the call about order TMB-26-00118". No phone numbers. */
            'confirmation_note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function confirmationMethod(): ?ConsentConfirmationMethod
    {
        return $this->birthday() ? $this->enum('confirmation_method', ConsentConfirmationMethod::class) : null;
    }

    public function confirmationNote(): ?string
    {
        return $this->birthday() && $this->filled('confirmation_note') ? $this->string('confirmation_note')->trim()->toString() : null;
    }

    public function birthday(): bool
    {
        return $this->boolean('birthday');
    }
}
