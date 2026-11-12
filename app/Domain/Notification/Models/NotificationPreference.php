<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A customer's explicit choice for one (channel, category). No row = the category's
 * default ({@see NotificationCategory::enabledByDefault()}).
 *
 * @property int $id
 * @property int $customer_id
 * @property NotificationChannel $channel
 * @property NotificationCategory $category
 * @property bool $enabled
 * @property string $source
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationPreference extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'enabled' => 'boolean',
            'updated_by' => 'integer',
        ];
    }
}
