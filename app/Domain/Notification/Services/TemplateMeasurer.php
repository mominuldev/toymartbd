<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Support\SmsSegments;
use App\Domain\Notification\Support\SmsTariff;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Support\Money;

/**
 * Measures a template body rendered with the catalog's sample values (P4-I18N-03):
 * characters, encoding and — for SMS — segments and the per-send cost at the configured
 * (PLACEHOLDER) rate. The admin editor's live counter calls this through the API; the
 * client never counts segments or prices a message itself (rule 1).
 */
final class TemplateMeasurer
{
    public function __construct(private readonly SmsTariff $tariff) {}

    /**
     * @return array{rendered: string, chars: int, encoding: string, segments: int|null, per_segment_limit: int|null, cost: Money}
     */
    public function measure(string $key, NotificationChannel $channel, string $locale, string $body): array
    {
        $rendered = TemplateRenderer::render($body, TemplateCatalog::sampleValues($locale), TemplateCatalog::variables($key));
        $chars = mb_strlen($rendered);
        $encoding = SmsSegments::encoding($rendered);

        if ($channel !== NotificationChannel::Sms) {
            return [
                'rendered' => $rendered,
                'chars' => $chars,
                'encoding' => $channel === NotificationChannel::WhatsApp ? 'utf8' : 'html',
                'segments' => null,
                'per_segment_limit' => null,
                'cost' => $this->flatCost($channel),
            ];
        }

        $segments = SmsSegments::count($rendered);
        $gsm = $encoding === 'gsm7';

        return [
            'rendered' => $rendered,
            'chars' => $chars,
            'encoding' => $encoding,
            'segments' => $segments,
            'per_segment_limit' => $segments > 1
                ? ($gsm ? SmsSegments::GSM7_MULTI : SmsSegments::UCS2_MULTI)
                : ($gsm ? SmsSegments::GSM7_SINGLE : SmsSegments::UCS2_SINGLE),
            'cost' => $this->tariff->costFor($segments),
        ];
    }

    private function flatCost(NotificationChannel $channel): Money
    {
        $rate = config('notifications.'.$channel->value.'.cost_per_message', '0.00');

        return Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00');
    }
}
