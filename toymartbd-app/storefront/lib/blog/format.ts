import { format, getMessages, type Locale } from '../i18n';
import { formatPrice } from '../listing/format';
import type { BlogMessages } from './messages';
import type { BlogCard, BlogTerm } from './types';

// Display helpers for blog pages. Text picks the page's locale and falls back to English when the
// Bangla field is empty (the caller marks the fallback with lang="en"). Dates are Dhaka dates with
// Western digits (rules 11, 36); money is the API's decimal string through the shared formatter.

export interface Localised {
    text: string;
    /** `en` when a Bangla page shows the English fallback. */
    lang?: 'en';
}

export function pick(locale: Locale, en: string, bn: string | null | undefined): Localised {
    if (locale === 'bn' && bn && bn.trim() !== '') return { text: bn };
    return locale === 'bn' ? { text: en, lang: 'en' } : { text: en };
}

export function pickOptional(locale: Locale, en: string | null | undefined, bn: string | null | undefined): string | null {
    if (locale === 'bn' && bn && bn.trim() !== '') return bn;
    return en ?? null;
}

export const postTitle = (post: Pick<BlogCard, 'title' | 'title_bn'>, locale: Locale) => pick(locale, post.title, post.title_bn);
export const termName = (term: Pick<BlogTerm, 'name' | 'name_bn'>, locale: Locale) => pick(locale, term.name, term.name_bn);

export function readTime(post: Pick<BlogCard, 'reading_minutes' | 'reading_minutes_bn'>, locale: Locale, t: BlogMessages): string {
    const minutes = locale === 'bn' && post.reading_minutes_bn ? post.reading_minutes_bn : post.reading_minutes;
    return format(t.readTime, { minutes: String(minutes) });
}

/** "8 Nov 2026" / "8 নভেম্বর 2026" in Dhaka; empty when the API has no date. */
export function postDate(iso: string | null | undefined, locale: Locale): string {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const fields = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(date);
    const get = (type: string) => Number(fields.find((p) => p.type === type)?.value ?? '0');
    const months = getMessages(locale).delivery.months;
    return `${get('day')} ${months[get('month') - 1] ?? get('month')} ${get('year')}`;
}

/** ISO date (YYYY-MM-DD) of an instant in Dhaka, for `<time datetime>`. */
export function dhakaIsoDate(iso: string | null | undefined): string | undefined {
    if (!iso) return undefined;
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return undefined;
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

/** A section's budget line from the API's decimal strings (no arithmetic, rule 1). */
export function budgetLabel(min: string | null | undefined, max: string | null | undefined, t: BlogMessages): string | null {
    if (min && max) return format(t.budget, { range: `${formatPrice(min)}–${formatPrice(max)}` });
    if (max) return format(t.budget, { range: format(t.budgetUpTo, { max: formatPrice(max) }) });
    if (min) return format(t.budget, { range: format(t.budgetFrom, { min: formatPrice(min) }) });
    return null;
}

/** "2–4 years" from an age band value ("24-48", "144-"), via the listing's own age labels. */
export function ageBandRange(band: string | null | undefined): { min: number; max: number | null } | null {
    if (!band) return null;
    const m = /^(\d{1,3})-(\d{1,3})?$/.exec(band);
    if (!m) return null;
    return { min: Number(m[1]), max: m[2] ? Number(m[2]) : null };
}

/** `?page=n` parsed strictly: 1 when absent, null when malformed (→ 404). */
export function parsePage(raw: string | string[] | undefined): number | null {
    if (raw === undefined) return 1;
    if (Array.isArray(raw) || !/^[1-9]\d{0,4}$/.test(raw)) return null;
    return Number(raw);
}

/** A list page URL: page 1 is the bare path (one canonical), later pages carry `?page=n`. */
export function pagePath(path: string, page: number): string {
    return page <= 1 ? path : `${path}?page=${page}`;
}
