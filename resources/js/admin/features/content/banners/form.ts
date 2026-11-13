import type { Banner, BannerInput, BannerPlacement } from './api';
import { endsBeforeStart, fromDhakaInput, toDhakaInput } from '../shared/dhaka';
import { isPageLink, isValidLink } from '../shared/links';
import type { PickedImage } from '../shared/MediaField';

export type BannerForm = {
    placement: BannerPlacement;
    name: string;
    title: string;
    title_bn: string;
    body: string;
    body_bn: string;
    image: PickedImage | null;
    link_url: string;
    link_label: string;
    link_label_bn: string;
    category_id: string;
    priority: string;
    is_active: boolean;
    /** datetime-local values, Dhaka time. */
    starts_at: string;
    ends_at: string;
};

export function allowsImage(placement: BannerPlacement): boolean {
    return placement === 'category_page' || placement === 'popup';
}

export function toBannerForm(banner?: Banner): BannerForm {
    return {
        placement: banner?.placement ?? 'announcement_bar',
        name: banner?.name ?? '',
        title: banner?.title ?? '',
        title_bn: banner?.title_bn ?? '',
        body: banner?.body ?? '',
        body_bn: banner?.body_bn ?? '',
        image: banner?.media_id ? { media_id: banner.media_id, url: banner.image?.url ?? null, alt: banner.image?.alt ?? null } : null,
        link_url: banner?.link_url ?? '',
        link_label: banner?.link_label ?? '',
        link_label_bn: banner?.link_label_bn ?? '',
        category_id: banner?.category_id ? String(banner.category_id) : '',
        priority: String(banner?.priority ?? 0),
        is_active: banner?.is_active ?? true,
        starts_at: toDhakaInput(banner?.starts_at),
        ends_at: toDhakaInput(banner?.ends_at),
    };
}

const orNull = (value: string): string | null => (value.trim() === '' ? null : value.trim());

/** Placement-specific fields are dropped rather than sent: an info strip never carries an image. */
export function toBannerPayload(form: BannerForm): BannerInput {
    return {
        placement: form.placement,
        name: form.name.trim(),
        title: orNull(form.title),
        title_bn: orNull(form.title_bn),
        body: orNull(form.body),
        body_bn: orNull(form.body_bn),
        media_id: allowsImage(form.placement) ? (form.image?.media_id ?? null) : null,
        link_url: orNull(form.link_url),
        link_label: orNull(form.link_label),
        link_label_bn: orNull(form.link_label_bn),
        category_id: form.placement === 'category_page' && form.category_id !== '' ? Number(form.category_id) : null,
        priority: Number.parseInt(form.priority, 10) || 0,
        is_active: form.is_active,
        starts_at: fromDhakaInput(form.starts_at),
        ends_at: fromDhakaInput(form.ends_at),
    };
}

export type BannerRuleError = 'content' | 'ends' | 'infoLink' | 'link';

/** The placement rules the API enforces, checked before sending. */
export function bannerRuleErrors(form: BannerForm): Partial<Record<'title' | 'ends_at' | 'link_url', BannerRuleError>> {
    const out: Partial<Record<'title' | 'ends_at' | 'link_url', BannerRuleError>> = {};
    const payload = toBannerPayload(form);
    if (!payload.title && !payload.body && !payload.media_id) out.title = 'content';
    if (endsBeforeStart(form.starts_at, form.ends_at)) out.ends_at = 'ends';
    const link = form.link_url.trim();
    if (link !== '') {
        if (form.placement === 'info_strip' && !isPageLink(link)) out.link_url = 'infoLink';
        else if (!isValidLink(link)) out.link_url = 'link';
    }
    return out;
}
