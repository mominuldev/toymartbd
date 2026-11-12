<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\DTOs\WhatsAppResult;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Default WhatsApp driver: no WhatsApp Business account exists yet, so it sends nothing,
 * logs a masked line and reports the configured PLACEHOLDER utility cost.
 */
final class LogWhatsAppGateway implements WhatsAppGateway
{
    /** @param  list<string>  $parameters */
    public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult
    {
        Log::info('whatsapp.log_driver', [
            'to' => PhoneNumber::tryParse($e164Phone)?->masked() ?? '***',
            'template' => $template,
            'provider_template' => $providerTemplate,
            'language' => $language,
            'parameter_count' => count($parameters),
        ]);

        $rate = config('notifications.whatsapp.cost_per_message', '0.00');

        return WhatsAppResult::accepted('log-'.Str::uuid()->toString(), Money::of(is_string($rate) || is_int($rate) ? $rate : '0.00'));
    }
}
