'use client';

import { useEffect } from 'react';

interface Props {
    rootId: string;
}

// Progressive enhancement for ProductGallery (storefront spec §A9). The server markup already
// swipes (390) and pages by fragment links (1440); this adds:
// - the active dot, counter and thumb, tracked from the track's scroll position;
// - thumb clicks that scroll in place instead of jumping the page to the fragment;
// - ArrowLeft / ArrowRight on the focused stage;
// - the 2× magnifier inside the stage bounds on hover (desktop pointers only; never a side panel).
export function GalleryEnhancer({ rootId }: Props) {
    useEffect(() => {
        const root = document.getElementById(rootId);
        const track = root?.querySelector<HTMLElement>('[data-gallery-track]');
        if (!root || !track) return;
        const slides = Array.from(track.children) as HTMLElement[];
        const dots = Array.from(root.querySelectorAll<HTMLElement>('[data-gallery-dot]'));
        const thumbs = Array.from(root.querySelectorAll<HTMLAnchorElement>('[data-gallery-thumb]'));
        const counter = root.querySelector<HTMLElement>('[data-gallery-counter]');
        const lens = root.querySelector<HTMLElement>('[data-gallery-lens]');
        const total = slides.length;
        let index = 0;

        const show = (next: number) => {
            index = next;
            dots.forEach((dot, i) => (i === next ? dot.setAttribute('data-active', '') : dot.removeAttribute('data-active')));
            thumbs.forEach((thumb, i) => thumb.setAttribute('aria-selected', String(i === next)));
            if (counter) counter.textContent = counter.textContent?.replace(/^\d+/, String(next + 1)) ?? null;
        };
        const go = (next: number) => {
            const clamped = Math.max(0, Math.min(total - 1, next));
            track.scrollTo({ left: clamped * track.clientWidth, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            show(clamped);
        };

        const onScroll = () => {
            const next = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
            if (next !== index) show(Math.max(0, Math.min(total - 1, next)));
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'ArrowRight') go(index + 1);
            else if (event.key === 'ArrowLeft') go(index - 1);
            else return;
            event.preventDefault();
        };
        const onThumb = (event: MouseEvent) => {
            const thumb = (event.currentTarget as HTMLElement).dataset.galleryThumb;
            if (thumb === undefined) return;
            event.preventDefault();
            go(Number(thumb));
        };

        const desktopHover = matchMedia('(hover: hover) and (min-width: 64rem)');
        const onMove = (event: PointerEvent) => {
            if (!lens || !desktopHover.matches || event.pointerType !== 'mouse') return;
            const img = slides[index]?.querySelector<HTMLImageElement>('img');
            const src = img?.currentSrc || img?.dataset.zoom;
            if (!src) return;
            const box = track.getBoundingClientRect();
            const x = ((event.clientX - box.left) / box.width) * 100;
            const y = ((event.clientY - box.top) / box.height) * 100;
            lens.style.backgroundImage = `url("${src}")`;
            lens.style.backgroundPosition = `${x}% ${y}%`;
            lens.style.display = 'block';
            track.style.cursor = 'zoom-in';
        };
        const onLeave = () => {
            if (lens) lens.style.display = '';
        };

        track.addEventListener('scroll', onScroll, { passive: true });
        track.addEventListener('keydown', onKey);
        track.addEventListener('pointermove', onMove);
        track.addEventListener('pointerleave', onLeave);
        thumbs.forEach((thumb) => thumb.addEventListener('click', onThumb));
        return () => {
            track.removeEventListener('scroll', onScroll);
            track.removeEventListener('keydown', onKey);
            track.removeEventListener('pointermove', onMove);
            track.removeEventListener('pointerleave', onLeave);
            thumbs.forEach((thumb) => thumb.removeEventListener('click', onThumb));
        };
    }, [rootId]);

    return null;
}
