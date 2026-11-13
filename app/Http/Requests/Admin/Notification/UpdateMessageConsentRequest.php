<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationPreference;
use App\Http\Requests\ApiFormRequest;

/**
 * `PUT /notifications/customers/{customer}/consents` — records the customer's birthday-message
 * consent as told to staff (P8); needs customers.update, like the channel preferences.
 * Recording a "yes" needs `customer_confirmed: true` — staff never opt a customer in on a guess.
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
            /** The customer agreed to a message on their birthday (reads their date of birth). */
            'birthday' => ['required', 'boolean'],
            /**
             * Required (and must be true) when recording consent: staff confirm the customer
             * agreed — e.g. on a phone call. Not needed to withdraw.
             */
            'customer_confirmed' => ['required_if:birthday,true', 'boolean', 'accepted_if:birthday,true'],
        ];
    }

    public function birthday(): bool
    {
        return $this->boolean('birthday');
    }
}
