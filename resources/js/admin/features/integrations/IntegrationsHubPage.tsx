import { useState, type ComponentType } from 'react';
import { Link } from 'react-router';
import {
    ChartLineUpIcon,
    ChatTextIcon,
    CheckCircleIcon,
    CreditCardIcon,
    FlaskIcon,
    GoogleLogoIcon,
    MessengerLogoIcon,
    MoneyIcon,
    PlugsIcon,
    ProhibitIcon,
    TagIcon,
    TargetIcon,
    TruckIcon,
    WalletIcon,
    WarningCircleIcon,
    WhatsappLogoIcon,
    type IconProps,
    ChatsCircleIcon,
} from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { can, useMe } from '@/features/auth/api';
import { buttonClass } from '@/components/ui/Button';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { QueryError } from '@/components/ui/QueryError';
import { StatusChip, type StatusTone } from '@/components/ui/StatusChip';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { integrationCategories, useIntegrations, useToggleCourier, type Integration, type IntegrationCategory, type IntegrationStatus } from './api';

const PERMISSION = 'settings.manage_integrations';

const categoryLabel: Record<IntegrationCategory, MessageKey> = {
    couriers: 'integrationsHub.category.couriers',
    payments: 'integrationsHub.category.payments',
    messaging: 'integrationsHub.category.messaging',
    marketing: 'integrationsHub.category.marketing',
};

const statusChip: Record<IntegrationStatus, { tone: StatusTone; icon: ComponentType<IconProps>; label: MessageKey }> = {
    connected_live: { tone: 'success', icon: CheckCircleIcon, label: 'integrationsHub.status.connected_live' },
    test_mode: { tone: 'warning', icon: FlaskIcon, label: 'integrationsHub.status.test_mode' },
    needs_attention: { tone: 'danger', icon: WarningCircleIcon, label: 'integrationsHub.status.needs_attention' },
    not_connected: { tone: 'neutral', icon: PlugsIcon, label: 'integrationsHub.status.not_connected' },
    not_available: { tone: 'neutral', icon: ProhibitIcon, label: 'integrationsHub.status.not_available' },
};

/** Note and attention codes the API sends → copy. An unknown code is skipped, never shown raw. */
const noteKeys: Record<string, MessageKey> = {
    no_driver: 'integrationsHub.note.no_driver',
    no_credentials: 'integrationsHub.note.no_credentials',
    no_sandbox: 'integrationsHub.note.no_sandbox',
    sandbox: 'integrationsHub.note.sandbox',
    unverified_booking_blocked: 'integrationsHub.note.unverified_booking_blocked',
    unverified_payment_blocked: 'integrationsHub.note.unverified_payment_blocked',
    webhook_secret_missing: 'integrationsHub.note.webhook_secret_missing',
    switched_off: 'integrationsHub.note.switched_off',
    cod_always_on: 'integrationsHub.note.cod_always_on',
    driver_log: 'integrationsHub.note.driver_log',
    driver_null: 'integrationsHub.note.driver_null',
    driver_reve: 'integrationsHub.note.driver_reve',
    driver_sms_net_bd: 'integrationsHub.note.driver_sms_net_bd',
    server_and_browser: 'integrationsHub.note.server_and_browser',
    browser_only: 'integrationsHub.note.browser_only',
    test_event_code: 'integrationsHub.note.test_event_code',
    container_set: 'integrationsHub.note.container_set',
    no_container: 'integrationsHub.note.no_container',
};

const attentionKeys: Record<string, MessageKey> = {
    courier_unreachable: 'integrationsHub.attention.courier_unreachable',
    sms_credentials_missing: 'integrationsHub.attention.sms_credentials_missing',
    sms_last_send_failed: 'integrationsHub.attention.sms_last_send_failed',
    whatsapp_on_without_driver: 'integrationsHub.attention.whatsapp_on_without_driver',
    pixel_id_missing: 'integrationsHub.attention.pixel_id_missing',
    measurement_id_missing: 'integrationsHub.attention.measurement_id_missing',
    tracking_send_failing: 'integrationsHub.attention.tracking_send_failing',
};

