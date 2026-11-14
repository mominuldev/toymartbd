<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inbox;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Inbox\Actions\MarkConversationRead;
use App\Domain\Inbox\Actions\SetConversationStatus;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Queries\ConversationList;
use App\Domain\Ordering\Models\Order;
use App\Domain\Reporting\Queries\Customers\CustomerLedgerValues;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Inbox\AssignConversationRequest;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Inbox\InboxConversationDetail;
use App\Http\Resources\Admin\Inbox\InboxConversationDetailResource;
use App\Http\Resources\Admin\Inbox\InboxConversationResource;
use App\Models\Enums\Permission;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Unified inbox (P10, Figma B · 22 241:43115). Reads need inbox.view; assign, resolve and
 * reopen need inbox.reply. Conversations are never deleted. Replies and notes:
 * {@see InboxMessageController}.
 */
final class InboxConversationController extends ApiController
{
    public const int PER_PAGE = 30;

    private const array FILTERS = ['view', 'channel', 'status', 'search'];

    /**
     * `GET /inbox/conversations` — newest activity first, cursor-paginated (`cursor`).
     * `meta.counts` holds the tab counts (`all`, `mine`, `needs_human`) under every filter
     * except `view`. Anything not listed is a 400 (rule 16). Phones are masked unless the
     * viewer holds customers.view_pii (rule 20).
     */
    #[QueryParameter('filter[view]', 'all|mine|needs_human (the tabs).', type: 'string', default: 'all')]
    #[QueryParameter('filter[channel]', 'whatsapp|messenger|live_chat', type: 'string')]
    #[QueryParameter('filter[status]', 'open|resolved (both when absent).', type: 'string')]
    #[QueryParameter('filter[search]', 'A name, 4+ phone digits, or an order number prefix.', type: 'string')]
    #[QueryParameter('cursor', 'The `meta.next_cursor` of the previous page.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing inbox.view (`forbidden`)')]
    public function index(Request $request, ConversationList $list): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Conversation::class);

        $query = ReportQuery::from($request, self::FILTERS, ['cursor']);
        $filters = [
            'view' => $query->filterIn('view', ConversationList::VIEWS) ?? 'all',
            'channel' => $query->filterIn('channel', InboxChannel::values()),
            'status' => $query->filterIn('status', ConversationStatus::values()),
            'search' => $query->search(),
        ];
        /** @var User $user */
        $user = $request->user();

        $page = $list->paginate($filters, $user->id, self::PER_PAGE)->withQueryString();

        return InboxConversationResource::collection($page)->additional(['meta' => [
            'counts' => $list->counts($filters, $user->id),
        ]]);
    }

    /**
     * `GET /inbox/conversations/{conversation}` — the thread header and the customer panel:
     * orders, OUR risk score labelled `ours` (rule 24), lifetime value and the last order —
     * all computed server-side. The panel is null without customers.view.
     */
    #[OpenApiResponse(403, 'Missing inbox.view (`forbidden`)')]
    public function show(Request $request, Conversation $conversation): InboxConversationDetailResource
    {
        $this->authorize('view', $conversation);

        return new InboxConversationDetailResource($this->detail($request, $conversation));
    }

    /** `POST /inbox/conversations/{conversation}/read` — clears the unread count; inbox.view. */
    #[OpenApiResponse(403, 'Missing inbox.view (`forbidden`)')]
    public function read(Request $request, Conversation $conversation, MarkConversationRead $action): InboxConversationDetailResource
    {
        $this->authorize('markRead', $conversation);

        return new InboxConversationDetailResource($this->detail($request, $action->handle($conversation)));
    }

    /** `POST /inbox/conversations/{conversation}/assign` — `{user_id}` or `{user_id: null}`; inbox.reply. */
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    public function assign(AssignConversationRequest $request, Conversation $conversation, AssignConversation $action): InboxConversationDetailResource
    {
        /** @var User $user */
        $user = $request->user();

        return new InboxConversationDetailResource($this->detail($request, $action->handle($conversation, $request->assignee(), $user)));
    }

    /** `POST /inbox/conversations/{conversation}/resolve` — inbox.reply. */
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    public function resolve(Request $request, Conversation $conversation, SetConversationStatus $action): InboxConversationDetailResource
    {
        return $this->setStatus($request, $conversation, $action, ConversationStatus::Resolved);
    }

    /** `POST /inbox/conversations/{conversation}/reopen` — inbox.reply. */
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    public function reopen(Request $request, Conversation $conversation, SetConversationStatus $action): InboxConversationDetailResource
    {
        return $this->setStatus($request, $conversation, $action, ConversationStatus::Open);
    }

    /**
     * `GET /inbox/assignees` — active staff who can reply (inbox.reply), for "Assign to…".
     *
     * @return array{data: list<array{id: int, name: string}>}
     */
    #[OpenApiResponse(403, 'Missing inbox.reply (`forbidden`)')]
    public function assignees(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->checkPermissionTo(Permission::InboxReply->value), 403);

        $rows = User::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(static fn (User $u): bool => $u->checkPermissionTo(Permission::InboxReply->value))
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();

        return ['data' => $rows];
    }

    private function setStatus(Request $request, Conversation $conversation, SetConversationStatus $action, ConversationStatus $status): InboxConversationDetailResource
    {
        $this->authorize('resolve', $conversation);
        /** @var User $user */
        $user = $request->user();

        return new InboxConversationDetailResource($this->detail($request, $action->handle($conversation, $status, $user)));
    }

    private function detail(Request $request, Conversation $conversation): InboxConversationDetail
    {
        $conversation->loadMissing(['customer', 'assignee:id,name']);
        $customer = $conversation->customer;
        $user = $request->user();
        if (! $customer instanceof Customer || ! $user instanceof User || ! $user->checkPermissionTo(Permission::CustomersView->value)) {
            return new InboxConversationDetail($conversation, null, null, null, null);
        }

        return new InboxConversationDetail(
            $conversation,
            $customer,
            CustomerLedgerValues::forCustomer($customer->id)['lifetime_value'],
            CustomerRiskProfile::forPhone($customer->phone),
            Order::query()->where('customer_id', $customer->id)->with('items')->orderByDesc('created_at')->orderByDesc('id')->first(),
        );
    }
}
