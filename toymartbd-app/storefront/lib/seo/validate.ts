// No-network check of JSON-LD against Google's Rich Results requirements (Search Central structured
// data docs, as of 2026-11). `errors` = a property Google marks REQUIRED for the rich result (the
// page loses the result); `warnings` = RECOMMENDED. Where Google lists no required property (Article,
// Organization), the properties we treat as mandatory are errors by OUR policy and say so.
// Used by the unit tests on every template's output; not shipped to the browser.

export interface ValidationResult {
    type: string;
    errors: string[];
    warnings: string[];
}

type Node = Record<string, unknown>;

const isObject = (v: unknown): v is Node => typeof v === 'object' && v !== null && !Array.isArray(v);
const list = (v: unknown): unknown[] => (v === undefined || v === null ? [] : Array.isArray(v) ? v : [v]);
const filled = (v: unknown): boolean => (typeof v === 'string' ? v.trim() !== '' : typeof v === 'number' ? Number.isFinite(v) : Array.isArray(v) ? v.length > 0 : isObject(v));
const absoluteUrl = (v: unknown): boolean => typeof v === 'string' && /^https?:\/\/[^\s]+$/.test(v);
const isoDate = (v: unknown): boolean => typeof v === 'string' && !Number.isNaN(Date.parse(v)) && /^\d{4}-\d{2}-\d{2}/.test(v);
const numeric = (v: unknown): boolean => (typeof v === 'number' && Number.isFinite(v)) || (typeof v === 'string' && /^\d+(\.\d+)?$/.test(v));

const ISO_4217 = /^[A-Z]{3}$/;
const AVAILABILITY = /^https?:\/\/schema\.org\/(InStock|OutOfStock|LimitedAvailability|PreOrder|BackOrder|Discontinued|SoldOut|OnlineOnly|InStoreOnly|PreSale|MadeToOrder|Reserved)$/;

function typeOf(node: Node): string {
    const t = node['@type'];
    return Array.isArray(t) ? String(t[0]) : String(t ?? '');
}

function need(errors: string[], node: Node, key: string, label = key): void {
    if (!filled(node[key])) errors.push(`missing required "${label}"`);
}

function want(warnings: string[], node: Node, key: string, label = key): void {
    if (!filled(node[key])) warnings.push(`missing recommended "${label}"`);
}

function offer(o: unknown, i: number, errors: string[], warnings: string[]): void {
    const at = `offers[${i}]`;
    if (!isObject(o)) return void errors.push(`${at} is not an object`);
    const price = o.price ?? (isObject(o.priceSpecification) ? o.priceSpecification.price : undefined);
    if (!numeric(price)) errors.push(`missing required "${at}.price" (a number, no currency symbol)`);
    else if (Number(price) <= 0) errors.push(`"${at}.price" must be greater than 0`);
    if (typeof o.priceCurrency !== 'string' || !ISO_4217.test(o.priceCurrency)) errors.push(`missing required "${at}.priceCurrency" (ISO 4217)`);
    if (o.availability === undefined) warnings.push(`missing recommended "${at}.availability"`);
    else if (!AVAILABILITY.test(String(o.availability))) errors.push(`"${at}.availability" is not a schema.org ItemAvailability URL`);
    if (o.url !== undefined && !absoluteUrl(o.url)) errors.push(`"${at}.url" must be absolute`);
    if (o.priceValidUntil !== undefined && !(typeof o.priceValidUntil === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(o.priceValidUntil))) errors.push(`"${at}.priceValidUntil" must be an ISO 8601 date`);
}

function aggregateRating(r: unknown, errors: string[]): void {
    if (!isObject(r)) return void errors.push('aggregateRating is not an object');
    if (!numeric(r.ratingValue)) errors.push('missing required "aggregateRating.ratingValue"');
    const count = r.ratingCount ?? r.reviewCount;
    if (!numeric(count)) errors.push('missing required "aggregateRating.ratingCount" or "reviewCount"');
    else if (Number(count) <= 0) errors.push('"aggregateRating" with zero reviews must not be emitted');
    if (numeric(r.ratingValue) && numeric(r.bestRating) && Number(r.ratingValue) > Number(r.bestRating)) errors.push('"aggregateRating.ratingValue" exceeds bestRating');
}

function product(node: Node, errors: string[], warnings: string[]): void {
    need(errors, node, 'name');
    // Product snippet: one of offers / review / aggregateRating. Merchant listing: image + offers.
    if (!filled(node.offers) && !filled(node.review) && !filled(node.aggregateRating)) errors.push('needs one of "offers", "review" or "aggregateRating"');
    // `image` is required for merchant listings (not for the product snippet): a warning here, so a
    // product still waiting for photos keeps its snippet.
    if (!filled(node.image)) warnings.push('missing "image" (required for merchant listings)');
    for (const img of list(node.image)) if (!absoluteUrl(isObject(img) ? img.url : img)) errors.push('"image" must be absolute URLs');
    list(node.offers).forEach((o, i) => offer(o, i, errors, warnings));
    if (node.aggregateRating !== undefined) aggregateRating(node.aggregateRating, errors);
    want(warnings, node, 'description');
    want(warnings, node, 'brand');
    want(warnings, node, 'sku');
}

