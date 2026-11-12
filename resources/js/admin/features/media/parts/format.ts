/**
 * Display helpers for the media library. Western digits in both locales (rule 36):
 * the numbers never pass through a Bangla number format.
 */
const KB = 1024;
const MB = KB * 1024;
const GB = MB * 1024;

const oneDecimal = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

/** 312 KB · 2.4 MB · 3.2 GB — the units the Figma uses (binary, like the OS file dialog). */
export function formatBytes(bytes: number): string {
    if (bytes >= GB) return `${oneDecimal.format(bytes / GB)} GB`;
    if (bytes >= MB) return `${oneDecimal.format(bytes / MB)} MB`;
    if (bytes >= KB) return `${whole.format(bytes / KB)} KB`;
    return `${whole.format(bytes)} B`;
}

/** 1,262 — grouped, Western digits. */
export function formatCount(n: number): string {
    return whole.format(n);
}

/** 1600×1600 */
export function formatDimensions(width: number | null | undefined, height: number | null | undefined): string | null {
    return width && height ? `${width}×${height}` : null;
}

/** Dhaka date and time, "08 Nov 2026, 2:30 pm", Western digits. */
export function formatDhakaDateTime(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** "08 Nov, 2:30 pm" — the list view's Uploaded column (Dhaka time, the footer says so). */
export function formatDhakaShort(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        numberingSystem: 'latn',
    }).format(new Date(iso));
}
