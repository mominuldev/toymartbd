/**
 * Activity log (Figma B · 39), spread into `en` / `bn` in messages.ts. Own file so parallel work on other screens
 * does not collide.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const auditEn = {} as const;

export const auditBn: Record<keyof typeof auditEn, string> = {};
