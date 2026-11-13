import { Field, inputClass, textareaClass } from '@/components/cart/fields';
import { Icon } from '@/components/icons/Icon';
import { iconPaths } from '@/components/icons/paths';
import { buttonClass } from '@/components/layout/button-styles';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { ratingValue, starFills } from '@/lib/product/format';
import type { ReviewPage, StorefrontReview } from '@/lib/reviews/fetch';
import type { ReviewMessages } from '@/lib/reviews/messages';

export type ReviewOutcome = 'sent' | 'duplicate' | 'invalid' | 'failed';

interface Props {
    page: ReviewPage;
    productId: number;
    slug: string;
    locale: Locale;
    m: ReviewMessages;
    /** The cursor of the next page, as a link (`?reviews=<cursor>#reviews`). */
    moreHref: string | null;
    outcome: ReviewOutcome | null;
}

/**
 * "12 Oct 2026" / "12 অক্টোবর 2026" in Dhaka time, Western digits in both locales (rule 36). The
 * Bangla page names the month in Bangla (the catalogue's month names) — an English "Oct" there was
 * untranslated copy (rule 35; Phase 9 bilingual sweep).
 */
function reviewDate(iso: string, locale: Locale): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    // en-GB order ("12 Oct 2026") with en-US short months: en-GB's ICU spells September "Sept".
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Dhaka', day: 'numeric', month: locale === 'bn' ? 'numeric' : 'short', year: 'numeric' }).formatToParts(date);
    const pick = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
    const month = locale === 'bn' ? (getMessages('bn').delivery.months[Number(pick('month')) - 1] ?? pick('month')) : pick('month');
    return `${pick('day')} ${month} ${pick('year')}`;
}

function initial(name: string): string {
    return (Array.from(name.trim())[0] ?? '').toUpperCase();
}

function Stars({ rating, size = 'sm' }: { rating: number; size?: 'sm' | 'md' }) {
    const box = size === 'md' ? 'size-4' : 'size-3.5';
    return (
        <span className="flex items-center gap-0.5" aria-hidden="true">
            {starFills(rating).map((fill, i) => (
                <svg key={i} viewBox="0 0 256 256" className={`${box} shrink-0 stroke-line-strong [stroke-width:14] ${fill > 0 ? 'fill-accent' : 'fill-subtle'}`}>
                    {iconPaths.starFill.map((p) => (
                        <path key={p} d={p} />
                    ))}
                </svg>
            ))}
        </span>
    );
}

/**
 * Reviews section of the product page (P4-CAT-12) — Figma "Product detail" Reviews 135:1600: a
 * 400-wide summary card (title, score + count, 5-row histogram, "Write a review") beside an 840-wide
 * list of `ReviewCard`s (03-storefront-spec A21: avatar initial, name, date, stars, verified badge,
 * body, shop reply); stacked on mobile. Every figure is the API's: the average, the count and the
 * histogram come from `meta.summary` (rule 1). Only approved reviews are ever returned.
 *
 * The write form is a plain `<form method="post">` to /api/account/reviews (works without JS); a
 * signed-out shopper is sent to sign-in and back. The page is cached, so it never knows who is signed
 * in — the hint under the form says sign-in may be asked for.
 */
