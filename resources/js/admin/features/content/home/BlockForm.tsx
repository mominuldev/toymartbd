import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { MediaField, type PickedImage } from '@/features/content/shared/MediaField';
import type { CmsBlock } from './api';
import { bool, ids, num, rows, str, type Content } from './content';
import { Bilingual, moved, Repeater, SelectField, SwitchRow, TextField, type FieldCtx } from './fields';
import { BrandPicker, CategoryPicker, ProductPicker, useBrandSelectOptions, useCategoryOptions } from './pickers';

/*
 * The Content and Style tabs of the B · 26 settings panel, one form per block type. Each edit
 * hands back the whole new `content` object; the panel autosaves it (PATCH) and the server
 * validates it against the type's schema. Copy is always a pair: English, then Bangla.
 */

export type MediaThumb = { id: number; thumb_url: string | null; alt_text: string | null };

type Props = {
    block: CmsBlock;
    content: Content;
    onChange: (next: Content) => void;
    ctx: FieldCtx;
    media: ReadonlyMap<number, MediaThumb>;
};

const USP_ICONS = ['cod', 'return', 'authentic', 'support', 'delivery', 'gift', 'educational'] as const;

function imageValue(content: Content, media: ReadonlyMap<number, MediaThumb>, picked: Map<number, PickedImage>): PickedImage | null {
    const id = typeof content.media_id === 'number' ? content.media_id : null;
    if (id === null) return null;
    return picked.get(id) ?? { media_id: id, url: media.get(id)?.thumb_url ?? null, alt: media.get(id)?.alt_text ?? null };
}

/** Thumbnails of images picked in this session, before the draft reload knows them. */
const pickedThisSession = new Map<number, PickedImage>();

