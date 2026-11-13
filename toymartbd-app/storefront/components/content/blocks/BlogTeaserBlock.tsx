import { bnClass, MediaPicture } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { SectionHeader } from '@/components/listing/ProductRail';
import { postPath, type BlogCard } from '@/lib/blog/types';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';

// Blog teaser (storefront spec B1.8, Figma Home "Blog" row): header + sub-line, then three blog
// cards — a 280-wide swipe rail below 1024, a 3-up row from 1024. Card: image on top (radius/lg),
// category · read time caption, h4 title clamped to 2 lines at a fixed height. Posts come from the
// blog's own card shape; Bangla falls back to English per field.

export function BlogTeaserBlock({ id, title, sub, posts, locale, t }: { id: string; title: string; sub: string | null; posts: BlogCard[]; locale: Locale; t: Messages['home'] }) {
    const headingId = `cms-${id}-title`;
    const bn = locale === 'bn';
    return (
        <section aria-labelledby={headingId} className={`flex flex-col gap-3 lg:gap-6 ${bnClass(locale)}`}>
            <div className="flex flex-col gap-1">
                <SectionHeader id={headingId} title={title} action={{ href: localizedPath(locale, '/blog'), label: t.seeAll, ariaLabel: format(t.seeAllLabel, { section: title }) }} />
                {sub ? <p className="t-body-sm text-fg-secondary">{sub}</p> : null}
            </div>
            <ul className="relative -mx-gutter-mobile flex snap-x snap-mandatory gap-3 overflow-x-auto px-gutter-mobile pb-2 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-3 lg:gap-6 lg:overflow-visible lg:p-0">
                {posts.map((post) => {
                    const postTitle = (bn && post.title_bn) || post.title;
                    const category = post.category ? (bn && post.category.name_bn) || post.category.name : null;
                    const minutes = (bn && post.reading_minutes_bn) || post.reading_minutes;
                    const cover = post.cover ? { ...post.cover, alt: (bn && post.cover.alt_bn) || post.cover.alt || '' } : null;
                    return (
                        <li key={post.id} className="w-70 shrink-0 snap-start lg:w-auto">
                            <article className="flex h-full flex-col overflow-hidden rounded-lg border border-line-brand-subtle bg-surface shadow-e1">
                                <div className="aspect-video w-full overflow-hidden rounded-t-lg bg-image">
                                    {cover ? (
                                        <MediaPicture image={cover} sizes="(min-width: 64rem) 26rem, 17.5rem" className="size-full object-cover" />
                                    ) : (
                                        <span aria-hidden="true" className="flex size-full items-center justify-center text-fg-tertiary">
                                            <Icon name="books" className="size-icon-category" />
                                        </span>
                                    )}
                                </div>
                                <div className="flex flex-col gap-1 p-4">
                                    <p className="t-caption text-fg-tertiary">{[category, format(t.readingTime, { minutes: String(minutes) })].filter(Boolean).join(' · ')}</p>
                                    <h3 lang={bn && !post.title_bn ? 'en' : undefined} className="t-h4 line-clamp-2 h-[2lh] text-fg-primary">
                                        <a href={localizedPath(locale, postPath(post))} className="hover:text-fg-link">
                                            {postTitle}
                                        </a>
                                    </h3>
                                </div>
                            </article>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
