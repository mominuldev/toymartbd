import { PlusIcon } from '@phosphor-icons/react';
import { Button } from '@/components/ui/Button';
import { MediaField, type PickedImage } from '@/features/content/shared/MediaField';
import { useI18n } from '@/i18n/I18nProvider';
import { ids, rows, str, type Content } from './content';
import { Bilingual, Repeater, SelectField, SwitchRow, TextField, type FieldCtx } from './fields';
import { ProductPicker } from './pickers';

/*
 * Content forms for the Smart Junior home sections (Figma 2b6PVOOapPbcaKYIdHWZup page 34:5658):
 * the shared section header (eyebrow chip + its colour, heading, line), the age photo cards, the
 * promo banners, "Why Smart Junior" and the video reel. Every word, link, image and figure these
 * sections show on the storefront is edited here — the storefront draws none of its own.
 */

export type MediaThumb = { id: number; thumb_url: string | null; alt_text: string | null };

/** What the server found for a reel link's length (CmsHomeDraft `video_lengths`), by link. */
export type VideoLengthInfo = { seconds: number | null; status: 'pending' | 'found' | 'unavailable' | 'failed' | 'no_key' | 'unsupported' };

export type FormKit = {
    content: Content;
    set: (key: string, value: unknown) => void;
    setRow: (listKey: string, index: number, key: string, value: unknown) => void;
    listOps: (listKey: string) => { onMove: (from: number, to: number) => void; onRemove: (index: number) => void };
    pickImage: (apply: (id: number | null) => void) => (next: PickedImage | null) => void;
    imageOf: (row: Content) => PickedImage | null;
    ctx: FieldCtx;
    videoLengths?: ReadonlyMap<string, VideoLengthInfo>;
};