export function ProductReviews({ page, productId, slug, locale, m, moreHref, outcome }: Props) {
    const { summary } = page.meta;
    const count = summary.rating_count;
    const rated = summary.rating_avg !== null && count > 0;
    const max = Math.max(1, ...Object.values(summary.distribution));

    const histogram = rated ? (
        <ul className="flex min-w-0 grow flex-col gap-1.5 lg:w-full lg:gap-3">
            {['5', '4', '3', '2', '1'].map((stars) => {
                const n = summary.distribution[stars] ?? 0;
                return (
                    <li key={stars} className="flex items-center gap-2" aria-label={format(m.histogramAria, { stars, count: String(n) })}>
                        <span aria-hidden="true" className="t-caption-strong w-5 shrink-0 text-fg-secondary tabular-nums">
                            {stars}★
                        </span>
                        <span aria-hidden="true" className="h-1 grow overflow-hidden rounded-full bg-subtle">
                            {/* Width is the API's count over the API's largest count — a ratio for display, not a figure. */}
                            <span className="block h-full rounded-full bg-brand" style={{ width: `${Math.round((n / max) * 100)}%` }} />
                        </span>
                    </li>
                );
            })}
        </ul>
    ) : null;

    return (
        <section id="reviews" aria-labelledby="pdp-reviews-title" className="scroll-mt-36 flex flex-col gap-3 lg:flex-row lg:items-start lg:gap-10">
            {/* 390 (128:1200): "Reviews" above a compact card — score + count beside the histogram.
                1440 (135:1601): one 400-wide card — title, score "from N reviews", histogram, Write a review. */}
            <h2 id="pdp-reviews-title" className="t-h3 text-fg-primary lg:hidden">
                {m.title}
            </h2>
            <div className="flex flex-col gap-4 rounded-sm border border-line-default bg-surface p-4 lg:sticky lg:top-34 lg:w-100 lg:shrink-0 lg:gap-3 lg:p-6">
                {/* The 1440 title; the 390 one above is display:none here, so each width has exactly one h2. */}
                <h2 className="t-h2 hidden text-fg-primary lg:block">{m.title}</h2>
                {rated && summary.rating_avg ? (
                    <div className="flex items-start gap-5 lg:flex-col lg:gap-3">
                        <p className="flex shrink-0 flex-col items-center gap-0.5 lg:flex-row lg:items-baseline lg:gap-2.5" aria-label={format(m.ratingAria, { rating: ratingValue(summary.rating_avg) })}>
                            <span className="t-price-lg text-fg-primary tabular-nums">{ratingValue(summary.rating_avg)}</span>
                            <span className="t-caption lg:t-body-sm text-fg-secondary tabular-nums lg:hidden">{format(m.reviewsCount, { count: String(count) })}</span>
                            <span className="t-body-sm hidden text-fg-secondary tabular-nums lg:inline">{format(m.fromBuyers, { count: String(count) })}</span>
                        </p>
                        {histogram}
                    </div>
                ) : (
                    <div className="flex flex-col gap-1">
                        <p className="t-h4 text-fg-primary">{m.noneTitle}</p>
                        <p className="t-body-sm text-fg-secondary">{m.noneBody}</p>
                    </div>
                )}

                {outcome ? (
                    <p role="status" className={`t-body-sm rounded-md px-3 py-2 ${outcome === 'sent' ? 'bg-subtle text-fg-primary' : 'bg-brand-subtle text-fg-primary'}`}>
                        {m[outcome]}
                    </p>
                ) : null}

                <details className="group" open={outcome === 'invalid' || outcome === 'failed'}>
                    {/* Button Outline Size=md, full width (135:1636). */}
                    <summary className={`${outlineButton} w-full cursor-pointer list-none [&::-webkit-details-marker]:hidden`}>{m.write}</summary>
                    <form method="post" action="/api/account/reviews" className="mt-4 flex flex-col gap-4">
                        <input type="hidden" name="locale" value={locale} />
                        <input type="hidden" name="slug" value={slug} />
                        <input type="hidden" name="product_id" value={productId} />
                        <fieldset className="flex flex-col gap-2">
                            <legend className="t-field-label text-fg-primary">{m.formRating}</legend>
                            <div className="flex flex-row-reverse justify-end gap-1">
                                {[5, 4, 3, 2, 1].map((n) => (
                                    <label key={n} className="flex size-11 cursor-pointer items-center justify-center rounded-md border border-line-default has-checked:border-line-brand has-checked:bg-brand-subtle">
                                        <input type="radio" name="rating" value={n} required className="sr-only" />
                                        <span className="t-body-strong text-fg-primary tabular-nums" aria-hidden="true">
                                            {n}★
                                        </span>
                                        <span className="sr-only">{format(m.formStar, { count: String(n) })}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <Field id="review-title" label={m.formTitle}>
                            <input id="review-title" name="title" maxLength={160} className={inputClass} />
                        </Field>
                        <Field id="review-body" label={m.formBody} help={m.formBodyHint}>
                            <textarea id="review-body" name="body" required minLength={10} maxLength={5000} rows={4} aria-describedby="review-body-help" className={textareaClass} />
                        </Field>
                        <Field id="review-name" label={m.formName} help={m.formNameHint}>
                            <input id="review-name" name="reviewer_name" maxLength={120} aria-describedby="review-name-help" className={inputClass} />
                        </Field>
                        <button type="submit" className={`${buttonClass('primary', 'md')} w-full`}>
                            {m.submit}
                        </button>
                        <p className="t-caption text-fg-tertiary">{m.signInHint}</p>
                    </form>
                </details>
            </div>

            {page.data.length > 0 ? (
                <div className="flex min-w-0 grow flex-col">
                    <ul className="flex flex-col">
                        {page.data.map((review) => (
                            <ReviewCard key={review.id} review={review} m={m} locale={locale} />
                        ))}
                    </ul>
                    {moreHref ? (
                        <a href={localizedPath(locale, moreHref)} className={`${outlineButton} mt-4 w-full lg:mt-6 lg:w-fit lg:self-start`}>
                            {m.more}
                        </a>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}

/** Button Outline (29:316): white, 2px soft-pink border, coral label; 44 tall (Size=md). */
const outlineButton =
    't-button relative isolate inline-flex h-control-md min-w-32 items-center justify-center rounded-full border-2 border-line-brand-subtle bg-surface px-5 text-fg-brand transition-transform duration-(--tm-duration-fast) ease-standard hover:bg-subtle active:scale-98';

/** Bodies longer than this get the 4-line clamp and "Read more" (A21). A length, not a measurement. */
const CLAMP_FROM = 220;

/** ReviewCard (Figma 121:1860, in 135:1654 / 128:1200): surface, bottom hairline, 16 inset, 10 gap.
 *  Avatar 40 (hairline ring, initials) · name · date · stars + score; mint "Verified purchase"; body
 *  clamped at 4 lines with "Read more" (a checkbox toggle, so it works without JavaScript); the shop
 *  reply in a brand-subtle box. Not built (not collected yet): the "Bought: … · child aged 5" context
 *  line, photos and the helpful / report row. */
function ReviewCard({ review, m, locale }: { review: StorefrontReview; m: ReviewMessages; locale: Locale }) {
    const long = (review.body?.length ?? 0) > CLAMP_FROM;
    const toggle = `review-${review.id}-more`;
    return (
        <li className="flex flex-col items-start gap-2.5 border-b border-line-default bg-surface p-4">
            <div className="flex w-full items-center gap-3">
                <span aria-hidden="true" className="t-avatar-initials-md flex size-10 shrink-0 items-center justify-center rounded-full border border-line-default bg-surface text-fg-primary">
                    {initial(review.reviewer_name)}
                </span>
                <div className="flex min-w-0 grow flex-col">
                    <p className="t-body-sm-strong text-fg-primary">{review.reviewer_name}</p>
                    <p className="t-caption text-fg-tertiary tabular-nums">{reviewDate(review.created_at, locale)}</p>
                </div>
                <span className="flex h-5 items-center gap-2" role="img" aria-label={format(m.ratingAria, { rating: String(review.rating) })}>
                    <Stars rating={review.rating} />
                    <span aria-hidden="true" className="t-caption text-fg-primary tabular-nums">
                        {review.rating.toFixed(1)}
                    </span>
                </span>
            </div>
            {review.is_verified_purchase ? (
                <span className="t-caption-strong inline-flex items-center gap-1 rounded-full bg-success-subtle px-2 py-0.75 text-status-in-stock">
                    <Icon name="shieldCheck" className="size-3" />
                    {m.verified}
                </span>
            ) : null}
            {review.title ? (
                <p lang={review.locale} className="t-body-sm-strong text-fg-primary">
                    {review.title}
                </p>
            ) : null}
            {review.body ? (
                long ? (
                    <div className="flex flex-col items-start gap-2.5">
                        <input id={toggle} type="checkbox" className="peer sr-only" />
                        <p lang={review.locale} className="t-body-sm lg:t-body line-clamp-4 whitespace-pre-line text-fg-primary peer-checked:line-clamp-none">
                            {review.body}
                        </p>
                        <label htmlFor={toggle} className="t-body-sm-strong cursor-pointer text-fg-brand peer-checked:hidden peer-focus-visible:outline-2 peer-focus-visible:outline-line-focus">
                            {m.readMore}
                        </label>
                    </div>
                ) : (
                    <p lang={review.locale} className="t-body-sm lg:t-body whitespace-pre-line text-fg-primary">
                        {review.body}
                    </p>
                )
            ) : null}
            {review.admin_reply ? (
                <div className="flex w-full flex-col gap-1 rounded-sm bg-brand-subtle p-3">
                    <p className="t-caption-strong text-fg-primary">{m.shopReply}</p>
                    <p className="t-body-sm text-fg-primary">{review.admin_reply}</p>
                </div>
            ) : null}
        </li>
    );
}