/** Names that are not brands are translated; brand names stay as the API gives them. */
const nameKeys: Record<string, MessageKey> = {
    cod: 'integrationsHub.name.cod',
    sms: 'integrationsHub.name.sms',
    live_chat: 'integrationsHub.name.live_chat',
};

const icons: Record<string, ComponentType<IconProps>> = {
    cod: MoneyIcon,
    sslcommerz: CreditCardIcon,
    bkash: WalletIcon,
    nagad: WalletIcon,
    sms: ChatTextIcon,
    whatsapp: WhatsappLogoIcon,
    messenger: MessengerLogoIcon,
    live_chat: ChatsCircleIcon,
    meta_capi: TargetIcon,
    ga4: ChartLineUpIcon,
    gtm: TagIcon,
    search_console: GoogleLogoIcon,
};

function iconFor(item: Integration): ComponentType<IconProps> {
    return icons[item.key] ?? (item.category === 'couriers' ? TruckIcon : PlugsIcon);
}

/**
 * Settings → Integrations hub (Figma B · 37 `241:50294`): every outside service the shop uses,
 * grouped by category, with a status the SERVER derives from stored state. The only switch is a
 * courier's on/off (the existing `PATCH /settings/couriers/{id}`); every other state is shown
 * read-only and changed on its own settings screen. Figma's "API keys & webhooks" button has
 * no backing screen and is left out.
 */
export function IntegrationsHubPage() {
    const { t } = useI18n();
    const { data: me } = useMe();
    const allowed = can(me, PERMISSION);
    const integrations = useIntegrations(allowed);
    const [tab, setTab] = useState<IntegrationCategory | 'all'>('all');

    if (me && !allowed) return <p className="t-admin-body text-fg-secondary">{t('error.forbidden')}</p>;

    const header = (
        <ContentHeader
            breadcrumb={[{ label: t('integrationsHub.crumb.settings') }, { label: t('integrationsHub.title') }]}
            title={t('integrationsHub.title')}
            subtitle={t('integrationsHub.subtitle')}
        />
    );

    if (integrations.isError) {
        return (
            <section className="flex flex-col gap-6">
                {header}
                <QueryError error={integrations.error} onRetry={() => void integrations.refetch()} />
            </section>
        );
    }
    if (!integrations.data) {
        return (
            <section className="flex flex-col gap-6">
                {header}
                <p aria-busy="true" className="t-admin-body text-fg-tertiary">
                    {t('common.loading')}
                </p>
            </section>
        );
    }

    const rows = integrations.data;
    const present = integrationCategories.filter((c) => rows.some((r) => r.category === c));
    const active = tab !== 'all' && !present.includes(tab) ? 'all' : tab;
    const shown = active === 'all' ? present : [active];

    return (
        <section className="flex flex-col gap-6">
            {header}

            <TabList label={t('integrationsHub.tabs')}>
                <TabButton active={active === 'all'} label={t('integrationsHub.tab.all')} count={rows.length} onClick={() => setTab('all')} />
                {present.map((c) => (
                    <TabButton
                        key={c}
                        active={active === c}
                        label={t(categoryLabel[c])}
                        count={rows.filter((r) => r.category === c).length}
                        onClick={() => setTab(c)}
                    />
                ))}
            </TabList>

            {shown.map((c) => (
                <section key={c} aria-labelledby={`integrations-${c}`} className="flex flex-col gap-3">
                    <h2 id={`integrations-${c}`} className="t-admin-overline text-fg-tertiary uppercase">
                        {t(categoryLabel[c])}
                    </h2>
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {rows
                            .filter((r) => r.category === c)
                            .map((item) => (
                                <IntegrationCard key={item.key} item={item} />
                            ))}
                    </ul>
                </section>
            ))}
        </section>
    );
}

