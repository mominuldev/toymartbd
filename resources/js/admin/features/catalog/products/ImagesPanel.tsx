import { useRef, useState } from 'react';
import { ImageIcon, ImagesIcon, TrashIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ImageUploader } from '@/features/catalog/media/ImageUploader';
import { MAX_IMAGES, useInvalidateImages, useProductImages } from '@/features/catalog/media/api';
import { attachMediaToProduct, problemMessage, type Media } from '@/features/media';
import { MediaPickerModal, type InsertFailure, type MediaPickerContext } from '@/features/media/picker';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { FormSection } from '@/components/ui/FormSection';
import { useToast } from '@/components/ui/Toast';
import type { Product } from './api';

type Props = {
    product?: Product;
    disabled: boolean;
    /** Create page only: images chosen or uploaded before the product exists, attached right after it is created. */
    pending?: Media[];
    onPendingChange?: (next: Media[]) => void;
};

/**
 * Images tab: the ImageUploader (A8, Figma 201:1035) for a saved product, with "Choose from
 * library" beside it opening the MediaPickerModal (294:2661 / 303:55922). On the create page
 * there is no product id yet, so images are chosen (or uploaded into the library) through the
 * same picker and held on the page; ProductFormPage attaches them in that order as soon as the
 * product is created ({@link attachPendingImages}). Per-variant images and alt text are edited
 * after that, on the saved product.
 */
export function ImagesPanel({ product, disabled, pending = [], onPendingChange }: Props) {
    if (!product) return <PendingImagesPanel disabled={disabled || !onPendingChange} pending={pending} onChange={onPendingChange ?? (() => undefined)} />;

    return <SavedImagesPanel product={product} disabled={disabled} />;
}

