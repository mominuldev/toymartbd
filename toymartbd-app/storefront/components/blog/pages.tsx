import 'server-only';

import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';

import { PageShell } from '@/components/PageShell';
import { JsonLd } from '@/components/seo/JsonLd';
import { format, localizedPath, type Locale } from '@/lib/i18n';
import { listBlogCategories, listPosts, type ListQuery } from '@/lib/blog/fetch';
import { pagePath, pick, pickOptional, postTitle, termName } from '@/lib/blog/format';
import { blogMessages } from '@/lib/blog/messages';
import { postPath, termPath, type BlogPage, type BlogPost, type BlogTerm, type BlogTermKind } from '@/lib/blog/types';
import { articleJsonLd, breadcrumbJsonLd } from '@/lib/seo/jsonld';
import { buildMetadata, INDEX, NOINDEX_FOLLOW, NOINDEX_NOFOLLOW, type Robots } from '@/lib/seo/metadata';
import { BlogArticle } from './BlogArticle';
import { BlogList } from './BlogList';
import type { BlogCrumb } from './Breadcrumbs';

// Shared rendering for the blog routes (index, guides, category/tag/author archives, post, guide,
// preview). Pagination SEO: page 1 is the bare URL; later pages are indexable and self-canonical
// (`?page=n` kept in canonical and hreflang) with rel=prev/next; a page past the end is a 404.
// Metadata goes through the SEO module's buildMetadata (canonical, hreflang en/bn/x-default, OG).

export interface ListSpec {
    locale: Locale;
    /** Locale-independent path (`/blog`, `/blog/tag/stem`). */
    path: string;
    page: number;
    title: string;
    titleLang?: 'en';
    intro?: string | null;
    description: string;
    crumbs: BlogCrumb[];
    query: Omit<ListQuery, 'page'>;
    chips?: boolean;
}

export async function listMetadata(spec: Omit<ListSpec, 'crumbs' | 'query' | 'chips'>): Promise<Metadata> {
    const t = blogMessages(spec.locale);
    const title = spec.page > 1 ? format(t.pageTitle, { title: spec.title, page: String(spec.page) }) : spec.title;
    return buildMetadata({ locale: spec.locale, path: pagePath(spec.path, spec.page), title, description: spec.description, robots: INDEX });
}

export async function renderList(spec: ListSpec) {
    const list: BlogPage | null = await listPosts({ ...spec.query, page: spec.page }, spec.locale);
    if (!list) notFound();
    const t = blogMessages(spec.locale);
    const chips = spec.chips ? await categoryChips(spec.locale, spec.path) : [];
    const crumbsLd = spec.crumbs.map((c) => ({ name: c.label, path: c.path ?? spec.path }));

    return (
        <PageShell locale={spec.locale} path={pagePath(spec.path, spec.page)} top="crumb">
            <JsonLd data={breadcrumbJsonLd(spec.locale, crumbsLd)} />
            <BlogList locale={spec.locale} t={t} path={spec.path} title={spec.title} titleLang={spec.titleLang} intro={spec.intro} crumbs={spec.crumbs} chips={chips} list={list} />
        </PageShell>
    );
}

async function categoryChips(locale: Locale, currentPath: string) {
    const t = blogMessages(locale);
    const categories = await listBlogCategories(locale);
    if (categories.length === 0) return [];
    return [
        { label: t.all, href: localizedPath(locale, '/blog'), selected: currentPath === '/blog' },
        ...categories.map((c) => {
            const name = termName(c, locale);
            const path = termPath('category', c.slug);
            return { label: name.text, lang: name.lang, href: localizedPath(locale, path), selected: currentPath === path };
        }),
    ];
}

const termTitleKey = { category: 'categoryTitle', tag: 'tagTitle', author: 'authorTitle' } as const;
const termMetaKey = { category: 'categoryMeta', tag: 'tagMeta', author: 'authorMeta' } as const;

