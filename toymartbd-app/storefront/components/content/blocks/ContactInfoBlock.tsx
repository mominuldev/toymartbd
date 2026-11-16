import { blockLink, bnClass } from "@/components/content/blocks/shared";
import { Icon } from "@/components/icons/Icon";
import type { IconName } from "@/components/icons/paths";
import { ContactDetail } from "@/components/layout/ContactDetail";
import type {
    ContactChannelKey,
    ContactInfoContent,
} from "@/lib/content/home-blocks";
import { getMessages, type Locale } from "@/lib/i18n";
import type { ContactValue } from "@/lib/site-config";
import { getStoreInfo } from "@/lib/store-info";

// Contact details (CMS section `contact_info`, 2026-11-16 — the Contact page's main section). The
// block carries only copy and switches; every value is the store's own, from Settings › Store ›
// Business (lib/store-info.ts, tag `store-info`), so the footer and this section never disagree.
// A value the owner has not set renders as the marked sample from lib/site-config.ts — plain text
// with data-placeholder, never a link (ContactDetail). Messenger has no sample and is left out
// until it is set; the address falls back to the footer's sample copy, likewise marked.
// Cards use the USP band's pastel tiles (pink → butter → mint → sky), so it reads as one family.

const tiles = [
    { tile: "bg-tile-pink", ink: "text-fg-brand" },
    { tile: "bg-tile-butter", ink: "text-fg-primary" },
    { tile: "bg-tile-mint", ink: "text-fg-ink-mint" },
    { tile: "bg-tile-sky", ink: "text-fg-ink-sky" },
] as const;

const icons: Record<ContactChannelKey | "hours", IconName> = {
    hotline: "phoneFill",
    whatsapp: "whatsappLogo",
    messenger: "messengerLogo",
    email: "envelopeSimple",
    address: "mapPin",
    hours: "clock",
};

interface Card {
    key: ContactChannelKey | "hours";
    label: string;
    value: ContactValue | { text: string; placeholder: boolean };
    extra?: { href: string; label: string; external: boolean };
}

export async function ContactInfoBlock({
    id,
    content,
    locale,
}: {
    id: string;
    content: ContactInfoContent;
    locale: Locale;
}) {
    const { contact, address } = await getStoreInfo();
    const t = getMessages(locale);
    const labels = t.footer.contact;
    const headingId = `cms-${id}-title`;

    const cards: Card[] = [];
    for (const channel of content.channels) {
        switch (channel) {
            case "hotline":
                cards.push({
                    key: channel,
                    label: labels.hotline,
                    value: contact.hotline,
                });
                break;
            case "whatsapp":
                cards.push({
                    key: channel,
                    label: labels.whatsapp,
                    value: contact.whatsapp,
                });
                break;
            case "messenger":
                if (contact.messenger)
                    cards.push({
                        key: channel,
                        label: t.content.contactMessenger,
                        value: contact.messenger,
                    });
                break;
            case "email":
                cards.push({
                    key: channel,
                    label: labels.email,
                    value: contact.email,
                });
                break;
            case "address": {
                const set = address[locale] ?? address.en;
                const map = content.map_href
                    ? blockLink(locale, content.map_href)
                    : null;
                cards.push({
                    key: channel,
                    label: t.content.contactAddress,
                    value: set
                        ? { text: set, placeholder: false }
                        : { text: labels.address, placeholder: true },
                    extra: map
                        ? { ...map, label: t.content.contactMap }
                        : undefined,
                });
                break;
            }
        }
    }
    if (content.hours)
        cards.push({
            key: "hours",
            label: t.content.contactHours,
            value: { text: content.hours, placeholder: false },
        });

    return (
        <section
            aria-labelledby={headingId}
            className={`flex flex-col gap-4 lg:gap-6 ${bnClass(locale)}`}
        >
            <div className="flex max-w-prose flex-col gap-2">
                <h2 id={headingId} className="t-h2 text-fg-primary">
                    {content.title}
                </h2>
                {content.sub ? (
                    <p className="t-body text-fg-secondary">{content.sub}</p>
                ) : null}
            </div>
            {cards.length > 0 ? (
                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6">
                    {cards.map((card, i) => {
                        const look = tiles[i % tiles.length]!;
                        return (
                            <li
                                key={card.key}
                                data-contact={card.key}
                                className="flex items-start gap-4 rounded-md border border-line-brand-subtle bg-surface p-5 shadow-e1"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`flex size-12 shrink-0 rotate-4 items-center justify-center rounded-sm ${look.tile} ${look.ink}`}
                                >
                                    <Icon
                                        name={icons[card.key]}
                                        className="size-icon-md"
                                    />
                                </span>
                                <span className="flex min-w-0 flex-col gap-1">
                                    <span className="t-overline text-fg-tertiary">
                                        {card.label}
                                    </span>
                                    {"display" in card.value ? (
                                        <ContactDetail
                                            value={card.value}
                                            className="t-body-strong text-fg-primary"
                                        />
                                    ) : (
                                        <span
                                            className="t-body-strong text-fg-primary"
                                            data-placeholder={
                                                card.value.placeholder
                                                    ? "owner-to-confirm"
                                                    : undefined
                                            }
                                        >
                                            {card.value.text}
                                        </span>
                                    )}
                                    {card.extra ? (
                                        <a
                                            href={card.extra.href}
                                            {...(card.extra.external
                                                ? {
                                                      target: "_blank",
                                                      rel: "noopener noreferrer",
                                                  }
                                                : {})}
                                            className="t-body-sm-strong inline-flex items-center gap-1 text-fg-link underline-offset-4 hover:underline"
                                        >
                                            {card.extra.label}
                                            {card.extra.external ? (
                                                <Icon
                                                    name="arrowSquareOut"
                                                    className="size-icon-sm"
                                                />
                                            ) : null}
                                            {card.extra.external ? (
                                                <span className="sr-only">
                                                    {" "}
                                                    {t.chat.newTab}
                                                </span>
                                            ) : null}
                                        </a>
                                    ) : null}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </section>
    );
}
