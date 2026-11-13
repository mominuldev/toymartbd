<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SetMessageConsent;
use App\Domain\Notification\Actions\UpdateAutomationSettings;
use App\Domain\Notification\Enums\MessageConsentPurpose;
use App\Domain\Notification\Models\MessageConsent;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Queries\AutomationStats;
use App\Domain\Notification\Services\AutomationSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Notification\UpdateAutomationSettingsRequest;
use App\Http\Requests\Admin\Notification\UpdateMessageConsentRequest;
use App\Http\Resources\Admin\AutomationSettingsResource;
use App\Http\Resources\Admin\MessageConsentResource;
use App\Models\Enums\Permission;

/**
 * Scheduled automations (P8): review requests and birthday reminders (before each child's
 * birthday, with an optional offer — P8 batch 3). Their on/off switches and copy are the
 * `review.request` / `customer.birthday` templates on Settings → Automations; this controller
 * holds the scheduling and offer values and the birthday consent.
 */
final class AutomationController extends ApiController
{
    /** `GET /notifications/automations` — settings.view. */
    public function show(AutomationSettings $settings, AutomationStats $stats): AutomationSettingsResource
    {
        $this->authorize(Permission::SettingsView->value);

        return new AutomationSettingsResource(['settings' => $settings->adminView(), 'stats' => $stats->lastDays()]);
    }

    /** `PUT /notifications/automations` — settings.update. */
    public function update(UpdateAutomationSettingsRequest $request, UpdateAutomationSettings $update, AutomationSettings $settings, AutomationStats $stats): AutomationSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->delayDays(), $request->quietFrom(), $request->quietTo(), is_int($userId) ? $userId : null, $request->leadDays(), $request->offer());

        return new AutomationSettingsResource(['settings' => $settings->adminView(), 'stats' => $stats->lastDays()]);
    }

    /** `GET /notifications/customers/{customer}/consents` — customers.view. */
    public function consents(Customer $customer): MessageConsentResource
    {
        $this->authorize('viewAny', NotificationPreference::class);

        return new MessageConsentResource(self::birthday($customer->id));
    }

    /** `PUT /notifications/customers/{customer}/consents` — customers.update; source `admin`; a "yes" needs how the customer agreed. */
    public function updateConsents(UpdateMessageConsentRequest $request, Customer $customer, SetMessageConsent $set): MessageConsentResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $set->handle($customer->id, MessageConsentPurpose::Birthday, $request->birthday(), 'admin', is_int($userId) ? $userId : null, $request->confirmationMethod(), $request->confirmationNote());

        return new MessageConsentResource(self::birthday($customer->id));
    }

    private static function birthday(int $customerId): ?MessageConsent
    {
        return MessageConsent::query()->where('customer_id', $customerId)->where('purpose', MessageConsentPurpose::Birthday->value)->first();
    }
}
