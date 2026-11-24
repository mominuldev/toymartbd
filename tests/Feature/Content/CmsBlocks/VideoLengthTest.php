<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use App\Domain\Content\Blocks\Models\VideoLength;
use App\Domain\Content\Blocks\Services\VideoLengthProbe;
use App\Domain\Content\Blocks\Services\VideoLengths;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Jobs\FetchVideoLength;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Home video reel lengths: read from YouTube's Data API or the hosted file's header after the
 * block is saved, shown on the storefront unless the editor typed one.
 */

const REEL_YT_URL = 'https://www.youtube.com/shorts/Abc_def-123';
const REEL_MP4_URL = 'https://cdn.example.com/reels/tower.mp4';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'services.youtube.key' => 'yt-key']);
    // Offline DNS: cdn.example.com is public, intranet.example resolves to a private address.
    app()->instance(VideoLengthProbe::class, new VideoLengthProbe(static fn (string $host): array => match ($host) {
        'cdn.example.com' => ['93.184.216.34'],
        'intranet.example' => ['10.0.0.5'],
        default => [],
    }));
});

/** An REEL_MP4_URL movie header box: version 0 (32-bit) or 1 (64-bit) times. */
function mvhd(int $timescale, int $duration, int $version = 0): string
{
    $body = $version === 1
        ? chr(1)."\0\0\0".str_repeat("\0", 16).pack('N', $timescale).pack('J', $duration)
        : "\0\0\0\0".str_repeat("\0", 8).pack('N', $timescale).pack('N', $duration);
    $body .= str_repeat("\0", 80);

    return pack('N', strlen($body) + 8).'mvhd'.$body;
}

function reelBlockWith(string $url, ?string $typed = null): void
{
    $poster = Media::factory()->create();
    CmsKit::block('video_reel', ['title' => 'Reel', 'items' => [['title' => 'Tower', 'video_url' => $url, 'media_id' => $poster->id, 'duration' => $typed]]]);
}

it('reads the length from REEL_MP4_URL, WebM and ISO 8601 headers', function (): void {
    expect(VideoLengthProbe::mp4Seconds('ftypisom'.mvhd(1000, 42_400)))->toBe(42)
        ->and(VideoLengthProbe::mp4Seconds(mvhd(90_000, 90_000 * 754, version: 1)))->toBe(754)
        ->and(VideoLengthProbe::mp4Seconds('no movie header here'))->toBeNull()
        // WebM: TimecodeScale 1,000,000 ns (3-byte id, 1-byte size 0x83), Duration 58,000.0 as a 4-byte float.
        ->and(VideoLengthProbe::webmSeconds("\x1A\x45\xDF\xA3"."\x2A\xD7\xB1\x83\x0F\x42\x40"."\x44\x89\x84".pack('G', 58_000.0)))->toBe(58)
        ->and(VideoLengthProbe::isoSeconds('PT1M2S'))->toBe(62)
        ->and(VideoLengthProbe::isoSeconds('PT1H2M5S'))->toBe(3725)
        ->and(VideoLengthProbe::isoSeconds('P0D'))->toBe(0)
        ->and(VideoLengthProbe::isoSeconds('soon'))->toBeNull()
        ->and(VideoLengths::format(42))->toBe('0:42')
        ->and(VideoLengths::format(3725))->toBe('1:02:05');
});

it('queues a lookup after the block is saved, and a typed length still wins on the storefront', function (): void {
    Queue::fake();
    CmsKit::actAsEditor();
    $poster = Media::factory()->create();

    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'video_reel', 'content' => ['title' => 'Reel', 'items' => [
        ['title' => 'Tower', 'video_url' => REEL_YT_URL, 'media_id' => $poster->id],
        ['title' => 'Typed', 'video_url' => REEL_MP4_URL, 'media_id' => $poster->id, 'duration' => '1:05'],
        ['title' => 'Facebook', 'video_url' => 'https://www.facebook.com/reel/123', 'media_id' => $poster->id],
    ]]])->assertCreated();

    Queue::assertPushed(FetchVideoLength::class, 2); // YouTube and the file; Facebook has no lookup
    expect(VideoLength::query()->where('url', 'https://www.facebook.com/reel/123')->value('status'))->toBe(VideoLengthStatus::Unsupported);

    VideoLength::query()->where('url', REEL_YT_URL)->update(['status' => 'found', 'seconds' => 42]);
    VideoLength::query()->where('url', REEL_MP4_URL)->update(['status' => 'found', 'seconds' => 300]);
    app(PublishLayout::class)->handle(LayoutKey::home(), null);

    expect(CmsKit::home()->json('blocks.0.content.items.*.duration'))->toBe(['0:42', '1:05', null]);
});

