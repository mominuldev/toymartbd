import 'server-only';

import { cached } from '../api';
import { SEO_TYPES, type SeoDocument, type SeoType } from './types';

// The admin's SEO document for one page (docs/api/seo.md §3), tag-cached under `seo` and the
// entity's own tag, so a save in the SEO panel (or an entity write) refreshes the <head>.
// Never throws: metadata degrades to the entity-derived defaults when the document is missing
// (404, not live) or the API fails — a page must never break over its <head>.

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export const seoTags = (type: SeoType, slug: string): string[] => ['seo', `${type}:${slug}`];

function isSeoDocument(value: unknown): value is SeoDocument {
    if (typeof value !== 'object' || value === null) return false;
    const v = value as Partial<SeoDocument>;
    return typeof v.slug === 'string' && typeof v.en?.title === 'string' && typeof v.bn?.title === 'string' && typeof v.robots === 'object';
}

export async function getSeo(type: SeoType, slug: string): Promise<SeoDocument | null> {
    if (!(SEO_TYPES as readonly string[]).includes(type) || !SLUG.test(slug) || slug.length > 191) return null;
    try {
        const body = await cached<SeoDocument | { data: SeoDocument }>(`storefront/seo/${type}/${encodeURIComponent(slug)}`, seoTags(type, slug));
        const doc = typeof body === 'object' && body !== null && 'data' in body ? body.data : body;
        return isSeoDocument(doc) ? doc : null;
    } catch {
        return null;
    }
}
