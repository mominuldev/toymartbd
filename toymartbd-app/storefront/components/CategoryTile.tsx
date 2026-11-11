import { Icon } from '@/components/icons/Icon';
import { iconPaths, type IconName } from '@/components/icons/paths';
import { categoryName, categoryNameIsFallback, categoryPath, type CategoryNode } from '@/lib/category-tree';
import { localizedPath, type Locale } from '@/lib/i18n';

// Figma CategoryTile 110:701: pastel tile-* ground, white rim, the category icon on a white
// bubble in the theme ink, a centred Fredoka h4 label. Size sm (mobile, 148 tall) / md (≥1024,
// 180 tall). Themes cycle Pink → Butter → Mint → Sky → Lavender so neighbours never repeat.
// Rim: border-width/rim (3px) in color/bg/surface. Lift: the theme's Glow/Tile-* effect; hover e3
// (the Glow/Tile-* description). The product count line is left out: the categories tree endpoint
// returns no counts yet.
const themes = [
    { ground: 'bg-tile-pink shadow-glow-tile-pink', ink: 'text-fg-brand' },
    { ground: 'bg-tile-butter shadow-glow-tile-butter', ink: 'text-fg-primary' },
    { ground: 'bg-tile-mint shadow-glow-tile-mint', ink: 'text-feedback-success' },
    { ground: 'bg-tile-sky shadow-glow-tile-sky', ink: 'text-fg-link' },
    { ground: 'bg-tile-lavender shadow-glow-tile-lavender', ink: 'text-grape' },
] as const;

/** The API sends a Phosphor icon name in kebab case (`graduation-cap`); unknown or null → puzzle piece. */
export function categoryIcon(name: string | null): IconName {
    if (!name) return 'puzzlePiece';
    const key = name.replace(/-([a-z])/g, (_, c: string) => c.toUpperCase());
    return key in iconPaths ? (key as IconName) : 'puzzlePiece';
}

export function CategoryTile({ node, locale, index }: { node: CategoryNode; locale: Locale; index: number }) {
    const theme = themes[index % themes.length]!;
    return (
        <a
            href={localizedPath(locale, categoryPath(node.slug))}
            className={`flex h-37 flex-col items-center justify-center rounded-lg border-(length:--tm-border-width-rim) border-surface p-3 transition-[translate,box-shadow] duration-(--tm-duration-base) ease-standard hover:-translate-y-0.5 hover:shadow-e3 lg:h-45 lg:p-4 ${theme.ground}`}
        >
            <span aria-hidden="true" className={`inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-surface/85 lg:size-19 ${theme.ink}`}>
                <Icon name={categoryIcon(node.icon)} className="size-10 lg:size-icon-category" />
            </span>
            <span
                lang={categoryNameIsFallback(node, locale) ? 'en' : undefined}
                className="t-h4 line-clamp-2 w-full pt-2 text-center text-fg-primary lg:pt-2.5"
            >
                {categoryName(node, locale)}
            </span>
        </a>
    );
}
