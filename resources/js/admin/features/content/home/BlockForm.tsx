import { PlusIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { MediaField, type PickedImage } from '@/features/content/shared/MediaField';
import type { CmsBlock } from './api';
import { BLOOM_TONES, bool, CONTACT_CHANNELS, ids, num, rows, str, TILE_ICONS, type Content } from './content';
import { Bilingual, moved, Repeater, SelectField, SwitchRow, TextField, type FieldCtx } from './fields';
import { BrandPicker, CategoryPicker, ProductPicker, useBrandSelectOptions, useCategoryOptions } from './pickers';
import { AgeTilesForm, BandField, PromoBannersForm, SectionHeaderFields, VideoReelForm, WhyBandForm, type FormKit, type VideoLengthInfo } from './SectionForms';

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
    /** Block settings shown inside the Content tab where Figma puts them (rail: "Show on mobile"). */
    onSettings: (patch: { show_mobile: boolean }) => void;
    ctx: FieldCtx;
    media: ReadonlyMap<number, MediaThumb>;
    /** Detected reel lengths by video link (CmsHomeDraft `video_lengths`). */
    videoLengths?: ReadonlyMap<string, VideoLengthInfo>;
};

const USP_ICONS = ['cod', 'return', 'authentic', 'support', 'delivery', 'gift', 'educational'] as const;

const tileIconOptions = (t: (key: MessageKey) => string) => TILE_ICONS.map((icon) => ({ value: icon, label: t(`homeBuilder.tileIcon.${icon}` as MessageKey) }));

function pickedValue(id: number | null, media: ReadonlyMap<number, MediaThumb>, picked: Map<number, PickedImage>): PickedImage | null {
    if (id === null) return null;
    return picked.get(id) ?? { media_id: id, url: media.get(id)?.thumb_url ?? null, alt: media.get(id)?.alt_text ?? null };
}

function imageValue(content: Content, media: ReadonlyMap<number, MediaThumb>, picked: Map<number, PickedImage>): PickedImage | null {
    const id = typeof content.media_id === 'number' ? content.media_id : null;
    if (id === null) return null;
    return picked.get(id) ?? { media_id: id, url: media.get(id)?.thumb_url ?? null, alt: media.get(id)?.alt_text ?? null };
}

/** Thumbnails of images picked in this session, before the draft reload knows them. */
const pickedThisSession = new Map<number, PickedImage>();

