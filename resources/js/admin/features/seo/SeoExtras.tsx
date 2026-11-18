import { useId, useState, type ReactNode } from 'react';
import { CheckCircleIcon, ImageIcon, ImagesIcon, TrashIcon, WarningCircleIcon, XCircleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { MediaPickerModal, type InsertHandler } from '@/features/media/picker';
import type { SeoDocument } from './api';

type Checklist = SeoDocument['checklist'];
type CheckItem = Checklist['items'][number];

const checkLabel: Record<CheckItem['key'], MessageKey> = {
    title_fits: 'seo.score.title_fits',
    description_fits: 'seo.score.description_fits',
    title_fits_bn: 'seo.score.title_fits_bn',
    description_fits_bn: 'seo.score.description_fits_bn',
    keyword_in_title: 'seo.score.keyword_in_title',
    keyword_in_description: 'seo.score.keyword_in_description',
    social_image: 'seo.score.social_image',
    indexable: 'seo.score.indexable',
};

/** Why a warn/fail item needs attention, when the bare label does not say it. */
function itemHint(item: CheckItem, t: ReturnType<typeof useI18n>['t']): string | null {
    if (item.status === 'pass') return null;
    if (item.key === 'keyword_in_title' || item.key === 'keyword_in_description') return item.params.keyword ? null : t('seo.score.hint.noKeyword');
    if (item.key === 'indexable') {
        const reason = String(item.params.reason ?? '');
        return reason === 'not_live' ? t('seo.score.hint.notLive') : reason === 'noindex' ? t('seo.score.hint.noindex') : reason === 'canonical' ? t('seo.score.hint.canonical') : null;
    }
    if ('px' in item.params) return t('seo.score.hint.width', { px: Number(item.params.px), max: Number(item.params.max) });
    return null;
}

/**
 * SEO score — the server's checklist of the SAVED document (rule 1: the browser never scores).
 * A ring-free meter: "6 / 8" with a bar in the success / warning / danger tone, then one row per
 * check. It refreshes after Save.
 */
export function SeoScore({ checklist }: { checklist: Checklist }) {
    const { t } = useI18n();
    const headingId = useId();
    const ratio = checklist.total === 0 ? 0 : checklist.passed / checklist.total;
    const tone = ratio >= 0.8 ? 'bg-feedback-success' : ratio >= 0.5 ? 'bg-feedback-warning' : 'bg-feedback-danger';

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 rounded-sm border border-line-default bg-subtle p-4" data-testid="seo-score">
            <div className="flex items-center gap-3">
                <h3 id={headingId} className="flex-1 t-admin-body-strong text-fg-primary">
                    {t('seo.score.title')}
                </h3>
                <p className="t-admin-body-strong tabular text-fg-primary">{t('seo.score.value', { passed: checklist.passed, total: checklist.total })}</p>
            </div>
            <div
                role="meter"
                aria-labelledby={headingId}
                aria-valuemin={0}
                aria-valuemax={checklist.total}
                aria-valuenow={checklist.passed}
                aria-valuetext={t('seo.score.value', { passed: checklist.passed, total: checklist.total })}
                className="h-1.5 overflow-hidden rounded-full bg-surface"
            >
                <div className={`h-full rounded-full ${tone}`} style={{ width: `${Math.round(ratio * 100)}%` }} />
            </div>
            <ul className="grid gap-x-6 gap-y-1.5 md:grid-cols-2">
                {checklist.items.map((item) => {
                    const hint = itemHint(item, t);
                    return (
                        <li key={item.key} className="flex items-start gap-2 t-admin-caption text-fg-primary">
                            {item.status === 'pass' ? (
                                <CheckCircleIcon size={16} weight="fill" aria-hidden className="mt-px shrink-0 text-feedback-success" />
                            ) : item.status === 'warn' ? (
                                <WarningCircleIcon size={16} weight="fill" aria-hidden className="mt-px shrink-0 text-feedback-warning" />
                            ) : (
                                <XCircleIcon size={16} weight="fill" aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                            )}
                            <span className="min-w-0">
                                <span className="sr-only">{t(`seo.score.status.${item.status}`)} </span>
                                {t(checkLabel[item.key], { keyword: String(item.params.keyword ?? '') })}
                                {hint && <span className="block text-fg-secondary">{hint}</span>}
                            </span>
                        </li>
                    );
                })}
            </ul>
            <p className="t-admin-caption text-fg-tertiary">{t('seo.score.afterSave')}</p>
        </section>
    );
}

