import type { StoreContact } from './site-config';

// Click-to-chat links (WhatsApp and Messenger) for the product page, the contact page and the footer.
// Plain https links built on the server from the store's own settings (lib/store-info.ts): no
// third-party script, SDK or pixel, so they work with JavaScript off and the CSP is unaffected (an
// <a href> to another origin is not governed by it). A channel the owner has not set — or whose value
// is still a design sample — has no link at all: nobody is sent to a made-up number. Client-safe and
// pure, for the unit tests.

export type ChatChannel = 'whatsapp' | 'messenger';

export interface ChatLink {
    channel: ChatChannel;
    href: string;
}

/**
 * `https://wa.me/{E.164 digits}` — with `?text=` prefilled when given (WhatsApp's documented
 * click-to-chat form) — then `https://m.me/{page}`. Each only when configured.
 */
export function chatLinks(contact: Pick<StoreContact, 'whatsapp' | 'messenger'>, text?: string | null): ChatLink[] {
    const links: ChatLink[] = [];
    const wa = contact.whatsapp;
    if (!wa.placeholder && /^https:\/\/wa\.me\/\d{8,15}$/.test(wa.href)) {
        links.push({ channel: 'whatsapp', href: text ? `${wa.href}?text=${encodeURIComponent(text)}` : wa.href });
    }
    const m = contact.messenger;
    if (m && !m.placeholder && /^https:\/\/m\.me\/[A-Za-z0-9.]+$/.test(m.href)) {
        links.push({ channel: 'messenger', href: m.href });
    }
    return links;
}
