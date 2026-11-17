import { ApiError, apiLocale, isProblem } from '@/lib/api';
import type { InvoicePrint } from './api';

/**
 * `GET /settings/print/invoice-preview` with the draft as query parameters (toggles `1`/`0`), so
 * "Open full size" shows the unsaved choices on the real template.
 */
export function invoicePreviewPath(draft: InvoicePrint): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(draft)) params.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    return `/settings/print/invoice-preview?${params.toString()}`;
}

/** Fetches the sample invoice PDF and opens it in a new tab (the same way order invoices open). */
export async function openInvoicePreview(draft: InvoicePrint): Promise<void> {
    const response = await fetch(`/api/v1/admin${invoicePreviewPath(draft)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf, application/problem+json', 'Accept-Language': apiLocale() },
    });
    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText, status: response.status });
    }
    const url = URL.createObjectURL(await response.blob());
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
