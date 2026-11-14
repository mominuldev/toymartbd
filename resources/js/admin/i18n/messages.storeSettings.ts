/**
 * Store settings — layout, Business, Branches & warehouses (Figma B · 38), spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const storeSettingsEn = {} as const;

export const storeSettingsBn: Record<keyof typeof storeSettingsEn, string> = {};
