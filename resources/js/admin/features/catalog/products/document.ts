import type { Attribute } from '../attributes/api';
import type { Gender, Product, ProductInput, ProductStatus, ProductType, ProductVariant, RelationType, RobotsDirective, TwitterCard } from './api';

/**
 * The product document as the form edits it, and its translation to the API payload.
 *
 * The admin never computes money (rule 1): prices, compare-at and cost travel as the
 * decimal strings the API sent, edited as text and sent back as text. SKUs for new
 * combinations are left blank so the server generates them. The only thing derived
 * here is the list of combinations — labels for the grid, not prices or SKUs.
 */

/** One variant's editable fields. Money and weight are strings while editing. */
export type VariantFields = {
    sku: string;
    barcode: string;
    price: string;
    compare_at_price: string;
    cost_price: string;
    wholesale_price: string;
    weight_grams: string;
    /** Sent only when creating the product (an `opening_balance` movement); prohibited on update. */
    opening_stock: string;
};

/** Read-only stock of a saved variant (needs inventory.view; absent otherwise). */
export type VariantStock = NonNullable<ProductVariant['stock']>;

export type MatrixRow = VariantFields & {
    /** The existing variant, or null for a combination the server has not created yet. */
    id: number | null;
    stock: VariantStock | null;
    value_ids: number[];
    status: 'active' | 'archived';
};

export type MatrixOption = { attribute_id: number; value_ids: number[] };

export type MatrixState = {
    options: MatrixOption[];
    /** Every row edited or loaded, keyed by combination; rows outside the matrix are kept so re-adding a value restores them. */
    rows: Record<string, MatrixRow>;
};

export type RelationRow = {
    /** Local identity for React keys and error placement. */
    uid: string;
    related_product_id: number;
    name: string;
    name_bn: string | null;
    product_status: ProductStatus;
    /** The target's 160 px thumbnail and default price, as the API sent them (display only). */
    image_url: string | null;
    price: string | null;
    type: RelationType;
    position: string;
    reason_note: string;
    is_auto: boolean;
};

export type SalesChannels = { online_store: boolean; pos: boolean; facebook_instagram: boolean; google_merchant: boolean };

/** The `seo` block (seo_meta) as edited; empty text means "use the server default". */
export type SeoFields = {
    title: string;
    title_bn: string;
    description: string;
    description_bn: string;
    canonical_url: string;
    robots: RobotsDirective;
    sitemap_exclude: boolean;
    og_title: string;
    og_description: string;
    og_image: string;
    twitter_card: TwitterCard;
    /** JSON text of the JSON-LD override object; parsed on submit. */
    schema_override: string;
    focus_keyword: string;
};

export type FormValues = {
    name: string;
    name_bn: string;
    slug: string;
    type: ProductType;
    status: ProductStatus;
    brand_id: string;
    category_ids: number[];
    primary_category_id: string;
    short_description: string;
    short_description_bn: string;
    description: string;
    description_bn: string;
    age_min_months: string;
    age_max_months: string;
    gender: Gender | '';
    is_educational: boolean;
    safety_note: string;
    safety_note_bn: string;
    warranty_months: string;
    is_featured: boolean;
    sales_channels: SalesChannels;
    /** VAT included in the price, percent, as text. */
    vat_rate: string;
    bsti_certificate_no: string;
    /** `YYYY-MM-DD` or ''. */
    bsti_expires_on: string;
    seo: SeoFields;
    /** The SlugField redirect acknowledgement (a live product's slug change). */
    acknowledge_slug_redirect: boolean;
    simple: VariantFields;
    /** The saved simple variant's stock, for the read-only display on edit. */
    simple_stock: VariantStock | null;
};

export const variantFields: (keyof VariantFields)[] = ['sku', 'barcode', 'price', 'compare_at_price', 'cost_price', 'wholesale_price', 'weight_grams', 'opening_stock'];

