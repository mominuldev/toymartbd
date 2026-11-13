import { blockLink, bnClass, MediaPicture } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import type { BlockImage } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';

// Promo split (Phase 7): an image beside a headline, a line of copy and one call to action, on the
// Bloom-candy card of the hero (radius/md). Stacks below 1024, image first. No Figma frame of its
// own — it is the hero's card language at half the height. The image box has a fixed aspect
// ratio, so nothing shifts when it loads.

interface Props {
    id: string;
    content: { overline: string | null; headline: string; body: string | null; cta_label: string | null; cta_href: string | null; image: BlockImage | null; image_side: 'left' | 'right' };
    locale: Locale;
}

export function PromoSplitBlock({ id, content, locale }: Props) {
    const headingId = `cms-${id}-title`;
    const cta = content.cta_href && content.cta_label ? blockLink(locale, content.cta_href) : null;
    return (
        <section aria-labelledby={headingId} className={`flex flex-col gap-5 overflow-hidden rounded-md bg-(image:--tm-gradient-bloom-candy) p-5 lg:flex-row lg:items-center lg:gap-12 lg:p-8 ${content.image_side === 'right' ? 'lg:flex-row-reverse' : ''}`}>
            <div className="relative aspect-video w-full shrink-0 overflow-hidden rounded-sm bg-image lg:w-1/2">
                {content.image ? (
                    <MediaPicture image={content.image} sizes="(min-width: 64rem) 38rem, calc(100vw - 4.5rem)" className="size-full object-cover" />
                ) : (
                    <span aria-hidden="true" className="flex size-full items-center justify-center text-fg-tertiary">
                        <Icon name="puzzlePiece" className="size-icon-category" />
                    </span>
                )}
            </div>
            <div className={`flex min-w-0 flex-1 flex-col gap-3 ${bnClass(locale)}`}>
                {content.overline ? <p className="t-overline text-fg-discount">{content.overline}</p> : null}
                <h2 id={headingId} className="t-h2 text-fg-primary">
                    {content.headline}
                </h2>
                {content.body ? <p className="t-body text-fg-secondary">{content.body}</p> : null}
                {cta ? (
                    <a
                        href={cta.href}
                        rel={cta.external ? 'noopener' : undefined}
                        className="t-button flex h-control-lg items-center justify-center self-start rounded-full bg-(image:--tm-gradient-button-primary) px-6 text-fg-on-brand shadow-gloss-button"
                    >
                        {content.cta_label}
                    </a>
                ) : null}
            </div>
        </section>
    );
}
