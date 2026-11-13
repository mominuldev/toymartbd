<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * `GET /storefront/content/home[/preview]` query: `locale` (required, en|bn) and, for a preview
 * only, `at` (ISO 8601). Anything else is a 400 (rule 16).
 */
final readonly class HomeContentQuery
{
    private function __construct(
        public string $locale,
        public ?CarbonImmutable $at,
    ) {}

    public static function fromRequest(Request $request, bool $preview): self
    {
        $allowed = $preview ? ['locale', 'at'] : ['locale'];
        $unknown = array_diff(array_keys($request->query->all()), $allowed);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown query parameter(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', $allowed).'.');
        }

        $locale = $request->query('locale');
        if (! in_array($locale, ['en', 'bn'], true)) {
            throw new BadRequestHttpException('locale must be one of: en, bn.');
        }

        $at = null;
        $raw = $request->query('at');
        if ($raw !== null) {
            try {
                $at = is_string($raw) && $raw !== '' ? CarbonImmutable::parse($raw)->utc() : throw new BadRequestHttpException('at must be an ISO 8601 instant.');
            } catch (BadRequestHttpException $e) {
                throw $e;
            } catch (Throwable) {
                throw new BadRequestHttpException('at must be an ISO 8601 instant.');
            }
        }

        return new self($locale, $at);
    }
}
