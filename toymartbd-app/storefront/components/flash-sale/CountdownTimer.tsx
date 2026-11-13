'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import {
    announcementCrossed,
    clock,
    countdownState,
    dhakaDateTime,
    dhakaShort,
    remaining,
    tiles,
    type CountdownState,
} from '@/lib/flash-sale/format';
import { format, type Messages } from '@/lib/i18n';

// CountdownTimer — storefront spec §A15, Figma 119:1388 (Size xs 96×22 · sm 140×32 · md 288×64;
// State Normal / Urgent / Ended).
//
// Server truth: `endsAt` is the API's ISO timestamp. The server render (and the first client
// render, so hydration matches) shows the END TIME in Dhaka — the page is complete without
// JavaScript. After mount the client ticks once a second towards that instant. Every size has a
// fixed reserved box (w × h), so switching from the end time to the digits, or 2→1 digits,
// never reflows the card (zero CLS); digits are tabular. The digits are aria-live off; a single
// polite announcement fires at 60, 10 and 1 minute left. Reduced motion: no zap pulse.

export type CountdownSize = 'xs' | 'sm' | 'md';

interface Props {
    endsAt: string;
    size: CountdownSize;
    t: Messages['flashSale'];
    /**
     * When the server rendered this (ISO). Only decides the pre-JS state: a sale already over at
     * render time shows "Sale ended" without JavaScript. The ticking uses the device clock against
     * the server's `endsAt` (an ISR page's render time is not "now", so it is never used as a clock).
     */
    renderedAt?: string;
    /** Called once when the sale ends (the rail/page can drop itself). */
    onEnded?: () => void;
    /** Rail/card overlay: fill the image box's width, square corners (spec B1.3 "Fill width"). */
    fill?: boolean;
    className?: string;
}

const BOX: Record<CountdownSize, string> = {
    xs: 'h-5.5 w-24 rounded-full px-2 gap-1 t-caption-strong',
    sm: 'h-8 w-35 rounded-full px-3 gap-2 t-body-sm-strong',
    md: 'h-16 w-72 rounded-md px-4 gap-2 t-body-sm-strong',
};

const FILL_BOX = 'h-5.5 w-full px-2 gap-1 t-caption-strong';

const TONE: Record<CountdownState, string> = {
    normal: 'bg-accent text-fg-on-accent',
    urgent: 'bg-brand text-fg-on-brand',
    ended: 'bg-subtle text-fg-tertiary',
};

export function CountdownTimer({ endsAt, size, t, renderedAt, onEnded, fill = false, className = '' }: Props) {
    const [now, setNow] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const previous = useRef<number | null>(null);
    const ended = useRef(false);

    useEffect(() => {
        const tick = () => setNow(Date.now());
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, []);

    const left = now === null ? null : remaining(endsAt, now);
    const renderedState = renderedAt ? countdownState(remaining(endsAt, Date.parse(renderedAt))) : 'normal';
    const state: CountdownState = now === null ? (renderedState === 'ended' ? 'ended' : 'normal') : countdownState(left);

    useEffect(() => {
        if (left === null) return;
        const crossed = previous.current === null ? null : announcementCrossed(previous.current, left.totalMs);
        previous.current = left.totalMs;
        if (crossed !== null) setAnnouncement(crossed === 60 ? t.left60 : crossed === 10 ? t.left10 : t.left1);
        if (left.totalMs <= 0 && !ended.current) {
            ended.current = true;
            onEnded?.();
        }
    }, [left, onEnded, t]);

    // xs/sm reserve 96/140px: the short "14/11 23:59" fits; md has room for "Ends 14/11/2026, 23:59".
    const endTime = size === 'md' ? format(t.endsAt, { time: dhakaDateTime(endsAt) }) : dhakaShort(endsAt);

    let body: ReactNode;
    if (state === 'ended') {
        body = <span>{t.ended}</span>;
    } else if (left === null) {
        // Server render / no JS: the end time itself.
        body = (
            <>
                {size !== 'md' ? <Icon name="lightningFill" className="size-3.5 shrink-0" /> : null}
                <time dateTime={endsAt} className="truncate tabular-nums">
                    {size === 'md' ? endTime : <span aria-hidden="true">{endTime}</span>}
                </time>
                {size === 'md' ? null : <span className="sr-only">{format(t.endsAt, { time: dhakaDateTime(endsAt) })}</span>}
            </>
        );
    } else if (size === 'md') {
        const units = [t.days, t.hours, t.minutes, t.seconds];
        body = (
            <span aria-hidden="true" className="flex items-center gap-2 tabular-nums">
                {tiles(left).map((digits, i) => (
                    <span key={units[i]} className="flex items-center gap-2">
                        {i > 0 ? <span className="opacity-60">:</span> : null}
                        <span
                            className={`flex size-10 flex-col items-center justify-center rounded-sm leading-none ${state === 'urgent' ? 'border-(length:--tm-border-width-control) border-fg-on-brand' : 'bg-surface/20'}`}
                        >
                            <span className="t-price-current-compact">{digits}</span>
                            <span className="t-caption">{units[i]}</span>
                        </span>
                    </span>
                ))}
            </span>
        );
    } else {
        body = (
            <>
                <Icon name="lightningFill" className={`size-3.5 shrink-0 ${state === 'urgent' ? 'motion-safe:animate-pulse' : ''}`} />
                <span aria-hidden="true" className="tabular-nums">
                    {clock(left)}
                </span>
            </>
        );
    }

    return (
        <span
            role="timer"
            aria-label={left === null || state === 'ended' ? undefined : t.countdownLabel}
            aria-live="off"
            data-state={left === null && state !== 'ended' ? 'static' : state}
            data-size={size}
            className={`inline-flex shrink-0 items-center justify-center whitespace-nowrap ${fill ? FILL_BOX : BOX[size]} ${TONE[state]} ${className}`}
        >
            {body}
            {left !== null && state !== 'ended' ? (
                <span className="sr-only">
                    {format(t.endsAt, { time: dhakaDateTime(endsAt) })}
                </span>
            ) : null}
            <span className="sr-only" aria-live="polite">
                {announcement}
            </span>
        </span>
    );
}
