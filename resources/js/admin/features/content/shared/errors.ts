import { ApiError } from '@/lib/api';

export type FieldErrors = Record<string, string>;

/** Zod issues or an API 422 → `{ "faq_items.0.answer": "…" }`. */
export function issuesToErrors(issues: readonly { path: readonly PropertyKey[]; message: string }[]): FieldErrors {
    const out: FieldErrors = {};
    for (const issue of issues) {
        const field = issue.path.map(String).join('.');
        out[field] ??= issue.message;
    }
    return out;
}

/** The API's validation errors as a field map; null when the error is not a 422. */
export function apiFieldErrors(error: unknown): FieldErrors | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    const out: FieldErrors = {};
    for (const e of error.problem.errors ?? []) out[e.field] ??= e.message;
    return out;
}
