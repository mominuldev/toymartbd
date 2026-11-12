<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Contracts;

use App\Domain\Catalog\Import\Enums\RowOutcome;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Writes one import row's product document exactly as `POST /products` or
 * `PUT /products/{product}` would: the same FormRequest rules and abilities, then the
 * same Create/UpdateProduct actions. Implemented in the Http layer (it owns the
 * FormRequests), so the import never carries a second copy of the product rules.
 */
interface ProductRowWriter
{
    /**
     * @param  array<string, mixed>  $document  the API product document
     *
     * @throws ValidationException field (API path) → messages
     * @throws AuthorizationException
     */
    public function write(array $document, ?Product $existing, User $actor): RowOutcome;
}
