'use client';

import { animate } from 'motion';
import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { arrivalLine, placeName, publishedLine, quotePlace, workingDaysLine } from '@/lib/delivery/format';
import type { DeliveryQuote, DeliveryReference, DeliverySelection } from '@/lib/delivery/types';
import { format, type Locale, type Messages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { duration, ease, prefersReducedMotion, space } from '@/lib/motion';
import { contact } from '@/lib/site-config';

// The interactive DeliveryEstimator (see DeliveryEstimator.tsx for the contract). States follow
// Figma 119:1329: Empty · Loading · Selected (SelectedFree when free delivery applies) ·
// NotServiced, plus the spec's Error (§A12.6), which Figma does not draw.

type Phase = 'empty' | 'loading' | 'quote' | 'error';

interface Props {
    locale: Locale;
    t: Messages['delivery'];
    reference: DeliveryReference;
    variantId: number | null;
    quantity: number;
    /** GET target without JavaScript: the /delivery-charge page. */
    action: string;
    initial: { selection: DeliverySelection; quote: DeliveryQuote | null } | null;
}

/** The visitor's last district choice, so the next product page shows its charge (per-browser convenience only). */
const STORAGE_KEY = 'tm_delivery';

function readStored(): DeliverySelection | null {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const v = JSON.parse(raw) as { districtId?: unknown; upazilaId?: unknown };
        if (typeof v.districtId !== 'number') return null;
        return { districtId: v.districtId, upazilaId: typeof v.upazilaId === 'number' ? v.upazilaId : null };
    } catch {
        return null;
    }
}

function store(selection: DeliverySelection): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(selection));
    } catch {
        // Private mode or blocked storage: the estimator still works, it just forgets.
    }
}

