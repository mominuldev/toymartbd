import { accountWrite } from '@/lib/account/server';
import { clearAccountCookies, field, readAccountRequest, redirect303 } from '@/lib/account/route';
import type { SavedAddress } from '@/lib/account/types';
import { intField } from '@/lib/cart/route';

// POST /api/account/addresses — every address-book form (P4-STO-11, rule 31):
//   intent=save     add (no `id`) or edit (`id`) an address
//   intent=refresh  no-JS: re-render the form with the chosen district's upazilas, keeping what was typed
//   intent=delete   remove `id`
//   intent=default  make `id` the default
// The API validates exactly as checkout does; its field errors come back next to each field.

export const dynamic = 'force-dynamic';

const FIELDS = { type: 16, label: 64, recipient_name: 160, phone: 32, district_id: 9, upazila_id: 9, area: 160, address_line: 500, landmark: 255, is_default: 1 } as const;

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    if (!req.token) return redirect303(req.locale, '/account/login?next=/account/addresses', { message: 'sessionEnded' });

    const intent = String(req.form.get('intent') ?? 'save');
    const id = intField(req.form, 'id', 1, 999_999_999);
    const page = id !== null && (intent === 'save' || intent === 'refresh') ? `/account/addresses?edit=${id}#address-form` : '/account/addresses';

    const draft: Record<string, string> = {};
    for (const [key, max] of Object.entries(FIELDS)) draft[key] = field(req.form, key, max);
    if (id !== null) draft.id = String(id);

    let result;
    if (intent === 'refresh') {
        return redirect303(req.locale, id !== null ? page : '/account/addresses#address-form', { kind: 'success', draft });
    } else if (intent === 'delete' && id !== null) {
        result = await accountWrite<null>('DELETE', `storefront/account/addresses/${id}`, req.token, req.locale);
    } else if (intent === 'default' && id !== null) {
        result = await accountWrite<SavedAddress>('POST', `storefront/account/addresses/${id}/default`, req.token, req.locale);
    } else {
        const body = {
            type: ['home', 'office', 'other'].includes(draft.type ?? '') ? draft.type : 'home',
            label: draft.label || null,
            recipient_name: draft.recipient_name,
            phone: draft.phone,
            district_id: draft.district_id ? Number(draft.district_id) : null,
            upazila_id: draft.upazila_id ? Number(draft.upazila_id) : null,
            area: draft.area,
            address_line: draft.address_line,
            landmark: draft.landmark || null,
            is_default: draft.is_default === '1',
        };
        result = id !== null
            ? await accountWrite<SavedAddress>('PATCH', `storefront/account/addresses/${id}`, req.token, req.locale, body)
            : await accountWrite<SavedAddress>('POST', 'storefront/account/addresses', req.token, req.locale, body);
    }

    if (result.ok) {
        const message = intent === 'delete' ? 'addressRemoved' : intent === 'default' ? 'defaultSet' : 'addressSaved';
        return redirect303(req.locale, '/account/addresses', { kind: 'success', message });
    }
    if (result.status === 401) {
        const response = redirect303(req.locale, '/account/login?next=/account/addresses', { message: 'sessionEnded' });
        clearAccountCookies(response);
        return response;
    }
    if (result.status === 422) return redirect303(req.locale, id !== null ? page : '/account/addresses#address-form', { errors: result.errors, draft });
    return redirect303(req.locale, '/account/addresses', { message: 'failed', draft });
}
