/**
 * Analytics › Live (Figma B · 24 premium pass 518:78370). Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const analyticsLiveEn = {
    'analyticsLive.tab': 'Live',
} as const;

export const analyticsLiveBn: Record<keyof typeof analyticsLiveEn, string> = {
    'analyticsLive.tab': 'লাইভ',
};
