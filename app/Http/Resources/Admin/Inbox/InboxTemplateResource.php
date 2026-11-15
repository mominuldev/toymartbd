<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Notification\DTOs\ApprovedWhatsAppTemplate;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One approved utility WhatsApp template the inbox may send to restart a closed conversation.
 * `template` + `language` are what `POST …/template` takes. `body` is the approved copy with
 * `{{1}}`, `{{2}}`… where the parameters go; the admin shows it as the preview and fills the
 * placeholders as staff type (a display substitution only — the server renders the stored text).
 *
 * @property ApprovedWhatsAppTemplate $resource
 */
#[SchemaName('InboxTemplate')]
final class InboxTemplateResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;

        return [
            /** The library key (`order.confirmed`, `custom.*`): the admin labels a system template by it. */
            'key' => $t->key,
            /** Meta's template name. */
            'template' => $t->providerTemplate,
            /** @var 'en'|'bn_BD' */
            'language' => $t->language,
            /** @var 'en'|'bn' */
            'locale' => $t->locale,
            /** @var string|null A staff-made template's own name; null for a system template. */
            'name' => $t->name,
            'body' => $t->body,
            /** @var list<array{position: int, name: string, example: string}> {{1}} first. `name` is the library variable. */
            'parameters' => array_map(static fn (int $i, string $name): array => [
                'position' => $i + 1,
                'name' => $name,
                'example' => $t->examples[$i] ?? '',
            ], array_keys($t->parameters), $t->parameters),
        ];
    }
}
