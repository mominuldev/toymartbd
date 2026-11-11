import { z } from 'zod';
import { translate, type Locale } from './I18nProvider';

/**
 * Route Zod's validation messages through the i18n catalog so generated schemas
 * (packages/api-types/src/zod.ts) never show hardcoded English (rule 35).
 */
export function configureZodErrors(locale: Locale): void {
    z.config({
        customError: (issue) => {
            switch (issue.code) {
                case 'invalid_type':
                    return issue.input === undefined || issue.input === null || issue.input === ''
                        ? translate(locale, 'validation.required')
                        : translate(locale, 'validation.invalid');
                case 'too_small':
                    return issue.origin === 'string' && Number(issue.minimum) <= 1
                        ? translate(locale, 'validation.required')
                        : translate(locale, 'validation.min', { min: String(issue.minimum) });
                case 'too_big':
                    return translate(locale, 'validation.max', { max: String(issue.maximum) });
                case 'invalid_format':
                    return issue.format === 'email' ? translate(locale, 'validation.email') : translate(locale, 'validation.format');
                default:
                    return translate(locale, 'validation.invalid');
            }
        },
    });
}
