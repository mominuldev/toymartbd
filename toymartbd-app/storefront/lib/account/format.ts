import { format, type Messages } from '../i18n';
import { contact } from '../site-config';
import type { AccountMessages } from './messages';

// Display helpers for the account pages (P4-STO-11).

/** A flash message is either a key of `notices` or the API's own localised sentence (shown verbatim). */
export function noticeText(message: string | null | undefined, m: AccountMessages): string | null {
    if (!message) return null;
    if (message in m.notices) {
        const text = m.notices[message as keyof AccountMessages['notices']];
        return format(text, { phone: contact.hotline.display });
    }
    return message;
}

/** Initials for the account avatar: first letters of the first two words, or null for no name. */
export function initials(name: string): string | null {
    const words = name.trim().split(/\s+/u).filter(Boolean);
    if (words.length === 0) return null;
    return words
        .slice(0, 2)
        .map((w) => Array.from(w)[0] ?? '')
        .join('')
        .toUpperCase();
}

/** "Sumaiya Islam" → "Sumaiya" for the greeting. */
export function firstName(name: string): string {
    return name.trim().split(/\s+/u)[0] ?? '';
}

export { clock } from './clock';

/** "Oct 2026" (Dhaka month, Western digits — rule 36) for "member since"; null without a date. */
export function monthYear(iso: string | null | undefined, t: Messages): string | null {
    if (!iso) return null;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;
    const fields = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', month: 'numeric', year: 'numeric' }).formatToParts(date);
    const month = Number(fields.find((p) => p.type === 'month')?.value ?? '0');
    const year = fields.find((p) => p.type === 'year')?.value ?? '';
    return `${t.delivery.months[month - 1] ?? String(month)} ${year}`;
}
