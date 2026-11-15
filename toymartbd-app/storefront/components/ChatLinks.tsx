import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { buttonClass, buttonIconClass } from '@/components/layout/button-styles';
import { chatLinks, type ChatChannel } from '@/lib/chat-links';
import { getMessages, type Locale } from '@/lib/i18n';
import type { StoreContact } from '@/lib/site-config';

interface Props {
    locale: Locale;
    contact: Pick<StoreContact, 'whatsapp' | 'messenger'>;
    /** Prefilled WhatsApp message (the product page: name + canonical URL, in the page's locale). */
    text?: string | null;
    variant?: 'primary' | 'secondary';
    className?: string;
}

const icons: Record<ChatChannel, IconName> = { whatsapp: 'whatsappLogo', messenger: 'messengerLogo' };

// WhatsApp click-to-chat and Messenger m.me buttons (lib/chat-links.ts): server-rendered <a> elements
// that open the app in a new tab — no script, SDK or pixel, so they work with JavaScript off. Each is
// rendered only when the owner has set it (Settings › Store › Business); with neither, nothing renders.
// 44 px tall (button md), the visible label plus a screen-reader "opens in a new tab".
export function ChatLinks({ locale, contact, text = null, variant = 'secondary', className = '' }: Props) {
    const links = chatLinks(contact, text);
    if (links.length === 0) return null;
    const t = getMessages(locale).chat;

    return (
        <ul className={`flex flex-wrap gap-3 ${className}`}>
            {links.map((link) => (
                <li key={link.channel}>
                    <a href={link.href} target="_blank" rel="noopener noreferrer" data-chat={link.channel} className={buttonClass(variant, 'md')}>
                        <Icon name={icons[link.channel]} className={buttonIconClass.md} />
                        {t[link.channel]}
                        <span className="sr-only"> {t.newTab}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

interface SectionProps extends Omit<Props, 'className'> {
    /** Heading id (the section is labelled by it). */
    id: string;
    title: string;
    className?: string;
}

/** A titled block of chat buttons; renders nothing when no channel is configured. */
export function ChatSection({ id, title, className = '', ...links }: SectionProps) {
    if (chatLinks(links.contact).length === 0) return null;
    return (
        <section aria-labelledby={id} className={`flex flex-col gap-2.5 ${className}`}>
            <h2 id={id} className="t-body-strong text-fg-primary">
                {title}
            </h2>
            <ChatLinks {...links} />
        </section>
    );
}
