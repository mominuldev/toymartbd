import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/i18n/I18nProvider';

export type Device = 'desktop' | 'mobile';

/** The storefront layouts the canvas shows: the 1440 design at its 1280 content width, and 390. */
const FRAME_WIDTH: Record<Device, number> = { desktop: 1280, mobile: 390 };

/** Mirrors toymartbd-app/storefront/lib/content/preview-bridge.ts. */
export const BRIDGE_SOURCE = 'tm-home-builder';

type Props = {
    url: string | null | undefined;
    loading: boolean;
    device: Device;
    /** Changes whenever the draft changed; the frame re-renders in place to show it. */
    revision: number;
    /** The section open in the settings panel, by uuid (the id the storefront payload uses). */
    selectedId: string | null;
    /** Section names by block uuid, shown on the canvas outlines. */
    labels: Record<string, string>;
    /** A section was clicked on the canvas (its uuid). */
    onSelect: (uuid: string) => void;
};

function originOf(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        return new URL(url).origin;
    } catch {
        return null;
    }
}

/** A message from the storefront frame: `ready`, or `select` with a block uuid. */
export function parseFromCanvas(data: unknown): { type: 'ready' } | { type: 'select'; id: string } | null {
    if (typeof data !== 'object' || data === null) return null;
    const m = data as Record<string, unknown>;
    if (m.source !== BRIDGE_SOURCE) return null;
    if (m.type === 'ready') return { type: 'ready' };
    if (m.type === 'select' && typeof m.id === 'string' && /^[A-Za-z0-9-]{1,64}$/.test(m.id)) return { type: 'select', id: m.id };
    return null;
}

/**
 * Figma B · 26 "Canvas" (241:44161): the live storefront on bg/canvas, framed like a page
 * (radius/md, e2). It is the real storefront in Next.js draft mode — an iframe of
 * `/api/preview/home?token=…` (the route checks the signed token with the API, server-side,
 * then shows the draft). Desktop renders the storefront at 1280 and scales it to the column;
 * mobile renders it at 390, unscaled.
 *
 * Live editor (Phase 8): the frame and the builder talk over postMessage, origin-checked both
 * ways. Hovering a section on the canvas outlines it with its name; clicking selects it here.
 * After each autosave the frame re-renders in place (router.refresh in the storefront) instead
 * of reloading, so the scroll position and the selection survive. Selecting a section in the
 * list scrolls the canvas to it.
 */
export function PreviewCanvas({ url, loading, device, revision, selectedId, labels, onSelect }: Props) {
    const { t } = useI18n();
    const box = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLIFrameElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const [ready, setReady] = useState(false);
    const origin = originOf(url);

    // The selection made on the canvas itself must not scroll the canvas.
    const fromCanvas = useRef<string | null>(null);
    const latest = useRef({ onSelect, selectedId, labels });
    latest.current = { onSelect, selectedId, labels };

    const post = (message: Record<string, unknown>) => {
        const target = frame.current?.contentWindow;
        if (target && origin) target.postMessage({ source: BRIDGE_SOURCE, ...message }, origin);
    };

    useEffect(() => {
        const el = box.current;
        if (!el || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => entry && setSize({ width: entry.contentRect.width, height: entry.contentRect.height }));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    // A new token URL loads a new document; wait for its bridge before talking to it.
    useEffect(() => setReady(false), [url]);

    useEffect(() => {
        if (!origin) return;
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== origin || event.source !== frame.current?.contentWindow) return;
            const message = parseFromCanvas(event.data);
            if (!message) return;
            if (message.type === 'ready') {
                setReady(true);
                const { labels: names, selectedId: id } = latest.current;
                post({ type: 'labels', labels: names });
                post({ type: 'select', id, scroll: true });
            } else {
                fromCanvas.current = message.id;
                latest.current.onSelect(message.id);
            }
        };
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
        // post() reads refs only, so the listener depends on the origin alone.
    }, [origin]);

    useEffect(() => {
        if (ready && revision > 0) post({ type: 'refresh' });
    }, [revision]);

    useEffect(() => {
        if (!ready) return;
        const clicked = fromCanvas.current === selectedId;
        fromCanvas.current = null;
        post({ type: 'select', id: selectedId, scroll: !clicked });
    }, [selectedId, ready]);

    const labelKey = JSON.stringify(labels);
    useEffect(() => {
        if (ready) post({ type: 'labels', labels });
    }, [labelKey, ready]);

    const width = FRAME_WIDTH[device];
    const scale = device === 'desktop' && size.width > 0 ? Math.min(1, size.width / width) : 1;
    const height = size.height > 0 ? size.height / scale : 900;

    return (
        <div ref={box} className="relative h-full min-w-0 flex-1 overflow-hidden bg-canvas p-4 max-lg:hidden">
            {url ? (
                <div className="mx-auto overflow-hidden rounded-md bg-surface shadow-e2" style={{ width: width * scale, height: height * scale }}>
                    <iframe
                        ref={frame}
                        key={url}
                        title={t('homeBuilder.canvas')}
                        src={url}
                        width={width}
                        height={height}
                        style={{ transform: `scale(${scale})`, transformOrigin: '0 0', border: 0 }}
                    />
                </div>
            ) : (
                <p className="grid h-full place-items-center t-admin-body text-fg-secondary">{loading ? t('homeBuilder.canvasLoading') : t('homeBuilder.canvasUnavailable')}</p>
            )}
        </div>
    );
}
