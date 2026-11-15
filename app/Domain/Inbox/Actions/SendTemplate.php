<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\ReplyState;
use App\Domain\Inbox\Exceptions\TemplateNotAllowed;
use App\Domain\Inbox\Jobs\SendInboxMessage;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Domain\Inbox\Support\MessagePreview;
use App\Domain\Notification\Contracts\WhatsAppTemplateLibrary;
use App\Domain\Notification\DTOs\ApprovedWhatsAppTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restarts a WhatsApp conversation whose 24-hour window has closed with an approved utility
 * template (Meta: outside the window only templates may be sent). Only in reply state
 * `template_only`; only a template the library reports sendable ({@see WhatsAppTemplateLibrary}:
 * approved, active, utility — never OTP/authentication, never marketing).
 *
 * Stored like a reply: kind `template`, `body` = our rendering of the approved copy with the
 * parameters filled in (what the thread and the list preview show), `meta` = Meta's template
 * name, language code and the positional parameters the job sends. Recorded `queued` inside
 * the transaction and sent by {@see SendInboxMessage} after commit (rule 4); delivery
 * statuses then apply as for any reply.
 */
final readonly class SendTemplate
{
    /** Per-parameter cap. Meta's per-parameter limit was not found; its template body limit is 1024. */
    public const int MAX_PARAMETER = 1024;

    public function __construct(private ReplyWindow $window, private WhatsAppTemplateLibrary $library) {}

    /** @param  list<string>  $parameters */
    public function handle(Conversation $conversation, User $by, string $providerTemplate, string $language, array $parameters): ConversationMessage
    {
        $this->assertTemplateOnly($conversation);

        $template = $this->library->find($providerTemplate, $language);
        if (! $template instanceof ApprovedWhatsAppTemplate) {
            throw ValidationException::withMessages(['template' => __('inbox.errors.template_unavailable')]);
        }
        if (count($parameters) !== count($template->parameters)) {
            throw ValidationException::withMessages(['parameters' => __('inbox.errors.template_parameters', ['count' => count($template->parameters)])]);
        }

        $body = $template->render($parameters);

        $message = DB::transaction(function () use ($conversation, $by, $template, $parameters, $body): ConversationMessage {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertTemplateOnly($locked);

            $message = ConversationMessage::query()->create([
                'conversation_id' => $locked->id,
                'channel' => $locked->channel,
                'direction' => MessageDirection::Out,
                'kind' => MessageKind::Template,
                'body' => $body,
                'meta' => [
                    'template' => $template->providerTemplate,
                    'template_key' => $template->key,
                    'language' => $template->language,
                    'parameters' => $parameters,
                ],
                'sender_user_id' => $by->id,
                'delivery_status' => DeliveryStatus::Queued,
            ]);

            $locked->last_message_at = now();
            $locked->last_message_preview = MessagePreview::of($body);
            $locked->last_message_direction = MessageDirection::Out->value;
            $locked->needs_human = false;
            $locked->unread_count = 0;
            $locked->save();

            return $message;
        });

        SendInboxMessage::dispatch($message->id);

        return $message;
    }

    private function assertTemplateOnly(Conversation $conversation): void
    {
        if ($conversation->channel !== InboxChannel::WhatsApp) {
            throw TemplateNotAllowed::make(__('inbox.errors.template_whatsapp_only'));
        }
        if ($this->window->for($conversation)->state !== ReplyState::TemplateOnly) {
            throw TemplateNotAllowed::make(__('inbox.errors.template_window_open'));
        }
    }
}
