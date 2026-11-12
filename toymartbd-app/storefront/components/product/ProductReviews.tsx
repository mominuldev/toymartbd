import { Field, inputClass, textareaClass } from '@/components/cart/fields';
import { Icon } from '@/components/icons/Icon';
import { iconPaths } from '@/components/icons/paths';
import { buttonClass } from '@/components/layout/button-styles';
import { format, localizedPath, type Locale } from '@/lib/i18n';
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

/** "12 Oct 2026" in Dhaka time, Western digits in both locales (rule 36). */
function reviewDate(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', year: 'numeric' }).format(date);
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

    return (
        <section id="reviews" aria-labelledby="pdp-reviews-title" className="scroll-mt-36 flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-10">
            <div className="flex flex-col gap-3 rounded-lg border border-line-default bg-surface p-6 lg:sticky lg:top-34 lg:w-100 lg:shrink-0">
                <h2 id="pdp-reviews-title" className="t-h3 text-fg-primary">
                    {m.title}
                </h2>
                {rated && summary.rating_avg ? (
                    <p className="flex items-baseline gap-2.5" aria-label={format(m.ratingAria, { rating: ratingValue(summary.rating_avg) })}>
                        <span className="t-rating-numeric-lg text-fg-primary">{ratingValue(summary.rating_avg)}</span>
                        <span className="t-body-sm text-fg-secondary">{format(m.fromBuyers, { count: String(count) })}</span>
                    </p>
                ) : (
                    <div className="flex flex-col gap-1">
                        <p className="t-h4 text-fg-primary">{m.noneTitle}</p>
                        <p className="t-body-sm text-fg-secondary">{m.noneBody}</p>
                    </div>
                )}
                {rated ? (
                    <ul className="flex flex-col gap-3">
                        {['5', '4', '3', '2', '1'].map((stars) => {
                            const n = summary.distribution[stars] ?? 0;
                            return (
                                <li key={stars} className="flex items-center gap-2" aria-label={format(m.histogramAria, { stars, count: String(n) })}>
                                    <span aria-hidden="true" className="t-caption w-5 shrink-0 text-fg-secondary tabular-nums">
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
                ) : null}

                {outcome ? (
                    <p role="status" className={`t-body-sm rounded-md px-3 py-2 ${outcome === 'sent' ? 'bg-subtle text-fg-primary' : 'bg-brand-subtle text-fg-primary'}`}>
                        {m[outcome]}
                    </p>
                ) : null}

                <details className="group" open={outcome === 'invalid' || outcome === 'failed'}>
                    <summary className={`${buttonClass('secondary', 'md')} w-full cursor-pointer list-none [&::-webkit-details-marker]:hidden`}>{m.write}</summary>
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
                            <ReviewCard key={review.id} review={review} m={m} />
                        ))}
                    </ul>
                    {moreHref ? (
                        <a href={localizedPath(locale, moreHref)} className={`${buttonClass('secondary', 'md')} mt-6 self-start`}>
                            {m.more}
                        </a>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}

/** 03-storefront-spec A21 `ReviewCard`, without the helpful/report row and photos (not collected yet). */
function ReviewCard({ review, m }: { review: StorefrontReview; m: ReviewMessages }) {
    return (
        <li className="flex flex-col gap-3 border-b border-line-default py-4 lg:py-5">
            <div className="flex items-center gap-3">
                <span aria-hidden="true" className="t-avatar-initials-md flex size-10 shrink-0 items-center justify-center rounded-full border border-line-default bg-brand-subtle text-fg-primary lg:size-11">
                    {initial(review.reviewer_name)}
                </span>
                <div className="flex min-w-0 grow flex-col">
                    <p className="t-body-sm-strong text-fg-primary">{review.reviewer_name}</p>
                    <p className="t-caption text-fg-tertiary tabular-nums">{reviewDate(review.created_at)}</p>
                </div>
                <span className="flex items-center gap-1.5" role="img" aria-label={format(m.ratingAria, { rating: String(review.rating) })}>
                    <Stars rating={review.rating} />
                    <span aria-hidden="true" className="t-caption text-fg-secondary tabular-nums">
                        {review.rating.toFixed(1)}
                    </span>
                </span>
            </div>
            {review.is_verified_purchase ? (
                <span className="t-caption inline-flex h-5.5 items-center gap-1 self-start rounded-full bg-subtle px-2 text-fg-primary">
                    <Icon name="shieldCheck" className="size-3 text-status-in-stock" />
                    {m.verified}
                </span>
            ) : null}
            {review.title ? (
                <p lang={review.locale} className="t-body-strong text-fg-primary">
                    {review.title}
                </p>
            ) : null}
            {review.body ? (
                <p lang={review.locale} className="t-body-sm whitespace-pre-line text-fg-primary lg:t-body">
                    {review.body}
                </p>
            ) : null}
            {review.admin_reply ? (
                <div className="ms-8 flex flex-col gap-1 rounded-md bg-subtle p-4">
                    <p className="t-caption-strong text-fg-primary">{m.shopReply}</p>
                    <p className="t-body-sm text-fg-primary">{review.admin_reply}</p>
                </div>
            ) : null}
        </li>
    );
}
