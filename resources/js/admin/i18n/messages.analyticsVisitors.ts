/**
 * Analytics › Visitors & funnel (Figma B · 24 premium pass 518:78370). Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const analyticsVisitorsEn = {
    'analyticsVisitors.tab': 'Visitors & funnel',
} as const;

export const analyticsVisitorsBn: Record<keyof typeof analyticsVisitorsEn, string> = {
    'analyticsVisitors.tab': 'ভিজিটর ও ফানেল',
};
