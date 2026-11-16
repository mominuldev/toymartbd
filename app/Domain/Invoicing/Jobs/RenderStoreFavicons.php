<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Renders the storefront favicons (PNG 32/180/192/512) from the store icon's private master
 * after Store settings › Site & branding saves a new icon. Queued after commit on
 * `media.queue`, like GenerateMediaRenditions; no HTTP call, no transaction.
 *
 * Files go to a new random directory on the public media disk on every run — paths never change
 * in place, so a CDN may cache them forever (config/media.php). The paths are recorded under
 * `store.branding.icon_pngs` together with the media id they came from, and only if that media is
 * still the chosen icon (a later save wins). The storefront's `store-info` purge is chained after
 * this job by the controller.
 */
final class RenderStoreFavicons implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string DIRECTORY = 'branding/icons';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId)
    {
        $this->afterCommit();
        $queue = config('media.queue');
        $this->onQueue(is_string($queue) ? $queue : 'index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(ImagePipeline $pipeline): void
    {
        if (StoreBranding::id(Setting::get(StoreBranding::MEDIA_KEYS['icon_media_id'])) !== $this->mediaId) {
            return; // the icon changed again (or was removed) before this ran
        }
        $media = StoreBranding::readyImage($this->mediaId);
        if (! $media instanceof Media) {
            return;
        }

        $master = Storage::disk(MediaUrls::originalsDisk())->get($media->originalPath());
        if ($master === null) {
            throw new RuntimeException("Master missing for media {$media->id}.");
        }

        $disk = Storage::disk(MediaUrls::disk());
        $base = self::DIRECTORY.'/'.Str::lower((string) Str::ulid());
        $paths = [];
        foreach ($pipeline->squarePngs($master, StoreBranding::ICON_SIZES) as $size => $png) {
            $path = "{$base}/icon-{$size}.png";
            $disk->put($path, $png, ['visibility' => 'public']);
            $paths[(string) $size] = $path;
        }

        // Re-check: a save may have landed while rendering.
        if (StoreBranding::id(Setting::get(StoreBranding::MEDIA_KEYS['icon_media_id'])) !== $this->mediaId) {
            return;
        }
        Setting::put(StoreBranding::ICON_PNGS_KEY, ['media_id' => $media->id, 'paths' => $paths], SettingGroup::Store);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Store favicons could not be rendered.', ['media_id' => $this->mediaId, 'error' => $e?->getMessage()]);
    }
}
