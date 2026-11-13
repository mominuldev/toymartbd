'use client';

import { useEffect, useId, useRef, useState, type KeyboardEvent, type Ref } from 'react';

import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import type { Messages } from '@/lib/i18n';
import type { ConsentMode } from '@/lib/tracking/config';
import './consent.css';

interface Props {
    labels: Messages['consent'];
    privacyHref: string;
    bangla: boolean;
    mode: ConsentMode;
    /** Pre-ticked state of the two choices (the current consent). */
    initial: { analytics: boolean; ads: boolean };
    /** A decision already exists: Esc and the close button leave it unchanged. */
    dismissible: boolean;
    /** Opened from "Cookie settings": focus moves into the banner. */
    reopened: boolean;
    onDecide: (analytics: boolean, ads: boolean) => void;
    onClose: () => void;
}

// The consent banner. There is NO consent frame in Figma (searched 2026-11-13: file
// PWZp5VzEgEEf5N2Y38lGIh, pages 02 Primitives / 03 Storefront Components / 05–06 Storefront) —
// this is composed from existing primitives and NEEDS OWNER DESIGN SIGN-OFF:
//   <768  Drawer Side=Bottom Size=Small (78:1198): full width, radius/2xl top corners, bg/surface,
//         e4; header padding 16, body 16 h; sticky footer with a 1px border/default top rule and
//         FILL Size=Large buttons stacked, Primary on top (Modal 78:1003's <768 rule).
//   ≥768  Modal Size=Medium (78:913) as a floating panel, 560 wide, radius/xl, e4: header 24/24/16,
//         body 24 h, footer 16/24/24 with Size=Medium buttons right-aligned, Secondary before Primary.
//   Choices: Checkbox (44:244, as FilterForm builds it) — not Switch (50:374), whose Figma note
//         says a setting that needs confirming is a Checkbox in a form.
// Non-modal (aria-modal=false, no overlay): the shop stays usable, and a fixed panel moves no
// content (no CLS). Enter is the sheet's 320 ms decelerate slide; reduced motion = fade only.
export function ConsentBanner({ labels, privacyHref, bangla, mode, initial, dismissible, reopened, onDecide, onClose }: Props) {
    const id = useId();
    const titleId = `${id}-title`;
    const bodyId = `${id}-body`;
    const [choosing, setChoosing] = useState(false);
    const [analytics, setAnalytics] = useState(initial.analytics);
    const [ads, setAds] = useState(initial.ads);
    const title = useRef<HTMLHeadingElement>(null);
    const firstChoice = useRef<HTMLInputElement>(null);

    // Reopened from the footer: move focus in. First visit: leave focus where it is (the banner is
    // first in the tab order) — stealing focus on page load disorients screen-reader users.
    useEffect(() => {
        if (reopened) title.current?.focus();
    }, [reopened]);
    useEffect(() => {
        if (choosing) firstChoice.current?.focus();
    }, [choosing]);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'Escape') return;
        if (choosing) {
            event.stopPropagation();
            setChoosing(false);
            title.current?.focus();
        } else if (dismissible) {
            event.stopPropagation();
            onClose();
        }
    };

    const footer = 'flex flex-col gap-3 border-t border-line-default p-4 md:flex-row-reverse md:justify-start md:border-t-0 md:px-6 md:pt-4 md:pb-6';
    const button = (variant: 'primary' | 'outline' | 'ghost') => `${buttonClass(variant, 'lg')} w-full md:h-control-md md:w-auto md:px-5`;

    return (
        <div
            role="dialog"
            aria-modal="false"
            aria-labelledby={titleId}
            aria-describedby={bodyId}
            onKeyDown={onKeyDown}
            className="consent-banner fixed inset-x-0 bottom-0 z-50 flex max-h-[85dvh] flex-col rounded-t-2xl bg-surface pb-[env(safe-area-inset-bottom)] text-fg-primary shadow-e4 md:inset-x-auto md:bottom-6 md:left-6 md:max-h-[90dvh] md:w-140 md:rounded-xl md:pb-0"
        >
            <div className="flex items-start gap-4 px-4 pt-4 pb-4 md:px-6 md:pt-6">
                <h2 id={titleId} ref={title} tabIndex={-1} className={`grow pt-2 focus:outline-none ${bangla ? 't-h3' : 't-modal-title'}`}>
                    {labels.title}
                </h2>
                {dismissible ? (
                    <button type="button" onClick={onClose} aria-label={labels.close} className="flex size-touch-min shrink-0 items-center justify-center rounded-full text-fg-primary hover:bg-fg-primary/8">
                        <Icon name="x" className="size-icon-md" />
                    </button>
                ) : null}
            </div>

            <div className="flex min-h-0 grow flex-col gap-4 overflow-y-auto overscroll-contain px-4 md:px-6">
                <p id={bodyId} className="t-body">
                    {choosing ? labels.chooseIntro : mode === 'notice' ? labels.bodyNotice : labels.bodyOptIn}{' '}
                    <a href={privacyHref} className="font-semibold text-fg-link underline underline-offset-2">
                        {labels.privacy}
                    </a>
                </p>
                {choosing ? (
                    <ul className="flex flex-col gap-3 pb-1">
                        <Choice id={`${id}-essential`} label={labels.essential} body={labels.essentialBody} note={labels.alwaysOn} checked disabled />
                        <Choice id={`${id}-analytics`} label={labels.analytics} body={labels.analyticsBody} checked={analytics} onChange={setAnalytics} inputRef={firstChoice} />
                        <Choice id={`${id}-ads`} label={labels.ads} body={labels.adsBody} checked={ads} onChange={setAds} />
                    </ul>
                ) : null}
            </div>

            {choosing ? (
                <div className={footer}>
                    <button type="button" onClick={() => onDecide(analytics, ads)} className={button('primary')}>
                        {labels.save}
                    </button>
                    <button type="button" onClick={() => onDecide(true, true)} className={button('outline')}>
                        {labels.acceptAll}
                    </button>
                </div>
            ) : (
                <div className={footer}>
                    <button type="button" onClick={() => onDecide(true, true)} className={button('primary')}>
                        {labels.acceptAll}
                    </button>
                    <button type="button" onClick={() => onDecide(false, false)} className={button('outline')}>
                        {labels.reject}
                    </button>
                    <button type="button" onClick={() => setChoosing(true)} className={button('ghost')}>
                        {labels.choose}
                    </button>
                </div>
            )}
        </div>
    );
}

