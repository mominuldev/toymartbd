/**
 * AI for growth — aiInbox. Spread into `en` / `bn` in messages.ts.
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const aiInboxEn = {
    'aiInbox.draftWithAi': 'Draft with AI',
    'aiInbox.title': 'Suggested reply',
    'aiInbox.lang.bn': 'বাংলা',
    'aiInbox.lang.en': 'English',
    'aiInbox.meta': '{model} · {seconds} s',
    'aiInbox.drafting': 'Drafting a reply from this conversation and the customer’s orders…',
    'aiInbox.basedOn': 'Based on',
    'aiInbox.use': 'Use draft',
    'aiInbox.shorter': 'Shorter',
    'aiInbox.inEnglish': 'In English',
    'aiInbox.inBangla': 'In Bangla',
    'aiInbox.discard': 'Discard',
    'aiInbox.windowClosed': 'The 24-hour window has closed, so a free reply cannot be sent. Send an approved template instead.',
    'aiInbox.footnote': 'You send every message. The AI never sends, and never sees the customer’s phone number or address.',
} as const;

export const aiInboxBn: Record<keyof typeof aiInboxEn, string> = {
    'aiInbox.draftWithAi': 'AI দিয়ে খসড়া',
    'aiInbox.title': 'প্রস্তাবিত উত্তর',
    'aiInbox.lang.bn': 'বাংলা',
    'aiInbox.lang.en': 'English',
    'aiInbox.meta': '{model} · {seconds} সে.',
    'aiInbox.drafting': 'এই কথোপকথন ও গ্রাহকের অর্ডার দেখে উত্তর লেখা হচ্ছে…',
    'aiInbox.basedOn': 'যার ভিত্তিতে',
    'aiInbox.use': 'খসড়া ব্যবহার করুন',
    'aiInbox.shorter': 'আরও ছোট',
    'aiInbox.inEnglish': 'ইংরেজিতে',
    'aiInbox.inBangla': 'বাংলায়',
    'aiInbox.discard': 'বাদ দিন',
    'aiInbox.windowClosed': '24 ঘণ্টার সময়সীমা শেষ, তাই সাধারণ উত্তর পাঠানো যাবে না। এর বদলে অনুমোদিত টেমপ্লেট পাঠান।',
    'aiInbox.footnote': 'প্রতিটি মেসেজ আপনিই পাঠান। AI কখনো মেসেজ পাঠায় না, আর গ্রাহকের ফোন নম্বর বা ঠিকানা কখনো দেখে না।',
};
