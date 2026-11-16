import { field, readAccountRequest, redirect303 } from '@/lib/account/route';
import { accountWrite } from '@/lib/account/server';
import { CONTACT_TOPICS } from '@/components/content/blocks/ContactFormBlock';

// POST /api/contact — the Contact page's "Send us a message" form (CMS section `contact_form`).
// A plain form post, so it works without JavaScript: the fields go to the API's
// POST /storefront/contact-messages (it validates — rule 30 phone, topic, order number, length —
// and opens a conversation in the admin Inbox, channel `web_form`), and the answer is a 303 to
// /contact-message with a one-shot flash cookie: the thank-you, or the form again with the fields
// in error (as codes — the page words them in its own language) and what was typed. The phone is
// in the POST body and the flash cookie (httpOnly, 60 s), never a URL.

export const dynamic = 'force-dynamic';

const FIELDS = ['name', 'phone', 'topic', 'order_number', 'message'] as const;

export async function POST(request: Request): Promise<Response> {
    const req = await readAccountRequest(request);
    const topic = field(req.form, 'topic', 32);
    const draft = {
        name: field(req.form, 'name', 80),
        phone: field(req.form, 'phone', 20),
        topic: (CONTACT_TOPICS as readonly string[]).includes(topic) ? topic : 'other',
        order_number: field(req.form, 'order_number', 32),
        message: field(req.form, 'message', 500),
    };

    try {
        const result = await accountWrite<{ status: string }>('POST', 'storefront/contact-messages', null, req.locale, {
            name: draft.name,
            phone: draft.phone,
            topic: draft.topic,
            order_number: draft.order_number === '' ? null : draft.order_number,
            message: draft.message,
            locale: req.locale,
        });
        if (result.ok) return redirect303(req.locale, '/contact-message', { kind: 'success', draft: { phone: draft.phone } });
        if (result.status === 422) {
            const errors: Record<string, string> = {};
            for (const key of FIELDS) if (key in result.errors) errors[key] = 'invalid';
            return redirect303(req.locale, '/contact-message', { errors, draft, message: Object.keys(errors).length ? null : 'failed' });
        }
        return redirect303(req.locale, '/contact-message', { message: result.status === 429 ? 'limited' : 'failed', draft });
    } catch {
        return redirect303(req.locale, '/contact-message', { message: 'failed', draft });
    }
}
