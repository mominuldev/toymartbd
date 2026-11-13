import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/i18n/I18nProvider';

export type Device = 'desktop' | 'mobile';

/** The storefront layouts the canvas shows: the 1440 design at its 1280 content width, and 390. */
const FRAME_WIDTH: Record<Device, number> = { desktop: 1280, mobile: 390 };

type Props = {
    url: string | null | undefined;
    loading: boolean;
    device: Device;
    /** Changes whenever the draft changed; the frame reloads to show it. */
    revision: number;
};

/**
 * Figma B · 26 "Canvas" (241:44161): the live storefront on bg/canvas, framed like a page
 * (radius/md, e2). It is the real storefront in Next.js draft mode — an iframe of
 * `/api/preview/home?token=…` (the route checks the signed token with the API, server-side,
 * then shows the draft). Desktop renders the storefront at 1280 and scales it to the column;
 * mobile renders it at 390, unscaled. The frame reloads after every saved change.
 */
export function PreviewCanvas({ url, loading, device, revision }: Props) {
    const { t } = useI18n();
    const box = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });

    useEffect(() => {
        const el = box.current;
        if (!el || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => entry && setSize({ width: entry.contentRect.width, height: entry.contentRect.height }));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    const width = FRAME_WIDTH[device];
    const scale = device === 'desktop' && size.width > 0 ? Math.min(1, size.width / width) : 1;
    const height = size.height > 0 ? size.height / scale : 900;

    return (
        <div ref={box} className="relative h-full min-w-0 flex-1 overflow-hidden bg-canvas p-4 max-lg:hidden">
            {url ? (
                <div className="mx-auto overflow-hidden rounded-md bg-surface shadow-e2" style={{ width: width * scale, height: height * scale }}>
                    <iframe
                        key={`${url}#${revision}`}
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
