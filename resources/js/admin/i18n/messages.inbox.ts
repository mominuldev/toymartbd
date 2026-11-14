/**
 * Unified inbox (Figma B · 22), spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const inboxEn = {} as const;

export const inboxBn: Record<keyof typeof inboxEn, string> = {};
