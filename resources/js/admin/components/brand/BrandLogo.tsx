import logoOnDark from './smart-junior-logo-on-dark.svg';
import logoOnLight from './smart-junior-logo.svg';
import mark from './smart-junior-mark.svg';

/**
 * The Smart Junior logo from Figma `01 Brand identity` (12:2, file 2b6PVOOapPbcaKYIdHWZup), exported
 * as vectors: the Primary lockup (12:9) for light surfaces, the On dark lockup (298:58636 — cream
 * "Smart", its card background stripped) for dark ones, and the Mark (12:54, the rocket) where a
 * lockup does not fit. Re-export from Figma rather than editing the SVGs by hand.
 *
 * The lockup follows the surface's theme on its own: inside any `data-theme="admin-dark"` subtree
 * (the sidebar, the POS bar, or the whole admin in dark mode) it shows the On dark artwork. Size it
 * by height (`className="h-10"`); the width follows. `alt` is the store name, so the logo is the name.
 */
export function BrandLogo({ alt, className = 'h-10' }: { alt: string; className?: string }) {
    return (
        <span className="inline-flex shrink-0">
            <img src={logoOnLight} alt={alt} width={203} height={96} decoding="async" className={`w-auto [[data-theme=admin-dark]_&]:hidden ${className}`} />
            <img src={logoOnDark} alt={alt} width={206} height={97} decoding="async" className={`hidden w-auto [[data-theme=admin-dark]_&]:block ${className}`} />
        </span>
    );
}

/** The rocket mark alone — decorative, for the collapsed sidebar rail and other tight spots. */
export function BrandMark({ className = 'h-8' }: { className?: string }) {
    return <img src={mark} alt="" aria-hidden width={36} height={88} decoding="async" className={`w-auto shrink-0 ${className}`} />;
}
