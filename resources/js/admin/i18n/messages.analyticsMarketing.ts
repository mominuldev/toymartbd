/**
 * Analytics › Marketing ROI (Figma B · 24 premium pass 518:78370). Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const analyticsMarketingEn = {
    'analyticsMarketing.tab': 'Marketing ROI',
} as const;

export const analyticsMarketingBn: Record<keyof typeof analyticsMarketingEn, string> = {
    'analyticsMarketing.tab': 'মার্কেটিং ROI',
};
