import { draftMode } from 'next/headers';
import { NextResponse } from 'next/server';

import { HOME_PREVIEW_COOKIE } from '@/lib/content/preview';

// GET /api/preview/home/exit — leaves the homepage draft preview and returns to the live home.

export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
    (await draftMode()).disable();
    const response = new NextResponse(null, { status: 307, headers: { Location: '/' } });
    response.cookies.delete(HOME_PREVIEW_COOKIE);
    response.headers.set('Cache-Control', 'no-store');
    return response;
}
