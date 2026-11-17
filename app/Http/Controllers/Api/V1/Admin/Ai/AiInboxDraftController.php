<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Domain\Ai\Inbox\DraftInboxReply;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Ai\DraftInboxReplyRequest;
use App\Http\Resources\Admin\Ai\AiInboxDraftResource;
use App\Models\Enums\Permission;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/** Inbox › Suggested reply (Figma 539:83211). Drafts only: a person edits and sends every message. */
final class AiInboxDraftController extends ApiController
{
    /**
     * `POST /inbox/conversations/{conversation}/ai-draft` — a suggested reply from the thread and
     * the customer's orders. Nothing is sent or stored. inbox.reply; order facts also need
     * customers.view, like the customer panel.
     */
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    #[OpenApiResponse(409, 'AI is not set up (`ai-not-configured`) or over its monthly cap (`ai-cap-reached`)')]
    #[OpenApiResponse(503, 'The provider failed, or the draft quoted figures we could not check (`ai-unavailable`)')]
    public function store(DraftInboxReplyRequest $request, Conversation $conversation, DraftInboxReply $draft): AiInboxDraftResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AiInboxDraftResource($draft->handle(
            $conversation,
            $request->style(),
            $user->checkPermissionTo(Permission::CustomersView->value),
            $user->id,
        ));
    }
}