function PendingImagesPanel({ disabled, pending, onChange }: { disabled: boolean; pending: Media[]; onChange: (next: Media[]) => void }) {
    const { t } = useI18n();
    const [picking, setPicking] = useState(false);
    const room = MAX_IMAGES - pending.length;

    const context: MediaPickerContext = {
        title: t('picker.product.title'),
        subtitle: t('products.images.pendingSubtitle', { count: pending.length, max: MAX_IMAGES }),
        hint: t('picker.product.hint'),
        uploadHint: t('picker.product.uploadHint', { folder: t('media.folder.system.products') }),
        ratio: [1, 1],
        minSize: 1200,
        multiple: true,
        max: Math.max(room, 0),
        kinds: ['image'],
        defaultFolder: 'products',
        warnMissingAlt: true,
    };

    /** Keep pick order (1 = primary), never the same image twice, never past the gallery limit. */
    async function add(picked: Media[]): Promise<InsertFailure[]> {
        const known = new Set(pending.map((m) => m.id));
        const fresh = picked.filter((m) => !known.has(m.id)).slice(0, Math.max(room, 0));
        if (fresh.length > 0) onChange([...pending, ...fresh]);
        return [];
    }

    return (
        <FormSection
            title={t('products.section.images')}
            description={t('products.images.hint')}
            aside={
                !disabled && (
                    <Button variant="outline" size="sm" disabled={room <= 0} onClick={() => setPicking(true)}>
                        <ImagesIcon size={16} aria-hidden />
                        {t('products.images.chooseOrUpload')}
                    </Button>
                )
            }
        >
            {pending.length === 0 ? (
                <div className="flex flex-col items-center gap-3 rounded-md border border-dashed border-line-strong bg-subtle p-8 text-center">
                    <ImageIcon size={24} aria-hidden className="text-fg-tertiary" />
                    <p className="t-admin-body text-fg-secondary">{t('products.images.pendingEmpty')}</p>
                    {!disabled && (
                        <Button variant="outline" onClick={() => setPicking(true)}>
                            <ImagesIcon size={16} aria-hidden />
                            {t('products.images.chooseOrUpload')}
                        </Button>
                    )}
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    <ul aria-label={t('products.images.pendingList')} className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        {pending.map((media, index) => (
                            <li key={media.id} className="relative overflow-hidden rounded-md border border-line-default bg-subtle">
                                <img src={media.urls.thumb ?? undefined} alt={media.alt_text ?? media.filename} className="aspect-square w-full object-cover" />
                                {index === 0 && <span className="absolute top-1.5 left-1.5 rounded-sm bg-surface px-1.5 t-admin-caption text-fg-primary shadow-e1">{t('products.images.primary')}</span>}
                                {!disabled && (
                                    <IconButton
                                        size="sm"
                                        className="absolute top-1 right-1 bg-surface shadow-e1"
                                        aria-label={t('products.images.removePending', { name: media.filename })}
                                        onClick={() => onChange(pending.filter((m) => m.id !== media.id))}
                                    >
                                        <TrashIcon size={16} aria-hidden />
                                    </IconButton>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="t-admin-caption text-fg-tertiary">{t('products.images.pendingNote')}</p>
                </div>
            )}
            {picking && <MediaPickerModal context={context} onInsert={add} onClose={() => setPicking(false)} />}
        </FormSection>
    );
}

/**
 * After a create: attach the held images in order, one request each (the server appends, so the
 * first becomes the primary image). A refused one does not stop the rest; the count of refusals
 * is returned for one warning.
 */
export async function attachPendingImages(productId: number, pending: Media[]): Promise<number> {
    let failed = 0;
    for (const media of pending) {
        try {
            await attachMediaToProduct(productId, media.id);
        } catch {
            failed += 1;
        }
    }
    return failed;
}

function SavedImagesPanel({ product, disabled }: { product: Product; disabled: boolean }) {
    const { t, locale } = useI18n();
    const toast = useToast();
    const images = useProductImages(product.id);
    const invalidate = useInvalidateImages(product.id);
    const [picking, setPicking] = useState(false);
    const panel = useRef<HTMLDivElement>(null);

    const count = images.data?.length ?? 0;
    const room = MAX_IMAGES - count;
    const name = (locale === 'bn' ? (product.name_bn ?? product.name) : product.name) ?? '';

    // Variant labels come from the server's variant names ("Red / Large"); archived variants cannot take new images.
    const variants = product.variants
        .filter((v) => v.status === 'active' && v.name)
        .map((v) => ({ id: v.id, label: (locale === 'bn' ? (v.name_bn ?? v.name) : v.name) ?? v.sku }));

    const context: MediaPickerContext = {
        title: t('picker.product.title'),
        subtitle: t('picker.product.subtitle', { product: name, count, max: MAX_IMAGES }),
        hint: t('picker.product.hint'),
        uploadHint: t('picker.product.uploadHint', { folder: t('media.folder.system.products') }),
        ratio: [1, 1],
        minSize: 1200,
        multiple: true,
        max: Math.max(room, 0),
        kinds: ['image'],
        defaultFolder: 'products',
        warnMissingAlt: true,
    };

    /**
     * One request per file, in pick order, each after the previous one lands: the server
     * appends, so the gallery order is the pick order (1 = primary on an empty gallery).
     * A refused file does not stop the rest; it is reported with the server's reason.
     */
    async function attach(picked: Media[]): Promise<InsertFailure[]> {
        const failures: InsertFailure[] = [];
        let added = 0;
        for (const media of picked) {
            try {
                await attachMediaToProduct(product.id, media.id);
                added += 1;
            } catch (error: unknown) {
                failures.push({ media, reason: problemMessage(error, 'media_id') ?? String(error) });
            }
        }
        if (added > 0) {
            await invalidate();
            toast.notify(t('picker.product.attached', { count: added }));
        }
        return failures;
    }

    /** "Add alt text": insert, then put the cursor in the first empty Bangla alt field. */
    async function attachThenFocusAlt(picked: Media[]): Promise<InsertFailure[]> {
        const failures = await attach(picked);
        window.requestAnimationFrame(() => {
            panel.current?.querySelector<HTMLInputElement>('input[lang="bn"][aria-invalid="true"]')?.focus();
        });
        return failures;
    }

    return (
        <div ref={panel}>
            <ImageUploader
                productId={product.id}
                productName={product.name}
                variants={variants}
                disabled={disabled}
                headerAction={
                    !disabled && (
                        <Button variant="outline" size="sm" disabled={!images.isSuccess || room <= 0} onClick={() => setPicking(true)}>
                            <ImagesIcon size={16} aria-hidden />
                            {t('picker.product.open')}
                        </Button>
                    )
                }
            />
            {picking && <MediaPickerModal context={context} onInsert={attach} onAddAlt={attachThenFocusAlt} onClose={() => setPicking(false)} />}
        </div>
    );
}