export const generalFields = [
    'name', 'name_bn', 'slug', 'type', 'status', 'brand_id', 'category_ids', 'primary_category_id',
    'short_description', 'short_description_bn', 'description', 'description_bn', 'age_min_months', 'age_max_months',
    'gender', 'is_educational', 'safety_note', 'safety_note_bn', 'warranty_months', 'is_featured',
    'vat_rate', 'bsti_certificate_no', 'bsti_expires_on', 'acknowledge_slug_redirect',
] as const satisfies readonly (keyof FormValues)[];

/** The identity of a combination: its sorted attribute value ids (App\Domain\Catalog\Services\VariantMatrix::key). */
export function comboKey(valueIds: number[]): string {
    return [...valueIds].sort((a, b) => a - b).join(',');
}

function variantToFields(variant?: ProductVariant): VariantFields {
    return {
        sku: variant?.sku ?? '',
        barcode: variant?.barcode ?? '',
        price: variant?.price ?? '',
        compare_at_price: variant?.compare_at_price ?? '',
        cost_price: variant?.cost_price ?? '',
        wholesale_price: variant?.wholesale_price ?? '',
        weight_grams: variant ? String(variant.weight_grams) : '',
        opening_stock: '',
    };
}

export function emptyRow(valueIds: number[]): MatrixRow {
    return { ...variantToFields(), id: null, stock: null, value_ids: valueIds, status: 'active' };
}

const str = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));

export function toForm(product?: Product): FormValues {
    const simple = product?.type === 'simple' ? (product.variants.find((v) => v.is_default) ?? product.variants[0]) : undefined;

    return {
        name: product?.name ?? '',
        name_bn: product?.name_bn ?? '',
        slug: product?.slug ?? '',
        type: product?.type ?? 'simple',
        status: product?.status ?? 'draft',
        brand_id: str(product?.brand_id),
        category_ids: product?.category_ids ?? [],
        primary_category_id: str(product?.primary_category_id),
        short_description: product?.short_description ?? '',
        short_description_bn: product?.short_description_bn ?? '',
        description: product?.description ?? '',
        description_bn: product?.description_bn ?? '',
        age_min_months: str(product?.age_min_months),
        age_max_months: str(product?.age_max_months),
        gender: product?.gender ?? '',
        is_educational: product?.is_educational ?? false,
        safety_note: product?.safety_note ?? '',
        safety_note_bn: product?.safety_note_bn ?? '',
        warranty_months: str(product?.warranty_months),
        is_featured: product?.is_featured ?? false,
        sales_channels: product?.sales_channels ?? { online_store: true, pos: true, facebook_instagram: false, google_merchant: false },
        vat_rate: product ? stripZeros(product.vat_rate) : '0',
        bsti_certificate_no: product?.compliance.bsti_certificate_no ?? '',
        bsti_expires_on: product?.compliance.bsti_expires_on ?? '',
        seo: toSeoFields(product?.seo),
        acknowledge_slug_redirect: false,
        simple: variantToFields(simple),
        simple_stock: simple?.stock ?? null,
    };
}

/** "15.00" → "15", "7.50" → "7.5" — a display trim of the decimal string, not arithmetic. */
function stripZeros(decimal: string): string {
    return decimal.includes('.') ? decimal.replace(/\.?0+$/, '') : decimal;
}

function toSeoFields(seo?: Product['seo']): SeoFields {
    return {
        title: seo?.title ?? '',
        title_bn: seo?.title_bn ?? '',
        description: seo?.description ?? '',
        description_bn: seo?.description_bn ?? '',
        canonical_url: seo?.canonical_url ?? '',
        robots: seo?.robots ?? 'index,follow',
        sitemap_exclude: seo?.sitemap_exclude ?? false,
        og_title: seo?.og_title ?? '',
        og_description: seo?.og_description ?? '',
        og_image: seo?.og_image ?? '',
        twitter_card: seo?.twitter_card ?? 'summary_large_image',
        schema_override: seo?.schema_override ? JSON.stringify(seo.schema_override, null, 2) : '',
        focus_keyword: seo?.focus_keyword ?? '',
    };
}

/**
 * The matrix as loaded. A variant whose combination left the matrix (its value was
 * removed) is kept with status `active`: if the value is added back, the server
 * reactivates it, and the grid shows it as it will be.
 */
