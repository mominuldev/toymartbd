import { CategoryTile } from '@/components/CategoryTile';
import { SectionHeader } from '@/components/listing/ProductRail';
import type { CategoryNode } from '@/lib/category-tree';
import { format, localizedPath, type Locale, type Messages } from '@/lib/i18n';

// "Shop by category" (Figma Home 133:8 / 126:10): CategoryTile 110:701, 2-up below 1024 and
// 4-up from 1024. The categories come resolved from the API (top level or a manual pick).

export function CategoryTilesBlock({ id, title, showSeeAll, categories, locale, t }: { id: string; title: string; showSeeAll: boolean; categories: CategoryNode[]; locale: Locale; t: Messages['home'] }) {
    const headingId = `cms-${id}-title`;
    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4 lg:gap-6">
            <SectionHeader
                id={headingId}
                title={title}
                action={showSeeAll ? { href: localizedPath(locale, '/categories'), label: t.seeAll, ariaLabel: format(t.seeAllLabel, { section: title }) } : undefined}
            />
            <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-6">
                {categories.map((node, i) => (
                    <li key={node.slug}>
                        <CategoryTile node={node} locale={locale} index={i} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
