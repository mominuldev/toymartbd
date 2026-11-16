<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\CapitalHolder;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Add or change a capital holder (P10): name, Bangla name, optional staff account, active.
 * Audited — who may put money in or take it out is a money matter. Never deleted.
 */
final readonly class SaveCapitalHolder
{
    private const array FIELDS = ['name', 'name_bn', 'role', 'user_id', 'is_active'];

    /** @param array<string, mixed> $attributes */
    public function handle(?CapitalHolder $holder, array $attributes, ?int $userId): CapitalHolder
    {
        return DB::transaction(function () use ($holder, $attributes, $userId): CapitalHolder {
            $attributes = array_intersect_key($attributes, array_flip(self::FIELDS));

            if ($holder === null) {
                $saved = CapitalHolder::query()->create($attributes)->refresh();
                $before = null;
                $after = ['created' => true, ...self::plain($saved->only(self::FIELDS))];
            } else {
                $saved = CapitalHolder::query()->lockForUpdate()->findOrFail($holder->id);
                $saved->fill($attributes);
                $dirty = array_keys($saved->getDirty());
                $before = self::plain(array_intersect_key($saved->getOriginal(), array_flip($dirty)));
                $saved->save();
                $after = self::plain($saved->only($dirty));
                if ($dirty === []) {
                    return $saved->load('user');
                }
            }

            AuditSensitive::query()->create([
                'user_id' => $userId,
                'action' => AuditAction::CapitalHolderSaved,
                'model_type' => $saved->getMorphClass(),
                'model_id' => $saved->id,
                'before' => $before,
                'after' => $after,
            ]);

            return $saved->load('user');
        });
    }

    /**
     * Enum values as their stored strings, for the audit payload.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(static fn (mixed $v): mixed => $v instanceof BackedEnum ? $v->value : $v, $values);
    }
}
