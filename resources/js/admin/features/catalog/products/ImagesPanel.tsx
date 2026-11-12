import { ImageIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ImageUploader } from '@/features/catalog/media/ImageUploader';
import type { Product } from './api';
import { FormSection } from '@/components/ui/FormSection';

/**
 * Images tab: the ImageUploader (A8, Figma 201:1035) for a saved product. On the
 * create page there is no product id to attach images to yet.
 */
export function ImagesPanel({ product, disabled }: { product?: Product; disabled: boolean }) {
    const { t, locale } = useI18n();

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

    // Variant labels come from the server's variant names ("Red / Large"); archived variants cannot take new images.
    const variants = product.variants
        .filter((v) => v.status === 'active' && v.name)
        .map((v) => ({ id: v.id, label: (locale === 'bn' ? (v.name_bn ?? v.name) : v.name) ?? v.sku }));

    return <ImageUploader productId={product.id} productName={product.name} variants={variants} disabled={disabled} />;
}