/** How a shared link renders on Facebook / WhatsApp: 1.91:1 image, host, title, description. */
export function SocialCard({ image, host, title, description, heading, lang }: { image: string | null; host: string; title: string; description: string; heading: string; lang?: string }) {
    const { t } = useI18n();
    return (
        <div className="flex flex-col gap-2" data-testid="social-preview">
            <p className="t-admin-overline text-fg-tertiary uppercase">{heading}</p>
            <div lang={lang} className="flex max-w-sm flex-col overflow-hidden rounded-sm border border-line-default bg-surface">
                {image ? (
                    <img src={image} alt="" className="aspect-[1.91/1] w-full bg-subtle object-cover" />
                ) : (
                    <div className="flex aspect-[1.91/1] w-full flex-col items-center justify-center gap-1 bg-subtle t-admin-caption text-fg-tertiary">
                        <ImageIcon size={24} aria-hidden />
                        {t('seo.social.noImage')}
                    </div>
                )}
                <div className="flex flex-col gap-0.5 border-t border-line-default px-3 py-2.5">
                    <p className="truncate t-admin-caption text-fg-tertiary uppercase">{host}</p>
                    <p className="line-clamp-2 t-admin-body-strong text-fg-primary">{title}</p>
                    {description && <p className="line-clamp-2 t-admin-caption text-fg-secondary">{description}</p>}
                </div>
            </div>
        </div>
    );
}

/**
 * Share image: a thumbnail of the current one (own or default), "Choose from Media Library"
 * (the MediaPickerModal, single image, 1.91:1 at ≥ 1200 px) and Remove. The value stays a URL —
 * the picked image's large rendition — so a pasted URL still works under "Or paste a URL".
 */
export function OgImageField({ value, fallback, onChange, disabled, error, urlInput }: { value: string; fallback: string | null; onChange: (url: string) => void; disabled: boolean; error?: string; urlInput: ReactNode }) {
    const { t } = useI18n();
    const [picking, setPicking] = useState(false);
    const shown = value || fallback;

    const insert: InsertHandler = async (picked) => {
        const media = picked[0];
        const url = media ? (media.urls.large ?? media.public_url) : null;
        if (!media || !url) return media ? [{ media, reason: t('seo.ogImage.notReady') }] : [];
        onChange(url);
        setPicking(false);
        return [];
    };

    return (
        <div className="flex flex-col gap-3">
            <p className="t-admin-body-strong text-fg-primary">{t('seo.panel.ogImage')}</p>
            <div className="flex flex-wrap items-start gap-4">
                <div className="flex aspect-[1.91/1] w-48 items-center justify-center overflow-hidden rounded-sm border border-line-default bg-subtle">
                    {shown ? <img src={shown} alt="" className="size-full object-cover" /> : <ImageIcon size={24} aria-hidden className="text-fg-tertiary" />}
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <p className="t-admin-caption text-fg-secondary">{value ? t('seo.ogImage.own') : fallback ? t('seo.ogImage.default') : t('seo.ogImage.none')}</p>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" size="sm" disabled={disabled} onClick={() => setPicking(true)}>
                            <ImagesIcon size={16} aria-hidden />
                            {t('seo.ogImage.choose')}
                        </Button>
                        {value && (
                            <Button variant="ghost" size="sm" disabled={disabled} onClick={() => onChange('')}>
                                <TrashIcon size={16} aria-hidden />
                                {t('seo.ogImage.remove')}
                            </Button>
                        )}
                    </div>
                    <p className="t-admin-caption text-fg-tertiary">{t('seo.ogImage.hint')}</p>
                </div>
            </div>
            {error && <p className="t-admin-caption text-feedback-danger">{error}</p>}
            {urlInput}
            {picking && (
                <MediaPickerModal
                    context={{ title: t('seo.ogImage.pickerTitle'), hint: t('seo.ogImage.hint'), multiple: false, max: 1, kinds: ['image'], ratio: [1.91, 1], minSize: 630 }}
                    onInsert={insert}
                    onClose={() => setPicking(false)}
                />
            )}
        </div>
    );
}
