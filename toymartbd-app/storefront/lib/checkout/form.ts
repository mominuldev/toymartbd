// The checkout form's fields and their round trip through cookies (P4-STO-09). Pure functions:
// the Route Handler and the page share them, and they are unit-tested. Nothing here validates a
// phone or prices anything — the API does both (rules 1 and 30); we only cap lengths so a cookie
// stays small and pass the customer's text through.

export const DRAFT_FIELDS = ['name', 'phone', 'email', 'district_id', 'upazila_id', 'area', 'address_line', 'landmark', 'customer_note'] as const;
export type DraftField = (typeof DRAFT_FIELDS)[number];
export type CheckoutDraft = Partial<Record<DraftField, string>>;

/** API maxima (docs/api/checkout.md), so a draft cookie can never carry more than the API accepts. */
export const FIELD_MAX: Record<DraftField, number> = {
    name: 160,
    phone: 32,
    email: 191,
    district_id: 9,
    upazila_id: 9,
    area: 160,
    address_line: 500,
    landmark: 255,
    customer_note: 500,
};

export function readDraft(form: FormData): CheckoutDraft {
    const draft: CheckoutDraft = {};
    for (const key of DRAFT_FIELDS) {
        const value = String(form.get(key) ?? '').trim().slice(0, FIELD_MAX[key]);
        if (value !== '') draft[key] = value;
    }
    return draft;
}

const ID = /^[1-9]\d{0,8}$/;

export function draftId(value: string | undefined): number | null {
    return value && ID.test(value) ? Number(value) : null;
}

/** The POST /checkout body (checkout.md): the customer's text as typed, ids as numbers, COD. */
export function checkoutBody(draft: CheckoutDraft, expectedTotal: string): Record<string, unknown> {
    const opt = (key: DraftField) => draft[key] ?? null;
    return {
        name: draft.name ?? '',
        phone: draft.phone ?? '',
        alt_phone: null,
        email: opt('email'),
        district_id: draftId(draft.district_id),
        upazila_id: draftId(draft.upazila_id),
        area: draft.area ?? '',
        address_line: draft.address_line ?? '',
        landmark: opt('landmark'),
        postcode: null,
        payment_method: 'cod',
        customer_note: opt('customer_note'),
        expected_total: expectedTotal,
    };
}

export interface CheckoutFlash {
    /** Field → the API's localised message. */
    errors: Partial<Record<DraftField | 'payment_method', string>>;
    /** A message above the form (price changed, coupon, busy…): a catalog key or the API's sentence. */
    notice: string | null;
}

const b64 = {
    encode: (value: unknown) => Buffer.from(JSON.stringify(value), 'utf8').toString('base64url'),
    decode: (value: string): unknown => JSON.parse(Buffer.from(value, 'base64url').toString('utf8')),
};

export function encodeDraft(draft: CheckoutDraft): string {
    return b64.encode(draft);
}

export function decodeDraft(value: string | undefined | null): CheckoutDraft {
    if (!value || value.length > 4000) return {};
    try {
        const raw = b64.decode(value);
        if (typeof raw !== 'object' || raw === null) return {};
        const draft: CheckoutDraft = {};
        for (const key of DRAFT_FIELDS) {
            const v = (raw as Record<string, unknown>)[key];
            if (typeof v === 'string' && v !== '') draft[key] = v.slice(0, FIELD_MAX[key]);
        }
        return draft;
    } catch {
        return {};
    }
}

export function encodeFlash(flash: CheckoutFlash): string {
    return b64.encode(flash);
}

export function decodeFlash(value: string | undefined | null): CheckoutFlash | null {
    if (!value || value.length > 4000) return null;
    try {
        const raw = b64.decode(value) as Partial<CheckoutFlash> | null;
        if (!raw || typeof raw !== 'object') return null;
        const errors: CheckoutFlash['errors'] = {};
        if (raw.errors && typeof raw.errors === 'object') {
            for (const [key, message] of Object.entries(raw.errors)) {
                if ((DRAFT_FIELDS as readonly string[]).concat('payment_method').includes(key) && typeof message === 'string') {
                    errors[key as DraftField] = message.slice(0, 300);
                }
            }
        }
        return { errors, notice: typeof raw.notice === 'string' ? raw.notice.slice(0, 300) : null };
    } catch {
        return null;
    }
}

/**
 * Split the API's 422 `errors[]` into field messages (shown at the field) and a cart-level message
 * (`cart`, `items.<id>`, `coupon`, `expected_total` — shown above the form with a link to the cart).
 */
export function splitErrors(errors: Array<{ field: string; message: string }>): { fields: CheckoutFlash['errors']; general: string | null } {
    const fields: CheckoutFlash['errors'] = {};
    let general: string | null = null;
    for (const { field, message } of errors) {
        const key = field === 'alt_phone' ? 'phone' : field;
        if ((DRAFT_FIELDS as readonly string[]).includes(key) || key === 'payment_method') {
            fields[key as DraftField] ??= message;
        } else {
            general ??= message;
        }
    }
    return { fields, general };
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function validKey(value: unknown): string | null {
    return typeof value === 'string' && UUID.test(value) ? value : null;
}

const DECIMAL = /^\d{1,12}(?:\.\d{1,2})?$/;

export function validAmount(value: unknown): string | null {
    return typeof value === 'string' && DECIMAL.test(value) ? value : null;
}

/** "TMB-26-00042" — the confirmation URL segment; anything else is not an order number. */
export const ORDER_NUMBER = /^TMB-\d{2}-\d{5,8}$/;
