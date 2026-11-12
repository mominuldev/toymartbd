import { isLocale } from '@/lib/i18n';
import { getProduct } from '@/lib/product/fetch';
import { cardFromProduct, parseSlugParam } from '@/lib/recently-viewed';

// GET /api/recently-viewed?slugs=a,b,c&locale= — cards for the PDP "You looked at these" rail
// (P4-STO-07). The browser only remembers slugs; names, photos, stock and prices come from the
// API (GET /storefront/products/{slug}, the same tag-cached call the product pages make, so a
// repeat is a cache hit). Unknown, unpublished or failing products are dropped, never guessed.
//
// TODO(API): a batch card endpoint (e.g. filter[slug]=a,b,c on GET /storefront/products) would
// give listing-identical cards (the "from" price over all variants) in one request.

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<Response> {
    const params = new URL(request.url).searchParams;
    const slugs = parseSlugParam(params.get('slugs'));
    const raw = params.get('locale') ?? '';
    const locale = isLocale(raw) ? raw : 'en';

    const products = await Promise.all(slugs.map((slug) => getProduct(slug, locale).catch(() => null)));
    const data = products.flatMap((p) => (p && p.variants.length > 0 ? [cardFromProduct(p)] : [])).filter((card) => card.price !== '');
    return Response.json({ data }, { headers: { 'Cache-Control': 'private, no-store' } });
}