/** An archive page's list spec from its term (title, intro, crumbs, filter). */
export function termSpec(kind: BlogTermKind, term: BlogTerm, locale: Locale, page: number): ListSpec {
    const t = blogMessages(locale);
    const name = termName(term, locale);
    const title = format(t[termTitleKey[kind]], { name: name.text });
    const description = pickOptional(locale, term.description, term.description_bn);
    return {
        locale,
        path: termPath(kind, term.slug),
        page,
        title,
        titleLang: name.lang,
        intro: description,
        description: description ?? format(t[termMetaKey[kind]], { name: name.text }),
        crumbs: [{ label: t.home, path: '/' }, { label: t.title, path: '/blog' }, { label: title, lang: name.lang, path: null }],
        query: { [kind]: term.slug },
        chips: kind === 'category',
    };
}

function robotsOf(value: string | null | undefined): Robots {
    if (value === 'noindex,nofollow') return NOINDEX_NOFOLLOW;
    if (value === 'noindex,follow') return NOINDEX_FOLLOW;
    if (value === 'index,nofollow') return { index: true, follow: false };
    return INDEX;
}

/** A post's metadata: its seo_meta overrides per locale, else title and excerpt; OG article dates. */
export function postMetadata(post: BlogPost, locale: Locale, options: { preview?: boolean } = {}): Metadata {
    const title = postTitle(post, locale).text;
    const seoTitle = pickOptional(locale, post.seo.title, post.seo.title_bn);
    const description = pickOptional(locale, post.seo.description, post.seo.description_bn) ?? pickOptional(locale, post.excerpt, post.excerpt_bn);
    const image = post.seo.og_image ? { url: post.seo.og_image } : post.cover ? { url: post.cover.url, width: post.cover.width, height: post.cover.height, alt: pickOptional(locale, post.cover.alt, post.cover.alt_bn) } : null;
    return buildMetadata({
        locale,
        path: postPath(post),
        title: seoTitle ?? title,
        description,
        image,
        ogType: 'article',
        article: { publishedTime: post.published_at, modifiedTime: post.modified_at },
        canonicalUrl: post.seo.canonical_url,
        robots: options.preview ? NOINDEX_NOFOLLOW : robotsOf(post.seo.robots),
    });
}

/**
 * The post page. The route's section must match the post's type: an article under /guides or a
 * guide under /blog is a permanent redirect to its own section (one URL per post).
 */
export function renderPost(post: BlogPost, locale: Locale, section: 'blog' | 'guides', options: { preview?: boolean } = {}) {
    const own = postPath(post);
    if (!options.preview && !own.startsWith(`/${section}/`)) permanentRedirect(localizedPath(locale, own));
    const t = blogMessages(locale);
    const title = postTitle(post, locale);
    const root = post.type === 'buying_guide' ? { name: t.guidesTitle, path: '/guides' } : { name: t.title, path: '/blog' };
    const category = post.category ? pick(locale, post.category.name, post.category.name_bn) : null;
    const crumbs = [{ name: t.home, path: '/' }, root, ...(category && post.category ? [{ name: category.text, path: termPath('category', post.category.slug) }] : []), { name: title.text, path: own }];
    const author = post.author ? termName(post.author, locale) : null;

    return (
        <PageShell locale={locale} path={own} top="crumb">
            {options.preview ? null : (
                <JsonLd
                    data={[
                        articleJsonLd({
                            locale,
                            path: own,
                            headline: title.text,
                            description: pickOptional(locale, post.excerpt, post.excerpt_bn),
                            images: post.cover ? [post.cover.url] : [],
                            datePublished: post.published_at ?? post.modified_at ?? '',
                            dateModified: post.modified_at,
                            author: author && post.author ? { name: author.text, url: localizedPath(locale, termPath('author', post.author.slug)) } : null,
                            type: post.type === 'buying_guide' ? 'Article' : 'BlogPosting',
                        }),
                        breadcrumbJsonLd(locale, crumbs),
                    ]}
                />
            )}
            <BlogArticle post={post} locale={locale} t={t} preview={options.preview} />
        </PageShell>
    );
}
