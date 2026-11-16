import { TakaAmount } from '@/components/ui/TakaAmount';

/** "৳2,450" — lakh-grouped through the admin's one formatter (lib/money). */
export function Taka({ amount }: { amount: string }) {
    return <TakaAmount amount={amount} />;
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
