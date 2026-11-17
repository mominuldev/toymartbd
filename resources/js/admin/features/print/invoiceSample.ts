/**
 * The sample order the A4 invoice preview draws (Settings › Invoices, receipts & labels, Figma
 * 528:81351) — the same fixed order the server renders for "Open full size"
 * (app/Domain/Invoicing/Services/SampleInvoice.php). Every figure is a display string copied from
 * that fixture: the preview shows them, it never adds anything up (rule 1). Names and SKUs are
 * data, not UI copy, so they are not in the message catalogs; digits are Western (rule 36).
 */
export const invoiceSample = {
    number: 'INV-26-000126',
    order: 'TMB-260126',
    date: '16 Nov 2026',
    customer: { en: 'Rahela Khatun', bn: 'রাহেলা খাতুন' },
    phone: '01712-345678',
    email: 'rahela@example.com',
    address: 'House 12, Road 5, Dhanmondi, Dhaka',
    landmark: { en: 'Opposite Dhanmondi Lake gate 2', bn: 'ধানমন্ডি লেক গেট 2-এর বিপরীতে' },
    lines: [
        { name: { en: 'Remote control racing car', bn: 'রিমোট কন্ট্রোল রেসিং গাড়ি' }, options: 'Red · 1:16', sku: 'TMB-RC-01', qty: '1', unit: '৳2,450', amount: '৳2,450' },
        { name: { en: 'Building blocks, 500 pieces', bn: 'বিল্ডিং ব্লক, 500 টুকরো' }, options: 'Classic colours', sku: 'TMB-BB-500', qty: '2', unit: '৳1,450', amount: '৳2,900' },
        { name: { en: 'Wooden alphabet puzzle', bn: 'কাঠের বর্ণমালা পাজল' }, options: 'বাংলা + English', sku: 'TMB-WP-12', qty: '1', unit: '৳850', amount: '৳850' },
    ],
    subtotal: '৳6,200',
    delivery: '৳120',
    discount: '−৳60',
    coupon: 'WINTER60',
    total: '৳6,260',
    paid: '৳0',
    due: '৳6,260',
} as const;
