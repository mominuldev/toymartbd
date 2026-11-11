import { categoryName, categoryNameIsFallback, categoryPath, type CategoryNode } from '@/lib/category-tree';
import { localizedPath, type Locale } from '@/lib/i18n';

// A category and its children as plain nested links — the categories index, the category stub
// page and, functionally, the no-JavaScript / mobile fallback for the mega menu.
export function CategoryLinks({ nodes, locale, headingLevel = 2 }: { nodes: CategoryNode[]; locale: Locale; headingLevel?: 2 | 3 }) {
    const Heading = headingLevel === 2 ? 'h2' : 'h3';
    return (
        <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {nodes.map((node) => (
                <li key={node.slug} className="rounded-lg border border-line-default bg-surface p-5 shadow-e1">
                    <Heading className="t-h3" lang={categoryNameIsFallback(node, locale) ? 'en' : undefined}>
                        <a href={localizedPath(locale, categoryPath(node.slug))} className="rounded-xs text-fg-primary underline-offset-4 hover:underline">
                            {categoryName(node, locale)}
                        </a>
                    </Heading>
                    {node.children.length > 0 ? (
                        <ul className="mt-2 flex flex-col">
                            {node.children.map((child) => (
                                <li key={child.slug}>
                                    <a
                                        href={localizedPath(locale, categoryPath(child.slug))}
                                        lang={categoryNameIsFallback(child, locale) ? 'en' : undefined}
                                        className="t-body-sm inline-flex min-h-touch-min items-center rounded-xs text-fg-secondary underline-offset-4 hover:text-fg-link hover:underline"
                                    >
                                        {categoryName(child, locale)}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}
