<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Models;

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Enums\BlockType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One block in a placement's DRAFT (cms_blocks). Written only by the Content block Actions.
 * The storefront never reads this table: it reads the published CmsLayoutVersion.
 *
 * @property int $id
 * @property string $uuid
 * @property BlockPlacement $placement
 * @property BlockType $type
 * @property string|null $label
 * @property array<string, mixed> $content
 * @property int $position
 * @property bool $is_enabled
 * @property bool $show_en
 * @property bool $show_bn
 * @property bool $show_mobile
 * @property bool $show_desktop
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class CmsBlock extends Model
{
    protected $table = 'cms_blocks';

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'placement', 'type', 'label', 'content', 'position', 'is_enabled',
        'show_en', 'show_bn', 'show_mobile', 'show_desktop', 'starts_at', 'ends_at', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'placement' => 'home',
        'is_enabled' => true,
        'show_en' => true,
        'show_bn' => true,
        'show_mobile' => true,
        'show_desktop' => true,
        'position' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => BlockPlacement::class,
            'type' => BlockType::class,
            'content' => 'array',
            'position' => 'integer',
            'is_enabled' => 'boolean',
            'show_en' => 'boolean',
            'show_bn' => 'boolean',
            'show_mobile' => 'boolean',
            'show_desktop' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function inPlacement(Builder $query, BlockPlacement $placement): void
    {
        $query->where('placement', $placement->value)->orderBy('position')->orderBy('id');
    }

    /**
     * The block as it is frozen into a version: everything the storefront needs, nothing about
     * who edited it. Instants are UTC ISO 8601.
     *
     * @return array{uuid: string, type: string, label: string|null, content: array<string, mixed>, is_enabled: bool, show_en: bool, show_bn: bool, show_mobile: bool, show_desktop: bool, starts_at: string|null, ends_at: string|null}
     */
    public function snapshot(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'label' => $this->label,
            'content' => $this->content,
            'is_enabled' => $this->is_enabled,
            'show_en' => $this->show_en,
            'show_bn' => $this->show_bn,
            'show_mobile' => $this->show_mobile,
            'show_desktop' => $this->show_desktop,
            'starts_at' => $this->starts_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->ends_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
