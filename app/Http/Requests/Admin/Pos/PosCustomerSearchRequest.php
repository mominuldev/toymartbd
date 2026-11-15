<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Requests\ApiFormRequest;

/**
 * `GET /pos/customers/search?q=` — a name fragment or phone digits. With no `q`, the most
 * recent customers (the list the F9 dialog opens on). Only customers who have bought at
 * the POS are listed. Needs pos.use.
 */
final class PosCustomerSearchRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PosSession::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A name fragment (`rahim`) or phone digits (`01819`, `552310`); at least 2 characters. Omit for the most recent customers. */
            'q' => ['nullable', 'string', 'min:2', 'max:64'],
        ];
    }

    public function term(): string
    {
        return $this->string('q')->trim()->toString();
    }
}
