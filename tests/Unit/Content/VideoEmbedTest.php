<?php

declare(strict_types=1);

use App\Domain\Content\Blocks\ValueObjects\VideoEmbed;

/*
 * The reel popup player's source (VideoEmbed): the provider's official embed, or the file itself.
 */

it('builds the player for every supported link', function (string $url, ?array $expected): void {
    expect(VideoEmbed::fromUrl($url)?->toArray())->toBe($expected);
})->with([
    'YouTube Shorts' => ['https://www.youtube.com/shorts/Abc_def-123', ['kind' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/Abc_def-123?autoplay=1&playsinline=1&rel=0']],
    'YouTube watch' => ['https://m.youtube.com/watch?v=Abc_def-123&t=4s', ['kind' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/Abc_def-123?autoplay=1&playsinline=1&rel=0']],
    'youtu.be' => ['https://youtu.be/Abc_def-123', ['kind' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/Abc_def-123?autoplay=1&playsinline=1&rel=0']],
    'Facebook reel' => ['https://www.facebook.com/reel/123456', ['kind' => 'facebook', 'src' => 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Freel%2F123456&show_text=false&autoplay=true']],
    'fb.watch' => ['https://fb.watch/abc/', ['kind' => 'facebook', 'src' => 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Ffb.watch%2Fabc%2F&show_text=false&autoplay=true']],
    'Instagram reels' => ['https://www.instagram.com/reels/C9xYz12AbcD/', ['kind' => 'instagram', 'src' => 'https://www.instagram.com/reel/C9xYz12AbcD/embed']],
    'hosted mp4' => ['https://cdn.example.com/v/tower.MP4', ['kind' => 'file', 'src' => 'https://cdn.example.com/v/tower.MP4']],
    'http' => ['http://youtu.be/Abc_def-123', null],
    'short YouTube id' => ['https://youtu.be/abc', null],
    'YouTube channel' => ['https://www.youtube.com/@smartjunior', null],
    'Facebook page' => ['https://www.facebook.com/smartjunior', null],
    'TikTok' => ['https://www.tiktok.com/@a/video/1', null],
    'a web page' => ['https://example.com/video', null],
]);
