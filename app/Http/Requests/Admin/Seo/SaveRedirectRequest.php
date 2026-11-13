<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\DTOs\RedirectData;
use App\Domain\Seo\Models\Redirect;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /seo/redirects` and `PATCH /seo/redirects/{redirect}`. Shape only; the
 * conflict, loop and chain rules live in App\Domain\Seo\Actions\SaveRedirect.
 */
final class SaveRedirectRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $redirect = $this->route('redirect');

        return $redirect instanceof Redirect
            ? ($this->user()?->can('update', $redirect) ?? false)
            : ($this->user()?->can('create', Redirect::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'from_path' => [$required, 'string', 'max:512'],
            'to_path' => [$required, 'string', 'max:2048'],
            'status_code' => ['sometimes', 'integer', 'in:301,302'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function toData(?Redirect $existing = null): RedirectData
    {
        $note = $this->validated('note', $existing?->note);

        return new RedirectData(
            fromPath: (string) $this->validated('from_path', $existing->from_path ?? ''),
            toPath: (string) $this->validated('to_path', $existing->to_path ?? ''),
            statusCode: (int) $this->validated('status_code', $existing->status_code ?? 301),
            isActive: (bool) $this->validated('is_active', $existing->is_active ?? true),
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}
