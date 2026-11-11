<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale (P3-I18N-01, CLAUDE.md rule 35).
 *
 * The API contract is explicit-locale: clients send `?locale=en|bn`. Resolution order:
 *   1. `?locale=` query parameter
 *   2. admin API only — the authenticated staff user's `locale` preference, if the
 *      user model carries one (the column may not exist yet)
 *   3. `Accept-Language`, honouring q-values (`bn-BD`, `bn` → bn; `en-US` → en)
 *   4. config('app.locale')
 *
 * An unsupported value at any step is skipped, never an error; the last resort is `en`.
 * The chosen locale is echoed back in `Content-Language`.
 */
final class ResolveLocale
{
    private const FALLBACK = 'en';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    public function resolve(Request $request): string
    {
        $query = $request->query('locale');

        return $this->match(is_string($query) ? $query : null)
            ?? $this->fromStaffPreference($request)
            ?? $this->fromAcceptLanguage($request)
            ?? $this->match($this->configString('app.locale'))
            ?? self::FALLBACK;
    }

    private function fromStaffPreference(Request $request): ?string
    {
        if (! $request->is('api/v1/admin', 'api/v1/admin/*')) {
            return null;
        }

        $user = $request->user();

        if (! $user instanceof Model) {
            return null;
        }

        $preference = $user->getAttribute('locale');

        return $this->match(is_string($preference) ? $preference : null);
    }

    private function fromAcceptLanguage(Request $request): ?string
    {
        $header = $request->headers->get('Accept-Language');

        if ($header === null || trim($header) === '') {
            return null;
        }

        /** @var list<array{tag: string, q: float, order: int}> $ranges */
        $ranges = [];

        foreach (explode(',', $header) as $order => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = array_shift($pieces);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $q = 1.0;
            foreach ($pieces as $param) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{0,3})?)$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }

            if ($q > 0.0) {
                $ranges[] = ['tag' => $tag, 'q' => $q, 'order' => $order];
            }
        }

        // Highest q first; equal q keeps the client's order.
        usort($ranges, fn (array $a, array $b): int => [$b['q'], $a['order']] <=> [$a['q'], $b['order']]);

        foreach ($ranges as $range) {
            $match = $this->match($range['tag']);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** Map a BCP 47 tag (`bn-BD`, `en_US`, `BN`) to a supported locale, or null. */
    private function match(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $primary = strtolower(trim(explode('-', str_replace('_', '-', $tag))[0]));

        return in_array($primary, $this->supported(), true) ? $primary : null;
    }

    /** @return list<string> */
    private function supported(): array
    {
        $configured = config('app.supported_locales', [self::FALLBACK]);

        if (! is_array($configured)) {
            return [self::FALLBACK];
        }

        return array_values(array_filter(
            array_map(fn (mixed $l): string => is_string($l) ? strtolower(trim($l)) : '', $configured),
            fn (string $l): bool => $l !== '',
        ));
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
