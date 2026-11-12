import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import { getMessages } from '@/lib/i18n';
import { formatPrice } from '@/lib/listing/format';
import { ageForLabel, ageRange, AGE_SCALE, bandMatches, beforeCutoff, cutoffLabel, ratingValue, starFills, weightLabel } from '@/lib/product/format';
import { jsonLdScript, productJsonLd } from '@/lib/product/seo';
import { axisViews, isExplicitVariant, resolveVariant, variantHref, variantSummary, type StorefrontProduct } from '@/lib/product/variants';

// The e2e fixture doubles as the unit fixture: Set 90/484 × Packaging Box/Tub, 484+Tub sold out.
const { _related: _unused, ...raw } = JSON.parse(readFileSync(new URL('../e2e/fixtures/product-detail.json', import.meta.url), 'utf8')) as StorefrontProduct & { _related: string[] };
const product: StorefrontProduct = { ...raw, related: [] };

const en = getMessages('en');
const bn = getMessages('bn');
const byId = (id: number) => product.variants.find((v) => v.id === id)!;

describe('resolveVariant', () => {
    it('takes ?variant= when it names a variant of this product', () => {
        expect(resolveVariant(product, '501')?.id).toBe(501);
        expect(resolveVariant(product, ['502', '503'])?.id).toBe(502);
        expect(isExplicitVariant(product, '501')).toBe(true);
    });

    it('falls back to the default for a missing, unknown or malformed id', () => {
        for (const param of [undefined, '999', 'abc', '5.1', '-1', '']) {
            expect(resolveVariant(product, param)?.id).toBe(503);
        }
        expect(isExplicitVariant(product, '999')).toBe(false);
    });

    it('falls back to the first variant when there is no default', () => {
        expect(resolveVariant({ ...product, default_variant_id: null }, undefined)?.id).toBe(501);
    });
});

describe('axisViews', () => {
    it('marks the selection and links the other options to the matching variant', () => {
        const [set, pack] = axisViews(product, byId(501));
        expect(set!.options.map((o) => [o.value.slug, o.state, o.variantId])).toEqual([
            ['90-pcs', 'Selected', null],
            ['484-pcs', 'LowStock', 503],
        ]);
        expect(set!.options[1]!.reason).toMatchObject({ kind: 'lowStock', count: 3 });
        expect(pack!.options.map((o) => [o.value.slug, o.state, o.variantId])).toEqual([
            ['box', 'Selected', null],
            ['tub', 'Available', 502],
        ]);
    });

    it('disables a combination that is sold out with the current choice, naming the other value', () => {
        const [, pack] = axisViews(product, byId(503));
        const tub = pack!.options[1]!;
        expect(tub.state).toBe('OtherAxis');
        expect(tub.variantId).toBeNull();
        expect(tub.reason).toMatchObject({ kind: 'comboSoldOut', others: [expect.objectContaining({ slug: '484-pcs' })] });
    });

    it('says a value is sold out everywhere when no variant with it has stock', () => {
        const soldOut = { ...product, variants: product.variants.map((v) => (v.attribute_value_ids.includes(22) ? { ...v, available: 0, stock_state: 'out_of_stock' as const } : v)) };
        const [, pack] = axisViews(soldOut, byId(501));
        expect(pack!.options[1]).toMatchObject({ state: 'Unavailable', variantId: null, reason: { kind: 'soldOut' } });
    });

    it('flags a combination that does not exist', () => {
        // 90 + Tub does not exist; 484 + Tub is restocked, so Tub itself is not sold out.
        const sparse = { ...product, variants: product.variants.filter((v) => v.id !== 502).map((v) => (v.id === 504 ? { ...v, available: 4, stock_state: 'low_stock' as const } : v)) };
        const [, pack] = axisViews(sparse, byId(501));
        expect(pack!.options[1]).toMatchObject({ state: 'OtherAxis', reason: { kind: 'impossible' } });
    });

    it('keeps the selected option selected even when that variant is sold out', () => {
        const [set, pack] = axisViews(product, byId(504));
        expect(set!.options[1]!.state).toBe('Selected');
        expect(pack!.options[1]!.state).toBe('Selected');
        expect(pack!.options[0]).toMatchObject({ state: 'LowStock', variantId: 503 });
    });
});