/** 42 → "0:42", 3725 → "1:02:05" (same as the server's VideoLengths::format). */
export const formatLength = (seconds: number): string => {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = String(seconds % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
};

/** The palette (BlockSchema::TONES). */
export const TONES = ['pink', 'green', 'blue', 'yellow', 'purple', 'orange'] as const;

function ToneField({ label, value, fallback, onChange, path, ctx, none }: { label: string; value: unknown; fallback: string; onChange: (v: string | null) => void; path: string; ctx: FieldCtx; none?: boolean }) {
    const { t } = useI18n();
    const options = [...(none ? [{ value: '', label: t('homeBuilder.f.toneNone') }] : []), ...TONES.map((v) => ({ value: v as string, label: t(`homeBuilder.f.promoTone.${v}`) }))];
    return <SelectField label={label} value={str(value) || fallback} options={options} onChange={(v) => onChange(v === '' ? null : v)} path={path} ctx={ctx} />;
}

/** Eyebrow chip + its colour, heading, line (Figma "Section Header"). */
export function SectionHeaderFields({ kit, tone, sub = true }: { kit: FormKit; tone: string; sub?: boolean }) {
    const { t } = useI18n();
    const { content, set, ctx } = kit;
    return (
        <>
            <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
            <Bilingual content={content} keyName="eyebrow" set={set} ctx={ctx} hint={t('homeBuilder.f.eyebrowHint')} />
            <ToneField label={t('homeBuilder.f.eyebrowTone')} value={content.eyebrow_tone} fallback={tone} onChange={(v) => set('eyebrow_tone', v)} path="eyebrow_tone" ctx={ctx} />
            {sub && <Bilingual content={content} keyName="sub" set={set} ctx={ctx} multiline />}
        </>
    );
}

/** A pastel band behind the whole section, or none (the page canvas). */
export function BandField({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    return <ToneField label={t('homeBuilder.f.band')} value={kit.content.band} fallback="" onChange={(v) => kit.set('band', v)} path="band" ctx={kit.ctx} none />;
}

/** BlockSchema::SLIDER_SPEEDS (ms) and SLIDER_DELAYS (s): fixed choices, as the server validates. */
export const SLIDER_SPEEDS = [300, 500, 800, 1200] as const;
export const SLIDER_DELAYS = [3, 4, 5, 6, 8, 10] as const;
const SLIDER_DEFAULTS = { loop: false, autoplay: false, delay: 5, speed: 500 };

/** Carousel behaviour for a block the storefront shows as a slider (`content.slider`). */
export function SliderFields({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    const raw = kit.content.slider;
    const current = { ...SLIDER_DEFAULTS, ...(raw !== null && typeof raw === 'object' ? (raw as Partial<typeof SLIDER_DEFAULTS>) : {}) };
    const put = (patch: Partial<typeof SLIDER_DEFAULTS>) => kit.set('slider', { ...current, ...patch });
    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 t-admin-label text-fg-primary">{t('homeBuilder.f.slider')}</legend>
            <SwitchRow label={t('homeBuilder.f.slider.loop')} checked={current.loop === true} onChange={(v) => put({ loop: v })} disabled={kit.ctx.disabled} />
            <SwitchRow label={t('homeBuilder.f.slider.autoplay')} checked={current.autoplay === true} onChange={(v) => put({ autoplay: v })} disabled={kit.ctx.disabled} />
            {current.autoplay === true && (
                <>
                    <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.slider.autoplayHint')}</p>
                    <SelectField
                        label={t('homeBuilder.f.slider.delay')}
                        value={current.delay}
                        options={SLIDER_DELAYS.map((v) => ({ value: v, label: t('homeBuilder.f.slider.delayValue', { count: v }) }))}
                        onChange={(v) => put({ delay: v })}
                        path="slider.delay"
                        ctx={kit.ctx}
                    />
                </>
            )}
            <SelectField
                label={t('homeBuilder.f.slider.speed')}
                value={current.speed}
                options={SLIDER_SPEEDS.map((v) => ({ value: v, label: t(`homeBuilder.f.slider.speed.${v}`) }))}
                onChange={(v) => put({ speed: v })}
                path="slider.speed"
                ctx={kit.ctx}
            />
        </fieldset>
    );
}

function AddButton({ label, disabled, onClick }: { label: string; disabled: boolean; onClick: () => void }) {
    return (
        <Button variant="outline" size="sm" className="self-start" disabled={disabled} onClick={onClick}>
            <PlusIcon size={16} aria-hidden />
            {label}
        </Button>
    );
}

function ListError({ kit, path }: { kit: FormKit; path: string }) {
    const error = kit.ctx.errors[`content.${path}`];
    return error ? <p className="t-admin-caption text-fg-primary">{error}</p> : null;
}

/** "Shop by age": the header, then photo cards; with none the storefront shows the seven live age bands. */
export function AgeTilesForm({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    const { content, set, setRow, listOps, pickImage, imageOf, ctx } = kit;
    const cards = rows(content.cards);
    return (
        <>
            <SectionHeaderFields kit={kit} tone="blue" />
            <p className="t-admin-body-strong text-fg-primary">{t('homeBuilder.f.ageCards')}</p>
            <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.ageCardsHint')}</p>
            {cards.map((card, i) => {
                const setCard = (key: string, value: unknown) => setRow('cards', i, key, value);
                return (
                    <Repeater key={i} title={t('homeBuilder.f.card', { n: i + 1 })} index={i} count={cards.length} min={0} disabled={ctx.disabled} {...listOps('cards')}>
                        <Bilingual content={card} prefix={`cards.${i}.`} keyName="title" labelKey="itemTitle" set={setCard} ctx={ctx} />
                        <Bilingual content={card} prefix={`cards.${i}.`} keyName="line" set={setCard} ctx={ctx} />
                        <TextField label={t('homeBuilder.f.linkHref')} hint={t('homeBuilder.f.ageHrefHint')} value={str(card.href)} onChange={(v) => setCard('href', v)} path={`cards.${i}.href`} ctx={ctx} mono />
                        <ToneField label={t('homeBuilder.f.promoTone')} value={card.tone} fallback={TONES[[3, 0, 2, 1, 4, 5][i % 6]!]!} onChange={(v) => setCard('tone', v)} path={`cards.${i}.tone`} ctx={ctx} />
                        <MediaField label={t('homeBuilder.f.photo', { n: i + 1 })} pickerTitle="homeBuilder.f.pickImage" value={imageOf(card)} onChange={pickImage((id) => setCard('media_id', id))} disabled={ctx.disabled} error={ctx.errors[`content.cards.${i}.media_id`]} />
                        <Bilingual content={card} prefix={`cards.${i}.`} keyName="alt" set={setCard} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                    </Repeater>
                );
            })}
            <ListError kit={kit} path="cards" />
            <AddButton label={t('homeBuilder.f.addCard')} disabled={ctx.disabled || cards.length >= 8} onClick={() => set('cards', [...cards, { title: '', href: '', media_id: null, tone: TONES[[3, 0, 2, 1, 4, 5][cards.length % 6]!] }])} />
        </>
    );
}

/** One or two banners side by side (Promo banners 34:5763). */
export function PromoBannersForm({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    const { content, set, setRow, listOps, pickImage, imageOf, ctx } = kit;
    const items = rows(content.items);
    return (
        <>
            {items.map((item, i) => {
                const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                return (
                    <Repeater key={i} title={t('homeBuilder.f.banner', { n: i + 1 })} index={i} count={items.length} min={1} disabled={ctx.disabled} {...listOps('items')}>
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="title" set={setItem} ctx={ctx} />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="overline" set={setItem} ctx={ctx} />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="body" set={setItem} ctx={ctx} multiline />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="cta_label" set={setItem} ctx={ctx} />
                        <TextField label={t('homeBuilder.f.cta_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(item.cta_href)} onChange={(v) => setItem('cta_href', v)} path={`items.${i}.cta_href`} ctx={ctx} mono />
                        <ToneField label={t('homeBuilder.f.promoTone')} value={item.tone} fallback={i === 0 ? 'purple' : 'green'} onChange={(v) => setItem('tone', v)} path={`items.${i}.tone`} ctx={ctx} />
                        <MediaField label={t('homeBuilder.f.image')} pickerTitle="homeBuilder.f.pickImage" value={imageOf(item)} onChange={pickImage((id) => setItem('media_id', id))} disabled={ctx.disabled} error={ctx.errors[`content.items.${i}.media_id`]} />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="alt" set={setItem} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                    </Repeater>
                );
            })}
            <ListError kit={kit} path="items" />
            <AddButton label={t('homeBuilder.f.addBanner')} disabled={ctx.disabled || items.length >= 2} onClick={() => set('items', [...items, { title: '', cta_href: null, media_id: null, tone: items.length === 0 ? 'purple' : 'green' }])} />
        </>
    );
}

/** "Why Smart Junior": copy, up to four figures typed by the owner, a photo. */
export function WhyBandForm({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    const { content, set, setRow, listOps, pickImage, imageOf, ctx } = kit;
    const stats = rows(content.stats);
    return (
        <>
            <Bilingual content={content} keyName="title" set={set} ctx={ctx} />
            <Bilingual content={content} keyName="eyebrow" set={set} ctx={ctx} />
            <Bilingual content={content} keyName="body" set={set} ctx={ctx} multiline />
            <p className="t-admin-body-strong text-fg-primary">{t('homeBuilder.f.stats')}</p>
            <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.statsHint')}</p>
            {stats.map((stat, i) => {
                const setStat = (key: string, value: unknown) => setRow('stats', i, key, value);
                return (
                    <Repeater key={i} title={t('homeBuilder.f.stat', { n: i + 1 })} index={i} count={stats.length} min={0} disabled={ctx.disabled} {...listOps('stats')}>
                        <TextField label={t('homeBuilder.f.statValue')} value={str(stat.value)} onChange={(v) => setStat('value', v)} path={`stats.${i}.value`} ctx={ctx} mono />
                        <Bilingual content={stat} prefix={`stats.${i}.`} keyName="label" labelKey="statLabel" set={setStat} ctx={ctx} />
                    </Repeater>
                );
            })}
            <ListError kit={kit} path="stats" />
            <AddButton label={t('homeBuilder.f.addStat')} disabled={ctx.disabled || stats.length >= 4} onClick={() => set('stats', [...stats, { value: '', label: '' }])} />
            <MediaField label={t('homeBuilder.f.image')} pickerTitle="homeBuilder.f.pickImage" value={imageOf(content)} onChange={pickImage((id) => set('media_id', id))} disabled={ctx.disabled} error={ctx.errors['content.media_id']} />
            <Bilingual content={content} keyName="alt" set={set} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
        </>
    );
}

/** Video reel: the header and its "see all" link, then each reel (video link, poster, caption, product). */
export function VideoReelForm({ kit }: { kit: FormKit }) {
    const { t } = useI18n();
    const { content, set, setRow, listOps, pickImage, imageOf, ctx, videoLengths } = kit;
    const items = rows(content.items);
    // The Length hint says what the server read from the video; a typed length overrides it.
    const lengthHint = (url: string): string => {
        const info = url ? videoLengths?.get(url) : undefined;
        if (!info) return t('homeBuilder.f.durationAuto.pending');
        if (info.status === 'found' && info.seconds) return t('homeBuilder.f.durationAuto.found', { length: formatLength(info.seconds) });
        return t(`homeBuilder.f.durationAuto.${info.status === 'found' ? 'unavailable' : info.status}`);
    };
    return (
        <>
            <SectionHeaderFields kit={kit} tone="pink" />
            <Bilingual content={content} keyName="see_all_label" set={set} ctx={ctx} />
            <TextField label={t('homeBuilder.f.see_all_href')} hint={t('homeBuilder.f.cta_hrefHint')} value={str(content.see_all_href)} onChange={(v) => set('see_all_href', v)} path="see_all_href" ctx={ctx} mono />
            <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.reelHint')}</p>
            {items.map((item, i) => {
                const setItem = (key: string, value: unknown) => setRow('items', i, key, value);
                const product = typeof item.product_id === 'number' ? [item.product_id] : [];
                return (
                    <Repeater key={i} title={t('homeBuilder.f.reel', { n: i + 1 })} index={i} count={items.length} min={0} disabled={ctx.disabled} {...listOps('items')}>
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="title" labelKey="itemTitle" set={setItem} ctx={ctx} />
                        <TextField label={t('homeBuilder.f.videoUrl')} hint={t('homeBuilder.f.videoUrlHint')} value={str(item.video_url)} onChange={(v) => setItem('video_url', v)} path={`items.${i}.video_url`} ctx={ctx} mono />
                        <MediaField label={t('homeBuilder.f.poster')} hint={t('homeBuilder.f.posterHint')} pickerTitle="homeBuilder.f.pickImage" ratio={[9, 16]} value={imageOf(item)} onChange={pickImage((id) => setItem('media_id', id))} disabled={ctx.disabled} error={ctx.errors[`content.items.${i}.media_id`]} />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="alt" set={setItem} ctx={ctx} hint={t('homeBuilder.f.altHint')} />
                        <TextField label={t('homeBuilder.f.duration')} hint={lengthHint(str(item.video_url))} value={str(item.duration)} onChange={(v) => setItem('duration', v)} path={`items.${i}.duration`} ctx={ctx} mono />
                        <Bilingual content={item} prefix={`items.${i}.`} keyName="views" set={setItem} ctx={ctx} hint={t('homeBuilder.f.viewsHint')} />
                        <p className="t-admin-caption text-fg-secondary">{t('homeBuilder.f.reelProduct')}</p>
                        <ProductPicker picked={ids(product)} onChange={(next) => setItem('product_id', next[next.length - 1] ?? null)} ctx={ctx} path={`items.${i}.product_id`} max={1} />
                    </Repeater>
                );
            })}
            <ListError kit={kit} path="items" />
            <AddButton label={t('homeBuilder.f.addReel')} disabled={ctx.disabled || items.length >= 12} onClick={() => set('items', [...items, { title: '', video_url: '', media_id: null, product_id: null }])} />
        </>
    );
}