interface ChoiceProps {
    id: string;
    label: string;
    body: string;
    note?: string;
    checked: boolean;
    disabled?: boolean;
    onChange?: (checked: boolean) => void;
    inputRef?: Ref<HTMLInputElement>;
}

function Choice({ id, label, body, note, checked, disabled = false, onChange, inputRef }: ChoiceProps) {
    return (
        <li>
            <label htmlFor={id} className={`flex min-h-touch-min items-start gap-3 ${disabled ? '' : 'cursor-pointer'}`}>
                <span className="relative mt-0.5 flex size-5 shrink-0 items-center justify-center">
                    <input
                        ref={inputRef}
                        id={id}
                        type="checkbox"
                        checked={checked}
                        disabled={disabled}
                        aria-describedby={`${id}-body`}
                        onChange={(event) => onChange?.(event.currentTarget.checked)}
                        className="peer absolute inset-0 m-0 cursor-pointer appearance-none rounded-xs border-(length:--tm-border-width-control) border-line-strong bg-surface checked:border-brand checked:bg-brand disabled:cursor-not-allowed disabled:border-line-default disabled:bg-disabled"
                    />
                    <Icon name="check" className="pointer-events-none relative size-3.5 text-fg-on-brand opacity-0 peer-checked:opacity-100 peer-disabled:text-fg-disabled" />
                </span>
                <span className="flex min-w-0 grow flex-col gap-1">
                    <span className="flex flex-wrap items-baseline gap-x-2">
                        <span className="t-body-strong text-fg-primary">{label}</span>
                        {note ? <span className="t-caption text-fg-secondary">{note}</span> : null}
                    </span>
                    <span id={`${id}-body`} className="t-body-sm text-fg-secondary">
                        {body}
                    </span>
                </span>
            </label>
        </li>
    );
}
