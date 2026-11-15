<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Casts;

use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `placement` column ⇄ LayoutKey (`home` | `page:{id}`).
 *
 * @implements CastsAttributes<LayoutKey, LayoutKey|string>
 */
final class LayoutKeyCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?LayoutKey
    {
        return is_string($value) ? LayoutKey::fromString($value) : null;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof LayoutKey) {
            return $value->value;
        }
        if (is_string($value)) {
            return LayoutKey::fromString($value)->value;
        }

        throw new InvalidArgumentException('A CMS placement is a LayoutKey.');
    }
}
