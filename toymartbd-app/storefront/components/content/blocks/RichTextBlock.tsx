import { descriptionContext, sanitizeDescription } from '@/lib/product/description';
import type { Locale } from '@/lib/i18n';

// Rich text (Phase 7). The API sanitised the HTML on save and again on read; the storefront filters
// it once more with the product-description allowlist before it reaches dangerouslySetInnerHTML —
// the same treatment and the same token classes as CMS pages.

const bodyClass = [
    'mx-auto flex max-w-prose flex-col gap-5 t-body-lg text-fg-primary',
    '[&_h2]:t-h2 [&_h3]:t-h4',
    '[&_ul]:list-disc [&_ul]:ps-6 [&_ol]:list-decimal [&_ol]:ps-6 [&_li]:mt-1',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

export function RichTextBlock({ html, locale }: { html: string; locale: Locale }) {
    const clean = sanitizeDescription(html, descriptionContext(process.env.API_URL, []));
    if (!clean) return null;
    return <div className={`${bodyClass} ${locale === 'bn' ? 'lang-bn' : ''}`} dangerouslySetInnerHTML={{ __html: clean }} />;
}
