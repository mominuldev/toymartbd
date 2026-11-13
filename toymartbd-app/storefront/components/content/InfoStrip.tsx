import { Icon } from '@/components/icons/Icon';
import { getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { bannerAllowed, safeInfoStripLink, type Banner, type BannerContext } from '@/lib/content/types';

interface Props {
    locale: Locale;
    banners: Banner[];
    context: BannerContext;
}

// The info strip (cart and checkout): one plain information line — a delivery cut-off, a holiday
// notice. Text only; a link only to a store page (/pages/…). Never an image, a product or an offer
// (rule 25b: checkout completes one task). Renders nothing when no strip is live.
export function InfoStrip({ locale, banners, context }: Props) {
    const banner = banners.find((b) => bannerAllowed(b, context));
    if (!banner) return null;
    const t = getMessages(locale);
    const text = locale === 'bn' ? (banner.title_bn ?? banner.body_bn ?? banner.title ?? banner.body) : (banner.title ?? banner.body);
    const fallback = locale === 'bn' && !banner.title_bn && !banner.body_bn;
    const link = safeInfoStripLink(banner.link_url);
    const linkLabel = locale === 'bn' ? (banner.link_label_bn ?? banner.link_label) : banner.link_label;
    if (!text) return null;

    return (
        <aside aria-label={t.content.info} className="t-body-sm flex items-start gap-2 rounded-sm bg-info-subtle px-3 py-2 text-fg-primary">
            <Icon name="info" className="mt-0.5 size-icon-sm shrink-0 text-fg-link" />
            <p lang={fallback ? 'en' : undefined}>
                {text}
                {link && linkLabel ? (
                    <>
                        {' '}
                        <a href={localizedPath(locale, link)} className="font-semibold text-fg-link underline underline-offset-4">
                            {linkLabel}
                        </a>
                    </>
                ) : null}
            </p>
        </aside>
    );
}
