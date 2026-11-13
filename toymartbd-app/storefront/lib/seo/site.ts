/** The storefront's public origin (`SITE_URL`); every absolute URL in metadata, JSON-LD and robots.txt. */
export function siteUrl(): URL {
    return new URL(process.env.SITE_URL ?? 'http://localhost:3000');
}
