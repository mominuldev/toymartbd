import { TAKA, takaParts, type Places } from '@/lib/money';

/**
 * A taka amount as Figma sets it: the sign (a true minus, or "+" for money in) before the ৳, the
 * ৳ in the currency face (Hanken has no ৳ — 06-admin-feature-checklist §0), the figures lakh-
 * grouped in the surrounding style. Formatting only: the amount is the server's (rule 1).
 */
export function TakaAmount({ amount, places = 'trim', signClass = 't-admin-currency-num', plus = false }: { amount: string; places?: Places; signClass?: string; plus?: boolean }) {
    const { sign, digits } = takaParts(amount, places, { plus });
    return (
        <>
            {sign}
            <span className={signClass}>{TAKA}</span>
            {digits}
        </>
    );
}
