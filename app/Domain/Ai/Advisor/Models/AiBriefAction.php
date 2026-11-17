<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Models;

use App\Domain\Ai\Advisor\Enums\ActionKind;
use App\Domain\Ai\Advisor\Enums\ActionPriority;
use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Enums\ActionTarget;
use App\Policies\AiBriefPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suggested action under a weekly brief (see the migration).
 *
 * @property int $id
 * @property int $ai_brief_id
 * @property int $position
 * @property ActionPriority $priority
 * @property ActionKind $kind
 * @property string $title
 * @property string $why
 * @property list<string> $figures
 * @property ActionTarget $target_type
 * @property int|null $target_id
 * @property ActionStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 */
#[UsePolicy(AiBriefPolicy::class)]
final class AiBriefAction extends Model
{
    protected $table = 'ai_brief_actions';

    /** @var list<string> */
    protected $fillable = ['ai_brief_id', 'position', 'priority', 'kind', 'title', 'why', 'figures', 'target_type', 'target_id', 'status', 'decided_by', 'decided_at'];

    /** @return BelongsTo<AiBrief, $this> */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(AiBrief::class, 'ai_brief_id');
    }

    /** The admin path the primary button opens — built here, never taken from the model. */
    public function path(): ?string
    {
        return $this->target_type->path($this->target_id);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'priority' => ActionPriority::class,
            'kind' => ActionKind::class,
            'figures' => 'array',
            'target_type' => ActionTarget::class,
            'target_id' => 'integer',
            'status' => ActionStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }
}
