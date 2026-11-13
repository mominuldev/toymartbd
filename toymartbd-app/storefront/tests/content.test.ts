import { describe, expect, it } from 'vitest';

import type { MenuItem } from '@/lib/category-tree';
import {
    bannerAllowed,
    EMPTY_MENUS,
    footerMenu,
    headerMenu,
    linkAttrs,
    localised,
    localisedOptional,
    mobileMenu,
    normaliseMenus,
    pageBody,
    pageTemplate,
    safeInfoStripLink,
    splitSections,
    withHeadingIds,
    type CmsMenuItem,
} from '@/lib/content/types';

// CMS content helpers (Phase 7): localisation fallbacks, menu fallback and mapping, href
// building, page template selection and the checkout banner rule (25b).

const item = (label: string, type: string, href: string | null, children: CmsMenuItem[] = [], extra: Partial<CmsMenuItem> = {}): CmsMenuItem => ({
    label,
    label_bn: null,
    type,
    href,
    open_in_new_tab: false,
    children,
    ...extra,
});

describe('localisation', () => {
    it('falls back from Bangla to English and marks the fallback', () => {
        expect(localised('bn', 'Delivery', 'ডেলিভারি')).toEqual({ text: 'ডেলিভারি' });
        expect(localised('bn', 'Delivery', null)).toEqual({ text: 'Delivery', lang: 'en' });
        expect(localised('en', 'Delivery', 'ডেলিভারি')).toEqual({ text: 'Delivery' });
    });

    it('returns null when neither locale has a value', () => {
        expect(localisedOptional('bn', null, '')).toBeNull();
        expect(localisedOptional('bn', 'Sale', '  ')).toEqual({ text: 'Sale', lang: 'en' });
    });

    it('picks the page body per locale with an English fallback', () => {
        expect(pageBody({ content: '<p>A</p>', content_bn: null }, 'bn')).toEqual({ html: '<p>A</p>', lang: 'en' });
        expect(pageBody({ content: '<p>A</p>', content_bn: '<p>আ</p>' }, 'bn')).toEqual({ html: '<p>আ</p>' });
        expect(pageBody({ content: null, content_bn: '<p>আ</p>' }, 'en')).toEqual({ html: null });
    });
});

describe('links', () => {
    it('prefixes site paths with the locale and keeps https URLs absolute', () => {
        expect(linkAttrs('en', '/pages/returns')).toEqual({ href: '/pages/returns' });
        expect(linkAttrs('bn', '/pages/returns')).toEqual({ href: '/bn/pages/returns' });
        expect(linkAttrs('bn', 'https://facebook.com/toymartbd')).toEqual({ href: 'https://facebook.com/toymartbd', rel: 'noopener' });
        expect(linkAttrs('en', 'https://facebook.com/toymartbd', true)).toEqual({ href: 'https://facebook.com/toymartbd', target: '_blank', rel: 'noopener noreferrer' });
    });

    it('refuses scripts, protocol-relative and plain-http URLs', () => {
        expect(linkAttrs('en', 'javascript:alert(1)')).toBeNull();
        expect(linkAttrs('en', '//evil.test')).toBeNull();
        expect(linkAttrs('en', 'http://insecure.test')).toBeNull();
        expect(linkAttrs('en', null)).toBeNull();
    });
});

