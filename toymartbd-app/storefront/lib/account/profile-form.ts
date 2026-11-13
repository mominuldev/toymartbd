// The profile form (P4-STO-11 + P8 birthday messages), read on the server by the Route Handler. Pure
// and client-safe so it is unit-tested; the handler does the API calls. The birthday box is a plain
// checkbox: unticked (absent from the form) means "no" — never assumed, never pre-ticked for someone
// who has not opted in, and never on checkout.

const DATE = /^\d{4}-\d{2}-\d{2}$/;

export interface ProfileSubmission {
    /** What the customer typed, for a no-JS round trip after an error. */
    draft: Record<string, string>;
    /** PATCH /storefront/account. */
    body: { name: string; email: string | null; preferred_language: 'en' | 'bn'; date_of_birth: string | null };
    /** PUT /storefront/account/consents. */
    birthday: boolean;
    /** Field errors caught before any call: keys of the account catalog's `notices`. */
    errors: Record<string, string>;
}

function text(form: FormData, key: string, max: number): string {
    return String(form.get(key) ?? '').trim().slice(0, max);
}

export function readProfileForm(form: FormData): ProfileSubmission {
    const name = text(form, 'name', 160);
    const email = text(form, 'email', 191);
    const language = text(form, 'preferred_language', 2);
    const dob = text(form, 'date_of_birth', 10);
    const birthday = form.get('birthday_messages') === '1';

    const errors: Record<string, string> = {};
    // A birthday message needs a birthday. Format and range are the API's to judge (localised 422).
    if (birthday && dob === '') errors.date_of_birth = 'birthdayNeedsDate';
    else if (dob !== '' && !DATE.test(dob)) errors.date_of_birth = 'dateInvalid';

    return {
        draft: { name, email, preferred_language: language, date_of_birth: dob, birthday_messages: birthday ? '1' : '0' },
        body: { name, email: email === '' ? null : email, preferred_language: language === 'en' ? 'en' : 'bn', date_of_birth: dob === '' ? null : dob },
        birthday,
        errors,
    };
}
