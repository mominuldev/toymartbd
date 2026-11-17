/**
 * Settings › Invoices, receipts & labels — the shared shell (tabs). Spread into `en` / `bn` in
 * messages.ts. Each tab has its own file (messages.printInvoice/Receipt/Labels).
 *
 * NEEDS NATIVE-SPEAKER REVIEW, like the rest of the Bangla UI copy. Digits stay Western.
 */
export const printEn = {
    'print.tabs.label': 'Printed documents',
    'print.tab.invoice': 'A4 invoice',
    'print.tab.receipt': 'Thermal receipt',
    'print.tab.labels': 'Barcode labels',
} as const;

export const printBn: Record<keyof typeof printEn, string> = {
    'print.tabs.label': 'প্রিন্ট করা কাগজ',
    'print.tab.invoice': 'A4 ইনভয়েস',
    'print.tab.receipt': 'থার্মাল রসিদ',
    'print.tab.labels': 'বারকোড লেবেল',
};
