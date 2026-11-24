<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use App\Domain\Content\Blocks\ValueObjects\VideoEmbed;
use Closure;
use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use OverflowException;
use RuntimeException;
use Throwable;

/**
 * Reads how long a reel video is. Only official, documented sources (rule 24a):
 *
 * - YouTube: the YouTube Data API v3 `videos.list?part=contentDetails` (ISO 8601 duration), with
 *   `services.youtube.key`. No key → NoKey; no such public video → Unavailable.
 * - A hosted .mp4 / .webm: the file's own header, read with HTTP Range requests (never the whole
 *   file): an MP4's `mvhd` box (at the start for a "fast start" file, else in the last 1 MB), a
 *   WebM's Segment Info `Duration` × `TimecodeScale`.
 * - Facebook / Instagram: no official API gives the length → Unsupported (the editor types it).
 *
 * The links are typed by staff, so the file fetch is guarded against server-side request forgery:
 * https only, the host must resolve to public addresses only, no redirects, and at most a fixed
 * number of bytes is read. A network error or a 5xx throws (the job retries); anything else is an
 * answer.
 */
final class VideoLengthProbe
{
    public const int HEAD_BYTES = 65_536;

    public const int TAIL_BYTES = 1_048_576;

    /** @var Closure(string): list<string> host → its IPv4 and IPv6 addresses */
    private readonly Closure $resolve;

