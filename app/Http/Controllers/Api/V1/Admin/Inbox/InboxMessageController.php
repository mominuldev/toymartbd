<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inbox;

use App\Domain\Inbox\Actions\AddInternalNote;
use App\Domain\Inbox\Actions\SendQuickReply;
use App\Domain\Inbox\Actions\SendReply;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inbox\SendInboxReplyRequest;
use App\Http\Requests\Admin\Inbox\SendQuickReplyRequest;
use App\Http\Requests\Admin\Inbox\StoreInboxNoteRequest;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Inbox\InboxMessageResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A thread's messages (Figma B · 22 centre column). Replies are recorded `queued` and sent
 * by a queued job after commit (rule 4); notes never leave the system.
 */
final class InboxMessageController extends ApiController
{
    public const int PER_PAGE = 50;

    /**
     * `GET /inbox/conversations/{conversation}/messages` — NEWEST first, 50 a page; follow
     * `meta.next_cursor` for older messages. inbox.view.
     */
    #[QueryParameter('cursor', 'The `meta.next_cursor` of the previous page (older messages).', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing inbox.view (`forbidden`)')]
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);
        ReportQuery::from($request, [], ['cursor']);

        $page = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return InboxMessageResource::collection($page);
    }

    /** `POST /inbox/conversations/{conversation}/messages` — a reply; 202, sent by a queued job. */
    #[OpenApiResponse(409, 'The 24-hour customer-service window has closed (`conflict`) — nothing was queued')]
    public function store(SendInboxReplyRequest $request, Conversation $conversation, SendReply $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = $action->handle($conversation, $user, $request->body());

        return (new InboxMessageResource($message->load('sender:id,name')))->response()->setStatusCode(202);
    }

    /** `POST /inbox/conversations/{conversation}/notes` — an internal note; never sent. 201. */
    public function note(StoreInboxNoteRequest $request, Conversation $conversation, AddInternalNote $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = $action->handle($conversation, $user, $request->body());

        return (new InboxMessageResource($message->load('sender:id,name')))->response()->setStatusCode(201);
    }

    /** `POST /inbox/conversations/{conversation}/quick-replies` — product card or tracking link; 202. */
    #[OpenApiResponse(409, 'Outside the 24-hour window, or the product/order cannot be shared here (`conflict`)')]
    public function quick(SendQuickReplyRequest $request, Conversation $conversation, SendQuickReply $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = $action->handle($conversation, $user, $request->string('kind')->toString(), $request->integer('target_id'));

        return (new InboxMessageResource($message->load('sender:id,name')))->response()->setStatusCode(202);
    }
}
