import { bn } from './bn';
import { en, type Messages } from './en';
import type { Locale } from './config';

export * from './config';
export { format } from './format';
export type { Messages };

const catalogs: Record<Locale, Messages> = { en, bn };

export function getMessages(locale: Locale): Messages {
    return catalogs[locale];
}