    /** @param  (Closure(string): list<string>)|null  $resolve  DNS lookup (swapped in tests) */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? static fn (string $host): array => [
            ...(gethostbynamel($host) ?: []),
            ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        ];
    }

    /** @return array{0: VideoLengthStatus, 1: int|null} status and seconds */
    public function probe(string $url): array
    {
        $embed = VideoEmbed::fromUrl($url);

        return match ($embed?->kind) {
            VideoEmbed::YOUTUBE => $this->youtube((string) self::youtubeId($embed->src)),
            VideoEmbed::FILE => $this->file($url),
            VideoEmbed::FACEBOOK, VideoEmbed::INSTAGRAM => [VideoLengthStatus::Unsupported, null],
            default => [VideoLengthStatus::Unavailable, null],
        };
    }

    /** @return array{0: VideoLengthStatus, 1: int|null} */
    private function youtube(string $id): array
    {
        $key = config('services.youtube.key');
        if (! is_string($key) || $key === '') {
            return [VideoLengthStatus::NoKey, null];
        }

        $response = $this->send(fn () => Http::timeout(10)->acceptJson()
            ->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'contentDetails', 'id' => $id, 'key' => $key]));
        if ($response->clientError()) {
            // A bad or restricted key is a 4xx: an answer, not a retry (the sweep tries again later).
            return [VideoLengthStatus::Failed, null];
        }

        $duration = $response->json('items.0.contentDetails.duration');
        $seconds = is_string($duration) ? self::isoSeconds($duration) : null;

        return $seconds === null || $seconds === 0 ? [VideoLengthStatus::Unavailable, null] : [VideoLengthStatus::Found, $seconds];
    }

    /** @return array{0: VideoLengthStatus, 1: int|null} */
    private function file(string $url): array
    {
        $ip = $this->publicAddress($url);
        if ($ip === null) {
            return [VideoLengthStatus::Unavailable, null];
        }

        $head = $this->range($url, $ip, 'bytes=0-'.(self::HEAD_BYTES - 1), self::HEAD_BYTES);
        if ($head === null) {
            return [VideoLengthStatus::Unavailable, null];
        }

        $seconds = str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.webm')
            ? self::webmSeconds($head)
            : self::mp4Seconds($head);
        if ($seconds === null && ! str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.webm')) {
            // Not a "fast start" MP4: the moov box (and its mvhd) sits at the end of the file.
            $tail = $this->range($url, $ip, 'bytes=-'.self::TAIL_BYTES, self::TAIL_BYTES);
            $seconds = $tail === null ? null : self::mp4Seconds($tail);
        }

        return $seconds === null || $seconds === 0 ? [VideoLengthStatus::Unavailable, null] : [VideoLengthStatus::Found, $seconds];
    }

    /**
     * At most `$max` bytes of the response body, or null when the server refuses or sends more than
     * asked (a server that ignores Range): cURL's transfer callback aborts past the limit, so a whole
     * video is never downloaded. The connection is pinned to `$ip`, the address that was checked.
     */
    private function range(string $url, string $ip, string $range, int $max): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $limit = $max + 16_384;
        $tooBig = new OverflowException('More bytes than the Range asked for.');
        try {
            $response = $this->send(fn () => Http::timeout(15)->withHeaders(['Range' => $range])->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pin]],
                'progress' => static function (int $total, int $done) use ($limit, $tooBig): void {
                    if ($done > $limit) {
                        throw $tooBig; // aborts the transfer
                    }
                },
            ])->get($url));
        } catch (Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause === $tooBig) {
                    return null; // Range not honoured: never download a whole video
                }
            }
            throw $e;
        }

        return $response->successful() ? substr($response->body(), 0, $max) : null;
    }

    /** @param  callable(): Response  $request */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Video length lookup could not connect.', 0, $e);
        }
        if ($response->serverError()) {
            throw new RuntimeException('Video length lookup failed with HTTP '.$response->status().'.');
        }

        return $response;
    }

    public static function youtubeId(string $embedSrc): ?string
    {
        return preg_match('#/embed/([A-Za-z0-9_-]{11})#', $embedSrc, $m) === 1 ? $m[1] : null;
    }

    /** `PT1M2S` → 62; `P0D` (a live stream) → 0; garbage → null. */
    public static function isoSeconds(string $iso): ?int
    {
        try {
            $i = new DateInterval($iso);
        } catch (Throwable) {
            return null;
        }

        return (($i->d * 24 + $i->h) * 60 + $i->i) * 60 + $i->s;
    }

    /** The movie header (`mvhd`) duration ÷ timescale, rounded; null when absent. */
    public static function mp4Seconds(string $bytes): ?int
    {
        $at = strpos($bytes, 'mvhd');
        if ($at === false || strlen($bytes) < $at + 4 + 32) {
            return null;
        }
        $p = $at + 4;
        $version = ord($bytes[$p]);
        if ($version === 1) {
            $timescale = unpack('N', substr($bytes, $p + 20, 4))[1] ?? 0;
            $parts = unpack('N2', substr($bytes, $p + 24, 8)) ?: [];
            $duration = (($parts[1] ?? 0) * 4_294_967_296) + ($parts[2] ?? 0);
        } else {
            $timescale = unpack('N', substr($bytes, $p + 12, 4))[1] ?? 0;
            $duration = unpack('N', substr($bytes, $p + 16, 4))[1] ?? 0;
        }

        return is_int($timescale) && $timescale > 0 ? (int) round($duration / $timescale) : null;
    }

    /** Segment Info `Duration` (0x4489, a float in TimecodeScale units) × `TimecodeScale` (0x2AD7B1, default 1 ms). */
    public static function webmSeconds(string $bytes): ?int
    {
        $at = strpos($bytes, "\x44\x89");
        if ($at === false) {
            return null;
        }
        $size = ord($bytes[$at + 2] ?? "\x00");
        if ($size === 0x84) {
            $value = unpack('G', substr($bytes, $at + 3, 4))[1] ?? null; // big-endian float
        } elseif ($size === 0x88) {
            $value = unpack('E', substr($bytes, $at + 3, 8))[1] ?? null; // big-endian double
        } else {
            return null;
        }

        $scale = 1_000_000;
        $s = strpos($bytes, "\x2A\xD7\xB1");
        if ($s !== false) {
            $len = ord($bytes[$s + 3] ?? "\x00") & 0x0F; // a 1-byte size vint 0x8n → n bytes
            if ($len >= 1 && $len <= 8) {
                $scale = 0;
                foreach (str_split(substr($bytes, $s + 4, $len)) as $byte) {
                    $scale = ($scale << 8) | ord($byte);
                }
            }
        }

        return is_float($value) && $value > 0 && $scale > 0 ? (int) round($value * $scale / 1_000_000_000) : null;
    }

    /**
     * The address to connect to: https on port 443, and every address the host resolves to is
     * public (no loopback, private, link-local or reserved range). Null refuses the link.
     */
    public function publicAddress(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $ips[0];
    }
}
