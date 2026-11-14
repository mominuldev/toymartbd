<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Support\Money;

/** The pieces of a thread header and its customer panel, computed by the controller. */
final readonly class InboxConversationDetail
{
    public function __construct(
        public Conversation $conversation,
        public ?Customer $customer,
        public ?Money $lifetimeValue,
        public ?CustomerRiskProfile $risk,
        public ?Order $lastOrder,
    ) {}
}
