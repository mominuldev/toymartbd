<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\Models\Redirect;
use App\Http\Requests\ApiFormRequest;

/** `POST /seo/redirects/import` — a CSV `from_path,to_path,status_code[,note]`, ≤ 1 MB. */
final class ImportRedirectsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Redirect::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:1024', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']];
    }
}
