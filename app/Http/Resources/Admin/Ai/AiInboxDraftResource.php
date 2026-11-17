<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Ai;

use App\Domain\Ai\Inbox\InboxDraft;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Inbox › Suggested reply (Figma 539:83211). A draft for a person to edit and send — the AI
 * never sends, and never saw the customer's phone number, address or payment details.
 *
 * @property InboxDraft $resource
 */
#[SchemaName('AiInboxDraft')]
final class AiInboxDraftResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** The suggested reply. */
            'text' => $this->resource->text,
            /** @var 'bn'|'en' */
            'language' => $this->resource->language,
            /**
             * The facts the draft used, as display labels (e.g. `Order TMB-26-00126`, `Steadfast · In transit · 2 h ago`).
             *
             * @var list<string>
             */
            'based_on' => $this->resource->basedOn,
            /** The provider's model id. */
            'model' => $this->resource->model,
            /** How long the draft took, in seconds. */
            'seconds' => $this->resource->seconds,
            /** False when a free-form reply cannot be sent now (WhatsApp after 24 hours: an approved template is needed). */
            'window_open' => $this->resource->windowOpen,
            /** @var 'open'|'human_agent'|'template_only'|'closed' */
            'reply_state' => $this->resource->replyState,
        ];
    }
}
