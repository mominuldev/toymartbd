<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\WhatsAppResult;

/**
 * Sends one pre-approved WhatsApp **utility** template (rule 39). Never used for OTP —
 * Bangladesh is on Meta's authentication-international tier (rule 32b).
 *
 * Meta templates take positional parameters ({{1}}, {{2}}…) and are approved per
 * language (`en`, `bn_BD`). Like every gateway: only called from a queued job after
 * commit, returns a rejection rather than throwing for a provider-side refusal, and never
 * logs the full number.
 */
interface WhatsAppGateway
{
    /** @param  list<string>  $parameters  positional values, {{1}} first */
    public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult;
}
