import type { Locale } from '@/lib/i18n';
import type { SiteImage } from '@/lib/site-config';
import { Wordmark } from './Wordmark';

interface Props {
    locale: Locale;
    /** The owner's logo (Admin → Settings → Store → Site & branding, via lib/store-info.ts), or null. */
    logo: SiteImage | null;
}

// The header logo: the uploaded one when the owner has set it, else the built-in placeholder
// Wordmark. Decorative like the Wordmark — the link around it carries the accessible name. A plain
// <img>: the file is already a sized WebP rendition from the media pipeline (480 wide, so sharp at
// 2x), and its host is in the CSP img-src (API / MEDIA_ORIGINS).
export function SiteLogo({ locale, logo }: Props) {
    if (!logo) return <Wordmark locale={locale} />;
    return (
        // eslint-disable-next-line @next/next/no-img-element -- see above: a ready rendition, no optimiser round-trip
        <img
            src={logo.url}
            alt=""
            width={logo.width ?? undefined}
            height={logo.height ?? undefined}
            decoding="async"
            data-testid="site-logo"
            className="h-8 w-auto max-w-40 object-contain lg:h-10 lg:max-w-56"
        />
    );
}
