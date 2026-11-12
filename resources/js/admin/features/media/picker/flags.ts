import type { Media } from '../api';

/**
 * Per-context fit checks the picker shows on a tile, computed from the width and height
 * the API returns. Display only: the library has no minimum and no ratio rule
 * (docs/api/media-library.md decision 5) — the server stays the authority on what it takes.
 */
export type FitFlags = { crop: boolean; small: boolean };

export function fitFlags(media: Pick<Media, 'kind' | 'width' | 'height'>, ratio: readonly [number, number] | null | undefined, minSize: number | null | undefined): FitFlags {
    if (media.kind !== 'image' || !media.width || !media.height) return { crop: false, small: false };
    const crop = ratio ? media.width * ratio[1] !== media.height * ratio[0] : false;
    const small = minSize ? media.width < minSize || media.height < minSize : false;
    return { crop, small };
}

/** True when the image lacks its Bangla alt text (the publish blocker the warning bar names). */
export function lacksBanglaAlt(media: Pick<Media, 'kind' | 'alt_status'>): boolean {
    return media.kind === 'image' && (media.alt_status === 'missing_bn' || media.alt_status === 'missing');
}

/** True when either alt text is missing (the Upload tab's "need alt text in English and বাংলা"). */
export function lacksAnyAlt(media: Pick<Media, 'kind' | 'alt_status'>): boolean {
    return media.kind === 'image' && media.alt_status !== 'complete';
}

/**
 * Toggle one file in an ordered pick list. Multi-select appends (its number is its
 * position, 1 = primary) up to `max`; single-select replaces. Returns null when the
 * cap refuses the pick, so the caller can say why.
 */
export function togglePick<T extends { id: number }>(picked: T[], item: T, multiple: boolean, max: number): T[] | null {
    if (picked.some((p) => p.id === item.id)) return picked.filter((p) => p.id !== item.id);
    if (!multiple) return [item];
    if (picked.length >= max) return null;
    return [...picked, item];
}