describe('menus', () => {
    it('reads a missing or malformed location as "no menu" (fallback)', () => {
        expect(normaliseMenus(null)).toEqual(EMPTY_MENUS);
        expect(normaliseMenus({ header: { items: 'nope' }, footer: null })).toEqual(EMPTY_MENUS);
        const menus = normaliseMenus({ header: { items: [{ label: 'Puzzles', label_bn: 'পাজল', type: 'category', href: '/categories/puzzles', open_in_new_tab: false, children: [] }, { label: '' }] }, footer: null, mobile: null });
        expect(menus.header).toHaveLength(1);
        expect(menus.footer).toBeNull();
    });

    const categoryMenu: MenuItem[] = [{ id: 'cat-1', label: { label: 'Blocks', path: '/categories/blocks' }, columns: [], viewAll: null }];

    it('expands category_tree in place and builds panels from levels 2 and 3', () => {
        const menu = headerMenu(
            [
                item('Shop', 'category_tree', '/categories'),
                item('Brands', 'none', null, [item('Top brands', 'none', null, [item('LEGO', 'brand', '/brands/lego')])]),
                item('Offers', 'custom', 'https://offers.example.com', [], { open_in_new_tab: true }),
            ],
            'en',
            categoryMenu,
            (c) => `All ${c}`,
        );
        expect(menu.map((m) => m.label.label)).toEqual(['Blocks', 'Brands', 'Offers']);
        // A heading without a link points at its first link.
        expect(menu[1]?.label.path).toBe('/brands/lego');
        expect(menu[1]?.columns[0]?.[0]?.heading).toEqual({ label: 'Top brands', path: '/brands/lego' });
        expect(menu[1]?.viewAll).toBeNull();
        expect(menu[2]?.label).toEqual({ label: 'Offers', path: 'https://offers.example.com', external: true, newTab: true });
    });

    it('drops an item with nothing to link to', () => {
        expect(headerMenu([item('Empty', 'none', null)], 'en', categoryMenu, (c) => c)).toEqual([]);
    });

    it('maps footer columns and mobile rows with Bangla labels', () => {
        const footer = footerMenu([item('Help', 'none', null, [item('Returns', 'page', '/pages/returns', [], { label_bn: 'রিটার্ন' }), item('Bad', 'custom', 'javascript:x')], { label_bn: 'সহায়তা' })], 'bn');
        expect(footer).toEqual([{ heading: { text: 'সহায়তা' }, links: [{ label: 'রিটার্ন', path: '/pages/returns' }] }]);
        const rows = mobileMenu([item('Track', 'custom', '/track')], 'bn');
        expect(rows[0]?.link).toEqual({ label: 'Track', lang: 'en', path: '/track' });
    });
});

describe('pages', () => {
    it('selects the template, demoting an FAQ page without questions', () => {
        expect(pageTemplate({ template: 'faq', faq_items: [] })).toBe('default');
        expect(pageTemplate({ template: 'faq', faq_items: [{ question: 'Q', question_bn: null, answer: 'A', answer_bn: null }] })).toBe('faq');
        expect(pageTemplate({ template: 'sidebar', faq_items: [] })).toBe('sidebar');
        expect(pageTemplate({ template: 'default', faq_items: [] })).toBe('default');
    });

    it('gives h2 headings ids for the sidebar contents', () => {
        const { html, headings } = withHeadingIds('<h2>Inside Dhaka</h2><p>x</p><h2>Outside &amp; beyond</h2>');
        expect(html).toBe('<h2 id="section-1">Inside Dhaka</h2><p>x</p><h2 id="section-2">Outside &amp; beyond</h2>');
        expect(headings).toEqual([
            { id: 'section-1', text: 'Inside Dhaka' },
            { id: 'section-2', text: 'Outside & beyond' },
        ]);
    });
});

describe('sidebar sections', () => {
    it('splits the body into numbered h2 sections with an intro', () => {
        expect(splitSections('<p>Lead</p><h2>One</h2><p>a</p><h2><strong>Two</strong></h2><ul><li>b</li></ul>')).toEqual({
            intro: '<p>Lead</p>',
            sections: [
                { id: 'section-1', heading: 'One', html: '<p>a</p>' },
                { id: 'section-2', heading: 'Two', html: '<ul><li>b</li></ul>' },
            ],
        });
    });
});

describe('banners on cart and checkout (rule 25b)', () => {
    const banner = (placement: 'announcement_bar' | 'category_page' | 'info_strip' | 'popup') => ({ placement, link_url: null, image: null });

    it('allows only the info strip in a purchase context', () => {
        for (const context of ['cart', 'checkout'] as const) {
            expect(bannerAllowed(banner('info_strip'), context)).toBe(true);
            expect(bannerAllowed(banner('popup'), context)).toBe(false);
            expect(bannerAllowed(banner('announcement_bar'), context)).toBe(false);
            expect(bannerAllowed(banner('category_page'), context)).toBe(false);
        }
        expect(bannerAllowed(banner('popup'), 'general')).toBe(true);
    });

    it('links the info strip only to store pages', () => {
        expect(safeInfoStripLink('/pages/delivery-policy')).toBe('/pages/delivery-policy');
        expect(safeInfoStripLink('/products/lego-city')).toBeNull();
        expect(safeInfoStripLink('https://toymartbd.com/pages/x')).toBeNull();
    });
});
