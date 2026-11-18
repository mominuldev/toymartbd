import type { CmsPage, CmsPageInput } from './api';
import { fromDhakaInput, toDhakaInput } from '../shared/dhaka';

export type PageForm = {
    title: string;
    title_bn: string;
    slug: string;
    /**
     * The page body before sections (owner decision 2026-11-16) — no longer edited here, but the
     * PUT replaces the whole document, so the stored values go back untouched.
     */
    content: string | null;
    content_bn: string | null;
    faq_items: CmsPageInput['faq_items'];
    template: 'default' | 'sidebar' | 'faq';
    status: 'draft' | 'published' | 'archived';
    /** datetime-local value, Dhaka time. */
    published_at: string;
    acknowledge_slug_redirect: boolean;
};

export function toPageForm(page?: CmsPage): PageForm {
    return {
        title: page?.title ?? '',
        title_bn: page?.title_bn ?? '',
        slug: page?.slug ?? '',
        content: page?.content ?? null,
        content_bn: page?.content_bn ?? null,
        faq_items: page?.faq_items ? page.faq_items.map((f) => ({ question: f.question, question_bn: f.question_bn ?? null, answer: f.answer, answer_bn: f.answer_bn ?? null })) : null,
        template: page?.template ?? 'default',
        status: page?.status ?? 'draft',
        published_at: toDhakaInput(page?.published_at),
        acknowledge_slug_redirect: false,
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/**
 * The PUT/POST body. The schedule goes out as Dhaka time with +06:00; the legacy body and FAQ
 * list exactly as stored (sections replaced them, but a PUT must not wipe them);
 * `acknowledge_slug_redirect` only when the slug of a live page actually changes.
 */
/** SEO is not part of the page form: the SEO panel below it saves `seo_meta` on its own, so the PUT leaves it untouched. */
export function toPagePayload(form: PageForm, page?: CmsPage): CmsPageInput {
    const slug = orNull(form.slug);
    const slugChanged = page !== undefined && slug !== null && slug !== page.slug;
    const payload: CmsPageInput = {
        title: form.title.trim(),
        title_bn: orNull(form.title_bn),
        slug,
        content: form.content,
        content_bn: form.content_bn,
        template: form.template,
        status: form.status,
        published_at: fromDhakaInput(form.published_at),
        faq_items: form.faq_items,
    };
    if (slugChanged && page?.state === 'live' && form.acknowledge_slug_redirect) payload.acknowledge_slug_redirect = true;
    return payload;
}
