import { ApiError, apiLocale, isProblem } from '@/lib/api';

/**
 * Sends a PDF straight to the browser's print dialog (owner request 2026-11-17: Print prints,
 * it does not open a tab). The PDF loads into a hidden same-origin iframe from a blob URL and
 * that frame prints itself; the frame and the URL are cleaned up once the dialog has had time to
 * read them. If the browser refuses to print from a frame, the PDF opens in a new tab instead.
 */
export function printPdfBlob(blob: Blob): void {
    const url = URL.createObjectURL(blob);
    document.querySelectorAll('iframe[data-print-frame]').forEach((old) => old.remove());
    const frame = document.createElement('iframe');
    frame.setAttribute('data-print-frame', '');
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    // Rendered but unseen: Chrome does not start its PDF viewer in a display:none, hidden or
    // zero-sized frame, and then print() has nothing to print.
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';
    frame.onload = () => {
        // A beat for the PDF viewer to attach after the frame's load event.
        window.setTimeout(() => {
            try {
                frame.contentWindow?.focus();
                frame.contentWindow?.print();
            } catch {
                window.open(url, '_blank', 'noopener');
            }
        }, 250);
    };
    frame.src = url;
    document.body.appendChild(frame);
    // The dialog keeps its own copy once open; ten minutes covers a slow printer choice.
    window.setTimeout(() => {
        frame.remove();
        URL.revokeObjectURL(url);
    }, 600_000);
}

/**
 * Fetches a PDF from the admin API and sends it to the print dialog ({@link printPdfBlob};
 * invoices, P8). Returns the response headers so a caller can read per-item results
 * (`X-Invoice-Numbers`, `X-Orders-Not-Invoiced`). A problem response becomes an ApiError.
 */
export async function openPdf(path: string): Promise<Headers> {
    const response = await fetch(path.startsWith('/api/') ? path : `/api/v1/admin${path}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf, application/problem+json', 'Accept-Language': apiLocale() },
    });
    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw new ApiError(response.status, isProblem(payload) ? payload : { type: 'about:blank', title: response.statusText, status: response.status });
    }
    printPdfBlob(await response.blob());
    return response.headers;
}
