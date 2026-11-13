<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * How staff confirmed a customer agreed to a purpose consent (P8 batch 3, owner decision
 * 2026-11-13). Required when staff record a "yes" in the admin; null for a storefront opt-in.
 */
enum ConsentConfirmationMethod: string
{
    case PhoneCall = 'phone_call';
    case InPerson = 'in_person';
    case Written = 'written';
    case Other = 'other';
}
