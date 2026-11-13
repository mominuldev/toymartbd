<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\DTOs\CustomTemplateData;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\TemplateKind;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\CustomTemplateVariables;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /notifications/templates` — a staff-made template (P7 Message templates) for
 * marketing or manual use; needs settings.update. `variables` is a subset of the generic
 * set ({@see CustomTemplateVariables::ALL}); the bodies may name only those. One row is
 * created per channel, sharing a generated `custom.*` key. Subjects are required when
 * email is one of the channels.
 */
final class StoreNotificationTemplateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', NotificationTemplate::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channels = $this->input('channels');
        $email = is_array($channels) && in_array(NotificationChannel::Email->value, $channels, true);

        return [
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::in(TemplateKind::customValues())],
            'channels' => ['required', 'array', 'min:1', 'max:3'],
            'channels.*' => ['required', 'string', 'distinct', Rule::in(NotificationChannel::values())],
            'variables' => ['present', 'array', 'max:'.count(CustomTemplateVariables::ALL)],
            'variables.*' => ['required', 'string', 'distinct', Rule::in(CustomTemplateVariables::ALL)],
            'body_en' => ['required', 'string', 'max:'.UpdateNotificationTemplateRequest::BODY_MAX],
            'body_bn' => ['required', 'string', 'max:'.UpdateNotificationTemplateRequest::BODY_MAX],
            'subject_en' => [$email ? 'required' : 'nullable', 'string', 'max:150'],
            'subject_bn' => [$email ? 'required' : 'nullable', 'string', 'max:150'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = $this->variables();
            foreach (['body_en', 'body_bn', 'subject_en', 'subject_bn'] as $field) {
                $value = $this->input($field);
                if (! is_string($value)) {
                    continue;
                }
                $unknown = TemplateRenderer::unknownPlaceholders($value, $allowed);
                if ($unknown !== []) {
                    $validator->errors()->add($field, 'Unknown variable(s): {'.implode('}, {', $unknown).'}. Allowed: {'.implode('}, {', $allowed).'}.');
                }
            }
        }];
    }

    public function toData(): CustomTemplateData
    {
        $channels = array_map(
            static fn (string $c): NotificationChannel => NotificationChannel::from($c),
            array_values(array_filter((array) $this->input('channels', []), 'is_string')),
        );

        return new CustomTemplateData(
            nameEn: $this->string('name_en')->toString(),
            nameBn: $this->string('name_bn')->toString(),
            kind: TemplateKind::from($this->string('kind')->toString()),
            channels: $channels,
            variables: $this->variables(),
            bodyEn: $this->string('body_en')->toString(),
            bodyBn: $this->string('body_bn')->toString(),
            subjectEn: $this->filled('subject_en') ? $this->string('subject_en')->toString() : null,
            subjectBn: $this->filled('subject_bn') ? $this->string('subject_bn')->toString() : null,
        );
    }

    /** @return list<string> */
    private function variables(): array
    {
        $variables = $this->input('variables', []);

        return is_array($variables) ? array_values(array_intersect(CustomTemplateVariables::ALL, $variables)) : [];
    }
}
