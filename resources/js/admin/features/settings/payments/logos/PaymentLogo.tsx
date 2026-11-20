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
 */
export function PaymentLogo({ code, className = 'size-6' }: { code: PaymentLogoCode; className?: string }) {
    return <img src={logos[code]} alt="" aria-hidden className={`shrink-0 object-contain ${className}`} />;
}
