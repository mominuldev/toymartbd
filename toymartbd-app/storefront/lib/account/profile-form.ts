// The profile page's forms (P4-STO-11, P8 batch 3), read on the server by the Route Handlers. Pure and
// client-safe so they are unit-tested; the handlers do the API calls.
//  - details      name + email (PATCH /storefront/account)
//  - preferences  language (PATCH) + the "Birthday reminders and offers" switch (PUT /consents). The
//                 switch is a plain checkbox: unticked (absent from the form) means "no" — never
//                 assumed, never pre-ticked for someone who has not opted in, and never on checkout.
//                 Plus the "Order updates on WhatsApp" and "SMS updates" switches (PUT
//                 /notification-preferences, category order_updates). Unticked means off, but only when
//                 the form carried them (`order_updates_shown`): a form without the switches changes nothing.
//  - child        "My kids": add / edit / remove one child (first name or nickname, day + month,
//                 optional year). The API judges whether the date is real (31 February) and the cap.

export interface DetailsSubmission {
    draft: Record<string, string>;
    body: { name: string; email: string | null };
}

export interface PreferencesSubmission {
    draft: Record<string, string>;
    body: { preferred_language: 'en' | 'bn' };
    birthday: boolean;
    /** PUT /storefront/account/notification-preferences, or null when the form had no order-update switches. */
    orderUpdates: { preferences: Array<{ channel: 'whatsapp' | 'sms'; category: 'order_updates'; enabled: boolean }> } | null;
}

export interface ChildSubmission {
    intent: 'save' | 'delete';
    id: number | null;
    draft: Record<string, string>;
    body: { name: string | null; birth_month: number; birth_day: number; birth_year: number | null };
    /** Caught before any call: keys of the account catalog's `notices`. */
    errors: Record<string, string>;
}

function text(form: FormData, key: string, max: number): string {
    return String(form.get(key) ?? '').trim().slice(0, max);
}

function whole(value: string, min: number, max: number): number | null {
    if (!/^\d{1,9}$/.test(value)) return null;
    const n = Number(value);
    return n >= min && n <= max ? n : null;
}

export function readDetailsForm(form: FormData): DetailsSubmission {
    const name = text(form, 'name', 160);
    const email = text(form, 'email', 191);
    return { draft: { name, email }, body: { name, email: email === '' ? null : email } };
}

export function readPreferencesForm(form: FormData): PreferencesSubmission {
    const language = text(form, 'preferred_language', 2);
    const birthday = form.get('birthday_reminders') === '1';
    const shown = form.get('order_updates_shown') === '1';
    const whatsapp = form.get('order_updates_whatsapp') === '1';
    const sms = form.get('order_updates_sms') === '1';
    return {
        draft: {
            preferred_language: language,
            birthday_reminders: birthday ? '1' : '0',
            ...(shown ? { order_updates_whatsapp: whatsapp ? '1' : '0', order_updates_sms: sms ? '1' : '0' } : {}),
        },
        body: { preferred_language: language === 'en' ? 'en' : 'bn' },
        birthday,
        orderUpdates: shown
            ? {
                  preferences: [
                      { channel: 'whatsapp', category: 'order_updates', enabled: whatsapp },
                      { channel: 'sms', category: 'order_updates', enabled: sms },
                  ],
              }
            : null,
    };
}

export function readChildForm(form: FormData): ChildSubmission {
    const intent = form.get('intent') === 'delete' ? 'delete' : 'save';
    const id = whole(text(form, 'id', 10), 1, 999_999_999);
    const name = text(form, 'child_name', 40);
    const month = text(form, 'birth_month', 2);
    const day = text(form, 'birth_day', 2);
    const year = text(form, 'birth_year', 4);
    const m = whole(month, 1, 12);
    const d = whole(day, 1, 31);

    const errors: Record<string, string> = {};
    if (intent === 'save' && (m === null || d === null)) errors.birth_day = 'childDateMissing';

    return {
        intent,
        id,
        draft: { child_id: id === null ? 'new' : String(id), child_name: name, birth_month: month, birth_day: day, birth_year: year },
        body: { name: name === '' ? null : name, birth_month: m ?? 0, birth_day: d ?? 0, birth_year: whole(year, 1900, 2200) },
        errors,
    };
}
