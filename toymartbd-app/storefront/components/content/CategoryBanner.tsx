import { type Locale } from '@/lib/i18n';
import { linkAttrs, localisedOptional, type Banner } from '@/lib/content/types';

interface Props {
    locale: Locale;
    banner: Banner | undefined;
}

// A category-page banner (placement category_page): image (media library, srcset) beside an
// optional title, text and link. The whole card is not a link, so the text stays selectable and
// the link has its own accessible name.
export function CategoryBanner({ locale, banner }: Props) {
    if (!banner) return null;
    const title = localisedOptional(locale, banner.title, banner.title_bn);
    const body = localisedOptional(locale, banner.body, banner.body_bn);
    const link = linkAttrs(locale, banner.link_url);
    const linkLabel = localisedOptional(locale, banner.link_label, banner.link_label_bn);
    const image = banner.image;
    const alt = image ? ((locale === 'bn' ? (image.alt_bn ?? image.alt) : image.alt) ?? '') : '';
    const bn = (lang?: 'en') => (locale === 'bn' && !lang ? 'lang-bn' : '');

    return (
        <section className="mb-6 flex flex-col overflow-hidden rounded-lg bg-brand-subtle md:flex-row md:items-center">
            {image ? (
                // eslint-disable-next-line @next/next/no-img-element -- media renditions come with their own srcset
                <img src={image.url} srcSet={image.srcset ?? undefined} sizes="(min-width: 768px) 40vw, 100vw" width={image.width ?? undefined} height={image.height ?? undefined} alt={alt} className="h-auto w-full object-cover md:w-2/5" />
            ) : null}
            {title || body || (link && linkLabel) ? (
                <div className="flex flex-col gap-2 p-5 lg:p-8">
                    {title ? (
                        <h2 lang={title.lang} className={`t-h2 text-fg-primary ${bn(title.lang)}`}>
                            {title.text}
                        </h2>
                    ) : null}
                    {body ? (
                        <p lang={body.lang} className={`t-body text-fg-secondary ${bn(body.lang)}`}>
                            {body.text}
                        </p>
                    ) : null}
                    {link && linkLabel ? (
                        <a {...link} lang={linkLabel.lang} className="t-label self-start rounded-xs text-fg-link underline underline-offset-4">
                            {linkLabel.text}
                        </a>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