export function ContentForm({ block, content, onChange, onSettings, ctx, media, videoLengths }: Props) {
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
    const kit: FormKit = { content, set, setRow, listOps, pickImage, imageOf: (row) => imageValue(row, media, pickedThisSession), ctx, videoLengths };

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
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="accent" set={setSlide} ctx={ctx} hint={t('homeBuilder.f.accentHint')} />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="overline" set={setSlide} ctx={ctx} />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="sub" set={setSlide} ctx={ctx} multiline />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="cta_label" set={setSlide} ctx={ctx} />
                                <TextField label={t('homeBuilder.f.cta_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(slide.cta_href)} onChange={(v) => setSlide('cta_href', v)} path={`slides.${i}.cta_href`} ctx={ctx} mono />
                                <Bilingual content={slide} prefix={`slides.${i}.`} keyName="cta2_label" set={setSlide} ctx={ctx} />
                                <TextField label={t('homeBuilder.f.cta2_href')} value={str(slide.cta2_href)} onChange={(v) => setSlide('cta2_href', v)} path={`slides.${i}.cta2_href`} ctx={ctx} mono />
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
                    <HeroExtras content={content} set={set} setRow={setRow} listOps={listOps} pickImage={pickImage} ctx={ctx} media={media} />
                </>
            );
        }
        case 'category_tiles':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="eyebrow" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="sub" set={set} ctx={ctx} hint={t('homeBuilder.f.categoryTaglines')} />
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
        case 'flash_sale':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="sub" set={set} ctx={ctx} hint={t('homeBuilder.f.flashSubHint')} />
                </>
            );
        case 'age_tiles':
            return <AgeTilesForm kit={kit} />;
        case 'testimonials':
            return <Bilingual content={content} keyName="title" set={set} ctx={ctx} />;
        case 'blog_teaser':
            return <SectionHeaderFields kit={kit} tone="green" />;
        case 'product_rail':
            return <RailContent kit={kit} setMany={(patch) => onChange({ ...content, ...patch })} block={block} onSettings={onSettings} />;
        case 'promo_banners':
            return <PromoBannersForm kit={kit} />;
        case 'why_band':
            return <WhyBandForm kit={kit} />;
        case 'video_reel':
            return <VideoReelForm kit={kit} />;
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
                    <SelectField
                        label={t('homeBuilder.f.topicIcon')}
                        value={str(content.icon)}
                        options={[{ value: '', label: t('homeBuilder.f.noIcon') }, ...tileIconOptions(t)]}
                        onChange={(v) => set('icon', v === '' ? null : v)}
                        path="icon"
                        ctx={ctx}
                    />
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
        case 'image':
            return (
                <>
                    <MediaField
                        label={t('homeBuilder.f.image')}
                        hint={t('homeBuilder.f.imageRequired')}
                        pickerTitle="homeBuilder.f.pickImage"
                        value={imageValue(content, media, pickedThisSession)}
                        onChange={pickImage((id) => set('media_id', id))}
                        disabled={ctx.disabled}
                        error={ctx.errors['content.media_id']}
                    />
                    <Bilingual content={content} keyName="alt" set={set} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                    <Bilingual content={content} keyName="caption" set={set} ctx={ctx} multiline />
                    <TextField label={t('homeBuilder.f.href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(content.href)} onChange={(v) => set('href', v)} path="href" ctx={ctx} mono />
                </>
            );
        case 'contact_info':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="sub" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="hours" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="whatsapp_note" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="email_note" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="address_label" set={set} ctx={ctx} hint={t('homeBuilder.f.address_labelHint')} />
                    <Bilingual content={content} keyName="address_note" set={set} ctx={ctx} />
                    <TextField label={t('homeBuilder.f.map_href')} hint={t('homeBuilder.f.map_hrefHint')} value={str(content.map_href)} onChange={(v) => set('map_href', v)} path="map_href" ctx={ctx} mono />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.contactSource')}</p>
                </>
            );
        case 'story_hero': {
            const photos = ids(content.media_ids);
            // Three fixed slots; clearing one closes the gap so the collage keeps its order.
            const setPhoto = (slot: number, id: number | null) => {
                const next = [...photos];
                if (id === null) next.splice(slot, 1);
                else next[slot] = id;
                set('media_ids', next.filter((v, i, all) => all.indexOf(v) === i));
            };
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} hint={t('homeBuilder.f.storyHeading')} />
                    <Bilingual content={content} keyName="overline" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="lead" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="body" set={set} ctx={ctx} multiline />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.photosHint')}</p>
                    {[0, 1, 2].slice(0, Math.min(photos.length + 1, 3)).map((slot) => (
                        <MediaField
                            key={slot}
                            label={t('homeBuilder.f.photo', { n: slot + 1 })}
                            pickerTitle="homeBuilder.f.pickImage"
                            ratio={[1, 1]}
                            value={pickedValue(photos[slot] ?? null, media, pickedThisSession)}
                            onChange={pickImage((id) => setPhoto(slot, id))}
                            disabled={ctx.disabled}
                            error={ctx.errors[`content.media_ids.${slot}`] ?? (slot === 0 ? ctx.errors['content.media_ids'] : undefined)}
                        />
                    ))}
                    <Bilingual content={content} keyName="badge" set={set} ctx={ctx} hint={t('homeBuilder.f.badgeHint')} />
                    <Bilingual content={content} keyName="badge_sub" set={set} ctx={ctx} />
                </>
            );
        }
        case 'feature_tiles': {
            const items = rows(content.items);
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} hint={t('homeBuilder.f.tilesTitleHint')} />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.tilesColours')}</p>
                    {items.map((item, i) => {
                        const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                        return (
                            <Repeater key={i} title={t('homeBuilder.f.item', { n: i + 1 })} index={i} count={items.length} min={2} disabled={ctx.disabled} {...listOps('items')}>
                                <SelectField label={t('homeBuilder.f.icon')} value={str(item.icon) || 'check_circle'} options={tileIconOptions(t)} onChange={(v) => setItem('icon', v)} path={`items.${i}.icon`} ctx={ctx} />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="title" labelKey="itemTitle" set={setItem} ctx={ctx} />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="body" set={setItem} ctx={ctx} multiline />
                            </Repeater>
                        );
                    })}
                    {ctx.errors['content.items'] && <p className="t-admin-caption text-fg-primary">{ctx.errors['content.items']}</p>}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || items.length >= 4} onClick={() => set('items', [...items, { icon: 'check_circle', title: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addItem')}
                    </Button>
                </>
            );
        }
        case 'numbered_cards': {
            const items = rows(content.items);
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    {items.map((item, i) => {
                        const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                        return (
                            <Repeater key={i} title={t('homeBuilder.f.card', { n: i + 1 })} index={i} count={items.length} min={1} disabled={ctx.disabled} {...listOps('items')}>
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="title" labelKey="itemTitle" set={setItem} ctx={ctx} />
                                <Bilingual content={item} prefix={`items.${i}.`} keyName="body" set={setItem} ctx={ctx} multiline />
                            </Repeater>
                        );
                    })}
                    {ctx.errors['content.items'] && <p className="t-admin-caption text-fg-primary">{ctx.errors['content.items']}</p>}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || items.length >= 6} onClick={() => set('items', [...items, { title: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addCard')}
                    </Button>
                </>
            );
        }
        case 'cta_band':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="body" set={set} ctx={ctx} multiline />
                    {(['primary', 'secondary'] as const).map((button) => (
                        <div key={button} className="flex flex-col gap-4">
                            <Bilingual content={content} keyName={`${button}_label`} set={set} ctx={ctx} />
                            <TextField label={t(`homeBuilder.f.${button}_href`)} hint={t('homeBuilder.f.cta_hrefHint')} value={str(content[`${button}_href`])} onChange={(v) => set(`${button}_href`, v)} path={`${button}_href`} ctx={ctx} mono />
                            <SelectField
                                label={t(`homeBuilder.f.${button}_icon`)}
                                value={str(content[`${button}_icon`])}
                                options={[{ value: '', label: t('homeBuilder.f.noIcon') }, ...tileIconOptions(t)]}
                                onChange={(v) => set(`${button}_icon`, v === '' ? null : v)}
                                path={`${button}_icon`}
                                ctx={ctx}
                            />
                        </div>
                    ))}
                </>
            );
        case 'contact_hero': {
            const bubbles = rows(content.bubbles);
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} hint={t('homeBuilder.f.storyHeading')} />
                    <Bilingual content={content} keyName="overline" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="lead" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="status" set={set} ctx={ctx} hint={t('homeBuilder.f.statusHint')} />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.bubblesHint')}</p>
                    {bubbles.map((bubble, i) => {
                        const setBubble = (key: string, value: unknown) => setRow('bubbles', i, key, value);
                        return (
                            <Repeater key={i} title={t('homeBuilder.f.bubble', { n: i + 1 })} index={i} count={bubbles.length} min={0} disabled={ctx.disabled} {...listOps('bubbles')}>
                                <SelectField
                                    label={t('homeBuilder.f.bubbleFrom')}
                                    value={str(bubble.from) || 'customer'}
                                    options={(['customer', 'store'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.bubbleFrom.${v}`) }))}
                                    onChange={(v) => setBubble('from', v)}
                                    path={`bubbles.${i}.from`}
                                    ctx={ctx}
                                />
                                <Bilingual content={bubble} prefix={`bubbles.${i}.`} keyName="label" labelKey="bubbleLabel" set={setBubble} ctx={ctx} />
                                <Bilingual content={bubble} prefix={`bubbles.${i}.`} keyName="text" labelKey="bubbleText" set={setBubble} ctx={ctx} multiline />
                            </Repeater>
                        );
                    })}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || bubbles.length >= 4} onClick={() => set('bubbles', [...bubbles, { from: bubbles.length % 2 ? 'store' : 'customer', text: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addBubble')}
                    </Button>
                </>
            );
        }
        case 'contact_form': {
            const hours = rows(content.hours);
            const links = rows(content.links);
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="intro" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="privacy" set={set} ctx={ctx} />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.contactFormFields')}</p>
                    <Bilingual content={content} keyName="visit_title" set={set} ctx={ctx} hint={t('homeBuilder.f.sideCardHint')} />
                    <Bilingual content={content} keyName="visit_body" set={set} ctx={ctx} multiline />
                    <TextField label={t('homeBuilder.f.visit_map_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(content.visit_map_href)} onChange={(v) => set('visit_map_href', v)} path="visit_map_href" ctx={ctx} mono />
                    {hours.map((row, i) => {
                        const setHour = (key: string, value: unknown) => setRow('hours', i, key, value);
                        return (
                            <Repeater key={i} title={t('homeBuilder.f.hoursRow', { n: i + 1 })} index={i} count={hours.length} min={0} disabled={ctx.disabled} {...listOps('hours')}>
                                <Bilingual content={row} prefix={`hours.${i}.`} keyName="label" labelKey="hoursLabel" set={setHour} ctx={ctx} />
                                <Bilingual content={row} prefix={`hours.${i}.`} keyName="value" labelKey="hoursValue" set={setHour} ctx={ctx} />
                            </Repeater>
                        );
                    })}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || hours.length >= 6} onClick={() => set('hours', [...hours, { label: '', value: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addHoursRow')}
                    </Button>
                    <Bilingual content={content} keyName="links_title" set={set} ctx={ctx} hint={t('homeBuilder.f.sideCardHint')} />
                    {links.map((row, i) => {
                        const setLink = (key: string, value: unknown) => setRow('links', i, key, value);
                        return (
                            <Repeater key={i} title={t('homeBuilder.f.linkRow', { n: i + 1 })} index={i} count={links.length} min={0} disabled={ctx.disabled} {...listOps('links')}>
                                <SelectField label={t('homeBuilder.f.icon')} value={str(row.icon) || 'check_circle'} options={tileIconOptions(t)} onChange={(v) => setLink('icon', v)} path={`links.${i}.icon`} ctx={ctx} />
                                <Bilingual content={row} prefix={`links.${i}.`} keyName="label" labelKey="linkLabel" set={setLink} ctx={ctx} />
                                <TextField label={t('homeBuilder.f.linkHref')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(row.href)} onChange={(v) => setLink('href', v)} path={`links.${i}.href`} ctx={ctx} mono />
                            </Repeater>
                        );
                    })}
                    <Button variant="outline" size="sm" className="self-start" disabled={ctx.disabled || links.length >= 6} onClick={() => set('links', [...links, { icon: 'check_circle', label: '', href: '' }])}>
                        <PlusIcon size={16} aria-hidden />
                        {t('homeBuilder.f.addLink')}
                    </Button>
                </>
            );
        }
        case 'help_hero':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} hint={t('homeBuilder.f.storyHeading')} />
                    <Bilingual content={content} keyName="overline" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="lead" set={set} ctx={ctx} multiline />
                    <SwitchRow label={t('homeBuilder.f.showSearch')} checked={bool(content.show_search, true)} onChange={(v) => set('show_search', v)} disabled={ctx.disabled} />
                    <Bilingual content={content} keyName="search_placeholder" set={set} ctx={ctx} hint={t('homeBuilder.f.showSearchHint')} />
                </>
            );
        case 'chat_band':
            return (
                <>
                    <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
                    <Bilingual content={content} keyName="body" set={set} ctx={ctx} multiline />
                    <Bilingual content={content} keyName="button_label" set={set} ctx={ctx} />
                    <SwitchRow label={t('homeBuilder.f.showHotline')} checked={bool(content.show_hotline, false)} onChange={(v) => set('show_hotline', v)} disabled={ctx.disabled} />
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.chatBandSource')}</p>
                </>
            );
    }
}

function RailContent({ kit, setMany, block, onSettings }: { kit: FormKit; setMany: (patch: Content) => void; block: CmsBlock; onSettings: (patch: { show_mobile: boolean }) => void }) {
    const { t } = useI18n();
    const { content, set, ctx } = kit;
    const categories = useCategoryOptions();
    const brands = useBrandSelectOptions();
    const rule = str(content.rule) || 'best_sellers';
    const days = num(content.window_days, 30);
    const limit = num(content.limit, 8);
    // Figma folds the best-sellers window into the one "Products" select ("Automatic · best sellers, 30 days").
    const source = rule === 'best_sellers' ? `best_sellers:${days}` : rule;
    const sources = [
        ...[7, 30, 90].map((d) => ({ value: `best_sellers:${d}`, label: t('homeBuilder.f.rule.best_sellers', { days: d }) })),
        ...(['new', 'category', 'brand', 'manual'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.rule.${v}`) })),
    ];
    return (
        <>
            <SectionHeaderFields kit={kit} tone="yellow" />
            <SelectField
                label={t('homeBuilder.f.products')}
                value={source}
                options={sources}
                onChange={(v) => {
                    const [next, window] = v.split(':');
                    setMany(window ? { rule: next, window_days: Number(window) } : { rule: next });
                }}
                path="rule"
                ctx={ctx}
            />
            {rule === 'category' && (
                <SelectField label={t('homeBuilder.f.category')} value={typeof content.category_id === 'number' ? content.category_id : null} placeholder={t('homeBuilder.f.choose')} options={categories} onChange={(v) => set('category_id', v)} path="category_id" ctx={ctx} />
            )}
            {rule === 'brand' && (
                <SelectField label={t('homeBuilder.f.brand')} value={typeof content.brand_id === 'number' ? content.brand_id : null} placeholder={t('homeBuilder.f.choose')} options={brands} onChange={(v) => set('brand_id', v)} path="brand_id" ctx={ctx} />
            )}
            {rule === 'manual' && <ProductPicker picked={ids(content.product_ids)} onChange={(v) => set('product_ids', v)} ctx={ctx} path="product_ids" />}
            <SelectField
                label={t('homeBuilder.f.howMany')}
                value={limit}
                options={Array.from({ length: 11 }, (_, i) => ({ value: i + 2, label: t('homeBuilder.f.howManyRail', { count: i + 2 }) }))}
                onChange={(v) => set('limit', v)}
                path="limit"
                ctx={ctx}
            />
            <SwitchRow label={t('homeBuilder.f.hideSoldOut')} checked={bool(content.hide_sold_out, true)} onChange={(v) => set('hide_sold_out', v)} disabled={ctx.disabled} />
            <Bilingual content={content} keyName="see_all_label" set={set} ctx={ctx} hint={t('homeBuilder.f.see_all_labelHint')} />
            <CategoryPicker legend={t('homeBuilder.f.chips')} picked={ids(content.chip_category_ids)} onChange={(v) => set('chip_category_ids', v.slice(0, 6))} ctx={ctx} path="chip_category_ids" />
            {ids(content.chip_category_ids).length > 0 && <Bilingual content={content} keyName="all_label" set={set} ctx={ctx} />}
            <SwitchRow label={t('homeBuilder.v.showMobile')} checked={block.show_mobile} onChange={(v) => onSettings({ show_mobile: v })} disabled={ctx.disabled} />
        </>
    );
}

/** The Style tab: counts, layout switches. Types without style options say so. */
export function StyleForm({ block, content, onChange, ctx }: Omit<Props, 'media' | 'onSettings' | 'videoLengths'>) {
    const { t } = useI18n();
    const set = (key: string, value: unknown) => onChange({ ...content, [key]: value });
    const howMany = (min: number, max: number, fallback: number, label: (n: number) => string) => (
        <SelectField label={t('homeBuilder.f.howMany')} value={num(content.limit, fallback)} options={Array.from({ length: max - min + 1 }, (_, i) => ({ value: min + i, label: label(min + i) }))} onChange={(v) => set('limit', v)} path="limit" ctx={ctx} />
    );
    const count = (n: number) => t('homeBuilder.f.howManyItems', { count: n });
    const noop = () => () => undefined;
    const styleKit: FormKit = { content, set, setRow: () => undefined, listOps: () => ({ onMove: () => undefined, onRemove: () => undefined }), pickImage: noop, imageOf: () => null, ctx };

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
                    <SelectField
                        label={t('homeBuilder.f.layout')}
                        value={str(content.layout) || 'slider'}
                        options={(['slider', 'grid'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.layout.${v}`) }))}
                        onChange={(v) => set('layout', v)}
                        path="layout"
                        ctx={ctx}
                    />
                    <BandField kit={styleKit} />
                    <SelectField
                        label={t('homeBuilder.f.minItems')}
                        value={Math.min(num(content.min_items, 1), limit)}
                        options={Array.from({ length: limit }, (_, i) => ({ value: i + 1, label: t('homeBuilder.f.minItemsValue', { count: i + 1 }) }))}
                        onChange={(v) => set('min_items', v)}
                        path="min_items"
                        ctx={ctx}
                    />
                    <SwitchRow label={t('homeBuilder.f.showSeeAll')} checked={bool(content.show_see_all, true)} onChange={(v) => set('show_see_all', v)} disabled={ctx.disabled} />
                </>
            );
        }
        case 'brand_strip':
            return howMany(3, 24, 12, count);
        case 'testimonials':
            return howMany(1, 12, 6, count);
        case 'flash_sale':
            return howMany(2, 12, 8, (n) => t('homeBuilder.f.howManyRail', { count: n }));
        case 'blog_teaser':
            return (
                <>
                    {howMany(1, 6, 3, count)}
                    <SwitchRow label={t('homeBuilder.f.showSeeAll')} checked={bool(content.show_see_all, false)} onChange={(v) => set('show_see_all', v)} disabled={ctx.disabled} />
                </>
            );
        case 'video_reel':
            return <BandField kit={styleKit} />;
        case 'chat_band':
            return (
                <SelectField
                    label={t('homeBuilder.f.tone')}
                    value={str(content.tone) || 'mint'}
                    options={(['mint', 'yellow'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.chatTone.${v}`) }))}
                    onChange={(v) => set('tone', v)}
                    path="tone"
                    ctx={ctx}
                />
            );
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
        case 'image':
            return (
                <SelectField
                    label={t('homeBuilder.f.width')}
                    value={str(content.width) || 'content'}
                    options={(['content', 'wide'] as const).map((v) => ({ value: v, label: t(`homeBuilder.f.width.${v}`) }))}
                    onChange={(v) => set('width', v)}
                    path="width"
                    ctx={ctx}
                />
            );
        case 'contact_info':
            return (
                <>
                    {CONTACT_CHANNELS.map((channel) => (
                        <SwitchRow key={channel} label={t(`homeBuilder.f.show_${channel}`)} checked={bool(content[`show_${channel}`], true)} onChange={(v) => set(`show_${channel}`, v)} disabled={ctx.disabled} />
                    ))}
                </>
            );
        case 'story_hero':
        case 'cta_band':
            return (
                <SelectField
                    label={t('homeBuilder.f.tone')}
                    value={str(content.tone) || (block.type === 'cta_band' ? 'candy' : 'sunrise')}
                    options={BLOOM_TONES.map((v) => ({ value: v, label: t(`homeBuilder.f.tone.${v}`) }))}
                    onChange={(v) => set('tone', v)}
                    path="tone"
                    ctx={ctx}
                />
            );
        default:
            return null;
    }
}

