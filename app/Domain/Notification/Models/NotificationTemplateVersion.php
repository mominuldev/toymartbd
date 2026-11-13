<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One saved copy of a template (P7 Message templates). Append-only: inserted by
 * {@see \App\Domain\Notification\Support\TemplateVersions}, never updated or deleted.
 *
 * @property int $id
 * @property int $notification_template_id
 * @property int $version
 * @property string|null $subject_en
 * @property string|null $subject_bn
 * @property string|null $preheader_en
 * @property string|null $preheader_bn
 * @property string $body_en
 * @property string $body_bn
 * @property int|null $restored_from
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
final class NotificationTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('notification_template_versions is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('notification_template_versions is append-only.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notification_template_id' => 'integer',
            'version' => 'integer',
            'restored_from' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