export function toMatrix(product?: Product): MatrixState {
    if (!product || product.type !== 'variable') return { options: [], rows: {} };

    const selected = new Set(product.options.flatMap((o) => o.value_ids));
    const rows: Record<string, MatrixRow> = {};

    for (const variant of product.variants) {
        const valueIds = variant.attribute_values.map((v) => v.attribute_value_id);
        const inMatrix = valueIds.every((id) => selected.has(id));
        rows[comboKey(valueIds)] = {
            ...variantToFields(variant),
            id: variant.id,
            stock: variant.stock ?? null,
            value_ids: valueIds,
            status: inMatrix ? variant.status : 'active',
        };
    }

    return { options: product.options.map((o) => ({ attribute_id: o.attribute_id, value_ids: [...o.value_ids] })), rows };
}

export function toRelations(product?: Product): RelationRow[] {
    return (product?.relations ?? []).map((relation) => ({
        uid: `r${relation.id}`,
        related_product_id: relation.related_product_id,
        name: relation.related_product.name,
        name_bn: relation.related_product.name_bn,
        product_status: relation.related_product.status,
        image_url: relation.related_product.image_url,
        price: relation.related_product.price,
        type: relation.type,
        position: String(relation.position),
        reason_note: relation.reason_note ?? '',
        is_auto: relation.is_auto,
    }));
}

export type Combination = {
    key: string;
    /** In attribute order. */
    valueIds: number[];
    label: string;
    labelBn: string;
    attributeNames: string;
    attributeNamesBn: string;
};

const byPosition = <T extends { position: number; id: number }>(a: T, b: T) => a.position - b.position || a.id - b.id;

/**
 * The cartesian product of the selected values, in attribute then value order — the
 * same order the server writes (3 colours × 4 sizes = 12 rows). Labels only.
 */
export function combinations(options: MatrixOption[], attributes: Attribute[]): Combination[] {
    const sets = options
        .map((option) => ({ option, attribute: attributes.find((a) => a.id === option.attribute_id) }))
        .filter((x): x is { option: MatrixOption; attribute: Attribute } => x.attribute !== undefined && x.option.value_ids.length > 0)
        .sort((a, b) => byPosition(a.attribute, b.attribute))
        .map(({ option, attribute }) => ({
            attribute,
            values: attribute.values.filter((v) => option.value_ids.includes(v.id)).sort(byPosition),
        }));

    if (sets.length === 0) return [];

    let result: Attribute['values'][] = [[]];
    for (const set of sets) {
        result = result.flatMap((prefix) => set.values.map((value) => [...prefix, value]));
    }

    const names = sets.map((s) => s.attribute.name).join(' · ');
    const namesBn = sets.map((s) => s.attribute.name_bn ?? s.attribute.name).join(' · ');

    return result.map((values) => ({
        key: comboKey(values.map((v) => v.id)),
        valueIds: values.map((v) => v.id),
        label: values.map((v) => v.value).join(' / '),
        labelBn: values.map((v) => v.value_bn ?? v.value).join(' / '),
        attributeNames: names,
        attributeNamesBn: namesBn,
    }));
}

/** A combination's label from any value ids (used for rows outside the matrix). */
export function labelFor(valueIds: number[], attributes: Attribute[], bn: boolean): string {
    const values = attributes
        .slice()
        .sort(byPosition)
        .flatMap((a) => a.values.filter((v) => valueIds.includes(v.id)));
    return values.map((v) => (bn ? (v.value_bn ?? v.value) : v.value)).join(' / ');
}

export type BuildContext = {
    canCost: boolean;
    /** inventory.adjust on a new product: send `opening_stock`. Never on update (the server refuses it). */
    canOpeningStock?: boolean;
    combos: Combination[];
    matrix: MatrixState;
    relations: RelationRow[];
    product?: Product;
};

const text = (value: string): string | null => (value.trim() === '' ? null : value.trim());
const int = (value: string): number | null => (value.trim() === '' ? null : Number(value.trim()));

