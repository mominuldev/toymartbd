import { blockLink, bnClass } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { ContactDetail } from '@/components/layout/ContactDetail';
import type { ContactChannelKey, ContactInfoContent } from '@/lib/content/home-blocks';
import { getMessages, type Locale } from '@/lib/i18n';
import type { ContactValue } from '@/lib/site-config';
import { getStoreInfo } from '@/lib/store-info';

// Contact details (CMS section `contact_info`, 2026-11-16 — the Contact page's main section). The
// block carries only copy and switches; every value is the store's own, from Settings › Store ›
// Business (lib/store-info.ts, tag `store-info`), so the footer and this section never disagree.
// A value the owner has not set renders as the marked sample from lib/site-config.ts — plain text
// with data-placeholder, never a link (ContactDetail), and its tile then offers no action link.
// Messenger has no sample and is left out until it is set; the address falls back to the footer's
// sample copy, likewise marked.
// Look: Figma Contact 165:9913 "Channels" — one pastel tile per channel (radius lg, 24 inset, 6
// apart): a 48 icon disc in the channel's strong colour, the channel title (channel-title type),
// the value (body-strong), an optional caption, then an underlined text/primary link with an arrow
// (text/link on these pastels fails contrast — spec §B8). The block's opening hours are the Call
// tile's caption; without a Call tile they get a tile of their own. Tiles follow Figma's order —
// WhatsApp, Call, Email, Visit (Messenger after Email) — whatever order the switches are stored in.
// Each tile may carry the editor's caption (`whatsapp_note`, `email_note`, `address_note`), and
// `address_label` ("Dhanmondi, Dhaka") stands in for the full address as the Visit tile's value.
// Without a block title the tiles carry no section heading (the Contact page, 165:9913): the
// section is then named by an off-screen heading and each tile title stays an h3.

/** Figma 165:9913 order. */
const ORDER: readonly ContactChannelKey[] = ['whatsapp', 'hotline', 'email', 'messenger', 'address'];

const looks: Record<ContactChannelKey | 'hours', { tile: string; disc: string; icon: IconName }> = {
    whatsapp: { tile: 'bg-tile-mint', disc: 'bg-feedback-success text-fg-on-brand', icon: 'whatsappLogo' },
    hotline: { tile: 'bg-tile-sky', disc: 'bg-brand text-fg-on-brand', icon: 'phoneFill' },
    email: { tile: 'bg-tile-lavender', disc: 'bg-grape text-fg-on-grape', icon: 'envelopeSimple' },
    address: { tile: 'bg-tile-butter', disc: 'bg-accent text-fg-on-accent', icon: 'mapPin' },
    messenger: { tile: 'bg-tile-pink', disc: 'bg-brand text-fg-on-brand', icon: 'messengerLogo' },
    hours: { tile: 'bg-tile-peach', disc: 'bg-surface text-fg-primary', icon: 'clock' },
};

/** Two across at 390 (Figma 167:7500, compact), one row at 1440 (four across); six fall into two rows of three. */
const desktopCols = ['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4', 'lg:grid-cols-5', 'lg:grid-cols-3'] as const;

interface Card {
    key: ContactChannelKey | 'hours';
    title: string;
    value: ContactValue | { text: string; placeholder: boolean };
    caption?: string | null;
    action?: { href: string; label: string; external: boolean } | null;
}

/** The tile's action: only for a confirmed value (a sample is never a link). */
const act = (value: ContactValue, label: string, external = false) => (value.placeholder ? null : { href: value.href, label, external });