export function DeliveryEstimatorClient({ locale, t, reference, variantId, quantity, action, initial }: Props) {
    const bn = locale === 'bn';
    const id = useId();
    const [phase, setPhase] = useState<Phase>(initial?.quote ? 'quote' : initial ? 'error' : 'empty');
    const [districtId, setDistrictId] = useState<number | null>(initial?.selection.districtId ?? null);
    const [upazilaId, setUpazilaId] = useState<number | null>(initial?.selection.upazilaId ?? null);
    const [quote, setQuote] = useState<DeliveryQuote | null>(initial?.quote ?? null);
    const request = useRef<AbortController | null>(null);
    const body = useRef<HTMLDivElement | null>(null);
    const section = useRef<HTMLElement | null>(null);
    /** Set by a district/upazila choice: only a change the visitor made may scroll the page. */
    const userAction = useRef(false);
    const firstPhase = useRef(true);

    // Smooth state transition (motion.dev): the new body fades and settles in — opacity and
    // transform only, so nothing around the estimator moves (min-height is reserved). A result the
    // visitor asked for is scrolled into view smoothly when it ends below the fold. Reduced motion:
    // no animation, instant scroll.
    useEffect(() => {
        if (firstPhase.current) {
            firstPhase.current = false; // the server-rendered state never animates in
            return;
        }
        const el = body.current;
        const reduced = prefersReducedMotion();
        if (el && !reduced) {
            animate(el, { opacity: [0, 1], transform: [`translateY(${space('2')}px)`, 'translateY(0px)'] }, { duration: duration('base'), ease: ease('standard') });
        }
        if (userAction.current && phase === 'quote' && section.current) {
            userAction.current = false;
            const rect = section.current.getBoundingClientRect();
            if (rect.bottom > window.innerHeight) section.current.scrollIntoView({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' });
        }
    }, [phase]);

    const district = reference.districts.find((d) => d.id === districtId) ?? null;
    const upazilas = district?.upazila_required ? (reference.upazilas[district.id] ?? []) : [];
    const published = publishedLine(reference.rates, t);

    const load = useCallback(
        async (selection: DeliverySelection) => {
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            setPhase('loading');
            const q = new URLSearchParams({ district_id: String(selection.districtId), quantity: String(quantity), locale });
            if (selection.upazilaId !== null) q.set('upazila_id', String(selection.upazilaId));
            if (variantId !== null) q.set('variant_id', String(variantId));
            try {
                const response = await fetch(`/api/delivery-quote?${q.toString()}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                setQuote((await response.json()) as DeliveryQuote);
                setPhase('quote');
                store(selection);
            } catch {
                if (controller.signal.aborted) return;
                setPhase('error');
            }
        },
        [locale, quantity, variantId],
    );

    // A remembered district renders its quote on the next product page; a new variant re-quotes.
    useEffect(() => {
        if (initial) return;
        const stored = readStored();
        if (stored && reference.districts.some((d) => d.id === stored.districtId)) {
            setDistrictId(stored.districtId);
            setUpazilaId(stored.upazilaId);
            void load(stored);
        }
        return () => request.current?.abort();
    }, [initial, load, reference.districts]);

    const chooseDistrict = (value: string) => {
        const next = value === '' ? null : Number(value);
        setDistrictId(next);
        setUpazilaId(null);
        userAction.current = true;
        const chosen = reference.districts.find((d) => d.id === next);
        // A district that settles the charge on its own (or whose empty upazila is its city) quotes at once.
        if (chosen && (!chosen.upazila_required || chosen.city_option)) void load({ districtId: chosen.id, upazilaId: null });
    };

    const chooseUpazila = (value: string) => {
        if (districtId === null) return;
        const next = value === '' ? null : Number(value);
        userAction.current = true;
        setUpazilaId(next);
        if (next !== null || district?.city_option) void load({ districtId, upazilaId: next });
    };

    const selector = phase === 'empty' || (phase === 'error' && quote === null);

    return (
        <section ref={section} aria-labelledby={`${id}-title`} className="flex min-h-33 w-full flex-col gap-3 rounded-sm border-(length:--tm-border-width-control) border-line-brand-subtle bg-surface p-4 lg:min-h-35">
            <div className="flex w-full items-center gap-2">
                <span aria-hidden="true" className="flex size-7 shrink-0 items-center justify-center rounded-full bg-tile-mint text-status-in-stock">
                    <Icon name="truck" className="size-icon-sm" />
                </span>
                <h2 id={`${id}-title`} className="t-h4 min-w-0 grow text-fg-primary">
                    {t.title}
                </h2>
                {!selector ? (
                    <button type="button" onClick={() => setPhase('empty')} className="t-body-sm-strong shrink-0 rounded-xs text-fg-brand">
                        {t.change}
                    </button>
                ) : null}
            </div>

            <div ref={body} className="flex w-full flex-col gap-3">
            {selector ? (
                <form method="get" action={action} onSubmit={(e) => (e.preventDefault(), districtId !== null && void load({ districtId, upazilaId }))} className="flex w-full flex-col gap-3">
                    <p className="t-body-sm text-fg-secondary">{t.helper}</p>
                    {variantId !== null ? <input type="hidden" name="variant_id" value={variantId} /> : null}
                    <input type="hidden" name="quantity" value={quantity} />
                    <SelectField id={`${id}-district`} label={t.districtLabel} help={t.districtHelp}>
                        <select
                            id={`${id}-district`}
                            name="district_id"
                            required
                            value={districtId ?? ''}
                            onChange={(e) => chooseDistrict(e.target.value)}
                            aria-describedby={`${id}-district-help`}
                            className={selectClass(districtId === null)}
                        >
                            <option value="" disabled>
                                {t.districtPlaceholder}
                            </option>
                            {groups(reference.districts, bn).map(([division, list]) => (
                                <optgroup key={division} label={division}>
                                    {list.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {placeName(d, bn)}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                    </SelectField>
                    {district && upazilas.length > 0 ? (
                        <SelectField id={`${id}-upazila`} label={t.upazilaLabel}>
                            <select
                                id={`${id}-upazila`}
                                name="upazila_id"
                                value={upazilaId ?? ''}
                                onChange={(e) => chooseUpazila(e.target.value)}
                                className={selectClass(upazilaId === null && !district.city_option)}
                            >
                                {district.city_option ? (
                                    <option value="">{format(t.cityOption, { district: placeName(district, bn) })}</option>
                                ) : (
                                    <option value="" disabled>
                                        {t.upazilaPlaceholder}
                                    </option>
                                )}
                                {upazilas.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {placeName(u, bn)}
                                    </option>
                                ))}
                            </select>
                        </SelectField>
                    ) : null}
                    <noscript>
                        <button type="submit" className={`${buttonClass('secondary', 'md')} w-full`}>
                            {t.check}
                        </button>
                    </noscript>
                    {phase === 'error' ? <ErrorAlert t={t} onRetry={() => districtId !== null && void load({ districtId, upazilaId })} /> : null}
                    {published ? <p className="t-caption text-fg-tertiary">{published}</p> : null}
                </form>
            ) : (
                <div aria-live="polite" aria-busy={phase === 'loading'} className="flex w-full flex-col gap-3">
                    {phase === 'loading' ? <Skeleton label={t.loading} /> : null}
                    {phase === 'error' ? (
                        <>
                            <ErrorAlert t={t} onRetry={() => districtId !== null && void load({ districtId, upazilaId })} />
                            {published ? <p className="t-caption text-fg-tertiary">{published}</p> : null}
                        </>
                    ) : null}
                    {phase === 'quote' && quote ? (quote.serviceable ? <Selected quote={quote} bn={bn} t={t} /> : <NotServiced quote={quote} bn={bn} t={t} />) : null}
                </div>
            )}
            </div>
        </section>
    );
}

/** Districts grouped by division, in the API's order (Dhaka division first). */
function groups(districts: DeliveryReference['districts'], bn: boolean): Array<[string, DeliveryReference['districts']]> {
    const out = new Map<string, DeliveryReference['districts']>();
    for (const d of districts) {
        const key = bn ? d.division.name_bn : d.division.name;
        out.set(key, [...(out.get(key) ?? []), d]);
    }
    return [...out.entries()];
}

// NativeSelect Size=Medium (Figma 45:481): 44 tall, radius/md, hairline border, 16px value so iOS
// never zooms on focus; the OS picker on mobile, deliberately.
function selectClass(placeholder: boolean): string {
    return `t-body h-control-md w-full cursor-pointer appearance-none rounded-md border border-line-default bg-surface pr-10 pl-3 ${placeholder ? 'text-fg-tertiary' : 'text-fg-primary'}`;
}

function SelectField({ id, label, help, children }: { id: string; label: string; help?: string; children: ReactNode }) {
    return (
        <div className="flex w-full flex-col gap-2">
            <label htmlFor={id} className="t-field-label text-fg-primary">
                {label}
            </label>
            <div className="flex w-full flex-col gap-1">
                <div className="relative w-full">
                    {children}
                    <Icon name="caretDown" className="pointer-events-none absolute top-1/2 right-3 size-icon-md -translate-y-1/2 text-fg-secondary" />
                </div>
                {help ? (
                    <p id={`${id}-help`} className="t-caption text-fg-secondary">
                        {help}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

function ResultBlock({ children }: { children: ReactNode }) {
    return <div className="flex w-full flex-col items-start gap-1.5 overflow-hidden rounded-xs bg-subtle p-3">{children}</div>;
}

function Selected({ quote, bn, t }: { quote: DeliveryQuote; bn: boolean; t: Messages['delivery'] }) {
    const free = quote.free_shipping;
    const nudge = !free.applied && free.remaining !== null ? free.remaining : null;
    const label = format(t.chargeLabel, { place: quotePlace(quote, bn) });

    return (
        <>
            <ResultBlock>
                <p className="flex flex-wrap items-baseline gap-x-2">
                    {free.applied ? (
                        <>
                            <span className="t-price-current-card text-status-in-stock">{t.free}</span>
                            {quote.standard_charge ? <s className="t-price-was text-fg-price-was">{formatPrice(quote.standard_charge)}</s> : null}
                        </>
                    ) : (
                        <span className="t-price-current-card text-fg-price">{quote.charge ? formatPrice(quote.charge) : ''}</span>
                    )}
                    <span className="t-body-sm text-fg-secondary">{label}</span>
                </p>
                {quote.eta ? (
                    <p className="flex flex-wrap items-center gap-x-1.5">
                        <Icon name="calendarBlank" className="size-icon-sm text-fg-brand" />
                        <span className="t-body-strong text-fg-primary">{arrivalLine(quote.eta, t)}</span>
                        <span className="t-caption text-fg-secondary">{workingDaysLine(quote.eta, t)}</span>
                    </p>
                ) : null}
                {quote.courier ? <p className="t-caption text-fg-tertiary">{format(t.courier, { name: bn ? (quote.courier.name_bn ?? quote.courier.name) : quote.courier.name })}</p> : null}
            </ResultBlock>
            {nudge !== null ? (
                <div className="flex w-full flex-col gap-1.5">
                    <div
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={free.progress_percent ?? 0}
                        aria-valuetext={format(t.freeMeter, { amount: formatPrice(nudge) })}
                        className="h-1 w-full overflow-hidden rounded-full bg-subtle"
                    >
                        {(free.progress_percent ?? 0) > 0 ? <div className="h-full min-w-2 rounded-full bg-brand" style={{ width: `${free.progress_percent ?? 0}%` }} /> : null}
                    </div>
                    <p className="t-caption-strong text-fg-discount">{format(t.freeNudge, { amount: formatPrice(nudge) })}</p>
                </div>
            ) : null}
            {quote.cod_advance ? (
                <p className="t-caption flex items-center gap-1 text-status-low-stock">
                    <Icon name="info" className="size-3.5" />
                    {format(t.codAdvance, { amount: formatPrice(quote.cod_advance) })}
                </p>
            ) : null}
        </>
    );
}

function NotServiced({ quote, bn, t }: { quote: DeliveryQuote; bn: boolean; t: Messages['delivery'] }) {
    const place = quotePlace(quote, bn);
    const whatsapp = contact.whatsapp;
    return (
        <>
            <ResultBlock>
                <p className="flex w-full items-center gap-2">
                    <Icon name="warningCircle" className="size-icon-md text-status-out-of-stock" />
                    <span className="t-body-strong min-w-0 grow text-fg-primary">{format(t.notServicedTitle, { place })}</span>
                </p>
                <p className="t-body-sm w-full text-fg-secondary">{format(t.notServicedBody, { place })}</p>
            </ResultBlock>
            {/* Pickup points and back-in-area alerts have no backend yet: shown with their final look, disabled. The add-to-cart button is never blocked. */}
            <button type="button" disabled title={t.comingSoon} className={`${buttonClass('secondary', 'md')} w-full disabled:cursor-not-allowed`}>
                {t.pickup}
            </button>
            <button
                type="button"
                disabled
                title={t.comingSoon}
                className="t-button flex h-control-md w-full min-w-28 items-center justify-center rounded-full border-(length:--tm-border-width-strong) border-line-brand-subtle bg-surface px-5 text-fg-brand disabled:cursor-not-allowed"
            >
                {t.notify}
            </button>
            {whatsapp.placeholder ? (
                <span className="t-body-sm-strong text-fg-link">{t.whatsapp}</span>
            ) : (
                <a href={whatsapp.href} className="t-body-sm-strong rounded-xs text-fg-link">
                    {t.whatsapp}
                </a>
            )}
        </>
    );
}

function Skeleton({ label }: { label: string }) {
    return (
        <ResultBlock>
            <span className="sr-only">{label}</span>
            <span aria-hidden="true" className="h-4.5 w-30 rounded-full bg-line-default" />
            <span aria-hidden="true" className="h-4 w-50 max-w-full rounded-full bg-line-default" />
            <span aria-hidden="true" className="h-3 w-35 rounded-full bg-line-default" />
        </ResultBlock>
    );
}

function ErrorAlert({ t, onRetry }: { t: Messages['delivery']; onRetry: () => void }) {
    return (
        <div role="alert" className="flex w-full items-start gap-2 rounded-xs bg-warning-subtle p-3">
            <Icon name="warning" className="mt-0.5 size-icon-sm text-status-low-stock" />
            <div className="flex min-w-0 grow flex-col items-start gap-1">
                <p className="t-body-sm text-fg-primary">{t.errorTitle}</p>
                <button type="button" onClick={onRetry} className="t-body-sm-strong rounded-xs text-fg-brand">
                    {t.retry}
                </button>
            </div>
        </div>
    );
}
