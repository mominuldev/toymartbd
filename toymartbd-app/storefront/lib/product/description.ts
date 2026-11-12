import sanitizeHtml from 'sanitize-html';

/**
 * The long product description arrives as HTML the API has already sanitised
 * (App\Domain\Catalog\Services\ProductDescription). The storefront filters it again against
 * the same allowlist before it reaches `dangerouslySetInnerHTML` — defence in depth, so a
 * compromised or misconfigured API can never put script on a product page.
 *
 * - p, h2, h3, strong, em, ul, ol, li, blockquote, br;
 * - a[href] with http, https or mailto; links off the site get rel="noopener nofollow";
 * - img[src, alt, width, height] only from our own media: a `/m/…` URL on the API's host,
 *   or a file on a host the product's own images are served from (the media disk / CDN).
 *
 * Text with no tags at all (written before the editor existed) becomes paragraphs.
 */

const SITE_HOSTS = ['toymartbd.com', 'www.toymartbd.com'];

export interface DescriptionContext {
    /** Origin of the API (`API_URL`); a relative `/m/…` image resolves against it. */
    apiOrigin: string | null;
    /** Origins the product's gallery images come from — the media disk or CDN. */
    mediaOrigins: string[];
}

function originOf(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        return new URL(url).origin;
    } catch {
        return null;
    }
}

export function descriptionContext(apiUrl: string | undefined, imageUrls: Array<string | null | undefined>): DescriptionContext {
    const mediaOrigins = [...new Set(imageUrls.map(originOf).filter((o): o is string => o !== null))];
    return { apiOrigin: originOf(apiUrl), mediaOrigins };
}

const escapeText = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export function isHtml(text: string): boolean {
    return /<\/?[a-z][a-z0-9]*(?:\s[^>]*)?\/?>/i.test(text);
}

/** Plain text → paragraphs split on blank lines; single line breaks become <br>. */
export function plainTextToHtml(text: string): string {
    return text
        .replace(/\r\n?/g, '\n')
        .trim()
        .split(/\n[ \t]*\n+/)
        .filter((p) => p.trim() !== '')
        .map((p) => `<p>${p.trim().split('\n').map((line) => escapeText(line.trim())).join('<br />')}</p>`)
        .join('');
}

/** The image URL to render, or null when it is not one of our media files. */
export function ownMediaSrc(src: string, context: DescriptionContext): string | null {
    if (src.startsWith('/m/') && !src.includes('..')) {
        return context.apiOrigin ? `${context.apiOrigin}${src}` : null;
    }
    let url: URL;
    try {
        url = new URL(src);
    } catch {
        return null;
    }
    if ((url.protocol !== 'https:' && url.protocol !== 'http:') || url.username || url.password || src.includes('..')) return null;
    if (context.apiOrigin && url.origin === context.apiOrigin && url.pathname.startsWith('/m/')) return url.href;
    if (context.mediaOrigins.includes(url.origin)) return url.href;
    return null;
}

function isExternal(href: string, context: DescriptionContext): boolean {
    let url: URL;
    try {
        url = new URL(href);
    } catch {
        return false;
    }
    if (url.protocol === 'mailto:') return false;
    const host = url.hostname.toLowerCase();
    return !SITE_HOSTS.includes(host) && url.origin !== context.apiOrigin;
}

/** Sanitised HTML for the description, or null when nothing readable is left. */
export function sanitizeDescription(input: string | null | undefined, context: DescriptionContext): string | null {
    if (!input || input.trim() === '') return null;
    const html = isHtml(input) ? input : plainTextToHtml(input);

    const clean = sanitizeHtml(html, {
        allowedTags: ['p', 'h2', 'h3', 'strong', 'em', 'ul', 'ol', 'li', 'blockquote', 'br', 'a', 'img'],
        allowedAttributes: { a: ['href', 'rel'], img: ['src', 'alt', 'width', 'height'] },
        allowedSchemes: ['http', 'https', 'mailto'],
        allowedSchemesByTag: { img: ['http', 'https'] },
        allowProtocolRelative: false,
        disallowedTagsMode: 'discard',
        nonTextTags: ['script', 'style', 'textarea', 'option', 'noscript', 'iframe', 'template', 'svg', 'math', 'title', 'head'],
        exclusiveFilter: (frame) => frame.tag === 'img' && ownMediaSrc(frame.attribs.src ?? '', context) === null,
        transformTags: {
            a: (tagName, attribs) => {
                // Only the schemes the allowlist keeps; anything else loses its href (and gets no rel).
                const href = /^(https?:\/\/|mailto:)/i.test((attribs.href ?? '').trim()) ? (attribs.href ?? '').trim() : '';
                const attrs: Record<string, string> = href ? { href } : {};
                if (href && isExternal(href, context)) attrs.rel = 'noopener nofollow';
                return { tagName, attribs: attrs };
            },
            img: (tagName, attribs) => {
                const src = ownMediaSrc(attribs.src ?? '', context);
                const attrs: Record<string, string> = { src: src ?? '', alt: attribs.alt ?? '' };
                for (const dimension of ['width', 'height'] as const) {
                    const value = attribs[dimension];
                    if (value && /^\d{1,5}$/.test(value) && Number(value) > 0) attrs[dimension] = value;
                }
                return { tagName, attribs: attrs };
            },
        },
    }).trim();

    const hasText = clean.replace(/<[^>]*>/g, '').replace(/&nbsp;|\s/g, '') !== '';
    return hasText || /<img\b/i.test(clean) ? clean : null;
}
