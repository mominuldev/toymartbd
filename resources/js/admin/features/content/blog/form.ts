import { fromDhakaInput, toDhakaInput } from '../shared/dhaka';
import type { AgeBand, BlogPost, BlogPostInput, PostStatus, PostType } from './api';

// The post editor's form state and its mapping to the API document (a save replaces it).
// Money stays a decimal string (rule 1: the client never computes it); reading time comes back
// from the server; the schedule is Dhaka time (datetime-local) sent with +06:00.

export type ProductLink = {
    product_id: number;
    name: string;
    name_bn: string | null;
    /** False when the storefront hides it (out of stock, archived, unpublished). */
    visible: boolean;
    note: string;
    note_bn: string;
};

export type SectionForm = {
    uid: string;
    heading: string;
    heading_bn: string;
    body: string;
    body_bn: string;
    age_band: AgeBand | '';
    budget_min: string;
    budget_max: string;
    picks: ProductLink[];
};

export type CoverValue = { media_id: number; url: string | null; alt: string | null; alt_bn: string | null };

export type PostForm = {
    type: PostType;
    title: string;
    title_bn: string;
    slug: string;
    excerpt: string;
    excerpt_bn: string;
    body: string;
    body_bn: string;
    cover: CoverValue | null;
    author_id: string;
    category_id: string;
    tag_ids: number[];
    status: PostStatus;
    /** datetime-local value, Dhaka time. */
    publish_at: string;
    is_featured: boolean;
    guide_age_band: AgeBand | '';
    guide_budget_min: string;
    guide_budget_max: string;
    sections: SectionForm[];
    related: ProductLink[];
    seo_title: string;
    seo_title_bn: string;
};

let uid = 0;
export const nextUid = (): string => `s${++uid}`;

export function emptySection(): SectionForm {
    return { uid: nextUid(), heading: '', heading_bn: '', body: '', body_bn: '', age_band: '', budget_min: '', budget_max: '', picks: [] };
}

const band = (value: string | null | undefined): AgeBand | '' => (value ? (value as AgeBand) : '');

export function toPostForm(post?: BlogPost): PostForm {
    return {
        type: post?.type ?? 'article',
        title: post?.title ?? '',
        title_bn: post?.title_bn ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        excerpt_bn: post?.excerpt_bn ?? '',
        body: post?.body ?? '',
        body_bn: post?.body_bn ?? '',
        cover: post?.cover ? { media_id: post.cover.media_id, url: post.cover.url, alt: post.cover.alt, alt_bn: post.cover.alt_bn } : null,
        author_id: post?.author_id ? String(post.author_id) : '',
        category_id: post?.category_id ? String(post.category_id) : '',
        tag_ids: post?.tags.map((tag) => tag.id) ?? [],
        status: post?.status ?? 'draft',
        publish_at: post?.status === 'scheduled' ? toDhakaInput(post.published_at) : '',
        is_featured: post?.is_featured ?? false,
        guide_age_band: band(post?.guide_age_band),
        guide_budget_min: post?.guide_budget_min ?? '',
        guide_budget_max: post?.guide_budget_max ?? '',
        sections: (post?.sections ?? []).map((s) => ({
            uid: nextUid(),
            heading: s.heading,
            heading_bn: s.heading_bn ?? '',
            body: s.body ?? '',
            body_bn: s.body_bn ?? '',
            age_band: band(s.age_band),
            budget_min: s.budget_min ?? '',
            budget_max: s.budget_max ?? '',
            picks: s.picks.map((p) => ({ product_id: p.product_id, name: p.name, name_bn: p.name_bn, visible: p.visible, note: p.note ?? '', note_bn: p.note_bn ?? '' })),
        })),
        related: (post?.related_products ?? []).map((p) => ({ product_id: p.product_id, name: p.name, name_bn: p.name_bn, visible: p.visible, note: '', note_bn: '' })),
        seo_title: post?.seo.title ?? '',
        seo_title_bn: post?.seo.title_bn ?? '',
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());
const money = (value: string): string | null => {
    const v = value.trim().replace(/,/g, '');
    return v === '' ? null : v;
};
const id = (value: string): number | null => (value === '' ? null : Number(value));

/** An empty editor emits `<p></p>`: no content. */
export function html(value: string): string | null {
    const stripped = value.replace(/<p>\s*<\/p>/g, '').trim();
    return stripped === '' ? null : value;
}

/** The POST/PUT document; `status` is the action the editor chose (save draft, schedule, publish). */
export function toPostPayload(form: PostForm, status: PostStatus = form.status): BlogPostInput {
    const guide = form.type === 'buying_guide';
    return {
        type: form.type,
        title: form.title.trim(),
        title_bn: orNull(form.title_bn),
        slug: orNull(form.slug),
        excerpt: orNull(form.excerpt),
        excerpt_bn: orNull(form.excerpt_bn),
        body: html(form.body),
        body_bn: html(form.body_bn),
        cover_media_id: form.cover?.media_id ?? null,
        author_id: id(form.author_id),
        category_id: id(form.category_id),
        tag_ids: form.tag_ids,
        status,
        publish_at: status === 'scheduled' ? fromDhakaInput(form.publish_at) : null,
        is_featured: form.is_featured,
        guide_age_band: guide && form.guide_age_band !== '' ? form.guide_age_band : null,
        guide_budget_min: guide ? money(form.guide_budget_min) : null,
        guide_budget_max: guide ? money(form.guide_budget_max) : null,
        sections: guide
            ? form.sections.map((s) => ({
                  heading: s.heading.trim(),
                  heading_bn: orNull(s.heading_bn),
                  body: html(s.body),
                  body_bn: html(s.body_bn),
                  age_band: s.age_band === '' ? null : s.age_band,
                  budget_min: money(s.budget_min),
                  budget_max: money(s.budget_max),
                  picks: s.picks.map((p) => ({ product_id: p.product_id, note: orNull(p.note), note_bn: orNull(p.note_bn) })),
              }))
            : [],
        related_product_ids: form.related.map((p) => p.product_id),
        seo: { title: orNull(form.seo_title), title_bn: orNull(form.seo_title_bn) },
    };
}

/** Which languages are ready, for the read-only Language row (Figma 241:46054). */
export function languageState(form: Pick<PostForm, 'title_bn' | 'body' | 'body_bn'>): 'both' | 'en' {
    return form.title_bn.trim() !== '' && html(form.body_bn) !== null ? 'both' : 'en';
}