const PROMO_TONES = ['pink', 'green', 'blue', 'yellow', 'purple', 'orange'] as const;

/** The hero's side promo cards (0–2) and its parents row (Smart Junior 34:5686, 34:5672). */
function HeroExtras({
    content,
    set,
    setRow,
    listOps,
    pickImage,
    ctx,
    media,
}: {
    content: Content;
    set: (key: string, value: unknown) => void;
    setRow: (listKey: string, index: number, key: string, value: unknown) => void;
    listOps: (listKey: string) => { onMove: (from: number, to: number) => void; onRemove: (index: number) => void };
    pickImage: (apply: (id: number | null) => void) => (next: PickedImage | null) => void;
    ctx: FieldCtx;
    media: ReadonlyMap<number, MediaThumb>;
}) {
    const { t } = useI18n();
    const promos = rows(content.promos);
    const proof: Content = content.proof !== null && typeof content.proof === 'object' && !Array.isArray(content.proof) ? (content.proof as Content) : {};
    const setProof = (key: string, value: unknown) => set('proof', { ...proof, [key]: value });
    const avatars = ids(proof.media_ids);
    const setAvatar = (slot: number, id: number | null) => {
        const next = [...avatars];
        if (id === null) next.splice(slot, 1);
        else next[slot] = id;
        setProof('media_ids', next.filter((v, i, all) => all.indexOf(v) === i));
    };
    const rating = typeof proof.rating === 'number' ? String(proof.rating) : str(proof.rating);

    return (
        <>
            <p className="t-admin-body-strong text-fg-primary">{t('homeBuilder.f.promos')}</p>
            <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.promosHint')}</p>
            {promos.map((promo, i) => {
                const setPromo = (key: string, value: unknown) => setRow('promos', i, key, value);
                return (
                    <Repeater key={i} title={t('homeBuilder.f.promo', { n: i + 1 })} index={i} count={promos.length} min={0} disabled={ctx.disabled} {...listOps('promos')}>
                        <Bilingual content={promo} prefix={`promos.${i}.`} keyName="title" set={setPromo} ctx={ctx} />
                        <Bilingual content={promo} prefix={`promos.${i}.`} keyName="overline" set={setPromo} ctx={ctx} />
                        <Bilingual content={promo} prefix={`promos.${i}.`} keyName="cta_label" set={setPromo} ctx={ctx} />
                        <TextField label={t('homeBuilder.f.cta_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(promo.cta_href)} onChange={(v) => setPromo('cta_href', v)} path={`promos.${i}.cta_href`} ctx={ctx} mono />
                        <SelectField
                            label={t('homeBuilder.f.promoTone')}
                            value={str(promo.tone) || PROMO_TONES[i % PROMO_TONES.length]!}
                            options={PROMO_TONES.map((v) => ({ value: v, label: t(`homeBuilder.f.promoTone.${v}`) }))}
                            onChange={(v) => setPromo('tone', v)}
                            path={`promos.${i}.tone`}
                            ctx={ctx}
                        />
                        <MediaField
                            label={t('homeBuilder.f.promoImage')}
                            pickerTitle="homeBuilder.f.pickImage"
                            value={imageValue(promo, media, pickedThisSession)}
                            onChange={pickImage((id) => setPromo('media_id', id))}
                            disabled={ctx.disabled}
                            error={ctx.errors[`content.promos.${i}.media_id`]}
                        />
                        <Bilingual content={promo} prefix={`promos.${i}.`} keyName="alt" set={setPromo} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                    </Repeater>
                );
            })}
            {ctx.errors['content.promos'] && <p className="t-admin-caption text-fg-primary">{ctx.errors['content.promos']}</p>}
            <Button
                variant="outline"
                size="sm"
                className="self-start"
                disabled={ctx.disabled || promos.length >= 2}
                onClick={() => set('promos', [...promos, { title: '', cta_href: null, media_id: null, tone: PROMO_TONES[promos.length % PROMO_TONES.length] }])}
            >
                <PlusIcon size={16} aria-hidden />
                {t('homeBuilder.f.addPromo')}
            </Button>

            <p className="t-admin-body-strong text-fg-primary">{t('homeBuilder.f.proof')}</p>
            <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.proofHint')}</p>
            <TextField
                label={t('homeBuilder.f.proofRating')}
                value={rating}
                onChange={(v) => setProof('rating', v.trim() === '' ? null : Number.isNaN(Number(v)) ? v : Number(v))}
                path="proof.rating"
                ctx={ctx}
                mono
            />
            <Bilingual content={proof} prefix="proof." keyName="label" set={setProof} ctx={ctx} />
            {[0, 1, 2].slice(0, Math.min(avatars.length + 1, 3)).map((slot) => (
                <MediaField
                    key={slot}
                    label={t('homeBuilder.f.avatar', { n: slot + 1 })}
                    pickerTitle="homeBuilder.f.pickImage"
                    ratio={[1, 1]}
                    value={pickedValue(avatars[slot] ?? null, media, pickedThisSession)}
                    onChange={pickImage((id) => setAvatar(slot, id))}
                    disabled={ctx.disabled}
                    error={ctx.errors[`content.proof.media_ids.${slot}`] ?? (slot === 0 ? ctx.errors['content.proof.media_ids'] : undefined)}
                />
            ))}
        </>
    );
}
