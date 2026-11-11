import { LocaleToggle } from '@/components/LocaleToggle';
import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import { format, getMessages, localizedPath, otherLocale, type Locale } from '@/lib/i18n';
import { contact, footerColumns, legal, social, type FooterColumn } from '@/lib/site-config';
import { buttonClass, buttonIconClass } from './button-styles';
import { ContactDetail } from './ContactDetail';
import { FooterLogotype } from './FooterLogotype';

interface Props {
    locale: Locale;
    path: string;
}

// Per tile: pastel ground, corner-blob ink, icon-badge glyph and its ink (Figma Footer 124:3310).
const tiles: Record<FooterColumn['title'], { ground: string; blob: string; icon: IconName; ink: string }> = {
    shop: { ground: 'bg-tile-butter', blob: 'bg-deco-blob-butter', icon: 'money', ink: 'text-feedback-warning' },
    help: { ground: 'bg-tile-mint', blob: 'bg-deco-blob-mint', icon: 'heart', ink: 'text-feedback-success' },
    about: { ground: 'bg-tile-sky', blob: 'bg-deco-blob-sky', icon: 'mapPin', ink: 'text-fg-link' },
};

const socialIcon = { facebook: 'facebookLogo', instagram: 'instagramLogo', youtube: 'youtubeLogo', tiktok: 'tiktokLogo' } as const satisfies Record<string, IconName>;

// Rainbow strip: color/deco/* (Figma Stripe 154:2018–2023).
const rainbow = ['bg-deco-coral', 'bg-deco-sun', 'bg-deco-sky', 'bg-deco-pink', 'bg-deco-mint', 'bg-deco-grape'] as const;

function Blob({ tone }: { tone: string }) {
    return <span aria-hidden="true" className={`${tone} absolute -right-15 -bottom-17.5 size-40 rounded-full opacity-55`} />;
}