function breadcrumbList(node: Node, errors: string[]): void {
    const items = list(node.itemListElement);
    if (items.length < 2) errors.push('"itemListElement" needs at least two ListItems');
    items.forEach((raw, i) => {
        if (!isObject(raw)) return void errors.push(`itemListElement[${i}] is not an object`);
        if (raw.position !== i + 1) errors.push(`itemListElement[${i}].position must be ${i + 1}`);
        if (!filled(raw.name)) errors.push(`missing required "itemListElement[${i}].name"`);
        // `item` may be omitted on the last crumb only.
        if (i < items.length - 1 && !absoluteUrl(raw.item)) errors.push(`missing required "itemListElement[${i}].item" (absolute URL)`);
    });
}

function organization(node: Node, errors: string[], warnings: string[]): void {
    // Google lists no required property; name + url are required by our policy.
    need(errors, node, 'name');
    if (!absoluteUrl(node.url)) errors.push('missing required "url" (absolute; our policy)');
    if (node.logo !== undefined && !absoluteUrl(isObject(node.logo) ? node.logo.url : node.logo)) errors.push('"logo" must be an absolute URL');
    want(warnings, node, 'logo');
    for (const s of list(node.sameAs)) if (!absoluteUrl(s)) errors.push('"sameAs" must be absolute URLs');
}

function website(node: Node, errors: string[]): void {
    if (!absoluteUrl(node.url)) errors.push('missing required "url" (absolute)');
    need(errors, node, 'name');
    const action = list(node.potentialAction).find((a) => isObject(a) && typeOf(a) === 'SearchAction');
    if (!isObject(action)) return void errors.push('missing required "potentialAction" SearchAction');
    const target = isObject(action.target) ? action.target.urlTemplate : action.target;
    const input = String(action['query-input'] ?? '');
    const variable = /name=([A-Za-z_]+)/.exec(input)?.[1];
    if (typeof target !== 'string' || !absoluteUrl(target.replace(/\{[^}]+\}/g, 'x'))) errors.push('SearchAction "target.urlTemplate" must be an absolute URL');
    if (!variable || !input.startsWith('required')) errors.push('SearchAction "query-input" must be "required name=…"');
    else if (typeof target === 'string' && !target.includes(`{${variable}}`)) errors.push(`SearchAction target lacks {${variable}}`);
}

function article(node: Node, errors: string[], warnings: string[]): void {
    // Google lists no required property; these are required by our policy (they drive the result).
    need(errors, node, 'headline');
    if (typeof node.headline === 'string' && node.headline.length > 110) errors.push('"headline" longer than 110 characters');
    if (!isoDate(node.datePublished)) errors.push('missing required "datePublished" (ISO 8601; our policy)');
    if (node.dateModified !== undefined && !isoDate(node.dateModified)) errors.push('"dateModified" must be ISO 8601');
    const authors = list(node.author);
    if (authors.length === 0) errors.push('missing required "author" (our policy)');
    for (const a of authors) if (!isObject(a) || !filled(a.name)) errors.push('"author.name" is required');
    want(warnings, node, 'image');
    for (const img of list(node.image)) if (!absoluteUrl(isObject(img) ? img.url : img)) errors.push('"image" must be absolute URLs');
    want(warnings, node, 'dateModified');
}

function faqPage(node: Node, errors: string[]): void {
    const questions = list(node.mainEntity);
    if (questions.length === 0) errors.push('missing required "mainEntity" (one Question or more)');
    questions.forEach((q, i) => {
        if (!isObject(q) || typeOf(q) !== 'Question') return void errors.push(`mainEntity[${i}] must be a Question`);
        if (!filled(q.name)) errors.push(`missing required "mainEntity[${i}].name"`);
        const answer = q.acceptedAnswer;
        if (!isObject(answer) || typeOf(answer) !== 'Answer' || !filled(answer.text)) errors.push(`missing required "mainEntity[${i}].acceptedAnswer.text"`);
    });
}

export function validateJsonLd(node: unknown): ValidationResult {
    if (!isObject(node)) return { type: '', errors: ['not an object'], warnings: [] };
    const type = typeOf(node);
    const errors: string[] = [];
    const warnings: string[] = [];
    if (node['@context'] !== 'https://schema.org') errors.push('"@context" must be https://schema.org');
    switch (type) {
        case 'Product':
            product(node, errors, warnings);
            break;
        case 'BreadcrumbList':
            breadcrumbList(node, errors);
            break;
        case 'Organization':
            organization(node, errors, warnings);
            break;
        case 'WebSite':
            website(node, errors);
            break;
        case 'Article':
        case 'BlogPosting':
        case 'NewsArticle':
            article(node, errors, warnings);
            break;
        case 'FAQPage':
            faqPage(node, errors);
            break;
        default:
            errors.push(`unsupported @type "${type}"`);
    }
    return { type, errors, warnings };
}

/** Every node of a template's JSON-LD (an array of nodes or one). */
export function validateAll(data: unknown): ValidationResult[] {
    return list(data).map(validateJsonLd);
}
