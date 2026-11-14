/**
 * POS register (Figma B · 33), spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const posEn = {} as const;

export const posBn: Record<keyof typeof posEn, string> = {};