// Footer — Figma Footer 124:3310 / storefront spec §B1.9 (owner redesign 2026-11-11): a raised
// surface panel with rounded top corners, a six-colour rainbow strip, a bento row of pastel tiles
// (pink Help · butter Shop · mint Help · sky About · lavender Say hi!), the giant multicolour
// "toymart" logotype, and the legal bar. Contact values come only from lib/site-config.ts; a
// placeholder is never a tel:/mailto:/wa.me link and carries data-placeholder.
export function SiteFooter({ locale, path }: Props) {
    const t = getMessages(locale);
    const target = otherLocale(locale);
    const year = String(new Date().getFullYear());
    const whatsappHref = contact.whatsapp.placeholder ? localizedPath(locale, '/pages/contact') : contact.whatsapp.href;

    return (
        <footer className="mt-section-mobile rounded-t-xl-plus bg-surface shadow-up-footer px-gutter-mobile pt-5.5 pb-[calc(--spacing(22)+env(safe-area-inset-bottom))] lg:mt-section-desktop lg:rounded-t-3xl lg:px-gutter-desktop lg:py-7">
            <div className="mx-auto flex max-w-content flex-col items-center gap-3 lg:gap-4">
                <div aria-hidden="true" className="mb-2.5 flex gap-1.5 lg:mb-4">
                    {rainbow.map((tone) => (
                        <span key={tone} className={`${tone} h-1.5 w-8 rounded-full lg:w-11`} />
                    ))}
                </div>

                <div className="flex w-full flex-col gap-4 lg:flex-row lg:items-stretch">
                    <section
                        aria-labelledby="footer-help-tile"
                        className="relative flex flex-col gap-4.5 overflow-hidden rounded-xl bg-tile-pink p-7 lg:w-105 lg:shrink-0 lg:justify-between"
                    >
                        <Blob tone="bg-deco-blob-pink" />
                        <div className="relative flex flex-col items-start gap-2.5">
                            <p className="t-caption inline-flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1 font-semibold text-feedback-success" data-placeholder="owner-to-confirm">
                                <span aria-hidden="true" className="size-2 rounded-full bg-deco-mint" />
                                {t.footer.helpTile.status}
                            </p>
                            <h2 id="footer-help-tile" className="t-h2 text-fg-primary">
                                {t.footer.helpTile.title}
                            </h2>
                            <p className="t-body text-fg-secondary">{t.footer.helpTile.body}</p>
                        </div>
                        <div className="relative flex flex-wrap items-center gap-3">
                            <a href={whatsappHref} className={buttonClass('primary', 'md')}>
                                <Icon name="whatsappLogo" className={buttonIconClass.md} />
                                {t.footer.helpTile.whatsapp}
                            </a>
                            <p className="t-body inline-flex items-center gap-1.5 font-semibold text-fg-primary">
                                <Icon name="phoneFill" className="size-4.5 text-fg-brand" />
                                <span className="sr-only">{t.footer.contact.hotline}: </span>
                                <ContactDetail value={contact.hotline} />
                            </p>
                        </div>
                    </section>

                    <div className="grid grid-cols-2 gap-3 lg:contents">
                        {footerColumns.map((column) => {
                            const tile = tiles[column.title];
                            return (
                                <section
                                    key={column.title}
                                    aria-labelledby={`footer-${column.title}`}
                                    className={`relative flex flex-col gap-3.5 overflow-hidden rounded-xl p-7 lg:min-w-0 lg:flex-1 ${tile.ground} ${column.title === 'about' ? 'col-span-2' : ''}`}
                                >
                                    <Blob tone={tile.blob} />
                                    <h2 id={`footer-${column.title}`} className="t-h3 relative flex items-center gap-2.5 text-fg-primary">
                                        <span aria-hidden="true" className={`inline-flex size-9 shrink-0 items-center justify-center rounded-sm-plus bg-surface ${tile.ink}`}>
                                            <Icon name={tile.icon} className="size-icon-md" />
                                        </span>
                                        {t.footer.columns[column.title]}
                                    </h2>
                                    <ul className="relative flex flex-col gap-2 lg:gap-2.5">
                                        {column.links.map((link) => (
                                            <li key={link.path}>
                                                <a
                                                    href={localizedPath(locale, link.path)}
                                                    className="t-body inline-flex rounded-xs font-semibold text-fg-primary underline-offset-4 hover:underline"
                                                >
                                                    {t.footer.links[link.label]}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            );
                        })}

                        <section
                            aria-labelledby="footer-follow"
                            className="relative col-span-2 flex flex-col gap-3.5 overflow-hidden rounded-xl bg-tile-lavender p-7 lg:w-50 lg:shrink-0"
                        >
                            <Blob tone="bg-deco-blob-lavender" />
                            <h2 id="footer-follow" className="t-h3 relative text-fg-primary">
                                {t.footer.follow}
                            </h2>
                            <ul className="relative flex flex-wrap gap-2.5" data-placeholder={social.some((s) => !s.href) ? 'owner-to-confirm' : undefined}>
                                {social.map((s, i) => {
                                    const face = `inline-flex size-13 items-center justify-center rounded-md-plus bg-surface text-grape ${i % 2 === 0 ? 'rotate-4' : '-rotate-4'}`;
                                    const glyph = <Icon name={socialIcon[s.key]} className="size-icon-lg" />;
                                    return (
                                        <li key={s.key}>
                                            {s.href ? (
                                                <a href={s.href} aria-label={t.footer.social[s.key]} className={face}>
                                                    {glyph}
                                                </a>
                                            ) : (
                                                <span role="img" aria-label={t.footer.social[s.key]} className={face}>
                                                    {glyph}
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                            <p className="t-body-sm relative flex items-center gap-1.5 text-fg-primary">
                                <Icon name="envelopeSimple" className="size-icon-sm text-grape" />
                                <span className="sr-only">{t.footer.contact.email}: </span>
                                <ContactDetail value={contact.email} className="whitespace-nowrap" />
                            </p>
                        </section>
                    </div>
                </div>

                <FooterLogotype className="mt-0 w-19/20 max-w-265" />

                <div className="mt-2.5 flex w-full flex-col items-center gap-2.5 border-t border-line-default pt-3.5 lg:mt-5 lg:flex-row lg:justify-between lg:pt-4.5">
                    <p className="t-caption text-center text-fg-secondary" data-placeholder={legal.tradeLicence && legal.bin ? undefined : 'owner-to-confirm'}>
                        {format(t.footer.copyright, { year })}
                        {' · '}
                        {legal.tradeLicence && legal.bin ? format(t.footer.tradeLicence, { number: legal.tradeLicence, bin: legal.bin }) : t.footer.legalPending}
                    </p>
                    <div className="flex items-center gap-4">
                        <p className="t-caption inline-flex items-center gap-1.5 rounded-full bg-success-subtle px-2.5 py-1 font-semibold text-feedback-success">
                            <span className="sr-only">{t.footer.paymentTitle}: </span>
                            <Icon name="moneyFill" className="size-3.5" />
                            {t.footer.cod}
                        </p>
                        <p className="flex items-center gap-1.5">
                            <span lang={locale} aria-current="true" className="t-label text-fg-brand">
                                {t.language[locale]}
                            </span>
                            <span aria-hidden="true" className="t-label text-fg-brand">
                                ·
                            </span>
                            <LocaleToggle
                                target={target}
                                href={localizedPath(target, path)}
                                label={t.language[target]}
                                accessibleLabel={t.language.switchLabel}
                                variant="text"
                            />
                        </p>
                    </div>
                </div>
            </div>
        </footer>
    );
}
