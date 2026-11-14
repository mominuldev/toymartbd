<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The inbox list (Figma B · 22 left column), newest activity first, cursor-paginated.
 *
 * Filters (all already allowlisted by the controller, rule 16):
 *   view    all | mine | needs_human   (the tabs)
 *   channel whatsapp | messenger | live_chat
 *   status  open | resolved            (no status = both)
 *   search  a name (contact or linked customer), 4+ phone digits, or an order number prefix
 *
 * {@see counts()} gives the tab counts under every filter except `view`.
 */
final readonly class ConversationList
{
    public const array VIEWS = ['all', 'mine', 'needs_human'];

    /**
     * @param  array{view: string, channel: ?string, status: ?string, search: ?string}  $filters
     * @return CursorPaginator<int, Conversation>
     */
    public function paginate(array $filters, int $userId, int $perPage): CursorPaginator
    {
        $query = $this->base($filters);
        $this->applyView($query, $filters['view'], $userId);

        return $query
            ->with(['customer:id,name,phone', 'assignee:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * @param  array{view: string, channel: ?string, status: ?string, search: ?string}  $filters
     * @return array{all: int, mine: int, needs_human: int}
     */
    public function counts(array $filters, int $userId): array
    {
        $counts = [];
        foreach (self::VIEWS as $view) {
            $query = $this->base($filters);
            $this->applyView($query, $view, $userId);
            $counts[$view] = $query->count();
        }

        /** @var array{all: int, mine: int, needs_human: int} $counts */
        return $counts;
    }

    /**
     * @param  array{view: string, channel: ?string, status: ?string, search: ?string}  $filters
     * @return Builder<Conversation>
     */
    private function base(array $filters): Builder
    {
        $query = Conversation::query();

        if ($filters['channel'] !== null) {
            $query->where('channel', $filters['channel']);
        }
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['search'] !== null) {
            $this->applySearch($query, $filters['search']);
        }

        return $query;
    }

    /** @param  Builder<Conversation>  $query */
    private function applyView(Builder $query, string $view, int $userId): void
    {
        match ($view) {
            'mine' => $query->where('assigned_to', $userId),
            'needs_human' => $query->where('needs_human', true)->where('status', ConversationStatus::Open->value),
            default => null,
        };
    }

    /** @param  Builder<Conversation>  $query */
    private function applySearch(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $search) ?? '';
        $phone = PhoneNumber::tryParse($search);

        $query->where(function (Builder $q) use ($like, $search, $digits, $phone): void {
            $q->where('contact_name', 'like', $like)
                ->orWhereIn('customer_id', Customer::query()->select('id')->where('name', 'like', $like));

            if ($phone !== null) {
                $q->orWhere('contact_phone', $phone->e164())
                    ->orWhereIn('customer_id', Customer::query()->select('id')->where('phone', $phone->e164()));
            } elseif (strlen($digits) >= 4) {
                $q->orWhere('contact_phone', 'like', '%'.$digits.'%')
                    ->orWhereIn('customer_id', Customer::query()->select('id')->where('phone', 'like', '%'.$digits.'%'));
            }

            $q->orWhereIn('customer_id', Order::query()->select('customer_id')->where('number', 'like', addcslashes(strtoupper($search), '%_\\').'%'));
        });
    }
}
