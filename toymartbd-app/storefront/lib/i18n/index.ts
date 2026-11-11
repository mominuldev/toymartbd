import { bn } from './bn';
import { en, type Messages } from './en';
import type { Locale } from './config';

export * from './config';
export type { Messages };

const catalogs: Record<Locale, Messages> = { en, bn };

export function getMessages(locale: Locale): Messages {
    return catalogs[locale];
}

/** Replace `{name}` placeholders. Values are inserted as-is (React escapes them on render). */
export function format(template: string, values: Record<string, string>): string {
    return template.replace(/\{(\w+)\}/g, (match, key: string) => values[key] ?? match);
}
