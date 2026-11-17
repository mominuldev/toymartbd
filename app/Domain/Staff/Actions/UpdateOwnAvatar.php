<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Exceptions\InvalidImage;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * My account → profile photo. The upload is never stored as sent: ImagePipeline checks the
 * real type, applies orientation and strips metadata (a phone photo carries GPS), then a
 * 256 px centre-cropped square is written to the public media disk under a fresh name, so a
 * cached old photo never shows. The previous file is deleted once the row points at the new one.
 */
final readonly class UpdateOwnAvatar
{
    public const int SIZE = 256;

    public function __construct(private ImagePipeline $pipeline) {}

    public function handle(User $user, string $uploadedPath): User
    {
        try {
            $master = $this->pipeline->master($uploadedPath);
            $square = $this->pipeline->squarePngs($master['bytes'], [self::SIZE])[self::SIZE];
        } catch (InvalidImage $e) {
            throw ValidationException::withMessages(['avatar' => $e->getMessage()]);
        }

        return $this->store($user, $square);
    }

    /**
     * A photo picked from the media library. Its master is already sanitised (the library
     * re-encodes every upload), so only the square is cut — from a copy: the library file is
     * shared and stays where it is.
     */
    public function fromMedia(User $user, Media $media): User
    {
        if (! $media->isImage() || $media->processing_status !== ImageProcessingStatus::Ready) {
            throw ValidationException::withMessages(['media_id' => __('staff.avatar_media_not_ready')]);
        }

        $bytes = Storage::disk(MediaUrls::originalsDisk())->get($media->originalPath());
        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['media_id' => __('staff.avatar_media_not_ready')]);
        }

        try {
            $square = $this->pipeline->squarePngs($bytes, [self::SIZE])[self::SIZE];
        } catch (Throwable) {
            throw ValidationException::withMessages(['media_id' => __('staff.avatar_media_not_ready')]);
        }

        return $this->store($user, $square);
    }

    private function store(User $user, string $square): User
    {
        $disk = Storage::disk(MediaUrls::disk());
        $path = "avatars/{$user->getKey()}/".Str::lower((string) Str::ulid()).'.png';
        $disk->put($path, $square);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if (is_string($previous) && $previous !== '') {
            $disk->delete($previous);
        }

        return $user->refresh();
    }

    /** Back to initials. */
    public function remove(User $user): User
    {
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null])->save();

        if (is_string($previous) && $previous !== '') {
            Storage::disk(MediaUrls::disk())->delete($previous);
        }

        return $user->refresh();
    }
}
