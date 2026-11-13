<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\MenuLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One menu entry. Written only by {@see \App\Domain\Content\Actions\SaveMenu}, which replaces
 * the whole tree.
 *
 * @property int $id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property int $position
 * @property string $label
 * @property string|null $label_bn
 * @property MenuLinkType $link_type
 * @property int|null $link_id
 * @property string|null $url
 * @property bool $is_visible
 * @property bool $open_in_new_tab
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MenuItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'menu_id', 'parent_id', 'position', 'label', 'label_bn', 'link_type', 'link_id', 'url', 'is_visible', 'open_in_new_tab',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'parent_id' => 'integer',
            'position' => 'integer',
            'link_type' => MenuLinkType::class,
            'link_id' => 'integer',
            'is_visible' => 'boolean',
            'open_in_new_tab' => 'boolean',
        ];
    }
}
