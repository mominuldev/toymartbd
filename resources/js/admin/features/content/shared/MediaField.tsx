import { useState } from 'react';
import { ImageIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { MediaPickerModal, type InsertFailure } from '@/features/media/picker';
import type { Media } from '@/features/media/api';

export type PickedImage = { media_id: number; url: string | null; alt: string | null };

type Props = {
    label: string;
    hint?: string;
    pickerTitle: MessageKey;
    value: PickedImage | null;
    onChange: (next: PickedImage | null) => void;
    disabled?: boolean;
    ratio?: readonly [number, number] | null;
    error?: string;
};

/** One library image (banner, testimonial photo), picked with MediaPickerModal (Figma 294:2661). */
export function MediaField({ label, hint, pickerTitle, value, onChange, disabled, ratio = null, error }: Props) {
    const { t } = useI18n();
    const [picking, setPicking] = useState(false);

    const insert = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media) return [];
        if (media.processing_status !== 'ready') return [{ media, reason: t('content.banners.field.imageNotReady') }];
        onChange({ media_id: media.id, url: media.urls.medium ?? media.urls.thumb, alt: media.alt_text });
        setPicking(false);
        return [];
    };

    return (
        <div className="flex flex-col gap-1.5">
            <span className="t-admin-body-strong text-fg-primary">
                {label}
                <span className="ms-1.5 t-admin-caption text-fg-tertiary">{t('common.optional')}</span>
            </span>
            <div className="flex flex-wrap items-center gap-3">
                <span className="grid size-20 shrink-0 place-items-center overflow-hidden rounded-sm border border-line-default bg-subtle">
                    {value?.url ? (
                        <img src={value.url} alt={value.alt ?? ''} className="size-full object-cover" />
                    ) : (
                        <ImageIcon size={24} aria-hidden className="text-fg-tertiary" />
                    )}
                </span>
                <Button variant="outline" size="sm" disabled={disabled} onClick={() => setPicking(true)}>
                    {value ? t('content.banners.field.replaceImage') : t('content.banners.field.chooseImage')}
                </Button>
                {value && (
                    <IconButton aria-label={t('content.banners.field.removeImage')} disabled={disabled} onClick={() => onChange(null)}>
                        <TrashIcon size={16} aria-hidden />
                    </IconButton>
                )}
            </div>
            {hint && !error && <p className="t-admin-caption text-fg-secondary">{hint}</p>}
            {error && <p className="t-admin-caption text-fg-primary">{error}</p>}
            {picking && (
                <MediaPickerModal
                    context={{ title: t(pickerTitle), multiple: false, max: 1, kinds: ['image'], defaultFolder: 'pages', ratio }}
                    onInsert={insert}
                    onClose={() => setPicking(false)}
                />
            )}
        </div>
    );
}
