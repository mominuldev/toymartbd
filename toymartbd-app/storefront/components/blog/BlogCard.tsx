import { Icon } from '@/components/icons/Icon';
import { localizedPath, type Locale } from '@/lib/i18n';
import { pickOptional, postTitle, readTime, termName } from '@/lib/blog/format';
import type { BlogMessages } from '@/lib/blog/messages';
import { postPath, type BlogCard as Card } from '@/lib/blog/types';

// Blog card — storefront spec §B1.8 (Home blog teaser): a 16:9 cover with `radius/lg` top, then
// padding `spacing/4`: a `caption` tertiary line (category · read time) and an `h4` title clamped to
// two lines at a fixed height. Used by the home teaser (agent 1), the blog/guides lists and
// "Keep reading". Server-rendered; the whole card is one link target via the title.

interface Props {
    post: Card;
    locale: Locale;
    t: BlogMessages;
    /** The first row is above the fold: load eagerly. */
    priority?: boolean;
    /** Heading level inside its section (lists: h2, rails under an h2: h3). */
    as?: 'h2' | 'h3';
}

export function BlogCard({ post, locale, t, priority = false, as: Heading = 'h2' }: Props) {
    const title = postTitle(post, locale);
    const href = localizedPath(locale, postPath(post));
    const cover = post.cover;
    const alt = cover ? (pickOptional(locale, cover.alt, cover.alt_bn) ?? '') : '';
    const category = post.category ? termName(post.category, locale) : null;
    const meta = [category?.text ?? (post.type === 'buying_guide' ? t.guideBadge : null), readTime(post, locale, t)].filter(Boolean).join(' · ');

    return (
        <article className="flex h-full flex-col overflow-hidden rounded-lg bg-surface shadow-e1">
            <a href={href} tabIndex={-1} aria-hidden="true" className="relative block aspect-16/9 w-full overflow-hidden rounded-t-lg bg-tile-sky">
                {cover ? (
                    <picture>
                        {cover.srcset_avif ? <source type="image/avif" srcSet={cover.srcset_avif} sizes="(min-width: 64rem) 26rem, 80vw" /> : null}
                        {cover.srcset_webp ? <source type="image/webp" srcSet={cover.srcset_webp} sizes="(min-width: 64rem) 26rem, 80vw" /> : null}
                        <img
                            src={cover.url}
                            alt={alt}
                            width={cover.width ?? undefined}
                            height={cover.height ?? undefined}
                            loading={priority ? 'eager' : 'lazy'}
                            fetchPriority={priority ? 'high' : undefined}
                            decoding="async"
                            className="size-full object-cover"
                        />
                    </picture>
                ) : (
                    <span className="flex size-full items-center justify-center text-fg-tertiary">
                        <Icon name="puzzlePiece" className="size-icon-category" />
                    </span>
                )}
            </a>
            <div className="flex grow flex-col gap-1 p-4">
                <p className="t-caption text-fg-tertiary" lang={category?.lang}>
                    {meta}
                </p>
                <Heading className="t-h4 line-clamp-2 h-[2lh] text-fg-primary" lang={title.lang}>
                    <a href={href} className="rounded-xs hover:text-fg-link">
                        {title.text}
                    </a>
                </Heading>
            </div>
        </article>
    );
}
