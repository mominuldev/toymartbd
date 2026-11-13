import type { Metadata } from 'next';

import type { Locale } from '../i18n/config';
import type { SeoDocument } from '../seo/types';
import { contentMetadata } from '../seo/metadata';
import { localised, type CmsPage } from './types';

/**
 * <head> for a CMS static page — /pages/{slug} and the About route (/pages/about) — through the
 * lib/seo builder, so every page gets the same canonical, hreflang (en, bn, x-default), Open
 * Graph and the admin's SEO document overlay. The page payload's own `seo` block gives the
 * defaults; the SEO document (GET /storefront/seo/page/{slug}) wins where it is set.
 */
export function cmsPageMetadata(page: CmsPage, locale: Locale, path: string, seo: SeoDocument | null): Metadata {
    const title = (locale === 'bn' ? page.seo.title_bn : page.seo.title) || localised(locale, page.title, page.title_bn).text;
    const description = (locale === 'bn' ? page.seo.description_bn : page.seo.description) || null;
    return contentMetadata({
        locale,
        path,
        title,
        description,
        image: page.seo.og_image ? { url: page.seo.og_image } : null,
        seo,
        kind: 'page',
        modifiedTime: page.updated_at,
    });
}