export function ContentForm({ block, content, onChange, ctx, media }: Props) {
    const { t } = useI18n();
    const set = (key: string, value: unknown) => onChange({ ...content, [key]: value });
    const setRow = (listKey: string, index: number, key: string, value: unknown) => {
        const list = rows(content[listKey]).map((r, i) => (i === index ? { ...r, [key]: value } : r));
        set(listKey, list);
    };
    const listOps = (listKey: string) => ({
        onMove: (from: number, to: number) => set(listKey, moved(rows(content[listKey]), from, to)),
        onRemove: (index: number) => set(listKey, rows(content[listKey]).filter((_, i) => i !== index)),
    });
    const pickImage = (apply: (id: number | null) => void) => (next: PickedImage | null) => {
        if (next) pickedThisSession.set(next.media_id, next);
        apply(next?.media_id ?? null);
    };

    switch (block.type) {
        case 'hero': {
            const slides = rows(content.slides);
            return (
                <>
                    {slides.map((slide, i) => {
                        const title = t('homeBuilder.f.slide', { n: i + 1 });
                        const setSlide = (key: string, value: unknown) => setRow('slides', i, key, value);
                        return (
                            <Repeater key={i} title={title} index={i} count={slides.length} min={1} disabled={ctx.disabled} {...listOps('slides')}>
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="headline" set={setSlide} ctx={ctx} />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="overline" set={setSlide} ctx={ctx} />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="sub" set={setSlide} ctx={ctx} multiline />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="cta_label" set={setSlide} ctx={ctx} />
                                <TextField label={t('homeBuilder.f.cta_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(slide.cta_href)} onChange={(v) => setSlide('cta_href', v)} path={`slides.${i}.cta_href`} ctx={ctx} mono />
                                <MediaField
                                    label={t('homeBuilder.f.image')}
                                    hint={t('homeBuilder.f.imageDefault')}
                                    pickerTitle="homeBuilder.f.pickImage"
                                    value={imageValue(slide, media, pickedThisSession)}
                                    onChange={pickImage((id) => setSlide('media_id', id))}
                                    disabled={ctx.disabled}
                                    error={ctx.errors[`content.slides.${i}.media_id`]}
                                />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="alt" set={setSlide} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                            </Repeater>
                        );
                    })}
                    {ctx.errors['content.slides'] && <p className="t-admin-caption text-fg-primary">{ctx.errors['content.slides']}</p>}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || slides.length >= 5} onClick={() => set('slides', [...slides, { headline: '', cta_href: null, media_id: null }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addSlide')}
                    </Button>
                </>
            );
        }
        case 'category_tiles':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <SelectField
                        label={t('homeBuilder.f.source')}
                        value={str(content.source) || 'top_level'}
                        options={(['top_level', 'manual'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.source.${v}`) }))}
                        onChange={(v) => set('source', v)}
                        path="source"
                        ctx={ctx}
                    />
                    {str(content.source) === 'manual' && <CategoryPicker legend={t('homeBuilder.f.pickCategories')} picked={ids(content.category_ids)} onChange={(v) => set('category_ids', v)} ctx={ctx} path="category_ids" />}
                </>
            );
        case 'age_tiles':
        case 'testimonials':
            return <Bilingual content={content} keyName="title" set={set} ctx={ctx} />;
        case 'blog_teaser':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="sub" set={set} ctx={ctx} />
                </>
            );
        case 'product_rail':
            return <RailContent content={content} set={set} ctx={ctx} />;
        case 'brand_strip':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <BrandPicker legend={t('homeBuilder.f.pickBrands')} picked={ids(content.brand_ids)} onChange={(v) => set('brand_ids', v)} ctx={ctx} path="brand_ids" />
                </>
            );
        case 'usp_band': {
            const items = rows(content.items);
            return (
                <>
                    <Bilingual content={content} keyName="eyebrow" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    {items.map((item, i) => {
                        const title = t('homeBuilder.f.item', { n: i + 1 });
                        const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                        return (
                            <Repeater key={i} title={title} index={i} count={items.length} min={2} disabled={ctx.disabled} {...listOps('items')}>
                                <SelectField
                                    label={t('homeBuilder.f.icon')}
                                    value={str(item.icon) || 'cod'}
                                    options={USP_ICONS.map((icon) => ({ value: icon, label: t(`homeBuilder.icon.${icon}` as MessageKey) }))}
                                    onChange={(v) => setItem('icon', v)}
                                    path={`items.${i}.icon`}
                                    ctx={ctx}
                                />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="title" labelKey="itemTitle" set={setItem} ctx={ctx} />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="detail" set={setItem} ctx={ctx} hint={t('homeBuilder.f.detailHint')} />
                            </Repeater>
                        );
                    })}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || items.length >= 4} onClick={() => set('items', [...items, { icon: 'cod', title: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addItem')}
                    </Button>
                </>
            );
        }
        case 'promo_split':
            return (
                <>
                    <Bilingual content={content} keyName="headline" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="overline" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="body" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="cta_label" set={set} ctx={ctx} />
                    <TextField label={t('homeBuilder.f.cta_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(content.cta_href)} onChange={(v) => set('cta_href', v)} path="cta_href" ctx={ctx} mono />
                    <MediaField
                        label={t('homeBuilder.f.image')}
                        pickerTitle="homeBuilder.f.pickImage"
                        value={imageValue(content, media, pickedThisSession)}
                        onChange={pickImage((id) => set('media_id', id))}
                        disabled={ctx.disabled}
                        error={ctx.errors['content.media_id']}
                    />
                    <Bilingual content={content} keyName="alt" set={set} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                </>
            );
        case 'rich_text':
            return <Bilingual content={content} keyName="html" set={set} ctx={ctx} multiline hint={t('homeBuilder.f.htmlHint')} />;
        case 'faq': {
            const items = rows(content.items);
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    {items.map((item, i) => {
                        const title = t('homeBuilder.f.item', { n: i + 1 });
                        const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                        return (
                            <Repeater key={i} title={title} index={i} count={items.length} min={1} disabled={ctx.disabled} {...listOps('items')}>
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="question" set={setItem} ctx={ctx} />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="answer" set={setItem} ctx={ctx} multiline />
                            </Repeater>
                        );
                    })}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || items.length >= 20} onClick={() => set('items', [...items, { question: '', answer: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addQuestion')}
                    </Button>
                </>
            );
        }
    }
}

function RailContent({ content, set, ctx }: { content: Content; set: (key: string, value: unknown) => void; ctx: FieldCtx }) {
    const { t } = useI18n();
    const categories = useCategoryOptions();
    const brands = useBrandSelectOptions();
    const rule = str(content.rule) || 'best_sellers';
    const days = num(content.window_days, 30);
    return (
        <>
            <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
            <SelectField
                label={t('homeBuilder.f.products')}
                value={rule}
                options={(['best_sellers', 'new', 'category', 'brand', 'manual'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.rule.${v}`, { days }) }))}
                onChange={(v) => set('rule', v)}
                path="rule"
                ctx={ctx}
            />
            {rule === 'best_sellers' && (
                <SelectField label={t('homeBuilder.f.window')} value={days} options={[7, 30, 90].map((d) => ({ value: d, label: t('homeBuilder.f.windowDays', { days: d }) }))} onChange={(v) => set('window_days', v)} path="window_days" ctx={ctx} />
            )}
            {rule === 'category' && (
                <SelectField label={t('homeBuilder.f.category')} value={typeof content.category_id === 'number' ? content.category_id : null} placeholder={t('homeBuilder.f.choose')} options={categories} onChange={(v) => set('category_id', v)} path="category_id" ctx={ctx} />
            )}
            {rule === 'brand' && (
                <SelectField label={t('homeBuilder.f.brand')} value={typeof content.brand_id === 'number' ? content.brand_id : null} placeholder={t('homeBuilder.f.choose')} options={brands} onChange={(v) => set('brand_id', v)} path="brand_id" ctx={ctx} />
            )}
            {rule === 'manual' && <ProductPicker picked={ids(content.product_ids)} onChange={(v) => set('product_ids', v)} ctx={ctx} path="product_ids" />}
        </>
    );
}

/** The Style tab: counts, layout switches. Types without style options say so. */
export function StyleForm({ block, content, onChange, ctx }: Omit<Props, 'media'>) {
    const { t } = useI18n();
    const set = (key: string, value: unknown) => onChange({ ...content, [key]: value });
    const howMany = (min: number, max: number, fallback: number, label: (n: number) => string) => (
        <SelectField label={t('homeBuilder.f.howMany')} value={num(content.limit, fallback)} options={Array.from({ length: max - min + 1 }, (_, i) => ({ value: min + i, label: label(min + i) }))} onChange={(v) => set('limit', v)} path="limit" ctx={ctx} />
    );
    const count = (n: number) => t('homeBuilder.f.howManyItems', { count: n });

    switch (block.type) {
        case 'hero':
            return <SwitchRow label={t('homeBuilder.f.showAgePills')} checked={bool(content.show_age_pills, true)} onChange={(v) => set('show_age_pills', v)} disabled={ctx.disabled} />;
        case 'category_tiles':
            return (
                <>
                    {str(content.source) !== 'manual' && howMany(2, 12, 8, count)}
                    <SwitchRow label={t('homeBuilder.f.showSeeAll')} checked={bool(content.show_see_all, true)} onChange={(v) => set('show_see_all', v)} disabled={ctx.disabled} />
                </>
            );
        case 'age_tiles':
            return <SwitchRow label={t('homeBuilder.f.showSeeAll')} checked={bool(content.show_see_all, true)} onChange={(v) => set('show_see_all', v)} disabled={ctx.disabled} />;
        case 'product_rail': {
            const limit = num(content.limit, 8);
            return (
                <>
                    {howMany(2, 12, 8, (n) => t('homeBuilder.f.howManyRail', { count: n }))}
                    <SelectField
                        label={t('homeBuilder.f.minItems')}
                        value={Math.min(num(content.min_items, 1), limit)}
                        options={Array.from({ length: limit }, (_, i) => ({ value: i + 1, label: t('homeBuilder.f.minItemsValue', { count: i + 1 }) }))}
                        onChange={(v) => set('min_items', v)}
                        path="min_items"
                        ctx={ctx}
                    />
                    <SwitchRow label={t('homeBuilder.f.hideSoldOut')} checked={bool(content.hide_sold_out, true)} onChange={(v) => set('hide_sold_out', v)} disabled={ctx.disabled} />
                    <SwitchRow label={t('homeBuilder.f.showSeeAll')} checked={bool(content.show_see_all, true)} onChange={(v) => set('show_see_all', v)} disabled={ctx.disabled} />
                </>
            );
        }
        case 'brand_strip':
            return howMany(3, 24, 12, count);
        case 'testimonials':
            return howMany(1, 12, 6, count);
        case 'blog_teaser':
            return howMany(1, 6, 3, count);
        case 'promo_split':
            return (
                <SelectField
                    label={t('homeBuilder.f.imageSide')}
                    value={str(content.image_side) || 'left'}
                    options={(['left', 'right'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.imageSide.${v}`) }))}
                    onChange={(v) => set('image_side', v)}
                    path="image_side"
                    ctx={ctx}
                />
            );
        default:
            return null;
    }
}
