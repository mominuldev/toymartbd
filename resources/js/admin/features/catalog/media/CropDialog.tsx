import { useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { useI18n } from '@/i18n/I18nProvider';
import type { CropRect, ProductImage } from './api';

const ASPECTS = { '1:1': 1, '4:5': 4 / 5, '16:9': 16 / 9 } as const;
type Aspect = keyof typeof ASPECTS;

const clamp = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value));

/**
 * The largest box of `aspect` that fits the image, shrunk by `zoom`, centred on
 * (cx, cy) and kept inside it — in the image's own pixels (what the API expects).
 */
export function cropRect(width: number, height: number, aspect: number, zoom: number, cx: number, cy: number): CropRect {
    const boxWidth = Math.min(width, height * aspect) / zoom;
    const boxHeight = boxWidth / aspect;
    return {
        x: Math.round(clamp(cx * width - boxWidth / 2, 0, width - boxWidth)),
        y: Math.round(clamp(cy * height - boxHeight / 2, 0, height - boxHeight)),
        width: Math.round(boxWidth),
        height: Math.round(boxHeight),
    };
}

/**
 * Crop modal (spec A8: 1:1 locked by default, 1:1 / 4:5 / 16:9, 3×3 grid). The box
 * moves by drag or arrow keys and resizes with the zoom slider; the server crops the
 * stored master and regenerates every rendition.
 */
export function CropDialog({
    image,
    pending,
    onApply,
    onClose,
}: {
    image: ProductImage;
    pending: boolean;
    onApply: (rect: CropRect) => void;
    onClose: () => void;
}) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const frame = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const [aspect, setAspect] = useState<Aspect>('1:1');
    const [zoom, setZoom] = useState(1);
    const [center, setCenter] = useState({ x: 0.5, y: 0.5 });
    const drag = useRef<{ x: number; y: number } | null>(null);

    const width = image.width ?? 1;
    const height = image.height ?? 1;
    const rect = cropRect(width, height, ASPECTS[aspect], zoom, center.x, center.y);

    useEffect(() => {
        const dialog = ref.current;
        if (dialog && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
    }, []);

    function nudge(dx: number, dy: number) {
        setCenter((c) => ({ x: clamp(c.x + dx, 0, 1), y: clamp(c.y + dy, 0, 1) }));
    }

    function onKey(event: KeyboardEvent) {
        const step = event.shiftKey ? 0.1 : 0.02;
        const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        const delta = moves[event.key];
        if (delta) {
            event.preventDefault();
            nudge(delta[0], delta[1]);
        }
    }

    function onPointerMove(event: PointerEvent) {
        const box = frame.current?.getBoundingClientRect();
        if (!drag.current || !box || box.width === 0) return;
        nudge((event.clientX - drag.current.x) / box.width, (event.clientY - drag.current.y) / box.height);
        drag.current = { x: event.clientX, y: event.clientY };
    }

    const percent = (value: number, of: number) => `${(value / of) * 100}%`;

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,42.5rem)] rounded-xl border border-line-default bg-surface-raised p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <div className="flex flex-col gap-4 p-6">
                <div className="flex items-center gap-3">
                    <h2 id={titleId} className="min-w-0 flex-1 t-admin-section">
                        {t('images.cropTitle')}
                    </h2>
                    <Select
                        size="sm"
                        aria-label={t('images.cropAspect')}
                        value={aspect}
                        onChange={(event) => setAspect(event.target.value as Aspect)}
                        className="w-28"
                    >
                        {Object.keys(ASPECTS).map((key) => (
                            <option key={key} value={key}>
                                {key}
                            </option>
                        ))}
                    </Select>
                </div>

                <div className="grid place-items-center rounded-md bg-subtle p-2">
                    <div ref={frame} className="relative max-h-[60vh] w-full overflow-hidden" style={{ aspectRatio: `${width} / ${height}` }}>
                        {image.urls.large && <img src={image.urls.large} alt="" draggable={false} className="size-full object-contain select-none" />}
                        <div
                            role="slider"
                            tabIndex={0}
                            aria-label={t('images.cropArea')}
                            aria-valuetext={`${rect.x}, ${rect.y} · ${rect.width}×${rect.height}`}
                            onKeyDown={onKey}
                            onPointerDown={(event) => {
                                event.currentTarget.setPointerCapture?.(event.pointerId);
                                drag.current = { x: event.clientX, y: event.clientY };
                            }}
                            onPointerMove={onPointerMove}
                            onPointerUp={() => (drag.current = null)}
                            className="absolute grid cursor-move grid-cols-3 grid-rows-3 border-2 border-line-focus shadow-[0_0_0_100vmax_var(--color-overlay)]"
                            style={{ left: percent(rect.x, width), top: percent(rect.y, height), width: percent(rect.width, width), height: percent(rect.height, height) }}
                        >
                            {Array.from({ length: 9 }, (_, i) => (
                                <span key={i} className="border border-line-default/40" />
                            ))}
                        </div>
                    </div>
                </div>

                <label className="flex items-center gap-3 t-admin-body-strong">
                    {t('images.cropZoom')}
                    <input
                        type="range"
                        min={1}
                        max={4}
                        step={0.05}
                        value={zoom}
                        onChange={(event) => setZoom(Number(event.target.value))}
                        className="flex-1 accent-brand"
                    />
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button disabled={pending} onClick={() => onApply(rect)}>
                        {t('images.cropApply')}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
