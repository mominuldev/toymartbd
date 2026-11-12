<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\DTOs\WhatsAppResult;
use App\Support\Money;

/** Silently accepts everything at zero cost. */
final class NullWhatsAppGateway implements WhatsAppGateway
{
    /** @param  list<string>  $parameters */
    public function sendTemplate(string $e164Phone, string $providerTemplate, string $language, array $parameters, string $template): WhatsAppResult
    {
        return WhatsAppResult::accepted(null, Money::zero());
    }
}
