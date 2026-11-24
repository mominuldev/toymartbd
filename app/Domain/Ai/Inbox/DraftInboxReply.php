<?php

declare(strict_types=1);

namespace App\Domain\Ai\Inbox;

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Services\AiClient;
use App\Domain\Ai\Services\AiSettings;
use App\Domain\Ai\Services\FigureGuard;
use App\Domain\Inbox\Enums\ReplyState;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Services\ReplyWindow;

/**
 * Inbox › Suggested reply (Figma 539:83211). Drafts one reply for a person to edit and send —
 * nothing is sent or stored as a message here. No database transaction: this calls a provider
 * (rule 4), through {@see AiClient} only.
 *
 * The draft is checked by {@see FigureGuard} against the facts it was given: a draft that
 * states an order number, amount, date or time the server did not supply is asked for once
 * more, then refused (`invalid_output` → 503 ai-unavailable) rather than shown.
 */
final readonly class DraftInboxReply
{
    public const array STYLES = ['default', 'shorter', 'english', 'bangla'];

    public function __construct(private AiClient $ai, private AiSettings $settings, private ReplyWindow $window) {}

    public function handle(Conversation $conversation, string $style, bool $includeOrders, ?int $userId): InboxDraft
    {
        $conversation->loadMissing('customer');
        $context = InboxReplyContext::for($conversation, $this->settings->share()['first_names'], $includeOrders);
        $language = match ($style) {
            'english' => 'en',
            'bangla' => 'bn',
            default => $context->customerLanguage,
        };
        $facts = ['language' => $language, 'track_url' => InboxReplyContext::trackUrl($language)] + $context->facts;

        $state = $this->window->for($conversation)->state;
        $started = hrtime(true);
        $system = self::system($language, $style === 'shorter', $facts['customer_first_name'] !== null);
        $user = self::user($facts);

        $unknown = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $retryNote = $unknown === [] ? '' : "\n\nYour previous draft stated figures that are not in the facts (".implode(', ', $unknown).'). Write it again using only figures that appear in the facts, or none.';
            $result = $this->ai->run(AiFeature::InboxReply, new AiPrompt($system.$retryNote, $user, self::schema(), 'inbox_reply'), $userId);
            $text = is_string($result->json['reply'] ?? null) ? trim($result->json['reply']) : '';
            if ($text === '') {
                $unknown = ['(empty)'];

                continue;
            }
            $unknown = FigureGuard::unknown($text, $facts);
            if ($unknown === []) {
                return new InboxDraft(
                    text: $text,
                    language: $language,
                    basedOn: $context->basedOn,
                    model: $result->model,
                    seconds: round((hrtime(true) - $started) / 1_000_000_000, 1),
                    windowOpen: in_array($state, [ReplyState::Open, ReplyState::HumanAgent], true),
                    replyState: $state->value,
                );
            }
        }

        throw new AiException(AiException::INVALID_OUTPUT, 'The suggested reply quoted figures we could not check, so it was not shown. Try again or write the reply yourself.');
    }

    private static function system(string $language, bool $shorter, bool $hasName): string
    {
        $lang = $language === 'bn'
            ? 'Write in natural, everyday Bangla (Bengali script). Keep order numbers, courier names and the link in Latin letters. Use Western digits (0-9) only, never Bengali digits.'
            : 'Write in simple, friendly English. Use Western digits (0-9).';
        $length = $shorter ? 'At most 2 short sentences.' : 'At most 3 sentences.';
        $greeting = $hasName
            ? 'You may greet the customer by the first name given in the facts.'
            : 'You do not know the customer\'s name: use a polite generic greeting, never invent a name.';

        return <<<TXT
        You draft a reply for a staff member of Smart Junior, a kids' toy shop in Bangladesh, answering a customer on WhatsApp, Messenger or live chat. A person reads, edits and sends your draft; you never send anything.

        Tone: polite, warm Bangladeshi shop tone, like a helpful shopkeeper. {$lang} {$length} {$greeting}

        Rules:
        - Use only the facts given (orders, courier status, times, amounts, the thread). Never invent an order number, amount, date, time or courier status.
        - Never promise a delivery date or time unless the facts clearly support it. If the order is in transit, say it is on the way without a guaranteed date.
        - Never offer a discount, coupon, refund, return, replacement or compensation. If the customer asks for one, say a team member will check and get back to them.
        - Never ask for an OTP, PIN, password or payment details, and never share them.
        - When the customer asks where an order is, you may include the tracking page as plain text, exactly as given in `track_url` (they enter their order number and phone digits there).
        - If the facts do not answer the question, say you will check and reply soon.
        - No markdown, no emojis beyond one at most, no sign-off with a staff name.

        Return JSON: {"reply": "<the draft>"}.
        TXT;
    }

    /** @param  array<string, mixed>  $facts */
    private static function user(array $facts): string
    {
        return "Facts (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\nDraft the shop's next reply to the customer's latest message.";
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['reply' => ['type' => 'string', 'description' => 'The suggested reply text.']],
            'required' => ['reply'],
            'additionalProperties' => false,
        ];
    }
}
