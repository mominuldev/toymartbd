<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Account;

use App\Domain\Notification\Actions\SetNotificationPreference;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Notification\Services\NotificationPreferences;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Account\AccountReadRequest;
use App\Http\Requests\Storefront\Account\UpdateAccountNotificationPreferencesRequest;
use App\Http\Resources\Storefront\Account\AccountNotificationPreferencesResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * The signed-in customer's own order-update channels (P4-NOT-03 on the storefront): "Order updates
 * on WhatsApp" and "SMS updates" on the profile page. No customer id in the path — the token is the
 * customer, so nobody can read or change another customer's switches. Recorded with source
 * `storefront`; QueueOrderNotification honours them (WhatsApp off → SMS; both off → nothing sent).
 */
final class NotificationPreferenceController extends ApiController
{
    /** `GET /account/notification-preferences` — no stored choice reads as the default (on). */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    public function show(AccountReadRequest $request, NotificationPreferences $preferences, MessagingSettings $messaging): AccountNotificationPreferencesResource
    {
        return self::view($request->customer()->id, $preferences, $messaging);
    }

    /** `PUT /account/notification-preferences` — turn order updates on or off per channel; `security` is refused. */
    #[OpenApiResponse(401, 'No valid customer token (`unauthenticated`)')]
    #[OpenApiResponse(422, 'A field is invalid, or a security message was to be turned off (`validation-error`)')]
    public function update(UpdateAccountNotificationPreferencesRequest $request, SetNotificationPreference $set, NotificationPreferences $preferences, MessagingSettings $messaging): AccountNotificationPreferencesResource
    {
        $id = $request->customer()->id;
        foreach ($request->preferences() as $row) {
            $set->handle($id, $row['channel'], $row['category'], $row['enabled'], 'storefront');
        }

        return self::view($id, $preferences, $messaging);
    }

    private static function view(int $customerId, NotificationPreferences $preferences, MessagingSettings $messaging): AccountNotificationPreferencesResource
    {
        $rows = [];
        foreach ($preferences->matrix($customerId) as $cell) {
            if (in_array($cell['channel'], UpdateAccountNotificationPreferencesRequest::CHANNELS, true) && in_array($cell['category'], UpdateAccountNotificationPreferencesRequest::CATEGORIES, true)) {
                $rows[] = ['channel' => $cell['channel'], 'category' => $cell['category'], 'enabled' => $cell['enabled'], 'is_default' => $cell['is_default']];
            }
        }

        return new AccountNotificationPreferencesResource(['preferences' => $rows, 'whatsapp_available' => $messaging->whatsappEnabled()]);
    }
}
