import { useEffect, useRef } from 'react';
import type { Locale } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';

type Translate = (key: MessageKey, vars?: Record<string, string | number>) => string;

type PlaceLike = { district: string; district_bn: string; upazila: string | null; upazila_bn: string | null };

/** "Savar, Dhaka" — or "Dhaka — whole district (no upazila)" — in the UI's language. */
export function placeLabel(place: PlaceLike, locale: Locale, t: Translate): string {
    const district = locale === 'bn' ? place.district_bn : place.district;
    const upazila = locale === 'bn' ? place.upazila_bn : place.upazila;
    return upazila ? t('locations.place', { upazila, district }) : t('locations.wholeDistrict', { district });
}

/** "Ashulia Bazar" for a zone, "Ashulia Bazar › Baipail" for an area — the courier's own names. */
export function zoneLabel(row: { zone_name: string; area_name: string | null }): string {
    return row.area_name ? `${row.zone_name} › ${row.area_name}` : row.zone_name;
}

/** Western digits with grouping in both locales (rule 36). */
export function formatUnits(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

/** A Dhaka date-time, "13 Nov 2026, 5:37 pm", Western digits. */
export function formatDhakaTime(iso: string, locale: Locale): string {
    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: 'Asia/Dhaka',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        numberingSystem: 'latn',
    }).format(new Date(iso));
}

/** Open/close a native <dialog> from a boolean (same behaviour as the purchasing dialogs). */
export function useDialog(open: boolean, onOpen: () => void) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            onOpen();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    return ref;
}
