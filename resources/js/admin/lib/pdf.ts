import { ApiError, apiLocale, isProblem } from '@/lib/api';

/**
 * Fetches a PDF from the admin API and opens it in a new tab for the browser's print dialog
 * (invoices, P8). Returns the response headers so a caller can read per-item results
 * (`X-Invoice-Numbers`, `X-Orders-Not-Invoiced`). A problem response becomes an ApiError.
 */
export async function openPdf(path: string): Promise<Headers> {
    const response = await fetch(`/api/v1/admin${path}`, {
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
    return response.headers;
}
