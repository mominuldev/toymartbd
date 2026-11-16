import {
    blockLink,
    bnClass,
    MediaPicture,
} from "@/components/content/blocks/shared";
import type { ImageBlockContent } from "@/lib/content/home-blocks";
import { getMessages, type Locale } from "@/lib/i18n";

// A single image (CMS section `image`, 2026-11-16): one media-library picture in a <figure>, at the
// text width (the prose measure, centred) or the full 1280 content width, with an optional caption
// and an optional link around the image. AVIF/WebP renditions with the intrinsic size set, so the
// box is reserved before it loads (CLS, rule 29); lazy, since a page's LCP is its header or hero.
// An external link opens in a new tab and says so to screen readers.

export function ImageBlock({
    content,
    locale,
}: {
    content: ImageBlockContent;
    locale: Locale;
}) {
    const link = content.href ? blockLink(locale, content.href) : null;
    const wide = content.width === "wide";
    const picture = (
        <MediaPicture
            image={content.image}
            sizes={
                wide
                    ? "(min-width: 80rem) 80rem, 100vw"
                    : "(min-width: 48rem) 42rem, 100vw"
            }
            className="h-auto w-full rounded-md bg-image object-cover"
        />
    );

    return (
        <figure
            className={`mx-auto flex w-full flex-col gap-2 ${
                wide ? "" : "max-w-prose"
            } ${bnClass(locale)}`}
        >
            {link ? (
                <a
                    href={link.href}
                    {...(link.external
                        ? { target: "_blank", rel: "noopener noreferrer" }
                        : {})}
                    className="block rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-line-focus"
                >
                    {picture}
                    {link.external ? (
                        <span className="sr-only">
                            {" "}
                            {getMessages(locale).chat.newTab}
                        </span>
                    ) : null}
                </a>
            ) : (
                picture
            )}
            {content.caption ? (
                <figcaption className="t-caption text-center text-fg-secondary">
                    {content.caption}
                </figcaption>
            ) : null}
        </figure>
    );
}
