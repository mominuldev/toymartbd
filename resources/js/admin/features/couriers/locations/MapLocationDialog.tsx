import { useId, useState } from 'react';
import { MapPinIcon, SparkleIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { SearchField } from '@/components/ui/SearchField';
import { useToast } from '@/components/ui/Toast';
import {
    useMapLocation,
    usePlaceSearch,
    usePlaceSuggestions,
    useZoneSearch,
    useZoneSuggestions,
    type CourierLocation,
    type CourierPlace,
} from './api';
import { formatUnits, placeLabel, useDialog, zoneLabel } from './format';

/** What is being mapped: one of our places (pick a courier zone), or one courier row (pick our place). */
export type MapTarget = { kind: 'place'; place: CourierPlace } | { kind: 'location'; location: CourierLocation };

type MapLocationDialogProps = {
    provider: string;
    courierName: string;
    target: MapTarget | null;
    onClose: () => void;
};

/**
 * The map dialog (no Figma frame — built from ConfirmDialog 203:1387's frame, SearchField
 * 185:28 and AdminButton 184:242, like PaymentDialog). Suggestions are labelled as such and
 * map only on an explicit "Confirm"; the search below is the normal path. Nothing maps by
 * name on its own (rule 32).
 */
export function MapLocationDialog({ provider, courierName, target, onClose }: MapLocationDialogProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const titleId = useId();
    const hintId = useId();
    const [search, setSearch] = useState('');
    const ref = useDialog(target !== null, () => setSearch(''));
    const map = useMapLocation(provider);

    const place = target?.kind === 'place' ? target.place : null;
    const location = target?.kind === 'location' ? target.location : null;
    const term = search.trim();

    const zoneSuggestions = useZoneSuggestions(provider, place);
    const placeSuggestions = usePlaceSuggestions(provider, location);
    const zoneResults = useZoneSearch(provider, place ? term : '');
    const placeResults = usePlaceSearch(provider, location ? term : '');

    function save(locationRow: CourierLocation, districtId: number, upazilaId: number | null, placeName: string) {
        map.mutate(
            { locationId: locationRow.id, input: { district_id: districtId, upazila_id: upazilaId } },
            {
                onSuccess: () => {
                    notify(t('locations.mapped', { zone: zoneLabel(locationRow), place: placeName }));
                    onClose();
                },
                onError: (error) => notify(error instanceof ApiError ? (error.problem.errors?.[0]?.message ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    }

    const title = place ? t('locations.dialog.placeTitle', { place: placeLabel(place, locale, t) }) : location ? t('locations.dialog.zoneTitle', { zone: zoneLabel(location) }) : '';

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,40rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            {target && (
                <div className="flex flex-col">
                    <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                        <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand">
                            <MapPinIcon size={22} />
                        </span>
                        <h2 id={titleId} className="t-admin-section text-fg-primary">
                            {title}
                        </h2>
                        <p className="t-admin-body text-fg-secondary">
                            {place
                                ? t('locations.dialog.placeBody', { courier: courierName })
                                : t('locations.dialog.zoneBody', { courier: courierName, city: location?.city_name ?? '' })}
                        </p>
                    </div>

                    <fieldset disabled={map.isPending} className="flex min-w-0 flex-col gap-4 px-6 pb-5">
                        <section aria-labelledby={`${titleId}-s`} className="flex flex-col gap-2">
                            <h3 id={`${titleId}-s`} className="flex items-center gap-1.5 t-admin-body-strong text-fg-primary">
                                <SparkleIcon size={16} aria-hidden className="text-fg-brand" />
                                {t('locations.dialog.suggestions')}
                            </h3>
                            <p className="t-admin-caption text-fg-secondary">{t('locations.dialog.suggestionsHint')}</p>
                            <ul className="flex flex-col divide-y divide-line-default rounded-sm border border-line-default">
                                {place &&
                                    (zoneSuggestions.data?.data ?? []).map((s) => (
                                        <SuggestionRow
                                            key={s.location.id}
                                            primary={zoneLabel(s.location)}
                                            secondary={s.location.city_name}
                                            score={s.score}
                                            onConfirm={() => save(s.location, place.district_id, place.upazila_id, placeLabel(place, locale, t))}
                                        />
                                    ))}
                                {location &&
                                    (placeSuggestions.data?.data ?? []).map((s) => (
                                        <SuggestionRow
                                            key={`${s.district_id}:${s.upazila_id ?? ''}`}
                                            primary={placeLabel(s, locale, t)}
                                            score={s.score}
                                            onConfirm={() => save(location, s.district_id, s.upazila_id, placeLabel(s, locale, t))}
                                        />
                                    ))}
                                {((place && zoneSuggestions.isSuccess && zoneSuggestions.data.data.length === 0) ||
                                    (location && placeSuggestions.isSuccess && placeSuggestions.data.data.length === 0)) && (
                                    <li className="px-3 py-2.5 t-admin-body text-fg-tertiary">{t('locations.dialog.noSuggestions')}</li>
                                )}
                            </ul>
                        </section>

                        <section className="flex flex-col gap-2">
                            <SearchField
                                label={place ? t('locations.dialog.searchZone', { courier: courierName }) : t('locations.dialog.searchPlace')}
                                placeholder={place ? t('locations.dialog.searchZone', { courier: courierName }) : t('locations.dialog.searchPlace')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                aria-describedby={hintId}
                            />
                            <p id={hintId} className="t-admin-caption text-fg-tertiary">
                                {t('locations.dialog.searchHint')}
                            </p>
                            {term.length >= 2 && (
                                <ul className="flex max-h-64 flex-col divide-y divide-line-default overflow-y-auto rounded-sm border border-line-default" aria-live="polite">
                                    {place &&
                                        (zoneResults.data?.data ?? []).map((row) => (
                                            <ResultRow
                                                key={row.id}
                                                primary={zoneLabel(row)}
                                                secondary={`${row.city_name} · ${t(row.level === 'zone' ? 'locations.zone' : 'locations.area')}`}
                                                action={t('locations.dialog.choose')}
                                                onChoose={() => save(row, place.district_id, place.upazila_id, placeLabel(place, locale, t))}
                                            />
                                        ))}
                                    {location &&
                                        (placeResults.data?.data ?? []).map((p) => (
                                            <ResultRow
                                                key={`${p.district_id}:${p.upazila_id ?? ''}`}
                                                primary={placeLabel(p, locale, t)}
                                                action={t('locations.dialog.choose')}
                                                onChoose={() => save(location, p.district_id, p.upazila_id, placeLabel(p, locale, t))}
                                            />
                                        ))}
                                    {((place && zoneResults.isSuccess && zoneResults.data.data.length === 0) ||
                                        (location && placeResults.isSuccess && placeResults.data.data.length === 0)) && (
                                        <li className="px-3 py-2.5 t-admin-body text-fg-tertiary">{t('locations.dialog.noResults')}</li>
                                    )}
                                </ul>
                            )}
                        </section>
                    </fieldset>

                    <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                        <Button variant="outline" onClick={onClose}>
                            {map.isPending ? t('locations.dialog.saving') : t('locations.dialog.cancel')}
                        </Button>
                    </div>
                </div>
            )}
        </dialog>
    );
}

function SuggestionRow({ primary, secondary, score, onConfirm }: { primary: string; secondary?: string; score: number; onConfirm: () => void }) {
    const { t } = useI18n();
    return (
        <li className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="inline-flex h-5.5 items-center rounded-xs border border-line-default bg-subtle px-2 t-admin-caption-strong text-fg-secondary">
                {t('locations.dialog.suggestion')}
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="t-admin-body-strong text-fg-primary">{primary}</span>
                <span className="t-admin-caption text-fg-tertiary tabular">
                    {secondary ? `${secondary} · ` : ''}
                    {t('locations.dialog.score', { score: formatUnits(Math.round(score * 100)) })}
                </span>
            </span>
            <Button variant="secondary" size="sm" onClick={onConfirm}>
                {t('locations.dialog.confirm')}
            </Button>
        </li>
    );
}

function ResultRow({ primary, secondary, action, onChoose }: { primary: string; secondary?: string; action: string; onChoose: () => void }) {
    return (
        <li className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="t-admin-body text-fg-primary">{primary}</span>
                {secondary && <span className="t-admin-caption text-fg-tertiary">{secondary}</span>}
            </span>
            <Button variant="outline" size="sm" onClick={onChoose}>
                {action}
            </Button>
        </li>
    );
}
