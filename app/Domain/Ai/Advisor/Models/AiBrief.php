<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Models;

use App\Policies\AiBriefPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Growth advisor's weekly brief (see the migration).
 *
 * @property int $id
 * @property CarbonImmutable $week_start
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $locale
 * @property string $provider
 * @property string $model
 * @property list<string> $sentences
 * @property int|null $generated_by
 * @property CarbonImmutable $generated_at
 */
#[UsePolicy(AiBriefPolicy::class)]
final class AiBrief extends Model
{
    protected $table = 'ai_briefs';

    /** @var list<string> */
    protected $fillable = ['week_start', 'period_from', 'period_to', 'locale', 'provider', 'model', 'sentences', 'generated_by', 'generated_at'];

    /** @return HasMany<AiBriefAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AiBriefAction::class, 'ai_brief_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'sentences' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
