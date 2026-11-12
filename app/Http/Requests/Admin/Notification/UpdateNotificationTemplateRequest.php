<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\DTOs\TemplateData;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

/**
 * `PUT /notifications/templates/{template}` — both bodies (EN + BN) in one document;
 * needs settings.update. A `{variable}` outside the template's allowlist is a 422 on that
 * field. Subject and preheader are required for email and ignored otherwise.
 */
final class UpdateNotificationTemplateRequest extends ApiFormRequest
{
    public const int BODY_MAX = 2000;

    public function authorize(): bool
    {
        $template = $this->template();

        return $template !== null && ($this->user()?->can('update', $template) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $email = $this->template()?->channel === NotificationChannel::Email;

        return [
            'body_en' => ['required', 'string', 'max:'.self::BODY_MAX],
            'body_bn' => ['required', 'string', 'max:'.self::BODY_MAX],
            'is_active' => ['required', 'boolean'],
            'subject_en' => [$email ? 'required' : 'nullable', 'string', 'max:150'],
            'subject_bn' => [$email ? 'required' : 'nullable', 'string', 'max:150'],
            'preheader_en' => ['nullable', 'string', 'max:200'],
            'preheader_bn' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $template = $this->template();
            if ($template === null) {
                return;
            }

            $allowed = $template->allowedVariables();
            foreach (['body_en', 'body_bn', 'subject_en', 'subject_bn', 'preheader_en', 'preheader_bn'] as $field) {
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

    public function toData(): TemplateData
    {
        return new TemplateData(
            bodyEn: $this->string('body_en')->toString(),
            bodyBn: $this->string('body_bn')->toString(),
            isActive: $this->boolean('is_active'),
            subjectEn: $this->filled('subject_en') ? $this->string('subject_en')->toString() : null,
            subjectBn: $this->filled('subject_bn') ? $this->string('subject_bn')->toString() : null,
            preheaderEn: $this->filled('preheader_en') ? $this->string('preheader_en')->toString() : null,
            preheaderBn: $this->filled('preheader_bn') ? $this->string('preheader_bn')->toString() : null,
        );
    }

    private function template(): ?NotificationTemplate
    {
        $template = $this->route('template');

        return $template instanceof NotificationTemplate ? $template : null;
    }
}
