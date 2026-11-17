import type { PosReceipt } from '@/features/pos/api';

/**
 * The sample sale the Thermal receipt preview prints (Figma 529:81638). Static strings only —
 * every figure is written out, never computed (rule 1). `print` is replaced by the draft
 * settings; the barcode and QR are the server's own output for this order number and
 * `wa.me` link (ReceiptCodes), captured once so the preview needs no request.
 */
export const SAMPLE_RECEIPT: Omit<PosReceipt, 'print'> = {
    store: {
        name_en: 'Toy Mart BD',
        name_bn: 'টয় মার্ট বিডি',
        address_en: null,
        address_bn: null,
        hotline: '09612-000000',
        bin: '000000000-0000',
        trade_licence: null,
        footer_en: null,
        footer_bn: null,
        placeholders: [],
    },
    branch: { name: 'Dhaka HQ', name_bn: 'ঢাকা HQ', address: 'Mirpur 10, Dhaka' },
    register: 'Counter 1',
    cashier: 'Rina',
    order_number: 'TMB-26-00221',
    sold_at: '2026-11-17T09:42:00+00:00',
    customer: { name: 'Rubina Akter', phone_masked: '018****2310' },
    lines: [
        { name: 'Kids Tricycle with Push Handle', name_bn: 'পুশ হ্যান্ডেলসহ বাচ্চাদের ট্রাইসাইকেল', variant_name: null, sku: 'TRI-PUSH-RED', quantity: 1, unit_price: '4900.00', discount: '0.00', total: '4900.00' },
        { name: 'Plush Teddy Bear 45 cm', name_bn: 'প্লাশ টেডি বিয়ার 45 সেমি', variant_name: null, sku: 'TED-45-BRN', quantity: 1, unit_price: '1200.00', discount: '0.00', total: '1200.00' },
        { name: 'Crayons 24 colours', name_bn: 'ক্রেয়ন 24 রং', variant_name: null, sku: 'CRY-24', quantity: 2, unit_price: '180.00', discount: '0.00', total: '360.00' },
    ],
    subtotal: '6460.00',
    promotion_discount: '0.00',
    coupon_discount: '0.00',
    coupon_code: null,
    manual_discount: '200.00',
    discount_reason: 'regular_customer',
    grand_total: '6260.00',
    payments: [{ method: 'cash', amount: '7000.00', reference: null }],
    cash_tendered: '7000.00',
    change_due: '740.00',
    due_amount: '0.00',
    whatsapp: { enabled: false, reason: 'no_provider' },
    logo_url: null,
    whatsapp_link: 'https://wa.me/8801700000000',
    barcode_svg:
        "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 187 40\" width=\"187\" height=\"40\" preserveAspectRatio=\"none\" shape-rendering=\"crispEdges\"><rect width=\"100%\" height=\"100%\" fill=\"white\"/><g fill=\"black\"><rect x=\"10\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"13\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"16\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"21\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"24\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"30\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"32\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"34\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"38\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"43\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"47\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"49\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"54\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"57\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"60\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"65\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"69\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"74\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"76\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"80\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"84\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"87\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"90\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"93\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"98\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"101\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"105\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"109\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"112\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"116\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"120\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"124\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"129\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"131\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"135\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"140\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"142\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"145\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"150\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"153\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"157\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"161\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"164\" y=\"0\" width=\"2\" height=\"40\"/><rect x=\"169\" y=\"0\" width=\"3\" height=\"40\"/><rect x=\"173\" y=\"0\" width=\"1\" height=\"40\"/><rect x=\"175\" y=\"0\" width=\"2\" height=\"40\"/></g></svg>",
    whatsapp_qr_svg:
        "<svg xmlns=\"http://www.w3.org/2000/svg\" version=\"1.1\" width=\"160\" height=\"160\" viewBox=\"0 0 160 160\"><rect x=\"0\" y=\"0\" width=\"160\" height=\"160\" fill=\"white\"/><g transform=\"scale(6.4)\"><g transform=\"translate(0,0)\"><path fill-rule=\"evenodd\" d=\"M8 0L8 1L9 1L9 2L8 2L8 5L9 5L9 4L11 4L11 5L10 5L10 10L8 10L8 9L9 9L9 8L8 8L8 9L7 9L7 8L6 8L6 9L7 9L7 10L5 10L5 9L4 9L4 8L0 8L0 9L2 9L2 10L3 10L3 11L4 11L4 10L5 10L5 12L6 12L6 13L7 13L7 14L5 14L5 16L4 16L4 14L3 14L3 12L2 12L2 11L0 11L0 13L1 13L1 15L0 15L0 16L1 16L1 15L2 15L2 17L5 17L5 16L6 16L6 17L7 17L7 16L9 16L9 17L8 17L8 18L9 18L9 17L10 17L10 19L9 19L9 20L10 20L10 22L9 22L9 21L8 21L8 25L11 25L11 24L9 24L9 23L10 23L10 22L11 22L11 23L12 23L12 22L13 22L13 20L12 20L12 19L13 19L13 18L15 18L15 17L16 17L16 21L17 21L17 25L25 25L25 24L23 24L23 23L24 23L24 22L25 22L25 15L24 15L24 14L25 14L25 12L23 12L23 11L21 11L21 12L18 12L18 10L17 10L17 11L16 11L16 13L15 13L15 14L16 14L16 13L17 13L17 15L16 15L16 16L13 16L13 15L14 15L14 14L13 14L13 15L12 15L12 16L11 16L11 14L10 14L10 13L11 13L11 11L12 11L12 10L13 10L13 9L12 9L12 8L13 8L13 6L12 6L12 5L13 5L13 4L14 4L14 3L15 3L15 5L14 5L14 7L15 7L15 5L16 5L16 7L17 7L17 5L16 5L16 4L17 4L17 2L16 2L16 3L15 3L15 0L14 0L14 2L13 2L13 0L12 0L12 1L11 1L11 0L10 0L10 1L9 1L9 0ZM11 2L11 4L12 4L12 3L13 3L13 2ZM8 6L8 7L9 7L9 6ZM11 6L11 8L12 8L12 6ZM15 8L15 9L14 9L14 11L13 11L13 13L14 13L14 11L15 11L15 9L16 9L16 8ZM17 8L17 9L18 9L18 8ZM20 8L20 9L19 9L19 11L20 11L20 9L23 9L23 10L24 10L24 9L25 9L25 8L24 8L24 9L23 9L23 8ZM3 9L3 10L4 10L4 9ZM11 9L11 10L12 10L12 9ZM6 11L6 12L7 12L7 13L8 13L8 14L7 14L7 15L6 15L6 16L7 16L7 15L8 15L8 14L9 14L9 16L10 16L10 14L9 14L9 12L8 12L8 11ZM17 12L17 13L18 13L18 12ZM21 12L21 16L24 16L24 15L22 15L22 14L24 14L24 13L22 13L22 12ZM2 14L2 15L3 15L3 14ZM19 14L19 15L17 15L17 16L19 16L19 15L20 15L20 14ZM12 17L12 18L11 18L11 19L10 19L10 20L11 20L11 21L12 21L12 20L11 20L11 19L12 19L12 18L13 18L13 17ZM17 17L17 20L20 20L20 17ZM21 17L21 20L22 20L22 21L24 21L24 19L22 19L22 17ZM23 17L23 18L24 18L24 17ZM18 18L18 19L19 19L19 18ZM14 19L14 20L15 20L15 19ZM14 21L14 23L13 23L13 25L14 25L14 23L16 23L16 22L15 22L15 21ZM19 21L19 24L20 24L20 21ZM21 22L21 24L22 24L22 22ZM0 0L0 7L7 7L7 0ZM1 1L1 6L6 6L6 1ZM2 2L2 5L5 5L5 2ZM25 0L18 0L18 7L25 7ZM24 1L19 1L19 6L24 6ZM23 2L20 2L20 5L23 5ZM0 25L7 25L7 18L0 18ZM1 24L6 24L6 19L1 19ZM2 23L5 23L5 20L2 20Z\" fill=\"black\"/></g></g></svg>\n",
};
