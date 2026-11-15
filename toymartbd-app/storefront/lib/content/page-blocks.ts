import { normalizeHome, type HomeLayout } from './home-blocks';
import { PAGE_SLUG, type CmsPage } from './types';

// Static pages built from sections (owner decision 2026-11-16) — client-safe and pure, like
// ./home-blocks. GET /storefront/content/pages/{slug}[/preview] answers `{ page, version,
// published_at, preview, blocks }`: `page` is the StorefrontPage document (title, SEO, legacy
// content), the rest is exactly the homepage's block shape, so the blocks go through the home
// normaliser and render with the home block components.

export interface PageLayout extends HomeLayout {
    page: CmsPage;
}

type Obj = Record<string, unknown>;

const isObj = (v: unknown): v is Obj => typeof v === 'object' && v !== null && !Array.isArray(v);

/** Validates the page layout body; null when the page document itself is unusable. */
export function normalizePageLayout(body: unknown): PageLayout | null {
    if (!isObj(body)) return null;
    const root = !('page' in body) && isObj(body.data) ? body.data : body;
    const page = isObj(root.page) && isObj(root.page.data) ? root.page.data : root.page;
    if (!isObj(page) || typeof page.slug !== 'string' || !PAGE_SLUG.test(page.slug) || typeof page.title !== 'string') return null;
    return {
        ...normalizeHome(root),
        page: { ...(page as unknown as CmsPage), faq_items: Array.isArray(page.faq_items) ? (page.faq_items as CmsPage['faq_items']) : [] },
    };
}

/** A page never published from the builder (`version` null, not a preview) renders its legacy body. */
export function usesSections(layout: Pick<PageLayout, 'version' | 'preview'>): boolean {
    return layout.preview || layout.version !== null;
}

/** The legacy-only shape, for when only GET /storefront/pages/{slug} could answer. */
export function legacyLayout(page: CmsPage): PageLayout {
    return { version: null, preview: false, blocks: [], page };
}
