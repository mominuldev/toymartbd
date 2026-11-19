import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type KeyboardEvent,
    type PointerEvent,
    type ReactNode,
} from 'react';
import { CheckCircleIcon, InfoIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { IconButton } from './IconButton';

type Tone = 'success' | 'danger' | 'info';
type Toast = { id: number; tone: Tone; message: string };
type ToastApi = { notify: (message: string, tone?: Tone) => void };

const ToastContext = createContext<ToastApi | null>(null);
let nextId = 1;

const looks = {
    success: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    danger: { icon: WarningCircleIcon, tint: 'text-feedback-danger' },
    info: { icon: InfoIcon, tint: 'text-feedback-info' },
} as const;

/** Success and info leave on their own after this long; errors stay until closed. */
const AUTO_DISMISS_MS = 5000;
/** At most this many are kept; the oldest beyond it are already hidden behind the stack. */
const MAX_TOASTS = 5;
/** Collapsed: how far each card behind the front one peeks out below it. */
const PEEK = 10;
const VISIBLE_BEHIND = 2;
const GAP = 8;
/** Used until a card has been measured. */
const FALLBACK_HEIGHT = 52;

// Swipe-to-dismiss: a release dismisses past this distance, or on a quick flick (px/ms).
const DISMISS_DISTANCE = 100;
const DISMISS_VELOCITY = 0.11;
/** Movement before deciding whether the gesture is a horizontal swipe or a vertical scroll. */
const AXIS_LOCK = 6;
// Snap-back: a critically damped spring, so it never wobbles past rest.
const SPRING_STIFFNESS = 520;
const SPRING_DAMPING = 2 * Math.sqrt(SPRING_STIFFNESS);
// Dismiss flight: keeps the release speed (px/s), never slower than the minimum, and speeds up.
const FLIGHT_MIN_SPEED = 1400;
const FLIGHT_ACCEL = 4200;
const VELOCITY_WINDOW_MS = 80;
const EXIT_MS = 200;

type Drag = {
    pointerId: number;
    startX: number;
    startY: number;
    /** Where the card already was when grabbed (mid snap-back). */
    originX: number;
    dx: number;
    axis: 'x' | 'y' | null;
    samples: { t: number; x: number }[];
};

const opacityFor = (x: number) => Math.max(1 - Math.abs(x) / 240, 0.3);

/** Release velocity in px/ms from the last stretch of the gesture, not its average. */
function releaseVelocity(samples: { t: number; x: number }[]): number {
    const last = samples.at(-1);
    if (!last) return 0;
    let first = last;
    for (let i = samples.length - 1; i >= 0; i--) {
        const sample = samples[i];
        if (!sample || last.t - sample.t > VELOCITY_WINDOW_MS) break;
        first = sample;
    }
    const dt = last.t - first.t;
    return dt > 0 ? (last.x - first.x) / dt : 0;
}

/** Which way a released card leaves, or 0 to spring back. A flick back toward rest returns it. */
export function dismissDirection(dx: number, velocity: number): 1 | -1 | 0 {
    const flick = Math.abs(velocity) > DISMISS_VELOCITY && Math.sign(velocity) === Math.sign(dx);
    if (Math.abs(dx) < DISMISS_DISTANCE && !flick) return 0;
    return (dx === 0 ? velocity : dx) > 0 ? 1 : -1;
}

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;

type Timer = { handle: number | null; remaining: number; startedAt: number };

/**
 * Figma AdminToast 203:2315 — top-right (moved from the Figma bottom-right, owner 2026-11-19), 420 wide, surface-raised, radius/sm, e3, a border/default
 * hairline all round (no coloured left edge, owner 2026-11-16), 18px feedback-tinted icon, Admin/body text, ghost close.
 *
 * Several toasts stack: the newest in front, up to two older ones peeking out below it. Hovering or
 * focusing the stack fans it out, and pauses every auto-dismiss timer while it is open — a message
 * never disappears while someone is reading it. A card swipes away sideways (a quick flick is
 * enough), its close button or the Delete key throws it off, and focus stays in the stack.
 * One polite live region; success and info dismiss after 5s, errors persist until closed.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    /** Newest first. */
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [heights, setHeights] = useState<Record<number, number>>({});
    const [entered, setEntered] = useState<ReadonlySet<number>>(() => new Set());
    const [leaving, setLeaving] = useState<ReadonlySet<number>>(() => new Set());
    const [hovered, setHovered] = useState(false);
    const [focused, setFocused] = useState(false);
    const [dragging, setDragging] = useState(false);

    const listRef = useRef<HTMLOListElement>(null);
    const swipeRefs = useRef(new Map<number, HTMLDivElement>());
    const observer = useRef<ResizeObserver | null>(null);
    const drag = useRef<Drag | null>(null);
    const swipeX = useRef(new Map<number, number>());
    const frames = useRef(new Map<number, number>());
    const lastY = useRef(new Map<number, number>());
    /** Cards already on their way out, so a double click or a held key can't dismiss one twice. */
    const dismissed = useRef(new Set<number>());
    const timeouts = useRef(new Set<number>());
    // Auto-dismiss timers are paused while the stack is open, so they keep their remaining time.
    const timers = useRef(new Map<number, Timer>());
    const paused = useRef(false);
    const dismissRef = useRef<(id: number, direction: 1 | -1, velocity?: number) => void>(() => {});

    const later = useCallback((fn: () => void, ms: number) => {
        const handle = window.setTimeout(() => {
            timeouts.current.delete(handle);
            fn();
        }, ms);
        timeouts.current.add(handle);
        return handle;
    }, []);

    // Everything still scheduled is cancelled on unmount: a timer that outlives the provider would
    // set state on an unmounted tree (and, in tests, after the DOM is torn down).
    useEffect(() => {
        const pending = timeouts.current;
        const running = frames.current;
        const autos = timers.current;
        return () => {
            pending.forEach((handle) => window.clearTimeout(handle));
            pending.clear();
            autos.forEach((timer) => timer.handle !== null && window.clearTimeout(timer.handle));
            autos.clear();
            running.forEach((frame) => cancelAnimationFrame(frame));
            running.clear();
            observer.current?.disconnect();
        };
    }, []);

    const startTimer = useCallback(
        (id: number) => {
            const timer = timers.current.get(id);
            if (!timer || timer.handle !== null) return;
            timer.startedAt = Date.now();
            timer.handle = later(() => {
                timers.current.delete(id);
                dismissRef.current(id, 1);
            }, timer.remaining);
        },
        [later],
    );

    const notify = useCallback<ToastApi['notify']>(
        (message, tone = 'success') => {
            const id = nextId++;
            setToasts((all) => [{ id, tone, message }, ...all].slice(0, MAX_TOASTS));
            if (tone !== 'danger') {
                timers.current.set(id, { handle: null, remaining: AUTO_DISMISS_MS, startedAt: 0 });
                if (!paused.current) startTimer(id);
            }
        },
        [startTimer],
    );

    // Slide each new card in from above on the frame after it mounts.
    useEffect(() => {
        const fresh = toasts.filter((toast) => !entered.has(toast.id)).map((toast) => toast.id);
        if (fresh.length === 0) return;
        const frame = requestAnimationFrame(() =>
            setEntered((prev) => {
                const next = new Set(prev);
                fresh.forEach((id) => next.add(id));
                return next;
            }),
        );
        return () => cancelAnimationFrame(frame);
    }, [toasts, entered]);

    const laidOut = toasts.filter((toast) => !leaving.has(toast.id));
    const expanded = (hovered || focused || dragging) && laidOut.length > 1;
    const open = hovered || focused || dragging;

    // Pause every auto-dismiss while the stack is open; resume with the time each had left.
    useEffect(() => {
        paused.current = open;
        timers.current.forEach((timer, id) => {
            if (open && timer.handle !== null) {
                window.clearTimeout(timer.handle);
                timeouts.current.delete(timer.handle);
                timer.handle = null;
                timer.remaining = Math.max(timer.remaining - (Date.now() - timer.startedAt), 1000);
            } else if (!open) {
                startTimer(id);
            }
        });
    }, [open, startTimer]);

    const measureRef = useCallback((id: number) => {
        return (node: HTMLLIElement | null) => {
            if (!node) return;
            const height = node.offsetHeight;
            if (height) setHeights((prev) => (prev[id] === height ? prev : { ...prev, [id]: height }));
            if (typeof ResizeObserver === 'undefined') return;
            observer.current ??= new ResizeObserver((entries) => {
                setHeights((prev) => {
                    let next = prev;
                    for (const entry of entries) {
                        const key = Number((entry.target as HTMLElement).dataset.id);
                        const h = Math.round(entry.borderBoxSize[0]?.blockSize ?? 0);
                        if (h && prev[key] !== h) {
                            next = next === prev ? { ...prev } : next;
                            next[key] = h;
                        }
                    }
                    return next;
                });
            });
            observer.current.observe(node);
        };
    }, []);

    const heightOf = (id: number) => heights[id] ?? FALLBACK_HEIGHT;
    const frontHeight = laidOut[0] ? heightOf(laidOut[0].id) : 0;
    const stackHeight = expanded
        ? laidOut.reduce((sum, toast) => sum + heightOf(toast.id), 0) + Math.max(laidOut.length - 1, 0) * GAP
        : frontHeight + Math.min(Math.max(laidOut.length - 1, 0), VISIBLE_BEHIND) * PEEK;
    const offsets: number[] = [];
    let running = 0;
    for (const toast of laidOut) {
        offsets.push(running);
        running += heightOf(toast.id) + GAP;
    }

    const stopFrame = (id: number) => {
        const frame = frames.current.get(id);
        if (frame !== undefined) {
            cancelAnimationFrame(frame);
            frames.current.delete(id);
        }
    };

    /** Written straight to the element: no re-render per pointer move. */
    const paint = (id: number, x: number) => {
        swipeX.current.set(id, x);
        const node = swipeRefs.current.get(id);
        if (!node) return;
        node.style.transition = 'none';
        node.style.transform = x === 0 ? '' : `translateX(${x}px)`;
        node.style.opacity = x === 0 ? '' : `${opacityFor(x)}`;
    };

    /** Step a per-card simulation on rAF until it reports it is done. */
    const run = (id: number, step: (dt: number) => boolean, onDone?: () => void) => {
        stopFrame(id);
        let last = performance.now();
        const tick = (now: number) => {
            // Clamp the step so a dropped frame or a background tab can't fling the simulation.
            const dt = Math.min((now - last) / 1000, 1 / 30);
            last = now;
            if (step(dt)) {
                frames.current.delete(id);
                onDone?.();
                return;
            }
            frames.current.set(id, requestAnimationFrame(tick));
        };
        frames.current.set(id, requestAnimationFrame(tick));
    };

    /** Spring back to rest, starting with the speed the finger let go at. */
    const snapBack = (id: number, velocity: number) => {
        if (prefersReducedMotion()) {
            paint(id, 0);
            return;
        }
        let x = swipeX.current.get(id) ?? 0;
        let v = velocity * 1000;
        run(id, (dt) => {
            v += (-SPRING_STIFFNESS * x - SPRING_DAMPING * v) * dt;
            x += v * dt;
            if (Math.abs(x) < 0.5 && Math.abs(v) < 10) {
                paint(id, 0);
                return true;
            }
            paint(id, x);
            return false;
        });
    };

    const finishDismiss = (id: number) => {
        setToasts((all) => all.filter((toast) => toast.id !== id));
        setLeaving((prev) => {
            const next = new Set(prev);
            next.delete(id);
            return next;
        });
        setEntered((prev) => {
            const next = new Set(prev);
            next.delete(id);
            return next;
        });
        setHeights((prev) => {
            const { [id]: _gone, ...rest } = prev;
            return rest;
        });
        swipeX.current.delete(id);
        lastY.current.delete(id);
        dismissed.current.delete(id);
    };

    /**
     * Throw the card off the side. A swipe keeps its release speed, so a hard flick leaves faster
     * than a slow drag past the threshold; the close button, Delete and the timer start from rest.
     */
    const dismiss = (id: number, direction: 1 | -1, velocity = 0) => {
        if (dismissed.current.has(id)) return;
        dismissed.current.add(id);
        const timer = timers.current.get(id);
        if (timer?.handle != null) {
            window.clearTimeout(timer.handle);
            timeouts.current.delete(timer.handle);
        }
        timers.current.delete(id);
        setLeaving((prev) => new Set(prev).add(id));
        const node = swipeRefs.current.get(id);

        // A keyboard or button dismissal keeps focus in the stack instead of dropping it to the page.
        if (node?.contains(document.activeElement)) {
            const at = laidOut.findIndex((toast) => toast.id === id);
            const next = laidOut[at + 1] ?? laidOut[at - 1];
            const target = next ? swipeRefs.current.get(next.id)?.querySelector<HTMLElement>('[data-toast-card]') : null;
            if (target) target.focus();
            else (document.activeElement as HTMLElement | null)?.blur();
        }

        if (!node || prefersReducedMotion()) {
            stopFrame(id);
            if (node) {
                node.style.transition = `opacity ${EXIT_MS}ms var(--tm-ease-decelerate)`;
                node.style.opacity = '0';
            }
            later(() => finishDismiss(id), EXIT_MS);
            return;
        }

        const distance = node.offsetWidth * 1.1;
        let x = swipeX.current.get(id) ?? 0;
        let speed = Math.max(Math.abs(velocity) * 1000, FLIGHT_MIN_SPEED);
        run(
            id,
            (dt) => {
                speed += FLIGHT_ACCEL * dt;
                x += direction * speed * dt;
                const done = Math.abs(x) >= distance;
                paint(id, done ? direction * distance : x);
                node.style.opacity = `${Math.max(1 - Math.abs(x) / distance, 0)}`;
                return done;
            },
            () => finishDismiss(id),
        );
    };
    dismissRef.current = dismiss;

    const onPointerDown = (event: PointerEvent<HTMLDivElement>, depth: number, id: number) => {
        // Only the front card swipes while collapsed, and a second finger never takes over a drag.
        if (drag.current || leaving.has(id) || (!expanded && depth > 0)) return;
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        if ((event.target as HTMLElement).closest('button')) return;
        stopFrame(id);
        drag.current = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: swipeX.current.get(id) ?? 0,
            dx: 0,
            axis: null,
            samples: [],
        };
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>, id: number) => {
        const current = drag.current;
        if (!current || current.pointerId !== event.pointerId) return;
        const dx = event.clientX - current.startX;
        const dy = event.clientY - current.startY;
        if (current.axis === null) {
            if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) return;
            current.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
            if (current.axis === 'x') {
                // Keep the swipe alive when the pointer leaves the card.
                event.currentTarget.setPointerCapture(event.pointerId);
                setDragging(true);
            }
        }
        if (current.axis !== 'x') return;
        current.dx = current.originX + dx;
        current.samples.push({ t: event.timeStamp, x: current.dx });
        if (current.samples.length > 12) current.samples.shift();
        paint(id, current.dx);
    };

    const onPointerUp = (event: PointerEvent<HTMLDivElement>, id: number) => {
        const current = drag.current;
        if (!current || current.pointerId !== event.pointerId) return;
        drag.current = null;
        setDragging(false);
        if (current.axis !== 'x') {
            if (swipeX.current.get(id)) snapBack(id, 0);
            return;
        }
        const velocity = releaseVelocity(current.samples);
        const direction = dismissDirection(current.dx, velocity);
        if (direction === 0) {
            snapBack(id, velocity);
            return;
        }
        // Never carry speed that points back toward the stack into the flight.
        dismiss(id, direction, Math.sign(velocity) === direction ? velocity : 0);
    };

    const onCardKeyDown = (event: KeyboardEvent<HTMLElement>, id: number) => {
        if (event.key !== 'Delete' && event.key !== 'Backspace') return;
        event.preventDefault();
        // A held key would clear the next card too, now that focus moves to it.
        if (!event.repeat) dismiss(id, 1);
    };

    return (
        <ToastContext.Provider value={{ notify }}>
            {children}
            <section
                aria-label={t('toast.region')}
                className="pointer-events-none fixed top-4 right-4 z-50 w-[min(100%-2rem,26.25rem)]"
                onFocus={() => setFocused(true)}
                onBlur={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocused(false);
                }}
                onPointerEnter={(event) => event.pointerType === 'mouse' && setHovered(true)}
                onPointerLeave={(event) => event.pointerType === 'mouse' && setHovered(false)}
            >
                <ol
                    aria-live="polite"
                    ref={listRef}
                    style={{ height: stackHeight }}
                    className={`relative transition-[height] duration-300 ease-decelerate motion-reduce:transition-none ${toasts.length > 0 ? 'pointer-events-auto' : ''}`}
                >
                    {toasts.map((toast) => {
                        const { icon: Icon, tint } = looks[toast.tone];
                        const index = laidOut.findIndex((x) => x.id === toast.id);
                        const isLeaving = index === -1;
                        const depth = Math.max(index, 0);
                        const hiddenBehind = !(expanded || depth <= VISIBLE_BEHIND);
                        const muted = !expanded && depth > 0;
                        const scale = expanded || isLeaving ? 1 : 1 - depth * 0.05;
                        // Top-anchored: older cards drop below the newest. Collapsed, each card behind is
                        // bottom-aligned so a strip of it peeks out below the front card, whatever its height.
                        const restingY = expanded ? (offsets[depth] ?? 0) : frontHeight + depth * PEEK - heightOf(toast.id) * scale;
                        const y = isLeaving ? (lastY.current.get(toast.id) ?? 0) : restingY;
                        if (!isLeaving) lastY.current.set(toast.id, restingY);
                        const transform = entered.has(toast.id)
                            ? `translateY(${y}px) scale(${scale})`
                            : 'translateY(calc(-100% - 1rem)) scale(1)';

                        return (
                            <li
                                key={toast.id}
                                data-id={toast.id}
                                ref={measureRef(toast.id)}
                                className="absolute inset-x-0 top-0 origin-top transition-[transform,opacity] duration-300 ease-decelerate motion-reduce:transition-opacity"
                                style={{
                                    transform,
                                    opacity: hiddenBehind || !entered.has(toast.id) ? 0 : 1,
                                    zIndex: toasts.length - depth,
                                    pointerEvents: hiddenBehind ? 'none' : undefined,
                                }}
                            >
                                <div
                                    className="touch-pan-y"
                                    ref={(node) => {
                                        if (node) swipeRefs.current.set(toast.id, node);
                                        else swipeRefs.current.delete(toast.id);
                                    }}
                                    onPointerDown={(event) => onPointerDown(event, depth, toast.id)}
                                    onPointerMove={(event) => onPointerMove(event, toast.id)}
                                    onPointerUp={(event) => onPointerUp(event, toast.id)}
                                    onPointerCancel={(event) => onPointerUp(event, toast.id)}
                                    onLostPointerCapture={(event) => {
                                        if (event.target === event.currentTarget) onPointerUp(event, toast.id);
                                    }}
                                >
                                    <div
                                        role="status"
                                        data-toast-card
                                        tabIndex={hiddenBehind ? -1 : 0}
                                        onKeyDown={(event) => onCardKeyDown(event, toast.id)}
                                        className="flex select-none items-center gap-2.5 rounded-sm border border-line-default bg-surface-raised py-3 ps-3.5 pe-2 shadow-e3 focus-visible:ring-2 focus-visible:ring-line-focus"
                                    >
                                        <div
                                            className={`flex min-w-0 flex-1 items-center gap-2.5 transition-opacity duration-200 ${muted ? 'opacity-0' : ''}`}
                                            aria-hidden={muted || undefined}
                                        >
                                            <Icon size={18} aria-hidden className={`shrink-0 ${tint}`} />
                                            <p className="flex-1 t-admin-body text-fg-primary">{toast.message}</p>
                                        </div>
                                        <IconButton
                                            size="sm"
                                            aria-label={t('common.close')}
                                            tabIndex={hiddenBehind || muted ? -1 : 0}
                                            className={muted ? 'invisible' : undefined}
                                            onClick={() => dismiss(toast.id, 1)}
                                        >
                                            <XIcon size={16} aria-hidden />
                                        </IconButton>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            </section>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const value = useContext(ToastContext);
    if (!value) throw new Error('useToast must be used inside <ToastProvider>');
    return value;
}