it('fetches a YouTube length with the Data API key and revalidates the storefront', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);
    Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['contentDetails' => ['duration' => 'PT58S']]]])]);
    VideoLength::query()->create(['url_hash' => VideoLength::hashOf(REEL_YT_URL), 'url' => REEL_YT_URL, 'provider' => 'youtube', 'status' => 'pending']);

    (new FetchVideoLength(REEL_YT_URL))->handle(app(VideoLengthProbe::class));

    expect(VideoLength::query()->first())->status->toBe(VideoLengthStatus::Found)->seconds->toBe(58);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'id=Abc_def-123') && str_contains($r->url(), 'part=contentDetails') && str_contains($r->url(), 'key=yt-key'));
    Bus::assertDispatched(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:home', 'content:pages']);
});

it('waits for a YouTube key, and the daily sweep fills it in once the key is set', function (): void {
    config(['services.youtube.key' => null]);
    reelBlockWith(REEL_YT_URL);
    VideoLength::query()->create(['url_hash' => VideoLength::hashOf(REEL_YT_URL), 'url' => REEL_YT_URL, 'provider' => 'youtube', 'status' => 'pending']);

    (new FetchVideoLength(REEL_YT_URL))->handle(app(VideoLengthProbe::class));
    expect(VideoLength::query()->first()->status)->toBe(VideoLengthStatus::NoKey);

    Queue::fake();
    $this->artisan('toymart:content:video-lengths')->assertSuccessful();
    Queue::assertPushed(FetchVideoLength::class, fn (FetchVideoLength $job): bool => $job->url === REEL_YT_URL);
});

it('reads a fast-start REEL_MP4_URL from its first bytes, else from its last megabyte, with a Range request', function (): void {
    Http::fake([
        'cdn.example.com/reels/fast.mp4' => Http::response('ftypisom'.mvhd(600, 600 * 27), 206),
        'cdn.example.com/reels/tower.mp4' => function (Request $r) {
            return $r->header('Range')[0] === 'bytes=-1048576' ? Http::response(mvhd(1000, 49_000), 206) : Http::response('ftypisom'.str_repeat("\0", 64), 206);
        },
    ]);
    $probe = app(VideoLengthProbe::class);

    expect($probe->probe('https://cdn.example.com/reels/fast.mp4'))->toBe([VideoLengthStatus::Found, 27])
        ->and($probe->probe(REEL_MP4_URL))->toBe([VideoLengthStatus::Found, 49]);
    Http::assertSent(fn (Request $r): bool => $r->header('Range')[0] === 'bytes=0-65535');
});

it('never fetches a file on a private address or off https, and marks Facebook as typed-only', function (): void {
    Http::fake();
    $probe = app(VideoLengthProbe::class);

    expect($probe->probe('https://intranet.example/v.mp4'))->toBe([VideoLengthStatus::Unavailable, null])
        ->and($probe->probe('https://127.0.0.1/v.mp4'))->toBe([VideoLengthStatus::Unavailable, null])
        ->and($probe->probe('https://unknown-host.example/v.mp4'))->toBe([VideoLengthStatus::Unavailable, null])
        ->and($probe->probe('https://www.facebook.com/reel/123'))->toBe([VideoLengthStatus::Unsupported, null]);
    Http::assertNothingSent();
});

it('retries a provider outage, then marks the length failed for the sweep', function (): void {
    Http::fake(['www.googleapis.com/*' => Http::response([], 503)]);
    VideoLength::query()->create(['url_hash' => VideoLength::hashOf(REEL_YT_URL), 'url' => REEL_YT_URL, 'provider' => 'youtube', 'status' => 'pending']);
    $job = new FetchVideoLength(REEL_YT_URL);

    expect(fn () => $job->handle(app(VideoLengthProbe::class)))->toThrow(RuntimeException::class);
    $job->failed(null);

    expect(VideoLength::query()->first()->status)->toBe(VideoLengthStatus::Failed)
        ->and(VideoLengthStatus::Failed->retryable())->toBeTrue();
});

it('tells the admin what was found for each reel link', function (): void {
    reelBlockWith(REEL_YT_URL);
    VideoLength::query()->create(['url_hash' => VideoLength::hashOf(REEL_YT_URL), 'url' => REEL_YT_URL, 'provider' => 'youtube', 'status' => 'found', 'seconds' => 42]);
    CmsKit::actAsEditor();

    $this->getJson(CmsKit::ADMIN)->assertOk()->assertJsonPath('video_lengths', [['url' => REEL_YT_URL, 'seconds' => 42, 'status' => 'found']]);
});
