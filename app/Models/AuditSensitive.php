<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\InsertOnlyBuilder;
use App\Models\Enums\AuditAction;
use App\Models\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit of price, cost, refund, role and credential changes (PRD P-03).
 * Insert-only: updating, deleting and bulk writes throw. The production DB grant is
 * INSERT + SELECT only — see the create_audit_sensitive_table migration.
 *
 * @property int $id
 * @property int|null $user_id
 * @property AuditAction $action
 * @property string|null $model_type
 * @property int|null $model_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Table('audit_sensitive')]
#[Fillable(['user_id', 'action', 'model_type', 'model_id', 'before', 'after', 'ip', 'user_agent'])]
#[UseEloquentBuilder(InsertOnlyBuilder::class)]
class AuditSensitive extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (self $audit): never {
            throw ImmutableRecordException::for($audit->getTable(), 'update');
        });

        static::deleting(static function (self $audit): never {
            throw ImmutableRecordException::for($audit->getTable(), 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
