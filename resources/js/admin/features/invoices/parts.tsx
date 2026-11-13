/** The taka sign — a currency symbol, not translatable copy. */
const TAKA = '৳';

/**
 * "2450.00" → "2,450". Groups and trims the server's decimal string; never parses it into a
 * float and never computes (rule 1). Western digits in both locales (rule 36).
 */
export function formatMoney(decimal: string): string {
    const negative = decimal.startsWith('-');
    const [whole = '', fraction = ''] = (negative ? decimal.slice(1) : decimal).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = fraction.replace(/0+$/, '');
    return `${negative ? '−' : ''}${grouped}${trimmed ? `.${trimmed}` : ''}`;
}

export function Taka({ amount }: { amount: string }) {
    return (
        <>
            <span className="t-admin-currency-num">{TAKA}</span>
            {formatMoney(amount)}
        </>
    );
}

/** "13 Nov 2026" in Dhaka time, Western digits. */
export function formatIssued(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}
