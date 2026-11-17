<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Services;

use App\Domain\Ai\Advisor\Enums\ActionKind;
use App\Domain\Ai\Advisor\Enums\ActionPriority;
use App\Domain\Ai\Advisor\Enums\ActionTarget;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\Services\FigureGuard;

/**
 * The Growth advisor's prompts and strict answer schemas, and the figure check every answer
 * passes before anyone sees it (rule 1: {@see FigureGuard}).
 */
final class AdvisorPrompts
{
    /** Sections an Ask AI answer may cite; the admin labels them ("Sales · Tue"). */
    public const array SOURCES = ['sales', 'sales_by_day', 'channels', 'payment_methods', 'categories', 'products', 'stock', 'marketing', 'couriers', 'risk', 'catalog'];

    public const int MAX_SENTENCES = 5;

    public const int MAX_ACTIONS = 5;

    public static function system(string $locale): string
    {
        $language = $locale === 'bn'
            ? 'Write in Bangla (Bengali script), plain and friendly. Write every number with Western digits 0-9, never Bengali digits. Keep product names as given.'
            : 'Write in plain, simple English.';

        return <<<TXT
        You are the growth advisor for Toy Mart BD, a small online toy and educational-products shop in Bangladesh. Most orders are cash on delivery and go out by courier.
        You write for the shop owner, who is busy and not an analyst. Use plain words and short sentences. One idea per sentence.
        Use ONLY the figures in FACTS. Never invent, estimate or calculate a new number, percentage, ratio, date or count. If a figure is not in FACTS, describe it in words or leave it out. Money is in taka; write it like ৳2,48,600 (lakh grouping).
        "placed" figures are orders placed (demand). "recognised_revenue" is revenue on delivery. Never call placed value revenue.
        Think about the Bangladesh context: cash on delivery, courier delays, first orders from new customers, Bangla-speaking shoppers, festival seasons.
        You only suggest. Never say you have done, changed, sent, booked or ordered anything. The owner decides and acts.
        Never mention customers by name, phone number or address — you are not given them.
        {$language}
        Answer with JSON matching the schema exactly.
        TXT;
    }

    /** @param  array<string, mixed>  $facts */
    public static function brief(array $facts, string $locale, ?string $note = null): AiPrompt
    {
        $task = 'TASK: Write this week\'s brief: 3 to '.self::MAX_SENTENCES.' sentences on what changed and why it matters, then up to '.self::MAX_ACTIONS." suggested actions, most important first.\n"
            ."For each action: priority (high|medium|low), kind, a short title (an instruction, under 60 characters), why (one or two sentences with figures from FACTS), figures (1 to 3 short labels such as \"12 in stock\", each using figures from FACTS), and a target.\n"
            ."Targets: product (target_id = a product_id from FACTS), promotion (target_id = a promotion_id from FACTS), or with target_id null: restock_forecast, purchase_order_new, low_stock, verification_queue, failed_bookings, promotions, coupons, sales_report, none.\n"
            .'Kinds: restock (low stock that is selling), campaign (a promotion or coupon), verify_orders (orders waiting verification or many cancellations), bangla_copy (best sellers without a Bangla description), other.';

        return new AiPrompt(self::system($locale), self::userTurn($facts, $locale, $task, $note), self::briefSchema(), 'weekly_brief');
    }

    /** @param  array<string, mixed>  $facts */
    public static function ask(array $facts, string $locale, string $question, ?string $note = null): AiPrompt
    {
        $task = "TASK: Answer the owner's QUESTION in 1 to 4 short sentences using only FACTS. If FACTS cannot answer it, say so plainly and say which report would help. "
            .'List the FACTS sections you used as sources (section, and a short detail such as a weekday or a name, or an empty string).'
            ."\n\nQUESTION: ".$question;

        return new AiPrompt(self::system($locale), self::userTurn($facts, $locale, $task, $note), self::askSchema(), 'advisor_answer');
    }

    /**
     * Bengali digits become Western (rule 36), whitespace is collapsed and the text is clipped.
     */
    public static function clean(mixed $text, int $max): string
    {
        if (! is_string($text)) {
            return '';
        }
        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * Every figure in the given strings that is not in the facts.
     *
     * @param  list<string>  $texts
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public static function unknownFigures(array $texts, array $facts): array
    {
        $out = [];
        foreach ($texts as $text) {
            array_push($out, ...FigureGuard::unknown($text, $facts));
        }

        return array_values(array_unique($out));
    }

    /** @param  list<string>  $unknown */
    public static function retryNote(array $unknown): string
    {
        return 'Your previous answer used figures that are not in FACTS: '.implode(', ', $unknown).'. Write it again using only figures that appear in FACTS.';
    }

    /** @param  array<string, mixed>  $facts */
    private static function userTurn(array $facts, string $locale, string $task, ?string $note): string
    {
        return 'LANGUAGE: '.($locale === 'bn' ? 'Bangla' : 'English')."\n\nFACTS (JSON):\n"
            .json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\n".$task
            .($note === null ? '' : "\n\nNOTE: ".$note);
    }

    /** @return array<string, mixed> */
    private static function briefSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sentences', 'actions'],
            'properties' => [
                'sentences' => ['type' => 'array', 'items' => ['type' => 'string']],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['priority', 'kind', 'title', 'why', 'figures', 'target_type', 'target_id'],
                        'properties' => [
                            'priority' => ['type' => 'string', 'enum' => ActionPriority::values()],
                            'kind' => ['type' => 'string', 'enum' => ActionKind::values()],
                            'title' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'figures' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'target_type' => ['type' => 'string', 'enum' => ActionTarget::values()],
                            'target_id' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function askSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['answer_sentences', 'sources'],
            'properties' => [
                'answer_sentences' => ['type' => 'array', 'items' => ['type' => 'string']],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['section', 'detail'],
                        'properties' => [
                            'section' => ['type' => 'string', 'enum' => self::SOURCES],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
