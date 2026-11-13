import { ProductCard } from '@/components/listing/ProductCard';
import { ProductRail } from '@/components/listing/ProductRail';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { ageBandRange, budgetLabel, dhakaIsoDate, pick, pickOptional, postDate, postTitle, readTime, termName } from '@/lib/blog/format';
import type { BlogMessages } from '@/lib/blog/messages';
import { isBlogCard, postPath, termPath, type BlogPost } from '@/lib/blog/types';
import { ageLabel } from '@/lib/listing/format';
import { isCard, type ProductCard as Card } from '@/lib/listing/response';
import { descriptionContext, sanitizeDescription } from '@/lib/product/description';
import { BlogCard } from './BlogCard';
import { Breadcrumbs } from './Breadcrumbs';

// A blog post or buying guide (Phase 7). No storefront article frame exists in Figma yet: the page
// follows the static-page article language (§B8 — Bloom header, prose column) with the blog card
// (§B1.8) for "Keep reading" and the listing ProductCard for picks, so every value is a token.
//
// Body HTML arrives sanitised from the API and is filtered again here (lib/product/description,
// the same allowlist) before it is set as markup. Guide picks and related products are product
// cards with live price and stock; the API omits unbuyable ones. Recommendations never appear on
// checkout (rule 25b) — this page is content, not checkout.

const proseClass = [
    'flex flex-col gap-5 t-body-lg text-fg-primary',
    '[&_h2]:t-h3 [&_h3]:t-h4',
    '[&_ul]:list-disc [&_ul]:ps-6 [&_ol]:list-decimal [&_ol]:ps-6 [&_li]:mt-1',
    '[&_blockquote]:border-s-4 [&_blockquote]:border-line-default [&_blockquote]:ps-4 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg',
].join(' ');

function Prose({ html, locale, fallback }: { html: string | null; locale: Locale; fallback: boolean }) {
    const clean = sanitizeDescription(html, descriptionContext(process.env.API_URL, []));
    if (!clean) return null;
    return <div lang={fallback ? 'en' : undefined} className={`${proseClass} ${locale === 'bn' && !fallback ? 'lang-bn' : ''}`} dangerouslySetInnerHTML={{ __html: clean }} />;
}

function cards(picks: Array<{ product: unknown; note?: string | null; note_bn?: string | null }>): Array<{ card: Card; note: string | null; noteBn: string | null }> {
    return picks.flatMap((p) => (isCard(p.product) ? [{ card: p.product, note: p.note ?? null, noteBn: p.note_bn ?? null }] : []));
}

interface Props {
    post: BlogPost;
    locale: Locale;
    t: BlogMessages;
    /** Shown above the article on the private preview route. */
    preview?: boolean;
}