function variantPayload(fields: VariantFields, canCost: boolean, openingStock: boolean) {
    return {
        ...(openingStock ? { opening_stock: int(fields.opening_stock) } : {}),
        sku: text(fields.sku),
        barcode: text(fields.barcode),
        price: text(fields.price),
        compare_at_price: text(fields.compare_at_price),
        wholesale_price: text(fields.wholesale_price),
        weight_grams: int(fields.weight_grams),
        // Without catalog.view_cost the field is absent from the write, not blank: the
        // server keeps the cost (and refuses a sent one with 403).
        ...(canCost ? { cost_price: text(fields.cost_price) } : {}),
    };
}

/**
 * The payload for POST/PUT. `variants[i]` follows `combos[i]` for a variable product,
 * so an error at `variants.3.price` lands on the fourth grid row.
 */
export function toPayload(values: FormValues, ctx: BuildContext): ProductInput {
    const categories = values.category_ids;
    const opening = ctx.product === undefined && ctx.canOpeningStock === true;

    return {
        name: values.name.trim(),
        name_bn: text(values.name_bn),
        slug: text(values.slug),
        type: values.type,
        status: values.status,
        brand_id: values.brand_id === '' ? null : Number(values.brand_id),
        primary_category_id: values.primary_category_id === '' ? (categories[0] ?? (undefined as unknown as number)) : Number(values.primary_category_id),
        category_ids: categories,
        short_description: text(values.short_description),
        short_description_bn: text(values.short_description_bn),
        description: text(values.description),
        description_bn: text(values.description_bn),
        age_min_months: int(values.age_min_months),
        age_max_months: int(values.age_max_months),
        gender: values.gender === '' ? null : values.gender,
        is_educational: values.is_educational,
        safety_note: text(values.safety_note),
        safety_note_bn: text(values.safety_note_bn),
        warranty_months: int(values.warranty_months),
        is_featured: values.is_featured,
        sales_channels: values.sales_channels,
        vat_rate: text(values.vat_rate),
        compliance: { bsti_certificate_no: text(values.bsti_certificate_no), bsti_expires_on: text(values.bsti_expires_on) },
        seo: seoPayload(values.seo),
        // Only meaningful (and only required) when a live product's slug changes.
        ...(ctx.product?.is_live && text(values.slug) !== null && text(values.slug) !== ctx.product.slug ? { acknowledge_slug_redirect: values.acknowledge_slug_redirect } : {}),
        // Fields this form does not edit travel back unchanged: PUT replaces the document.
        specifications: ctx.product?.specifications ?? null,
        published_at: ctx.product?.published_at ?? null,
        options: values.type === 'variable' ? ctx.matrix.options.filter((o) => o.value_ids.length > 0) : [],
        variants:
            values.type === 'simple'
                ? [variantPayload(values.simple, ctx.canCost, opening)]
                : ctx.combos.map((combo) => {
                      const row = ctx.matrix.rows[combo.key] ?? emptyRow(combo.valueIds);
                      return { attribute_value_ids: combo.valueIds, ...variantPayload(row, ctx.canCost, opening), status: row.status };
                  }),
        relations: ctx.relations
            .filter((r) => !r.is_auto)
            .map((r) => ({
                related_product_id: r.related_product_id,
                type: r.type,
                position: int(r.position) ?? 0,
                reason_note: text(r.reason_note),
            })),
    };
}

/** The JSON-LD override as an object; text that is not a JSON object is sent as-is so the schema check names the field. */
function seoPayload(seo: SeoFields): NonNullable<ProductInput['seo']> {
    let override: unknown = null;
    if (seo.schema_override.trim() !== '') {
        try {
            override = JSON.parse(seo.schema_override);
        } catch {
            override = seo.schema_override;
        }
    }
    return {
        title: text(seo.title),
        title_bn: text(seo.title_bn),
        description: text(seo.description),
        description_bn: text(seo.description_bn),
        canonical_url: text(seo.canonical_url),
        robots: seo.robots,
        sitemap_exclude: seo.sitemap_exclude,
        og_title: text(seo.og_title),
        og_description: text(seo.og_description),
        og_image: text(seo.og_image),
        twitter_card: seo.twitter_card,
        schema_override: override as Record<string, unknown> | null,
        focus_keyword: text(seo.focus_keyword),
    };
}

