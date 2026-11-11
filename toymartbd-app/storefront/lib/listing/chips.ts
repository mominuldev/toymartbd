import { format, type Locale, type Messages } from '../i18n';
import { priceBandLabel } from './format';
import type { Facets } from './response';
import type { FilterKey } from './state';

/** Chip text for one active filter ("Age: 2–4 years", "Brand: LEGO", "৳1,000–2,500"). */
export function chipLabel(key: FilterKey, value: string, facets: Facets, locale: Locale, t: Messages['listing']): { text: string; lang?: 'en' } {
    if (key === 'age') return { text: format(t.chipAge, { label: (t.ageBands as Record<string, string>)[value] ?? value }) };
    if (key === 'gender') return { text: format(t.chipGender, { label: (t.genders as Record<string, string>)[value] ?? value }) };
    if (key === 'price') {
        const band = facets.price.find((o) => o.value === value);
        return { text: band?.min !== undefined ? priceBandLabel(band.min, band.max ?? null, t) : value };
    }
    const option = (key === 'brand' ? facets.brand : facets.category).find((o) => o.value === value);
    const name = locale === 'bn' && option?.labelBn ? option.labelBn : (option?.label ?? value);
    const template = key === 'brand' ? t.chipBrand : t.chipCategory;
    // A Bangla page showing an English name keeps the whole chip in Bangla; only the name is a fallback.
    return { text: format(template, { label: name }) };
}
