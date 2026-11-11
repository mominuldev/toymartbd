<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Models\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent builder for append-only tables: bulk update/delete through the model's query
 * builder throws, closing the hole that model events alone leave open
 * (`Model::query()->update()` fires no `updating` event).
 *
 * This is a guard against mistakes, not a security boundary — the database grant is.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class InsertOnlyBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw $this->immutable('update');
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw $this->immutable('upsert');
    }

    /**
     * @param  string|null  $column
     */
    public function touch($column = null): int
    {
        throw $this->immutable('touch');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw $this->immutable('increment');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw $this->immutable('decrement');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        throw $this->immutable('incrementEach');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        throw $this->immutable('decrementEach');
    }

    public function delete(): mixed
    {
        throw $this->immutable('delete');
    }

    public function forceDelete(): mixed
    {
        throw $this->immutable('forceDelete');
    }

    private function immutable(string $operation): ImmutableRecordException
    {
        return ImmutableRecordException::for($this->getModel()->getTable(), $operation);
    }
}
