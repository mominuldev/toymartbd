/**
 * AI for growth — shared strings (notices, byline). Spread into `en` / `bn` in messages.ts.
 * Each AI feature has its own file (messages.aiSettings / aiAdvisor / aiCopy / aiInbox / aiRestock).
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const aiEn = {
    'ai.byline': 'Written by {model}. Every figure comes from your reports — check before acting.',
    'ai.problem.not_configured.title': 'AI is not set up for this',
    'ai.problem.not_configured.body': 'Connect an AI provider and choose it for this feature in Integrations › AI assistant.',
    'ai.problem.cap_reached.title': 'This month’s AI budget is used up',
    'ai.problem.cap_reached.body': 'Raise the monthly cap in Integrations › AI assistant, or wait until the 1st.',
    'ai.problem.not_shared.title': 'This is not shared with AI',
    'ai.problem.not_shared.body': 'The figures or details this feature needs are switched off in Integrations › AI assistant › What the AI can see.',
    'ai.problem.unavailable.title': 'The AI could not answer just now',
    'ai.problem.unavailable.body': 'The provider did not give a usable answer. Nothing was changed. Try again in a moment.',
    'ai.problem.other.title': 'Something went wrong',
    'ai.problem.other.body': 'Nothing was changed. Try again in a moment.',
    'ai.problem.openSettings': 'Open AI settings',
    'ai.problem.retry': 'Try again',
} as const;

export const aiBn: Record<keyof typeof aiEn, string> = {
    'ai.byline': '{model} লিখেছে। প্রতিটি সংখ্যা আপনার রিপোর্ট থেকে — কাজের আগে যাচাই করে নিন।',
    'ai.problem.not_configured.title': 'এর জন্য AI চালু করা হয়নি',
    'ai.problem.not_configured.body': 'ইন্টিগ্রেশন › AI সহকারী থেকে একটি AI প্রোভাইডার যুক্ত করে এই ফিচারের জন্য বেছে নিন।',
    'ai.problem.cap_reached.title': 'এ মাসের AI বাজেট শেষ',
    'ai.problem.cap_reached.body': 'ইন্টিগ্রেশন › AI সহকারী থেকে মাসিক সীমা বাড়ান, অথবা 1 তারিখ পর্যন্ত অপেক্ষা করুন।',
    'ai.problem.not_shared.title': 'এটি AI-এর সাথে শেয়ার করা নেই',
    'ai.problem.not_shared.body': 'এই ফিচারের দরকারি সংখ্যা বা তথ্য ইন্টিগ্রেশন › AI সহকারী › AI কী দেখতে পারে-তে বন্ধ করা আছে।',
    'ai.problem.unavailable.title': 'AI এই মুহূর্তে উত্তর দিতে পারেনি',
    'ai.problem.unavailable.body': 'প্রোভাইডার ব্যবহারযোগ্য উত্তর দেয়নি। কিছুই বদলানো হয়নি। একটু পরে আবার চেষ্টা করুন।',
    'ai.problem.other.title': 'কিছু একটা সমস্যা হয়েছে',
    'ai.problem.other.body': 'কিছুই বদলানো হয়নি। একটু পরে আবার চেষ্টা করুন।',
    'ai.problem.openSettings': 'AI সেটিংস খুলুন',
    'ai.problem.retry': 'আবার চেষ্টা করুন',
};
