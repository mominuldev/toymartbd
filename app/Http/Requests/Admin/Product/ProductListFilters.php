<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The admin product list's filter and sort allowlist (rule 16), shared by
 * `GET /products` and `GET /products/export` so an export is exactly the list the
 * owner is looking at. Anything unlisted is a 400.
 */
final class ProductListFilters
{
    /** @return QueryBuilder<Product> */
    public static function query(Request $request): QueryBuilder
    {
        $sort = $request->query('sort');
        $descending = ! is_string($sort) || str_starts_with($sort, '-');

        $query = QueryBuilder::for(Product::class, $request)
            ->allowedFilters(...self::filters())
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at');

        // A unique tiebreaker keeps cursor pagination stable.
        $query->getEloquentBuilder()->orderBy('products.id', $descending ? 'desc' : 'asc');

        return $query;
    }

    /** @return list<AllowedFilter> */
    public static function filters(): array
    {
        return [
            AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                $term = is_array($value) ? implode(',', array_map(strval(...), $value)) : (string) $value;
                $like = '%'.addcslashes($term, '\\%_').'%';

                $query->where(static function (Builder $q) use ($like): void {
                    $q->where('name', 'like', $like)
                        ->orWhere('name_bn', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhereHas('variants', static fn (Builder $v) => $v->where('sku', 'like', $like));
                });
            }),
            AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                $status = is_string($value) ? ProductStatus::tryFrom($value) : null;

                if ($status === null) {
                    throw new BadRequestHttpException('filter[status] must be one of: draft, active, archived.');
                }

                $query->where('status', $status->value);
            }),
            AllowedFilter::callback('type', static function (Builder $query, mixed $value): void {
                $type = is_string($value) ? ProductType::tryFrom($value) : null;

                if ($type === null) {
                    throw new BadRequestHttpException('filter[type] must be one of: simple, variable.');
                }

                $query->where('type', $type->value);
            }),
            AllowedFilter::callback('brand_id', static function (Builder $query, mixed $value): void {
                $query->where('brand_id', self::id('brand_id', $value));
            }),
            AllowedFilter::callback('category_id', static function (Builder $query, mixed $value): void {
                $id = self::id('category_id', $value);
                $query->whereHas('categories', static fn (Builder $c) => $c->where('categories.id', $id));
            }),
        ];
    }

    private static function id(string $filter, mixed $value): int
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            throw new BadRequestHttpException("filter[{$filter}] must be a positive integer id.");
        }

        return (int) $value;
    }
}
