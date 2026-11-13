import { draftMode } from 'next/headers';
import { NextResponse } from 'next/server';

import { HOME_PREVIEW_COOKIE } from '@/lib/content/preview';

// GET /api/preview/home/exit — leaves the homepage draft preview and returns to the live home.

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<Response> {
    (await draftMode()).disable();
    const response = NextResponse.redirect(new URL('/', request.url), 307);
    response.cookies.delete(HOME_PREVIEW_COOKIE);
    response.headers.set('Cache-Control', 'no-store');
    return response;
}
