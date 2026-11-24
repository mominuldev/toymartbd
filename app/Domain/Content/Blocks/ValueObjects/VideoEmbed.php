<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\ValueObjects;

/**
 * How the storefront plays a video reel in its popup player: from the editor's link, the
 * provider's own embed — YouTube through its privacy-enhanced youtube-nocookie.com host (no
 * cookies until the shopper presses play), Facebook through its video plugin, Instagram through the
 * post's /embed page — or, for an https link to an .mp4 / .webm file, a native <video>. Any other
 * link has no player and is refused when the block is saved.
 *
 * Only the official, documented embed URLs are built (rule 24a in spirit: no scraping). The
 * storefront's Content-Security-Policy `frame-src` lists exactly these hosts (FRAME_HOSTS).
 */
final readonly class VideoEmbed
{
    public const string YOUTUBE = 'youtube';

    public const string FACEBOOK = 'facebook';

    public const string INSTAGRAM = 'instagram';

    public const string FILE = 'file';

    /** The hosts a reel's player iframe may load from (mirrored in the storefront's lib/csp.ts). */
    public const array FRAME_HOSTS = ['https://www.youtube-nocookie.com', 'https://www.facebook.com', 'https://www.instagram.com'];

    private function __construct(public string $kind, public string $src) {}

    public static function fromUrl(?string $url): ?self
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '') {
            return null;
        }
        $host = (string) preg_replace('/^(www\.|m\.)/', '', $host);

        if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true)) {
            $id = null;
            if ($host === 'youtu.be') {
                $id = ltrim($path, '/');
            } elseif (preg_match('#^/(?:shorts|embed|live)/([^/?]+)#', $path, $m) === 1) {
                $id = $m[1];
            } elseif ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            }

            return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1
                ? new self(self::YOUTUBE, 'https://www.youtube-nocookie.com/embed/'.$id.'?autoplay=1&playsinline=1&rel=0')
                : null;
        }

        if ($host === 'facebook.com' || $host === 'fb.watch') {
            $isVideo = $host === 'fb.watch' || preg_match('#/(?:videos|reel|watch)\b#', $path) === 1;

            return $isVideo
                ? new self(self::FACEBOOK, 'https://www.facebook.com/plugins/video.php?'.http_build_query(['href' => $url, 'show_text' => 'false', 'autoplay' => 'true']))
                : null;
        }

        if ($host === 'instagram.com') {
            return preg_match('#^/(reel|reels|p|tv)/([A-Za-z0-9_-]+)#', $path, $m) === 1
                ? new self(self::INSTAGRAM, 'https://www.instagram.com/'.($m[1] === 'reels' ? 'reel' : $m[1]).'/'.$m[2].'/embed')
                : null;
        }

        return preg_match('/\.(mp4|webm)$/i', $path) === 1 ? new self(self::FILE, $url) : null;
    }

    /** @return array{kind: string, src: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'src' => $this->src];
    }
}
