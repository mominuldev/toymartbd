import { Icon } from '@/components/icons/Icon';

interface Props {
    message: string;
    /** `en` when a Bangla page shows the banner's English text. */
    lang?: 'en';
    /** Optional link (CMS announcement banner), already locale-resolved. */
    link?: { href: string; label: string; target?: '_blank'; rel?: string } | null;
}

// Figma AnnouncementBar 123:1729: a thin promise strip above the header (36 tall) on the candy
// bloom gradient. One true message at a time, never auto-rotating, not sticky. Home only in the
// design (Home 126:10 / 133:8); the 404, 500 and utility pages do not carry it. The message is the
// top live `announcement_bar` banner from the CMS, or the built-in COD promise when none is live.
export function AnnouncementBar({ message, lang, link = null }: Props) {
    return (
        <p className="t-body-sm flex min-h-9 items-center justify-center gap-2 bg-(image:--tm-gradient-bloom-candy) px-gutter-mobile text-center font-semibold text-fg-primary">
            <Icon name="sparkle" className="size-3.5 text-fg-brand" />
            <span lang={lang}>
                {message}
                {link ? (
                    <>
                        {' '}
                        <a href={link.href} target={link.target} rel={link.rel} className="text-fg-brand underline underline-offset-4">
                            {link.label}
                        </a>
                    </>
                ) : null}
            </span>
            <Icon name="sparkle" className="size-3.5 text-fg-brand" />
        </p>
    );
}
