import 'server-only';

import { ApiError, cached } from './api';
import type { Brand, BrandListResponse, BrandResponse } from './api-types';
import type { Locale } from './i18n/config';

export const brandTags = {
    list: 'brands',
    one: (slug: string) => `brand:${slug}`,
} as const;

export async function listBrands(locale: Locale): Promise<Brand[]> {
    const body = await cached<BrandListResponse>('storefront/brands', [brandTags.list], { locale });
    return body.data;
}

/** One brand, or null when the API answers 404. Any other failure propagates. */
export async function getBrand(slug: string, locale: Locale): Promise<Brand | null> {
    try {
        const body = await cached<BrandResponse>(
            `storefront/brands/${encodeURIComponent(slug)}`,
            [brandTags.list, brandTags.one(slug)],
            { locale },
        );
        return 'data' in body ? body.data : body;
    } catch (error) {
        if (error instanceof ApiError && error.isNotFound) return null;
        throw error;
    }
}

/** Localised display fields; Bangla falls back to English when the translation is missing. */
export function brandName(brand: Brand, locale: Locale): string {
    return locale === 'bn' && brand.name_bn ? brand.name_bn : brand.name;
}

export function brandDescription(brand: Brand, locale: Locale): string | null {
    return locale === 'bn' && brand.description_bn ? brand.description_bn : brand.description;
}

/** True when the shown name is not in the page language (Bangla page, English fallback). */
export function brandNameIsFallback(brand: Brand, locale: Locale): boolean {
    return locale === 'bn' && !brand.name_bn;
}
