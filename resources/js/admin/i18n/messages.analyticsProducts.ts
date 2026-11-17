/**
 * Analytics › Products (Figma B · 24 premium pass 518:78370). Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western (rule 36).
 */
export const analyticsProductsEn = {
    'analyticsProducts.tab': 'Products',
} as const;

export const analyticsProductsBn: Record<keyof typeof analyticsProductsEn, string> = {
    'analyticsProducts.tab': 'প্রোডাক্ট',
};
