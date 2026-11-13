import type { CmsPage, CmsPageInput } from './api';
import { fromDhakaInput, toDhakaInput } from '../shared/dhaka';

export type FaqRow = { question: string; question_bn: string; answer: string; answer_bn: string };

export type PageForm = {
    title: string;
    title_bn: string;
    slug: string;
    content: string;
    content_bn: string;
    template: 'default' | 'sidebar' | 'faq';
    status: 'draft' | 'published' | 'archived';
    /** datetime-local value, Dhaka time. */
    published_at: string;
    faq: FaqRow[];
    seo: {
        title: string;
        title_bn: string;
        description: string;
        description_bn: string;
        canonical_url: string;
        robots: 'index,follow' | 'noindex,follow' | 'index,nofollow' | 'noindex,nofollow';
        sitemap_exclude: boolean;
        og_title: string;
        og_description: string;
        og_image: string;
        twitter_card: 'summary_large_image' | 'summary';
        focus_keyword: string;
    };
    acknowledge_slug_redirect: boolean;
};

const robots = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as const;

export function toPageForm(page?: CmsPage): PageForm {
    const seo = page?.seo;
    return {
        title: page?.title ?? '',
        title_bn: page?.title_bn ?? '',
        slug: page?.slug ?? '',
        content: page?.content ?? '',
        content_bn: page?.content_bn ?? '',
        template: page?.template ?? 'default',
        status: page?.status ?? 'draft',
        published_at: toDhakaInput(page?.published_at),
        faq: (page?.faq_items ?? []).map((f) => ({ question: f.question, question_bn: f.question_bn ?? '', answer: f.answer, answer_bn: f.answer_bn ?? '' })),
        seo: {
            title: seo?.title ?? '',
            title_bn: seo?.title_bn ?? '',
            description: seo?.description ?? '',
            description_bn: seo?.description_bn ?? '',
            canonical_url: seo?.canonical_url ?? '',
            robots: (robots as readonly string[]).includes(seo?.robots ?? '') ? (seo!.robots as PageForm['seo']['robots']) : 'index,follow',
            sitemap_exclude: seo?.sitemap_exclude ?? false,
            og_title: seo?.og_title ?? '',
            og_description: seo?.og_description ?? '',
            og_image: seo?.og_image ?? '',
            twitter_card: seo?.twitter_card === 'summary' ? 'summary' : 'summary_large_image',
            focus_keyword: seo?.focus_keyword ?? '',
        },
        acknowledge_slug_redirect: false,
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/** An empty editor emits `<p></p>`: no content. */
function html(value: string): string | null {
    const stripped = value.replace(/<p>\s*<\/p>/g, '').trim();
    return stripped === '' ? null : value;
}

/**
 * The PUT/POST body. The schedule goes out as Dhaka time with +06:00; the FAQ list only for the
 * FAQ template; `acknowledge_slug_redirect` only when the slug of a live page actually changes.
 */
export function toPagePayload(form: PageForm, page?: CmsPage): CmsPageInput {
    const slug = orNull(form.slug);
    const slugChanged = page !== undefined && slug !== null && slug !== page.slug;
    const payload: CmsPageInput = {
        title: form.title.trim(),
        title_bn: orNull(form.title_bn),
        slug,
        content: html(form.content),
        content_bn: html(form.content_bn),
        template: form.template,
        status: form.status,
        published_at: fromDhakaInput(form.published_at),
        faq_items:
            form.template === 'faq'
                ? form.faq.map((f) => ({ question: f.question.trim(), question_bn: orNull(f.question_bn), answer: f.answer.trim(), answer_bn: orNull(f.answer_bn) }))
                : null,
        seo: {
            title: orNull(form.seo.title),
            title_bn: orNull(form.seo.title_bn),
            description: orNull(form.seo.description),
            description_bn: orNull(form.seo.description_bn),
            canonical_url: orNull(form.seo.canonical_url),
            robots: form.seo.robots,
            sitemap_exclude: form.seo.sitemap_exclude,
            og_title: orNull(form.seo.og_title),
            og_description: orNull(form.seo.og_description),
            og_image: orNull(form.seo.og_image),
            twitter_card: form.seo.twitter_card,
            focus_keyword: orNull(form.seo.focus_keyword),
        },
    };
    if (slugChanged && page?.state === 'live' && form.acknowledge_slug_redirect) payload.acknowledge_slug_redirect = true;
    return payload;
}
