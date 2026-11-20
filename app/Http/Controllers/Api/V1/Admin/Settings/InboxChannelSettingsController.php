<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Domain\Inbox\Actions\ConnectMessengerPage;
use App\Domain\Inbox\Actions\DisconnectInboxChannel;
use App\Domain\Inbox\Actions\FinishMessengerLogin;
use App\Domain\Inbox\Actions\StartMessengerLogin;
use App\Domain\Inbox\Actions\TestInboxChannelConnection;
use App\Domain\Inbox\Actions\UpdateInboxChannelSettings;
use App\Domain\Inbox\Exceptions\MetaConnectionFailed;
use App\Domain\Inbox\Queries\InboxChannelCredentialsView;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Settings\ConnectMessengerPageRequest;
use App\Http\Requests\Admin\Settings\DisconnectInboxChannelRequest;
use App\Http\Requests\Admin\Settings\FinishMessengerLoginRequest;
use App\Http\Requests\Admin\Settings\StartMessengerLoginRequest;
use App\Http\Requests\Admin\Settings\TestInboxChannelConnectionRequest;
use App\Http\Requests\Admin\Settings\UpdateInboxChannelSettingsRequest;
use App\Http\Resources\Admin\InboxChannelSettingsResource;
use App\Http\Resources\Admin\InboxChannelTestResultResource;
use App\Http\Resources\Admin\MessengerLoginPagesResource;
use App\Http\Resources\Admin\MessengerLoginStartResource;
use App\Models\Enums\Permission;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Admin → Integrations → Messaging, inbox channels (WhatsApp Cloud API, Messenger): the Meta
 * credentials entered in the admin, encrypted in `settings`, env as the fallback. Both
 * endpoints need settings.manage_integrations — even reading, because the card is about
 * credentials. Secrets are never returned. Every channel stays UNVERIFIED.
 */
final class InboxChannelSettingsController extends ApiController
{
    /** `GET /settings/inbox-channels` — driver, ids, secrets as set/source/last-4 hint, webhook URLs, readiness. */
    public function show(InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);

        return new InboxChannelSettingsResource($view->get());
    }

    /** `PUT /settings/inbox-channels` — blank secret = keep; `clear_*` = remove. Audited (`credential_changed`, scope `inbox_{channel}`). */
    public function update(UpdateInboxChannelSettingsRequest $request, UpdateInboxChannelSettings $update, InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $update->handle($request->toData(), is_int($userId) ? $userId : null);

        return new InboxChannelSettingsResource($view->get());
    }

    /**
     * `POST /settings/inbox-channels/{channel}/test` — one read-only Graph API call with the saved
     * credentials (WhatsApp: the phone number's display number, verified name, quality rating;
     * Messenger: the Page name). Stored as the channel's `last_check` and audited. A failed check
     * is a 200 with `ok: false`. Never marks the channel verified. Rate-limited.
     */
    #[OpenApiResponse(429, 'Too many tests (`too-many-requests`)')]
    public function test(TestInboxChannelConnectionRequest $request, TestInboxChannelConnection $test): InboxChannelTestResultResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $channel = $request->channel();

        return new InboxChannelTestResultResource(['channel' => $channel->value] + $test->handle($channel, is_int($userId) ? $userId : null));
    }

    /**
     * `POST /settings/inbox-channels/messenger/login` — "Continue with Facebook", step 1: the
     * Facebook dialog URL (Facebook Login for Business on Toy Mart's own Meta app) with a one-time
     * state bound to this staff member. 409 `conflict` until the App ID and App secret are saved.
     */
    #[OpenApiResponse(409, 'The Meta app is not set up yet (`conflict`)')]
    #[OpenApiResponse(429, 'Too many attempts (`too-many-requests`)')]
    public function startLogin(StartMessengerLoginRequest $request, StartMessengerLogin $start): MessengerLoginStartResource
    {
        return new MessengerLoginStartResource($this->meta(fn () => $start->handle($this->staffId($request))));
    }

    /**
     * `POST /settings/inbox-channels/messenger/login/finish` — step 2: `{code, state}` from the
     * redirect. Exchanges the code for a long-lived sign-in and lists the Pages that were shared,
     * ids and names only. 409 when the state is unknown, expired, used or someone else's, or
     * Facebook refused the code; 503 when Facebook cannot be reached.
     */
    #[OpenApiResponse(409, 'Expired or foreign sign-in, or Facebook refused it (`conflict`)')]
    #[OpenApiResponse(503, 'Facebook could not be reached (`service-unavailable`)')]
    #[OpenApiResponse(429, 'Too many attempts (`too-many-requests`)')]
    public function finishLogin(FinishMessengerLoginRequest $request, FinishMessengerLogin $finish): MessengerLoginPagesResource
    {
        $code = $request->string('code')->toString();
        $state = $request->string('state')->toString();

        return new MessengerLoginPagesResource($this->meta(fn () => $finish->handle($code, $state, $this->staffId($request))));
    }

    /**
     * `POST /settings/inbox-channels/messenger/connect` — step 3: `{session, page_id}`. Subscribes
     * the Page to the app's webhook, saves it with its Page token (encrypted) and turns Messenger
     * replies on. Audited. Returns the channel settings. Never marks the channel verified.
     */
    #[OpenApiResponse(409, 'Expired sign-in, Page not shared, or Facebook refused the subscription (`conflict`)')]
    #[OpenApiResponse(503, 'Facebook could not be reached (`service-unavailable`)')]
    #[OpenApiResponse(429, 'Too many attempts (`too-many-requests`)')]
    public function connect(ConnectMessengerPageRequest $request, ConnectMessengerPage $connect, InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $session = $request->string('session')->toString();
        $pageId = $request->string('page_id')->toString();
        $this->meta(fn () => $connect->handle($session, $pageId, $this->staffId($request)));

        return new InboxChannelSettingsResource($view->get());
    }

    /**
     * `POST /settings/inbox-channels/{channel}/disconnect` — removes the saved Page / number and
     * its token and switches replies off; the webhook keys and the Meta App ID stay. Messenger
     * also asks Meta to stop sending the Page's chats (best effort). Audited. Conversations stay.
     */
    public function disconnect(DisconnectInboxChannelRequest $request, DisconnectInboxChannel $disconnect, InboxChannelCredentialsView $view): InboxChannelSettingsResource
    {
        $userId = $request->user()?->getAuthIdentifier();
        $disconnect->handle($request->channel(), is_int($userId) ? $userId : null);

        return new InboxChannelSettingsResource($view->get());
    }

    private function staffId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function meta(callable $call): mixed
    {
        try {
            return $call();
        } catch (MetaConnectionFailed $e) {
            throw $e->retryable ? new ServiceUnavailableHttpException(null, $e->getMessage(), $e) : new ConflictHttpException($e->getMessage(), $e);
        }
    }
}