export type FieldError = { field: string; message: string };

export type PlacedErrors = {
    /** Top-level form fields and `simple.*`. */
    form: { field: keyof FormValues | `simple.${keyof VariantFields}` | `seo.${keyof SeoFields}` | `sales_channels.${keyof SalesChannels}`; message: string }[];
    rows: Record<string, Partial<Record<keyof VariantFields | 'row', string>>>;
    relations: Record<string, string>;
    matrix: string[];
    other: string[];
};

/**
 * Places API-shaped field errors (`variants.3.price`, `relations.0.related_product_id`,
 * `options.1.value_ids`) on the control that owns them. Used for both the generated
 * Zod schema's issues and the server's 422, so both land in the same place.
 */
const seoKeys: Record<keyof SeoFields, true> = {
    title: true, title_bn: true, description: true, description_bn: true, canonical_url: true, robots: true, sitemap_exclude: true,
    og_title: true, og_description: true, og_image: true, twitter_card: true, schema_override: true, focus_keyword: true,
};

export function placeErrors(errors: FieldError[], type: ProductType, combos: Combination[], relations: RelationRow[]): PlacedErrors {
    const placed: PlacedErrors = { form: [], rows: {}, relations: {}, matrix: [], other: [] };
    const curated = relations.filter((r) => !r.is_auto);
    const known = new Set<string>(generalFields);

    for (const { field, message } of errors) {
        const parts = field.split('.');
        const [head, index, sub] = parts;

        if (head === 'variants' && index !== undefined && /^\d+$/.test(index)) {
            const leaf = (sub ?? 'row') as keyof VariantFields | 'row';
            if (type === 'simple') {
                if (variantFields.includes(leaf as keyof VariantFields)) placed.form.push({ field: `simple.${leaf as keyof VariantFields}`, message });
                else placed.other.push(message);
                continue;
            }
            const combo = combos[Number(index)];
            if (!combo) {
                placed.matrix.push(message);
                continue;
            }
            const row = (placed.rows[combo.key] ??= {});
            const target = variantFields.includes(leaf as keyof VariantFields) ? leaf : 'row';
            row[target] ??= message;
            continue;
        }

        if (head === 'variants' || head === 'options' || head === 'variant_defaults') {
            placed.matrix.push(message);
            continue;
        }

        if (head === 'relations' && index !== undefined && /^\d+$/.test(index)) {
            const relation = curated[Number(index)];
            if (relation) placed.relations[relation.uid] ??= message;
            else placed.other.push(message);
            continue;
        }

        if (head === 'seo' && index !== undefined && index in seoKeys) {
            placed.form.push({ field: `seo.${index as keyof SeoFields}`, message });
            continue;
        }
        if (head === 'compliance' && (index === 'bsti_certificate_no' || index === 'bsti_expires_on')) {
            placed.form.push({ field: index, message });
            continue;
        }

        const top = head === 'category_ids' ? 'category_ids' : head;
        if (top !== undefined && known.has(top)) placed.form.push({ field: top as keyof FormValues, message });
        else placed.other.push(message);
    }

    return placed;
}

/** "4 years 6 months" style helper parts for an age in months (display only). */
export function ageParts(months: string): { years: number; months: number } | null {
    const value = Number(months);
    if (months.trim() === '' || !Number.isInteger(value) || value < 0) return null;
    return { years: Math.floor(value / 12), months: value % 12 };
}

/**
 * Thousands separators on a decimal string, without turning it into a number:
 * "1850.00" → "1,850", "1250.50" → "1,250.50". Formatting only — never arithmetic.
 */
export function formatMoney(decimal: string): string {
    const [whole = '', fraction = ''] = decimal.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return fraction === '' || /^0+$/.test(fraction) ? grouped : `${grouped}.${fraction}`;
}