describe('variant links and summary', () => {
    it('builds shareable ?variant= links and a localised summary', () => {
        expect(variantHref('/bn/products/x', 501)).toBe('/bn/products/x?variant=501');
        expect(variantSummary(product, byId(503), 'en')).toBe('484 pcs · Box');
        expect(variantSummary(product, byId(503), 'bn')).toBe('484 পিস · বক্স');
    });
});

describe('price formatting (display only, rule 1)', () => {
    it('formats the API decimals without arithmetic', () => {
        expect(formatPrice(byId(503).price)).toBe('৳4,250');
        expect(formatPrice(byId(503).saving!)).toBe('৳740');
        expect(formatPrice('990.50')).toBe('৳990.50');
    });
});

describe('product formatting', () => {
    it('derives the age labels', () => {
        expect(ageForLabel(48, 144, en)).toBe('For ages 4–12');
        expect(ageForLabel(48, 144, bn)).toBe('4–12 বছরের জন্য');
        expect(ageForLabel(36, null, en)).toBe('For ages 3+');
        expect(ageForLabel(0, 12, en)).toBe('For 0–12 months');
        expect(ageRange(48, 144, en)).toBe('4–12');
        expect(ageForLabel(null, null, en)).toBeNull();
    });

    it('lights the age-scale bands the range covers, edges exclusive', () => {
        const lit = (min: number | null, max: number | null) => AGE_SCALE.filter((b) => bandMatches(b, min, max)).map((b) => b.key);
        expect(lit(48, 144)).toEqual(['b2', 'b3', 'b4']);
        expect(lit(0, 12)).toEqual(['b0']);
        expect(lit(144, null)).toEqual(['b5']);
        expect(lit(null, null)).toEqual([]);
    });

    it('formats weight, cut-off and rating', () => {
        expect(weightLabel(1450, en)).toBe('1.45 kg');
        expect(weightLabel(2000, en)).toBe('2 kg');
        expect(weightLabel(450, bn)).toBe('450 গ্রাম');
        expect(weightLabel(0, en)).toBeNull();
        expect(cutoffLabel('17:00', en)).toBe('5 pm');
        expect(cutoffLabel('17:00:00', bn)).toBe('বিকেল 5টা');
        expect(cutoffLabel('09:30', en)).toBe('9:30 am');
        expect(cutoffLabel('nope', en)).toBeNull();
        expect(ratingValue('4.70')).toBe('4.7');
        expect(ratingValue('4.00')).toBe('4.0');
        expect(starFills(4.3)).toEqual([100, 100, 100, 100, 50]);
        expect(starFills(4.24)).toEqual([100, 100, 100, 100, 0]);
    });

    it('compares the cut-off on the Dhaka clock (UTC+6)', () => {
        expect(beforeCutoff('17:00', new Date('2026-11-12T10:59:00Z'))).toBe(true); // 16:59 Dhaka
        expect(beforeCutoff('17:00', new Date('2026-11-12T11:00:00Z'))).toBe(false); // 17:00 Dhaka
    });
});

describe('structured data', () => {
    it('emits a Product with one offer per variant and the full breadcrumb', () => {
        const [ld, crumbs] = productJsonLd(product, 'en', { home: 'Home' });
        expect(ld).toMatchObject({ '@type': 'Product', name: 'LEGO Classic Creative Bricks 484 pcs', sku: 'TMB-LEG-CL484', brand: { name: 'LEGO' } });
        expect(ld!.offers).toHaveLength(4);
        expect((crumbs!.itemListElement as unknown[]).length).toBe(5);
        expect(jsonLdScript({ a: '</script>' })).not.toContain('</script>');
    });
});
