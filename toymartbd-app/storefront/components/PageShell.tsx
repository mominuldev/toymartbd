import type { ReactNode } from 'react';

import { buildMenu } from '@/lib/category-tree';
import { getCategoryTree } from '@/lib/categories';
import { getBanners, getMenus } from '@/lib/content/api';
import { footerMenu, headerMenu, linkAttrs, localisedOptional } from '@/lib/content/types';
import { format, getMessages, localizedPath, type Locale } from '@/lib/i18n';
import { LocaleSuggestion } from './LocaleSuggestion';
import { BannerPopup } from './content/BannerPopup';
import { DisclosureKeyboard } from './layout/DisclosureKeyboard';
import { AnnouncementBar } from './layout/AnnouncementBar';
import { MobileNav } from './layout/MobileNav';
import { SiteFooter } from './layout/SiteFooter';
import { SiteHeader } from './layout/SiteHeader';

interface Props {
    locale: Locale;
    /**
     * Locale-independent path of this page (`/brands`, `/search?q=lego`). The language toggle links
     * to the same path in the other locale, and the mobile tab bar highlights from it. It is a prop
     * rather than read from the request so that pages stay ISR-cacheable.
     */
    path: string;
    /** Pre-fills the header search bar (search page). */
    searchQuery?: string;
    /** The candy AnnouncementBar above the header (Figma Home 126:10; Home only). */
    announcement?: boolean;
    /** The full-width search row under the mobile header (Figma Home "SearchRow", search page). */
    mobileSearch?: boolean;
    /**
     * Space between the chrome and the first block. `section` (default) is the section rhythm
     * (48 / 80); `tight` (16 / 32) is for pages that open with a hero card (404, About); `crumb`
     * (8 / 24) is for pages that open with a breadcrumb (Category listing 129:1572 / 136:2172).
     */
    top?: 'section' | 'tight' | 'crumb';
    /** The page has a fixed mobile bar above MobileNav (PDP AddToCartBar, 76): reserve its height below the footer. */
    bottomBar?: boolean;
    /** Children lay out their own gutters and 1280 cap (Home: full-bleed bands). */
    bleed?: boolean;
    /** Cart count the page knows server-side (cart page); cached pages leave it to the CartLink island. */
    cartCount?: number;
    children: ReactNode;
}

// The storefront chrome: skip link → (Bangla suggestion) → header + mega menu → main → footer →
// mobile tab bar. Server-rendered; the only client islands are the mega menu's keyboard layer,
// the language toggle's cookie and the suggestion banner.
export async function PageShell({ locale, path, searchQuery, announcement = false, mobileSearch = false, top = 'section', bottomBar = false, bleed = false, cartCount = 0, children }: Props) {
    const t = getMessages(locale);
    // The suggestion is shown in Bangla, so its copy comes from the Bangla catalog.
    const bn = getMessages('bn');
    const tree = await getCategoryTree(); // never throws: an API failure renders no menu
    const categoryMenu = buildMenu(tree, locale, {
        viewAll: (category) => format(t.nav.viewAll, { category }),
        more: t.nav.more,
        allCategories: t.nav.allCategories,
    });
    // The published CMS menus (tag content:menus); a location without one keeps today's menu.
    const menus = await getMenus(); // never throws
    const menu = menus.header ? headerMenu(menus.header, locale, categoryMenu, (category) => format(t.nav.viewAll, { category })) : categoryMenu;
    const footer = menus.footer ? footerMenu(menus.footer, locale) : null;

    // Banners (tag content:banners). Never on cart or checkout except the info strip, which those
    // pages render themselves (rule 25b).
    const purchaseFlow = /^\/(cart|checkout)(\/|\?|$)/.test(path);
    const announcementBanner = announcement ? (await getBanners('announcement_bar'))[0] : undefined;
    const announcementText = announcementBanner ? (localisedOptional(locale, announcementBanner.title, announcementBanner.title_bn) ?? localisedOptional(locale, announcementBanner.body, announcementBanner.body_bn)) : null;
    const announcementLink = announcementBanner ? linkAttrs(locale, announcementBanner.link_url) : null;
    const announcementLinkLabel = announcementBanner ? localisedOptional(locale, announcementBanner.link_label, announcementBanner.link_label_bn) : null;
    const popup = purchaseFlow ? undefined : (await getBanners('popup'))[0];
    const popupTitle = popup ? localisedOptional(locale, popup.title, popup.title_bn) : null;
    const popupBody = popup ? localisedOptional(locale, popup.body, popup.body_bn) : null;
    const popupLinkLabel = popup ? localisedOptional(locale, popup.link_label, popup.link_label_bn) : null;

    return (
        <>
            <a
                href="#main"
                className="t-label sr-only rounded-sm bg-surface px-4 py-3 text-fg-primary shadow-e2 focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50"
            >
                {t.nav.skipToContent}
            </a>
            {locale === 'en' ? (
                <LocaleSuggestion
                    href={localizedPath('bn', path)}
                    message={bn.suggest.message}
                    accept={bn.suggest.accept}
                    dismiss={bn.suggest.dismiss}
                    region={bn.suggest.region}
                />
            ) : null}
            {announcement ? (
                announcementText ? (
                    <AnnouncementBar
                        message={announcementText.text}
                        lang={announcementText.lang}
                        link={announcementLink && announcementLinkLabel ? { ...announcementLink, label: announcementLinkLabel.text } : null}
                    />
                ) : (
                    <AnnouncementBar message={t.announcement.cod} />
                )
            ) : null}
            <SiteHeader locale={locale} path={path} menu={menu} cartCount={cartCount} searchQuery={searchQuery} mobileSearch={mobileSearch || Boolean(searchQuery)} />
            {/* Gutters outside, content capped at 1280 inside: at 1440 that is the design's 80px margin. */}
            <main
                id="main"
                tabIndex={-1}
                className={`w-full grow focus:outline-none ${bleed ? '' : 'px-gutter-mobile md:px-gutter-tablet lg:px-gutter-desktop'} ${top === 'tight' ? 'pt-4 lg:pt-8' : top === 'crumb' ? 'pt-2 lg:pt-6' : 'pt-section-mobile lg:pt-section-desktop'}`}
            >
                {bleed ? children : <div className="mx-auto max-w-content">{children}</div>}
            </main>
            <SiteFooter locale={locale} path={path} menu={footer} />
            {popup && (popupTitle || popupBody) ? (
                <BannerPopup
                    id={popup.id}
                    title={popupTitle?.text ?? null}
                    body={popupBody?.text ?? null}
                    lang={popupTitle?.lang ?? popupBody?.lang}
                    bangla={locale === 'bn'}
                    link={linkAttrs(locale, popup.link_url)}
                    linkLabel={popupLinkLabel?.text ?? null}
                    image={
                        popup.image
                            ? { src: popup.image.url, srcSet: popup.image.srcset, width: popup.image.width, height: popup.image.height, alt: (locale === 'bn' ? (popup.image.alt_bn ?? popup.image.alt) : popup.image.alt) ?? '' }
                            : null
                    }
                    labels={{ notice: t.content.notice, close: t.content.close }}
                />
            ) : null}
            {bottomBar ? <div aria-hidden="true" className="h-19 shrink-0 lg:hidden" /> : null}
            <MobileNav locale={locale} path={path} cartCount={cartCount} />
            {/* Escape / focus trap for the <details> pop-overs (filter sheet, sort, language). */}
            <DisclosureKeyboard />
        </>
    );
}
