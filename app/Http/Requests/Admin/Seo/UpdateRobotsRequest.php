<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\RobotsTxt;
use App\Http\Requests\ApiFormRequest;
use Closure;

/** `PUT /seo/robots` — the robots.txt text, validated directive by directive. seo.manage. */
final class UpdateRobotsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SeoMeta::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => [
                'required', 'string', 'max:'.RobotsTxt::MAX_BYTES,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    foreach (RobotsTxt::validate(is_string($value) ? $value : '') as $error) {
                        $fail($error);
                    }
                },
            ],
        ];
    }

    public function content(): string
    {
        return (string) $this->validated('content');
    }
}
