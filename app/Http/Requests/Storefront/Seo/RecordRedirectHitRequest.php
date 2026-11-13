<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Seo;

use App\Http\Requests\ApiFormRequest;

/** `POST /storefront/redirects/{id}/hit` — no body; the storefront server's token only. */
final class RecordRedirectHitRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
