import { useRef, useState } from 'react';
import { ImageIcon, ImagesIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ImageUploader } from '@/features/catalog/media/ImageUploader';
import { MAX_IMAGES, useInvalidateImages, useProductImages } from '@/features/catalog/media/api';
import { attachMediaToProduct, problemMessage, type Media } from '@/features/media';
import { MediaPickerModal, type InsertFailure, type MediaPickerContext } from '@/features/media/picker';
import { Button } from '@/components/ui/Button';
import { FormSection } from '@/components/ui/FormSection';
import { useToast } from '@/components/ui/Toast';
import type { Product } from './api';

/**
 * Images tab: the ImageUploader (A8, Figma 201:1035) for a saved product, with "Choose from
 * library" beside it opening the MediaPickerModal (294:2661 / 303:55922). On the create page
 * there is no product id to attach images to yet, so both wait for the first save — the
 * same rule uploads have always followed.
 */
export function ImagesPanel({ product, disabled }: { product?: Product; disabled: boolean }) {
    const { t } = useI18n();

    if (!product) {
        return (
            <FormSection title={t('products.section.images')} description={t('products.images.hint')}>
                <div className="flex flex-col items-center gap-2 rounded-md border border-dashed border-line-strong bg-subtle p-8 text-center">
                    <ImageIcon size={24} aria-hidden className="text-fg-tertiary" />
                    <p className="t-admin-body text-fg-secondary">{t('products.images.saveFirst')}</p>
                </div>
            </FormSection>
        );
    }

    return <SavedImagesPanel product={product} disabled={disabled} />;
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
