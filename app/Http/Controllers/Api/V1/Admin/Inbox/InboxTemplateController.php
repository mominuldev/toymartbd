<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inbox;

use App\Domain\Inbox\Actions\SendTemplate;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Notification\Contracts\WhatsAppTemplateLibrary;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inbox\SendInboxTemplateRequest;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Inbox\InboxMessageResource;
use App\Http\Resources\Admin\Inbox\InboxTemplateResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Restarting a WhatsApp conversation whose 24-hour window has closed with an approved utility
 * template (Meta: outside the window only templates may be sent). Both need inbox.reply.
 * The template is recorded `queued` and sent by a queued job after commit (rule 4).
 */
final class InboxTemplateController extends ApiController
{
    /**
     * `GET /inbox/conversations/{conversation}/templates` — the approved utility WhatsApp
     * templates in the conversation's language (the customer's preferred language, Bangla when
     * unknown), with their positional parameters and the body to preview. Empty for a
     * Messenger or live-chat conversation. Never an OTP/authentication or marketing template.
     */
    #[OpenApiResponse(400, 'Any query parameter (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    public function index(Request $request, Conversation $conversation, WhatsAppTemplateLibrary $library): AnonymousResourceCollection
    {
        $this->authorize('reply', $conversation);
        ReportQuery::from($request, [], []);

        $templates = $conversation->channel === InboxChannel::WhatsApp ? $library->approved($conversation->locale()) : [];

        return InboxTemplateResource::collection($templates)->additional(['meta' => ['locale' => $conversation->locale()]]);
    }

    /** `POST /inbox/conversations/{conversation}/template` — send an approved template; 202, sent by a queued job. */
    #[OpenApiResponse(409, 'Not a WhatsApp conversation, or its 24-hour window is open (`conflict`) — nothing was queued')]
    public function store(SendInboxTemplateRequest $request, Conversation $conversation, SendTemplate $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = $action->handle(
            $conversation,
            $user,
            $request->string('template')->toString(),
            $request->string('language')->toString(),
            $request->parameters(),
        );

        return (new InboxMessageResource($message->load('sender:id,name')))->response()->setStatusCode(202);
    }
}