/** One service card (B · 37): icon tile, name, on/off, detail line, StatusChip and its action. */
function IntegrationCard({ item }: { item: Integration }) {
    const { t } = useI18n();
    const name = nameKeys[item.key] ? t(nameKeys[item.key]!) : item.name;
    const Icon = iconFor(item);
    const chip = statusChip[item.status];
    const attention = item.status === 'needs_attention';

    const detail =
        item.attention && attentionKeys[item.attention]
            ? t(attentionKeys[item.attention]!)
            : item.notes
                  .map((note) => {
                      if (note === 'bookings') {
                          const count = item.bookings_count ?? 0;
                          return count === 1 ? t('integrationsHub.note.bookingsOne') : t('integrationsHub.note.bookings', { count });
                      }
                      return noteKeys[note] ? t(noteKeys[note]!) : null;
                  })
                  .filter((text): text is string => text !== null)
                  .join(' · ');

    const action: MessageKey | null = !item.settings_path
        ? null
        : attention
          ? 'integrationsHub.action.fix'
          : item.status === 'not_connected'
            ? 'integrationsHub.action.connect'
            : 'integrationsHub.action.settings';

    return (
        <li
            aria-label={name}
            className="flex flex-col gap-4 rounded-lg border border-line-default bg-surface p-4 shadow-e1"
        >
            <div className="flex items-center gap-3">
                <span aria-hidden className={`grid size-9 shrink-0 place-items-center rounded-sm ${attention ? 'bg-danger-subtle text-feedback-danger' : 'bg-subtle text-fg-primary'}`}>
                    <Icon size={18} />
                </span>
                <h3 className="min-w-0 flex-1 truncate t-admin-body-strong text-fg-primary">{name}</h3>
                <CardSwitch item={item} name={name} />
            </div>
            <p className="min-h-5 t-admin-caption text-fg-secondary">{detail}</p>
            <div className="mt-auto flex min-h-8 items-center justify-between gap-3">
                <StatusChip tone={chip.tone} icon={chip.icon} label={t(chip.label)} />
                {action && item.settings_path && (
                    <Link
                        to={item.settings_path}
                        aria-label={t('integrationsHub.action.label', { action: t(action), name })}
                        className={buttonClass(item.status === 'not_connected' ? 'outline' : 'ghost', 'sm')}
                    >
                        {t(action)}
                    </Link>
                )}
            </div>
        </li>
    );
}

/** A courier's live switch, or a read-only one showing the state; nothing when the service has no on/off. */
function CardSwitch({ item, name }: { item: Integration; name: string }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const toggle = useToggleCourier();

    if (item.enabled === null) return null;
    const checked = item.enabled;
    const live = item.can_toggle && item.courier_provider_id !== null;
    const state = t(checked ? 'integrationsHub.state.on' : 'integrationsHub.state.off');
    const label = live
        ? t(checked ? 'integrationsHub.switch.on' : 'integrationsHub.switch.off', { name })
        : t(item.settings_path ? 'integrationsHub.switch.readOnly' : 'integrationsHub.switch.readOnlyNoPath', { name, state });

    const onClick = () => {
        if (!live || item.courier_provider_id === null) return;
        toggle.mutate(
            { id: item.courier_provider_id, active: !checked },
            {
                onSuccess: () => notify(t(checked ? 'integrationsHub.turnedOff' : 'integrationsHub.turnedOn', { name })),
                onError: (error) => notify(error instanceof ApiError ? (error.fieldError('is_active') ?? error.problem.detail ?? error.problem.title) : t('error.title'), 'danger'),
            },
        );
    };

    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={live ? undefined : label}
            disabled={!live || toggle.isPending}
            onClick={onClick}
            className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed ${
                checked ? 'bg-brand' : 'bg-line-strong'
            } ${toggle.isPending ? 'opacity-60' : ''}`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}
