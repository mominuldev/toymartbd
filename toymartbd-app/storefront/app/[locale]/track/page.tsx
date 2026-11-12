import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound } from 'next/navigation';

import { Field, inputClass } from '@/components/cart/fields';
import { buttonClass } from '@/components/layout/button-styles';
import { DeliveryDetails, OrderHeaderCard, OrderHelp, OrderItems, OrderTimeline } from '@/components/order/OrderParts';
import { PageShell } from '@/components/PageShell';
import { TRACK_COOKIE } from '@/lib/cart/cookies';
import { format, getMessages, isLocale } from '@/lib/i18n';
import { trackOrder, type TrackResult } from '@/lib/order/fetch';
import { alternatesFor } from '@/lib/seo';
import { contact } from '@/lib/site-config';

// /track — public order tracking (P4-STO-10; Figma Order tracking 132:2899 at 390, 161:16502 at
// 1440). Order number **plus** the last 4 phone digits (rule 22); a wrong pair gets one generic
// message, never a hint which part was wrong. The form POSTs to /api/track (no digits in the URL);
// this page reads that one-shot cookie and asks the API. The answer carries no street address.
// Layout: 1440 — status column (header card, timeline, delivery details) + 440 rail (form, items,
// help); 390 — form first, then the same cards.

const PATH = '/track';

// Per visitor (cookies): never prerendered or cached.
export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    const t = getMessages(locale).track;
    return { title: t.title, description: t.metaDescription, alternates: alternatesFor(locale, PATH) };
}

function readLookup(value: string | undefined): { number: string; phone: string } | null {
    if (!value || value.length > 300) return null;
    try {
        const raw = JSON.parse(Buffer.from(value, 'base64url').toString('utf8')) as { number?: unknown; phone?: unknown };
        return typeof raw.number === 'string' && typeof raw.phone === 'string' ? { number: raw.number, phone: raw.phone } : null;
    } catch {
        return null;
    }
}

export default async function TrackPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale);
    const k = t.track;
    const query = await searchParams;
    const lookup = readLookup((await cookies()).get(TRACK_COOKIE)?.value);
    const prefill = lookup?.number ?? (typeof query.number === 'string' ? query.number.slice(0, 32) : '');

    let result: TrackResult | null = null;
    if (lookup) result = lookup.number && lookup.phone ? await trackOrder(lookup.number, lookup.phone, locale) : { ok: false, reason: 'invalid' };
    const order = result?.ok ? result.order : null;
    const error = result && !result.ok ? { 'not-found': k.notFound, invalid: k.invalid, 'too-many': format(k.tooMany, { phone: contact.hotline.display }), failed: k.failed }[result.reason] : null;

    const form = (
        <form method="post" action="/api/track" className="flex flex-col gap-3 rounded-sm border border-line-default bg-surface p-4">
            <input type="hidden" name="locale" value={locale} />
            <Field id="track-number" label={k.number} required help={k.numberHelp}>
                <input id="track-number" name="number" type="text" required maxLength={32} autoComplete="off" spellCheck={false} defaultValue={prefill} placeholder={k.numberPlaceholder} aria-describedby="track-number-help" className={`${inputClass} uppercase tabular-nums placeholder:normal-case`} />
            </Field>
            <Field id="track-phone" label={k.phone} required help={k.phoneHelp}>
                <input id="track-phone" name="phone" type="text" inputMode="numeric" required maxLength={20} autoComplete="off" placeholder={k.phonePlaceholder} aria-describedby="track-phone-help" className={`${inputClass} tabular-nums`} />
            </Field>
            {error ? (
                <p role="alert" className="t-body-sm rounded-xs bg-danger-subtle px-3 py-2 text-feedback-danger">
                    {error}
                </p>
            ) : null}
            <button type="submit" className={`${buttonClass('primary')} w-full`}>
                {k.submit}
            </button>
        </form>
    );

    return (
        <PageShell locale={locale} path={PATH} top="tight">
            <div className="flex flex-col gap-4 pb-8 lg:gap-6 lg:pb-20">
                <header className="flex flex-col gap-1 lg:flex-row lg:items-baseline lg:gap-3">
                    <h1 className="t-h1 text-fg-primary">{k.title}</h1>
                    <p className="t-body text-fg-secondary">{k.sub}</p>
                </header>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                    <div className="order-2 flex min-w-0 flex-col gap-4 lg:order-none lg:grow">
                        {order ? (
                            <>
                                <OrderHeaderCard order={order} t={t} />
                                <OrderTimeline order={order} t={t} />
                                <div className="hidden lg:block">
                                    <DeliveryDetails order={order} locale={locale} t={t} />
                                </div>
                            </>
                        ) : (
                            <p className="t-body hidden rounded-sm bg-surface p-6 text-fg-secondary lg:block">{k.intro}</p>
                        )}
                    </div>
                    <aside className="contents lg:flex lg:w-110 lg:shrink-0 lg:flex-col lg:gap-4">
                        <div className="order-1 flex flex-col gap-2 lg:order-none">
                            <p className="t-body-sm text-fg-secondary lg:hidden">{k.intro}</p>
                            {form}
                        </div>
                        {order ? (
                            <div className="order-3 lg:order-none">
                                <OrderItems order={order} locale={locale} t={t} />
                            </div>
                        ) : null}
                        <div className="order-4 lg:order-none">
                            <OrderHelp t={t} />
                        </div>
                    </aside>
                </div>
            </div>
        </PageShell>
    );
}
