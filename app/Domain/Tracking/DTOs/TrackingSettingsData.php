<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

use App\Domain\Tracking\Enums\PurchaseTrigger;

/**
 * One save of Admin → Integrations → Tracking. A null/blank secret means "keep the current
 * one"; `clear*` removes it. Validation is the admin FormRequest's job.
 */
final readonly class TrackingSettingsData
{
    public function __construct(
        public bool $serverSideEnabled,
        public bool $ga4Enabled,
        public ?string $ga4MeasurementId,
        public bool $metaEnabled,
        public ?string $metaPixelId,
        public ?string $metaTestEventCode,
        public ?string $gtmContainerId,
        public PurchaseTrigger $purchaseTrigger,
        public string $consentMode,
        public ?string $consentPolicyVersion,
        public ?string $ga4ApiSecret = null,
        public bool $clearGa4ApiSecret = false,
        public ?string $metaAccessToken = null,
        public bool $clearMetaAccessToken = false,
    ) {}
}
