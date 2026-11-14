/**
 * Store settings › Delivery areas & timing, spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const deliverySettingsEn = {} as const;

export const deliverySettingsBn: Record<keyof typeof deliverySettingsEn, string> = {};
