import { draftMode } from 'next/headers';
import { NextResponse } from 'next/server';

import { PAGE_PREVIEW_COOKIE } from '@/lib/content/preview';
import { PAGE_SLUG } from '@/lib/content/types';
import { isLocale, localizedPath } from '@/lib/i18n';

// GET /api/preview/page/exit?slug=…&locale=… — leaves a static page's draft preview and returns to
// that page's live URL. The slug must be a page slug (never a URL), so this cannot redirect off-site.

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const slug = url.searchParams.get('slug') ?? '';
    const localeParam = url.searchParams.get('locale') ?? 'en';
    const locale = isLocale(localeParam) ? localeParam : 'en';
    const target = PAGE_SLUG.test(slug) ? `/${slug}` : '/';

    (await draftMode()).disable();
    const response = new NextResponse(null, { status: 307, headers: { Location: localizedPath(locale, target) } });
    response.cookies.delete(PAGE_PREVIEW_COOKIE);
    response.headers.set('Cache-Control', 'no-store');
    return response;
}
