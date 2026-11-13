import { useEffect, useId, useRef, type ReactNode } from 'react';
import { XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { IconButton } from '@/components/ui/IconButton';
import { QueryError } from '@/components/ui/QueryError';
import { destinations, useTrackingEvent, type TrackingEventDetail } from './api';
import { DispatchChip, dhakaWhen, formatMoney } from './parts';

/**
 * One tracking event (PRD K-03): what we recorded, the payload AS SENT to each destination
 * (user data already SHA-256 hashed, IP / user agent redacted, never a secret) and what each
 * provider answered. The 480 right drawer of CourierBookingPanel 196:743 — no frame of its own.
 */
export function EventDetailDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const open = id !== null;
    const event = useTrackingEvent(id);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-0 ms-auto h-dvh max-h-dvh w-full max-w-120 overflow-hidden rounded-s-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay max-md:max-w-none max-md:rounded-none"
        >
            {open && (
                <div className="flex h-full flex-col">
                    <div className="flex shrink-0 items-center gap-2 border-b border-line-default py-4 ps-5 pe-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <h2 id={titleId} className="truncate t-admin-section text-fg-primary">
                                {event.data ? event.data.event_name : t('tracking.detail.title')}
                            </h2>
                            {event.data && <p className="truncate t-admin-mono-sm text-fg-tertiary">{event.data.event_id}</p>}
                        </div>
                        <IconButton aria-label={t('tracking.drawer.close')} onClick={onClose}>
                            <XIcon size={18} aria-hidden />
                        </IconButton>
                    </div>
                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5">
                        {event.isError ? (
                            <QueryError error={event.error} onRetry={() => void event.refetch()} />
                        ) : !event.data ? (
                            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                                {t('common.loading')}
                            </p>
                        ) : (
                            <Detail event={event.data} />
                        )}
                    </div>
                </div>
            )}
        </dialog>
    );
}

function Detail({ event }: { event: TrackingEventDetail }) {
    const { t, locale } = useI18n();
    const when = (iso: string | null) => {
        if (!iso) return '—';
        const at = dhakaWhen(iso, locale);
        return `${at.day}, ${at.time}`;
    };
    const yesNo = (value: boolean | null) => (value === null ? '—' : t(value ? 'tracking.detail.granted' : 'tracking.detail.refused'));
    const sent = (event.payload as { sent?: Record<string, unknown> }).sent;

    return (
        <>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <Row label={t('tracking.detail.source')}>{t(`tracking.source.${event.source}` as MessageKey)}</Row>
                <Row label={t('tracking.detail.occurred')}>{when(event.occurred_at)}</Row>
                <Row label={t('tracking.detail.recorded')}>{when(event.created_at)}</Row>
                <Row label={t('tracking.detail.dispatched')}>{when(event.dispatched_at)}</Row>
                <Row label={t('tracking.detail.order')}>
                    <span className="t-admin-mono">{event.order_number ?? '—'}</span>
                </Row>
                <Row label={t('tracking.detail.value')}>{event.value !== null ? formatMoney(event.value, event.currency) : '—'}</Row>
                <Row label={t('tracking.detail.consentAnalytics')}>{yesNo(event.consent.analytics)}</Row>
                <Row label={t('tracking.detail.consentAds')}>{yesNo(event.consent.ads)}</Row>
                <Row label={t('tracking.detail.consentMode')}>
                    {event.consent.mode ? t(`tracking.consent.${event.consent.mode === 'notice' ? 'notice' : 'optIn'}` as MessageKey) : '—'}
                    {event.consent_policy_version && <span className="t-admin-caption text-fg-tertiary"> · {event.consent_policy_version}</span>}
                </Row>
            </dl>

            {destinations.map((key) => {
                const result = event[key];
                const response = event[`${key}_response`];
                const payload = sent?.[key];
                return (
                    <section key={key} className="flex flex-col gap-2 border-t border-line-default pt-4" aria-label={t(`tracking.${key}.title` as MessageKey)}>
                        <div className="flex items-center gap-2">
                            <h3 className="min-w-0 flex-1 t-admin-body-strong text-fg-primary">{t(`tracking.${key}.title` as MessageKey)}</h3>
                            <DispatchChip status={result.status} />
                        </div>
                        <p className="t-admin-caption text-fg-secondary">{t('tracking.detail.attempts', { count: result.attempts })}</p>
                        {result.error && <p className="t-admin-caption break-words text-fg-primary">{result.error}</p>}
                        {payload !== undefined && <Json label={t('tracking.detail.payload')} value={payload} />}
                        {response && <Json label={t('tracking.detail.response')} value={response} />}
                    </section>
                );
            })}

            <section className="flex flex-col gap-2 border-t border-line-default pt-4">
                <Json label={t('tracking.detail.recordedPayload')} value={withoutSent(event.payload)} />
                <p className="t-admin-caption text-fg-tertiary">{t('tracking.detail.hashedNote')}</p>
            </section>
        </>
    );
}

function withoutSent(payload: Record<string, unknown>): Record<string, unknown> {
    const { sent: _sent, ...rest } = payload;
    return rest;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="t-admin-caption text-fg-tertiary">{label}</dt>
            <dd className="min-w-0 t-admin-body break-words text-fg-primary">{children}</dd>
        </>
    );
}

function Json({ label, value }: { label: string; value: unknown }) {
    return (
        <details className="group rounded-sm border border-line-default">
            <summary className="cursor-pointer px-3 py-2 t-admin-caption-strong text-fg-primary">{label}</summary>
            <pre className="max-h-80 overflow-auto border-t border-line-default bg-subtle px-3 py-2 t-admin-mono-sm whitespace-pre-wrap break-all text-fg-secondary">
                {JSON.stringify(value, null, 2)}
            </pre>
        </details>
    );
}
