<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * Where a notification template came from (Message templates library, P7 owner request
 * 2026-11-13).
 *
 * - **system** — event-bound, defined by {@see \App\Domain\Notification\Support\TemplateCatalog}
 *   (order.placed, otp.login…). Its key, event and variable allowlist are code; staff
 *   edit copy and switch it off, never delete, archive or re-key it.
 * - **marketing** — a staff-made template for promotional sends. Opt-out category
 *   `marketing`: off until the customer opts in (NotificationCategory::Marketing).
 * - **manual** — a staff-made template for one-off service messages to a customer about
 *   their own order or account. Opt-out category `order_updates`: on by default, the
 *   customer may turn a channel off.
 *
 * Nothing sends a custom template yet — a later batch adds the sending; when it does, it
 * must check NotificationPreferences::allows() with {@see self::optOutCategory()}.
 */
enum TemplateKind: string
{
    case System = 'system';
    case Marketing = 'marketing';
    case Manual = 'manual';

    public function isCustom(): bool
    {
        return $this !== self::System;
    }

    /** The preferences category a custom template is filed under for opt-outs. */
    public function optOutCategory(): NotificationCategory
    {
        return $this === self::Marketing ? NotificationCategory::Marketing : NotificationCategory::OrderUpdates;
    }

    /** @return list<string> */
    public static function customValues(): array
    {
        return [self::Marketing->value, self::Manual->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
