import { bloomTone, blockLink, bnClass, tileIcons } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import type { CtaBandContent, CtaButton } from '@/lib/content/home-blocks';
import type { Locale } from '@/lib/i18n';

// Call-to-action band — About us "Come and play at our shop" (170:10502 / 174:8244): a Bloom
// gradient band, the title and a line of copy, with a primary and a secondary button (each with an
// optional icon). Side by side at 1440, stacked at 390. An https link opens in a new tab.

interface Props {
    id: string;
    content: CtaBandContent;
    locale: Locale;
}

function Button({ button, variant, locale }: { button: CtaButton; variant: 'primary' | 'secondary'; locale: Locale }) {
    const link = blockLink(locale, button.href);
    return (
        <a href={link.href} className={buttonClass(variant)} {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {button.icon ? <Icon name={tileIcons[button.icon]} className={buttonIconClass.lg} /> : null}
            {button.label}
        </a>
    );
}

export function CtaBandBlock({ id, content, locale }: Props) {
    const headingId = `cms-${id}-title`;
    return (
        <section
            aria-labelledby={headingId}
            className={`flex flex-col gap-6 rounded-lg-plus p-6 lg:flex-row lg:items-center lg:gap-8 lg:rounded-xl-plus lg:px-14 lg:py-12 ${bloomTone[content.tone]} ${bnClass(locale)}`}
        >
            <div className="flex min-w-0 flex-col gap-2 lg:flex-1">
                <h2 id={headingId} className="t-display-2 text-fg-primary">
                    {content.title}
                </h2>
                {content.body ? <p className="t-body-lg text-fg-secondary">{content.body}</p> : null}
            </div>
            {content.primary || content.secondary ? (
                <div className="flex flex-col gap-2.5 lg:flex-row lg:gap-3">
                    {content.primary ? <Button button={content.primary} variant="primary" locale={locale} /> : null}
                    {content.secondary ? <Button button={content.secondary} variant="secondary" locale={locale} /> : null}
                </div>
            ) : null}
        </section>
    );
}
