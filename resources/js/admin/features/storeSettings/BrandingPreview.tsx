import { useState } from 'react';
import { BrowserIcon, LockSimpleIcon, MagnifyingGlassIcon, ShoppingBagOpenIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { BrandingCard } from './brandingParts';
import type { BrandingImage } from './BrandingUploadTile';

export type PreviewLocale = 'en' | 'bn';

export type PreviewContent = {
    /** Per locale: the typed value, else the business-name fallback the storefront uses. */
    name: Record<PreviewLocale, string>;
    tagline: Record<PreviewLocale, string>;
    description: Record<PreviewLocale, string>;
    logo: BrandingImage | null;
    icon: BrandingImage | null;
    shareImage: BrandingImage | null;
    /** "toymartbd.com" from the API; null until the storefront URL is configured. */
    host: string | null;
};

/** The storefront's tab title: "Name — Tagline", or the name alone. */
export function tabTitle(name: string, tagline: string): string {
    return tagline ? `${name} — ${tagline}` : name;
}

/** The bag-on-brand tile that stands in for an icon not uploaded yet (Figma IconTile 395:69434). */
function IconStandIn({ size, glyph, radius }: { size: string; glyph: number; radius: string }) {
    return (
        <span aria-hidden className={`grid shrink-0 place-items-center bg-brand text-fg-on-brand ${size} ${radius}`}>
            <ShoppingBagOpenIcon size={glyph} weight="fill" />
        </span>
    );
}

/** The logo as the storefront header draws it: the uploaded image, else the icon + name wordmark. */
function Logo({ content, locale }: { content: PreviewContent; locale: PreviewLocale }) {
    if (content.logo?.url) return <img src={content.logo.url} alt={content.name[locale]} className="h-6 w-auto max-w-40 object-contain object-left" />;
    return (
        <span className="flex min-w-0 items-center gap-1.5">
            {content.icon?.url ? <img src={content.icon.url} alt="" className="size-6 shrink-0 rounded-xs object-cover" /> : <IconStandIn size="size-6" glyph={14} radius="rounded-xs" />}
            <span lang={locale} className="truncate t-admin-currency-section text-fg-primary">
                {content.name[locale]}
            </span>
        </span>
    );
}

const overline = 't-admin-overline text-fg-tertiary uppercase';

/**
 * Live preview rail (Figma 395:69414, mobile 396:69974): EN / বাংলা, then the browser tab
 * (favicon + "Name — Tagline", address bar), the storefront header (logo, tagline, search and
 * bag) and the card a WhatsApp share shows (share image 1.91:1, title, description, host).
 * Everything renders from the unsaved form, so it updates as the owner types.
 */
export function BrandingPreview({ content, className = '' }: { content: PreviewContent; className?: string }) {
    const { t } = useI18n();
    const [locale, setLocale] = useState<PreviewLocale>('en');
    const name = content.name[locale];
    const tagline = content.tagline[locale];
    const description = content.description[locale];
    const title = tabTitle(name, tagline);
    const host = content.host ?? t('storeBranding.preview.noHost');

    return (
        <BrandingCard icon={BrowserIcon} tone="teal" title={t('storeBranding.preview.title')} description={t('storeBranding.preview.description')} centered className={`gap-3.5 ${className}`}>
            <SegmentedControl
                label={t('storeBranding.preview.language')}
                options={[
                    { value: 'en', label: 'English' },
                    { value: 'bn', label: 'বাংলা', lang: 'bn' },
                ]}
                value={locale}
                onChange={setLocale}
                className="w-full [&>button]:flex-1"
            />

            <p className={overline}>{t('storeBranding.preview.tab')}</p>
            <div className="overflow-hidden rounded-sm border border-line-default bg-subtle">
                <div className="flex px-2 pt-2">
                    <div className="flex min-w-0 flex-1 items-center gap-2 rounded-t-xs bg-surface px-2.5 py-2">
                        {content.icon?.url ? <img src={content.icon.url} alt="" className="size-4 shrink-0 rounded-xs object-cover" /> : <IconStandIn size="size-4" glyph={10} radius="rounded-xs" />}
                        <p lang={locale} data-testid="preview-tab-title" className="min-w-0 flex-1 truncate t-admin-caption-strong text-fg-primary">
                            {title}
                        </p>
                    </div>
                </div>
                <div className="flex bg-surface px-2.5 py-2">
                    <p className="flex min-w-0 flex-1 items-center gap-1.5 rounded-full bg-subtle px-2.5 py-1 t-admin-mono-sm text-fg-secondary">
                        <LockSimpleIcon size={12} aria-hidden className="shrink-0" />
                        <span className="truncate">{host}</span>
                    </p>
                </div>
            </div>

            <p className={overline}>{t('storeBranding.preview.header')}</p>
            <div className="flex items-center gap-3 rounded-sm border border-line-default bg-surface px-3.5 py-3">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <Logo content={content} locale={locale} />
                    {tagline && (
                        <p lang={locale} className="truncate t-admin-caption text-fg-secondary">
                            {tagline}
                        </p>
                    )}
                </div>
                <MagnifyingGlassIcon size={20} aria-hidden className="shrink-0 text-fg-primary" />
                <ShoppingBagOpenIcon size={20} weight="fill" aria-hidden className="shrink-0 text-fg-primary" />
            </div>

            <p className={overline}>{t('storeBranding.preview.whatsapp')}</p>
            <div className="rounded-sm bg-tile-mint p-3">
                <div className="flex flex-col gap-2 rounded-sm bg-surface px-1 pt-1 pb-2">
                    {content.shareImage?.url ? (
                        <img src={content.shareImage.url} alt="" className="aspect-[1.91] w-full rounded-xs object-cover" />
                    ) : (
                        <div className="flex aspect-[1.91] w-full flex-col items-center justify-center gap-1 rounded-xs bg-tile-sky px-3">
                            <Logo content={content} locale={locale} />
                            {tagline && (
                                <p lang={locale} className="max-w-full truncate t-admin-caption text-fg-primary">
                                    {tagline}
                                </p>
                            )}
                        </div>
                    )}
                    <div className="flex flex-col gap-0.5 px-1.5 t-admin-caption">
                        <p lang={locale} className="t-admin-caption-strong text-fg-primary">
                            {title}
                        </p>
                        {description && (
                            <p lang={locale} className="text-fg-secondary">
                                {description}
                            </p>
                        )}
                        <p className="truncate t-admin-mono-sm text-fg-tertiary">{host}</p>
                    </div>
                    <p className="truncate px-1.5 t-admin-caption text-fg-brand">{content.host ? `${content.host}/${t('storeBranding.preview.samplePath')}` : host}</p>
                </div>
            </div>

            <p className="t-admin-caption text-fg-tertiary">{t('storeBranding.preview.cacheNote')}</p>
        </BrandingCard>
    );
}
