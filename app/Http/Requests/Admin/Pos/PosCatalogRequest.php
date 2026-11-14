<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

use App\Domain\Pos\Models\PosSession;
use App\Http\Requests\ApiFormRequest;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /pos/sessions/{session}/catalog` — the grid. Allowlisted filters only (rule 16):
 * `category_id`, `q`, `barcode`; anything else is a 400.
 */
final class PosCatalogRequest extends ApiFormRequest
{
    private const array ALLOWED = ['category_id', 'q', 'barcode'];

    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof PosSession && ($this->user()?->can('operate', $session) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_keys($this->query()), self::ALLOWED);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown filter: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::ALLOWED).'.');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A top-level category tab; includes its sub-categories. */
            'category_id' => ['sometimes', 'integer', 'min:1'],
            /** Name (EN/BN) or SKU. */
            'q' => ['sometimes', 'string', 'max:100'],
            /** Exact barcode, as a scanner types it. */
            'barcode' => ['sometimes', 'string', 'max:64'],
        ];
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? $this->integer('category_id') : null;
    }

    public function term(): ?string
    {
        return $this->filled('q') ? trim($this->string('q')->toString()) : null;
    }

    public function barcode(): ?string
    {
        return $this->filled('barcode') ? trim($this->string('barcode')->toString()) : null;
    }
}
