import type { ComponentType } from 'react';
import { Link } from 'react-router';
import {
    ArrowArcLeftIcon,
    BicycleIcon,
    CheckCircleIcon,
    ClockClockwiseIcon,
    ClockIcon,
    FlaskIcon,
    PackageIcon,
    PauseCircleIcon,
    PlugsIcon,
    ProhibitIcon,
    TruckIcon,
    WarningIcon,
    XCircleIcon,
    type IconProps,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { buttonClass } from '@/components/ui/Button';
import type { ShipmentStatus, Trust } from './api';

/** Where couriers are configured (credentials, rate cards). */
export const COURIER_SETTINGS_PATH = '/integrations/couriers';
/** Agent 2's Pathao location-mapping screen (Fulfilment → Courier location mapping). */
export const LOCATION_MAPPING_PATH = '/shipments/locations';
export const FAILED_BOOKINGS_PATH = '/shipments/failed';

const trustLook: Record<Trust, { icon: ComponentType<IconProps>; tint: string }> = {
    verified: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    unverified: { icon: FlaskIcon, tint: 'text-status-low-stock' },
    not_connected: { icon: PlugsIcon, tint: 'text-fg-tertiary' },
};

/**
 * Figma CourierProviderCard 194:1014 trust chip: Live (n bookings) / Untested driver / No
 * credentials — surface fill, hairline, 12px icon tinted, caption-strong label.
 */
export function TrustChip({ trust, bookings }: { trust: Trust; bookings?: number }) {
    const { t } = useI18n();
    const { icon: Icon, tint } = trustLook[trust];
    const label = trust === 'verified' ? t('couriers.trust.verified', { count: bookings ?? 0 }) : t(`couriers.trust.${trust}` as MessageKey);
    return (
        <span className="inline-flex items-center gap-1 rounded-xs border border-line-default bg-surface px-1.5 py-0.5 t-admin-caption-strong whitespace-nowrap text-fg-primary">
            <Icon size={12} aria-hidden className={`shrink-0 ${tint}`} />
            {label}
        </span>
    );
}

/**
 * Figma UnverifiedBanner (CourierBookingPanel I241:34192;196:754, 04-admin-spec A16): bg/subtle,
 * 3px status/low-stock left edge, flask 20 tinted, body-strong title and caption body.
 * Shown while a courier has no credentials or no booking has ever succeeded through it.
 */
export function UnverifiedBanner({ name, settingsLink = true, className = '' }: { name: string; settingsLink?: boolean; className?: string }) {
    const { t } = useI18n();
    return (
        <div role="note" className={`flex items-start gap-3 border-l-3 border-l-status-low-stock bg-subtle px-5 py-3.5 ${className}`}>
            <FlaskIcon size={20} aria-hidden className="shrink-0 text-status-low-stock" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="t-admin-body-strong text-fg-primary">{t('couriers.unverified.title', { name })}</p>
                <p className="t-admin-caption text-fg-secondary">{t('couriers.unverified.body', { name })}</p>
                {settingsLink && (
                    <Link to={COURIER_SETTINGS_PATH} className={`${buttonClass('ghost', 'sm')} -ms-3 self-start`}>
                        {t('couriers.unverified.open')}
                    </Link>
                )}
            </div>
        </div>
    );
}

const stateLook: Record<ShipmentStatus, { icon: ComponentType<IconProps>; tint: string }> = {
    pending: { icon: ClockIcon, tint: 'text-fg-secondary' },
    submitted: { icon: ClockClockwiseIcon, tint: 'text-status-low-stock' },
    booked: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    picked_up: { icon: PackageIcon, tint: 'text-feedback-info' },
    in_transit: { icon: TruckIcon, tint: 'text-feedback-info' },
    out_for_delivery: { icon: BicycleIcon, tint: 'text-fg-brand' },
    delivered: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    partially_delivered: { icon: WarningIcon, tint: 'text-status-low-stock' },
    returned: { icon: ArrowArcLeftIcon, tint: 'text-feedback-danger' },
    cancelled: { icon: ProhibitIcon, tint: 'text-fg-tertiary' },
    on_hold: { icon: PauseCircleIcon, tint: 'text-status-low-stock' },
    failed: { icon: XCircleIcon, tint: 'text-feedback-danger' },
};

export function shipmentIcon(status: ShipmentStatus) {
    return stateLook[status] ?? stateLook.pending;
}

/**
 * Booking state chip (04-admin-spec A16 table). `submitted` and `booked` look nothing alike:
 * submitted has a 2px status/low-stock left edge and a dashed border/strong elsewhere, and
 * reads "Submitted — awaiting confirmation" (rule 24d).
 */
export function ShipmentStateChip({ status }: { status: ShipmentStatus }) {
    const { t } = useI18n();
    const { icon: Icon, tint } = shipmentIcon(status);
    const edge =
        status === 'submitted'
            ? 'border border-dashed border-line-strong border-l-2 border-l-status-low-stock [border-left-style:solid]'
            : status === 'failed'
              ? 'border border-line-default border-l-2 border-l-feedback-danger'
              : 'border border-line-default';
    return (
        <span className={`inline-flex h-5.5 items-center gap-1 rounded-xs bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary ${edge}`}>
            <Icon size={14} aria-hidden className={`shrink-0 ${tint}`} />
            {t(`couriers.status.${status}` as MessageKey)}
        </span>
    );
}

const FAILURES = [
    'unverified_provider',
    'inactive_provider',
    'unmapped_location',
    'ambiguous_location',
    'provider_refused',
    'transport',
    'order_not_shippable',
    'bulk_unsupported',
    'completion_failed',
] as const;

/** A known failure code's message key; anything new from the server reads as "unknown". */
export function failureKey(code: string | null): MessageKey {
    return `couriers.failure.${(FAILURES as readonly string[]).includes(code ?? '') ? code : 'unknown'}` as MessageKey;
}

/** How an event was learned: webhook, poll or manual — anything else reads as "other". */
export function sourceKey(source: string): MessageKey {
    return `couriers.source.${['webhook', 'poll', 'manual'].includes(source) ? source : 'other'}` as MessageKey;
}
