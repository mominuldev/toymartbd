import { WalletIcon } from '@phosphor-icons/react';
import paystationLogo from './paystation.svg';
import sslcommerzLogo from './sslcommerz.svg';

export type PaymentLogoCode = 'sslcommerz' | 'paystation';

const logos: Record<PaymentLogoCode, string> = { sslcommerz: sslcommerzLogo, paystation: paystationLogo };

export function isPaymentLogoCode(code: string): code is PaymentLogoCode {
    return code in logos;
}

/**
 * Each online gateway's own mark (Figma `logo/sslcommerz` 613:2694, `logo/paystation` 613:2699),
 * on Integrations › Payments and the Integrations hub. Both are full-colour files on a
 * transparent ground, so they sit on a bg/surface tile in either theme. Source: ./LICENSE.md.
 *
 * A gateway with no approved mark yet (bKash: none in the Figma file, and its brand assets come
 * with merchant terms we have not seen) shows a neutral wallet icon — never a hand-drawn copy of
 * someone's trademark.
 */
export function PaymentLogo({ code, className = 'size-6' }: { code: string; className?: string }) {
    if (!isPaymentLogoCode(code)) {
        return <WalletIcon aria-hidden weight="fill" className={`shrink-0 text-fg-secondary ${className}`} />;
    }
    return <img src={logos[code]} alt="" aria-hidden className={`shrink-0 object-contain ${className}`} />;
}
