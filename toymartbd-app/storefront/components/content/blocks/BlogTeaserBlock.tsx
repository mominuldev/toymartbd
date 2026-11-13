import { bnClass } from '@/components/content/blocks/shared';
import { BlogCard } from '@/components/blog/BlogCard';
import { SectionHeader } from '@/components/listing/ProductRail';
import { blogMessages } from '@/lib/blog/messages';
import type { BlogCard as Card } from '@/lib/blog/types';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';

// Blog teaser (storefront spec B1.8, Figma Home "Blog" row): header + sub-line, then the blog's own
// BlogCard (components/blog) — a 280-wide swipe rail below 1024, a 3-up row from 1024. The posts
// come resolved with the homepage (StorefrontBlogPostCard, the same shape as getBlogTeaser()).

export function BlogTeaserBlock({ id, title, sub, posts, locale, t }: { id: string; title: string; sub: string | null; posts: Card[]; locale: Locale; t: Messages['home'] }) {
    const headingId = `cms-${id}-title`;
    const bt = blogMessages(locale);
    return (
        <section aria-labelledby={headingId} className={`flex flex-col gap-3 lg:gap-6 ${bnClass(locale)}`}>
            <div className="flex flex-col gap-1">
                <SectionHeader id={headingId} title={title} action={{ href: localizedPath(locale, '/blog'), label: t.seeAll, ariaLabel: format(t.seeAllLabel, { section: title }) }} />
                {sub ? <p className="t-body-sm text-fg-secondary">{sub}</p> : null}
            </div>
            <ul className="relative -mx-gutter-mobile flex snap-x snap-mandatory gap-3 overflow-x-auto px-gutter-mobile pb-2 md:-mx-gutter-tablet md:px-gutter-tablet lg:mx-0 lg:grid lg:grid-cols-3 lg:gap-6 lg:overflow-visible lg:p-0">
                {posts.map((post) => (
                    <li key={post.id} className="w-70 shrink-0 snap-start lg:w-auto">
                        <BlogCard post={post} locale={locale} t={bt} as="h3" />
                    </li>
                ))}
            </ul>
        </section>
    );
}