export async function ContactInfoBlock({ id, content, locale }: { id: string; content: ContactInfoContent; locale: Locale }) {
    const { contact, address } = await getStoreInfo();
    const t = getMessages(locale);
    const c = t.content;
    const headingId = `cms-${id}-title`;
    const hoursOnCall = Boolean(content.hours) && content.channels.includes('hotline');

    const cards: Card[] = [];
    for (const channel of ORDER.filter((ch) => content.channels.includes(ch))) {
        switch (channel) {
            case 'hotline':
                cards.push({ key: channel, title: c.contactCallTitle, value: contact.hotline, caption: hoursOnCall ? content.hours : null, action: act(contact.hotline, c.contactCallCta) });
                break;
            case 'whatsapp':
                cards.push({ key: channel, title: c.contactWhatsappTitle, value: contact.whatsapp, caption: content.whatsapp_note, action: act(contact.whatsapp, c.contactChatCta, true) });
                break;
            case 'messenger':
                if (contact.messenger) cards.push({ key: channel, title: c.contactMessenger, value: contact.messenger, action: act(contact.messenger, c.contactChatCta, true) });
                break;
            case 'email':
                cards.push({ key: channel, title: c.contactEmailTitle, value: contact.email, caption: content.email_note, action: act(contact.email, c.contactEmailCta) });
                break;
            case 'address': {
                const set = address[locale] ?? address.en;
                const map = content.map_href ? blockLink(locale, content.map_href) : null;
                cards.push({
                    key: channel,
                    title: c.contactAddress,
                    value: content.address_label
                        ? { text: content.address_label, placeholder: false }
                        : set
                          ? { text: set, placeholder: false }
                          : { text: t.footer.contact.address, placeholder: true },
                    caption: content.address_note,
                    action: map ? { ...map, label: c.contactMap } : null,
                });
                break;
            }
        }
    }
    if (content.hours && !hoursOnCall) cards.push({ key: 'hours', title: c.contactHours, value: { text: content.hours, placeholder: false } });

    return (
        <section aria-labelledby={headingId} className={`flex flex-col gap-4 lg:gap-6 ${bnClass(locale)}`}>
            {content.title ? (
                <div className="flex max-w-prose flex-col gap-2">
                    <h2 id={headingId} className="t-h2 text-fg-primary">
                        {content.title}
                    </h2>
                    {content.sub ? <p className="t-body text-fg-secondary">{content.sub}</p> : null}
                </div>
            ) : (
                <h2 id={headingId} className="sr-only">
                    {c.contactChannels}
                </h2>
            )}
            {cards.length > 0 ? (
                <ul className={`grid grid-cols-2 gap-3 lg:gap-5 ${desktopCols[Math.min(cards.length, 6)]}`}>
                    {cards.map((card) => {
                        const look = looks[card.key];
                        return (
                            <li key={card.key} data-contact={card.key} className={`flex min-w-0 flex-col items-start gap-1 rounded-md-plus p-4 lg:gap-1.5 lg:rounded-lg lg:p-6 ${look.tile}`}>
                                <span aria-hidden="true" className={`mb-2 flex size-10 shrink-0 items-center justify-center rounded-full lg:size-12 ${look.disc}`}>
                                    <Icon name={look.icon} className="size-icon-md lg:size-icon-lg" />
                                </span>
                                <h3 className="t-h4 text-fg-primary lg:t-channel-title">{card.title}</h3>
                                {'display' in card.value ? (
                                    <ContactDetail value={card.value} className="t-body-sm-strong break-all text-fg-primary lg:t-body-strong lg:break-normal" />
                                ) : (
                                    <span className="t-body-sm-strong text-fg-primary lg:t-body-strong" data-placeholder={card.value.placeholder ? 'owner-to-confirm' : undefined}>
                                        {card.value.text}
                                    </span>
                                )}
                                {card.caption ? (
                                    <span className="t-body-sm text-fg-secondary max-lg:hidden" data-contact={card.key === 'hotline' ? 'hours' : 'note'}>
                                        {card.caption}
                                    </span>
                                ) : null}
                                {card.action ? (
                                    <a
                                        href={card.action.href}
                                        {...(card.action.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                                        className="t-body-sm-strong mt-1 inline-flex min-h-touch-min items-center gap-1.5 text-fg-primary underline underline-offset-4 lg:t-body-strong lg:mt-1.5"
                                    >
                                        {card.action.label}
                                        <Icon name="arrowRight" className="size-icon-sm" />
                                        {card.action.external ? <span className="sr-only"> {t.chat.newTab}</span> : null}
                                    </a>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </section>
    );
}
