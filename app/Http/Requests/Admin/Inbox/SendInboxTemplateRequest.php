<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inbox;

use App\Domain\Inbox\Actions\SendTemplate;
use App\Domain\Inbox\Models\Conversation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /inbox/conversations/{conversation}/template` — an approved WhatsApp template to a
 * customer whose 24-hour window has closed; needs inbox.reply. `template` is Meta's template
 * name as `GET …/templates` lists it, `language` its Meta code, `parameters` the positional
 * values ({{1}} first, exactly as many as the template has). 409 when the conversation is not
 * a WhatsApp thread with a closed window; 422 when the template is not approved/utility.
 */
final class SendInboxTemplateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation && ($this->user()?->can('reply', $conversation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'max:512'],
            /** @var 'en'|'bn_BD' */
            'language' => ['required', 'string', Rule::in(['en', 'bn_BD'])],
            'parameters' => ['present', 'array', 'max:20'],
            /** Meta refuses a body parameter with a newline or tab, or more than 4 spaces in a row. */
            'parameters.*' => ['required', 'string', 'max:'.SendTemplate::MAX_PARAMETER, 'not_regex:/[\r\n\t]|\s{5,}/'],
        ];
    }

    /** @return list<string> */
    public function parameters(): array
    {
        $values = $this->input('parameters', []);

        return array_values(array_map(static fn (mixed $v): string => trim(is_string($v) ? $v : ''), is_array($values) ? $values : []));
    }
}
