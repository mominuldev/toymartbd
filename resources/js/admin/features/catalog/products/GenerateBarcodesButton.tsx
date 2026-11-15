import { useId } from 'react';
import { BarcodeIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';

type Props = {
    /** Saved variants on screen with an empty barcode. */
    missing: number;
    /** Unsaved new variants: they have no id yet, so the server cannot give them a code. */
    unsaved: number;
    pending: boolean;
    disabled: boolean;
    onGenerate: () => void;
    /** One variant (a simple product's barcode field) rather than the matrix. */
    single?: boolean;
};

/**
 * "Generate missing barcodes" above the variant matrix (and "Generate barcode" beside a
 * simple product's barcode field): the server gives each saved variant without a barcode an
 * in-store EAN-13 and never replaces one. Unsaved rows are explained, not silently skipped.
 */
export function GenerateBarcodesButton({ missing, unsaved, pending, disabled, onGenerate, single = false }: Props) {
    const { t } = useI18n();
    const hintId = useId();
    const hint = unsaved > 0 ? t('products.barcodes.saveFirst') : missing === 0 && !single ? t('products.barcodes.none') : null;

    return (
        <div className="flex flex-wrap items-center gap-2">
            {hint && (
                <p id={hintId} className="t-admin-caption text-fg-secondary">
                    {hint}
                </p>
            )}
            <Button variant="outline" size="sm" onClick={onGenerate} disabled={disabled || pending || missing === 0} aria-describedby={hint ? hintId : undefined}>
                <BarcodeIcon size={16} aria-hidden />
                {pending ? t('products.barcodes.generating') : single ? t('products.barcodes.generateOne') : t('products.barcodes.generate')}
            </Button>
        </div>
    );
}
