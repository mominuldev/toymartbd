import { Link } from 'react-router';
import { GearSixIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { buttonClass } from '@/components/ui/Button';
import { homeLayout } from './api';
import { LayoutBuilder } from './LayoutBuilder';

/** Homepage builder — Figma B · 26 Page builder (241:44102): the section builder on the home layout. */
export function HomeBuilderPage() {
    const { t } = useI18n();

    return (
        <LayoutBuilder
            base={homeLayout}
            title={t('homeBuilder.title')}
            actions={
                <Link to="/store/home/settings" className={buttonClass('outline')}>
                    <GearSixIcon size={16} aria-hidden />
                    {t('content.homeSettings.button')}
                </Link>
            }
            backTo="/store/pages"
            copy={{
                back: t('homeBuilder.back'),
                readOnly: t('homeBuilder.readOnly'),
                canvas: t('homeBuilder.canvas'),
                failed: t('homeBuilder.loadFailed'),
                published: (version) => t('homeBuilder.published', { version }),
            }}
        />
    );
}
