<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SetNotificationPreference;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Notification\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Admin\NotificationPreferenceResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A customer's notification preferences (P4-NOT-03), set by staff on the customer's
 * behalf. The full channel × category matrix is returned with defaults filled in;
 * `security` (OTP) is always on and cannot be changed.
 */
final class NotificationPreferenceController extends ApiController
{
    /** `GET /notifications/customers/{customer}/preferences` — needs customers.view. */
    public function show(Customer $customer, NotificationPreferences $preferences): AnonymousResourceCollection
    {
        $this->authorize('viewAny', NotificationPreference::class);

        return NotificationPreferenceResource::collection($preferences->matrix($customer->id));
    }

    /** `PUT /notifications/customers/{customer}/preferences` — needs customers.update. */
    public function update(UpdateNotificationPreferencesRequest $request, Customer $customer, SetNotificationPreference $set, NotificationPreferences $preferences): AnonymousResourceCollection
    {
        $userId = $request->user()?->getAuthIdentifier();
        foreach ($request->preferences() as $row) {
            $set->handle($customer->id, $row['channel'], $row['category'], $row['enabled'], 'admin', is_int($userId) ? $userId : null);
        }

        return NotificationPreferenceResource::collection($preferences->matrix($customer->id));
    }
}