export function BlogArticle({ post, locale, t, preview = false }: Props) {
    const listing = getMessages(locale).listing;
    const title = postTitle(post, locale);
    const guide = post.type === 'buying_guide';
    const sectionRoot = guide ? { label: t.guidesTitle, path: '/guides' } : { label: t.title, path: '/blog' };
    const category = post.category ? termName(post.category, locale) : null;
    const author = post.author;
    const authorName = author ? termName(author, locale) : null;
    const bnBody = locale === 'bn' && !!post.body_bn;
    const body = locale === 'bn' && post.body_bn ? post.body_bn : post.body;
    const excerpt = pickOptional(locale, post.excerpt, post.excerpt_bn);
    const cover = post.cover;
    const age = ageBandRange(post.guide_age_band);
    const ageText = age ? ageLabel(age.min, age.max, listing) : null;
    const budget = budgetLabel(post.guide_budget_min, post.guide_budget_max, t);
    const related = cards(post.related_products);
    const date = postDate(post.published_at, locale);
    const relatedPosts = post.related_posts.filter(isBlogCard);

    return (
        <article className="flex flex-col gap-8 lg:gap-12">
            {preview ? (
                <p role="status" className="t-body-sm-strong rounded-lg bg-warning-subtle px-4 py-3 text-fg-primary">
                    {t.previewBanner}
                </p>
            ) : null}
            <Breadcrumbs
                locale={locale}
                label={t.breadcrumb}
                current={localizedPath(locale, postPath(post))}
                crumbs={[{ label: t.home, path: '/' }, sectionRoot, ...(category && post.category ? [{ label: category.text, lang: category.lang, path: termPath('category', post.category.slug) }] : []), { label: title.text, lang: title.lang, path: null }]}
            />

            <header className="flex flex-col gap-4 rounded-lg-plus bg-(image:--tm-gradient-bloom-sky) p-6 lg:rounded-xl-plus lg:p-10">
                {guide ? <p className="t-overline self-start rounded-full bg-surface px-3.5 py-1.5 text-fg-brand">{t.guideBadge}</p> : null}
                <h1 className="t-display-2 max-w-4xl text-fg-primary lg:t-display-1" lang={title.lang}>
                    {title.text}
                </h1>
                {excerpt ? <p className="t-body max-w-prose text-fg-secondary lg:t-body-lg">{excerpt}</p> : null}
                <p className="t-body-sm flex flex-wrap gap-x-2 gap-y-1 text-fg-secondary">
                    {authorName && author ? (
                        <a href={localizedPath(locale, termPath('author', author.slug))} lang={authorName.lang} className="text-fg-link underline-offset-2 hover:underline">
                            {format(t.by, { name: authorName.text })}
                        </a>
                    ) : null}
                    {date ? <time dateTime={dhakaIsoDate(post.published_at)}>{format(t.publishedOn, { date })}</time> : null}
                    <span>{readTime(post, locale, t)}</span>
                    {ageText ? <span>{format(t.forAges, { range: ageText })}</span> : null}
                    {budget ? <span className="tabular-nums">{budget}</span> : null}
                </p>
            </header>

            {cover ? (
                <picture className="block overflow-hidden rounded-lg-plus bg-tile-sky lg:rounded-xl-plus">
                    {cover.srcset_avif ? <source type="image/avif" srcSet={cover.srcset_avif} sizes="(min-width: 80rem) 80rem, 100vw" /> : null}
                    {cover.srcset_webp ? <source type="image/webp" srcSet={cover.srcset_webp} sizes="(min-width: 80rem) 80rem, 100vw" /> : null}
                    <img
                        src={cover.url}
                        alt={pickOptional(locale, cover.alt, cover.alt_bn) ?? ''}
                        width={cover.width ?? undefined}
                        height={cover.height ?? undefined}
                        fetchPriority="high"
                        decoding="async"
                        className="aspect-16/9 w-full object-cover"
                    />
                </picture>
            ) : null}

            <div className="mx-auto flex w-full max-w-prose flex-col gap-8">
                {locale === 'bn' && !bnBody && post.body ? <p className="t-caption text-fg-tertiary">{t.fallbackLang}</p> : null}
                <Prose html={body ?? null} locale={locale} fallback={locale === 'bn' && !bnBody} />

                {post.sections.map((section, i) => {
                    const heading = pick(locale, section.heading, section.heading_bn);
                    const sectionBn = locale === 'bn' && !!section.body_bn;
                    const sectionAge = ageBandRange(section.age_band);
                    const sectionAgeText = sectionAge ? ageLabel(sectionAge.min, sectionAge.max, listing) : null;
                    const sectionBudget = budgetLabel(section.budget_min, section.budget_max, t);
                    const picks = cards(section.picks);
                    return (
                        <section key={`${section.heading}-${i}`} aria-labelledby={`guide-section-${i}`} className="flex flex-col gap-4">
                            <h2 id={`guide-section-${i}`} className="t-h2 text-fg-primary" lang={heading.lang}>
                                {heading.text}
                            </h2>
                            {sectionAgeText || sectionBudget ? (
                                <p className="flex flex-wrap gap-2">
                                    {sectionAgeText ? <span className="t-caption-strong rounded-full bg-tile-mint px-3 py-1 text-fg-primary">{format(t.forAges, { range: sectionAgeText })}</span> : null}
                                    {sectionBudget ? <span className="t-caption-strong rounded-full bg-tile-butter px-3 py-1 tabular-nums text-fg-primary">{sectionBudget}</span> : null}
                                </p>
                            ) : null}
                            <Prose html={(locale === 'bn' && section.body_bn ? section.body_bn : section.body) ?? null} locale={locale} fallback={locale === 'bn' && !sectionBn && !!section.body} />
                            {picks.length > 0 ? (
                                <ul aria-label={t.ourPicks} className="grid grid-cols-2 gap-3 lg:gap-6">
                                    {picks.map(({ card, note, noteBn }) => {
                                        const why = pickOptional(locale, note, noteBn);
                                        return (
                                            <li key={card.id} className="flex flex-col gap-2">
                                                <ProductCard product={card} locale={locale} t={listing} />
                                                {why ? (
                                                    <p className="t-body-sm text-fg-secondary">
                                                        <span className="t-body-sm-strong text-fg-primary">{t.whyPicked}: </span>
                                                        {why}
                                                    </p>
                                                ) : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : null}
                        </section>
                    );
                })}

                {post.tags.length > 0 ? (
                    <nav aria-label={t.tags}>
                        <ul className="flex flex-wrap gap-2">
                            {post.tags.map((tag) => {
                                const name = termName(tag, locale);
                                return (
                                    <li key={tag.slug}>
                                        <a href={localizedPath(locale, termPath('tag', tag.slug))} lang={name.lang} className="t-body-sm-strong inline-flex h-9 items-center rounded-full bg-surface px-4 text-fg-primary shadow-e1 hover:text-fg-link">
                                            #{name.text}
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>
                ) : null}

                {author && authorName ? (
                    <aside aria-label={t.aboutAuthor} className="flex items-start gap-4 rounded-lg bg-surface p-5 shadow-e1">
                        {author.avatar ? (
                            <img src={author.avatar.url} alt="" width={64} height={64} loading="lazy" decoding="async" className="size-16 shrink-0 rounded-full object-cover" />
                        ) : null}
                        <div className="flex flex-col gap-1">
                            <p className="t-overline text-fg-tertiary">{t.aboutAuthor}</p>
                            <a href={localizedPath(locale, termPath('author', author.slug))} lang={authorName.lang} className="t-h4 text-fg-primary hover:text-fg-link">
                                {authorName.text}
                            </a>
                            {pickOptional(locale, author.bio, author.bio_bn) ? <p className="t-body-sm text-fg-secondary">{pickOptional(locale, author.bio, author.bio_bn)}</p> : null}
                        </div>
                    </aside>
                ) : null}
            </div>

            <ProductRail id="blog-related-products" title={t.relatedProducts} products={related.map((r) => r.card)} locale={locale} t={listing} />

            {relatedPosts.length > 0 ? (
                <section aria-labelledby="blog-related-posts" className="flex flex-col gap-3 lg:gap-6">
                    <h2 id="blog-related-posts" className="t-h2 text-fg-primary">
                        {t.relatedPosts}
                    </h2>
                    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                        {relatedPosts.map((related) => (
                            <li key={related.id}>
                                <BlogCard post={related} locale={locale} t={t} as="h3" />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </article>
    );
}
