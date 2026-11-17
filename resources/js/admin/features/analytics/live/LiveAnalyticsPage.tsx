import { useId, useState, type ComponentType, type ReactNode } from "react";
import {
    CreditCardIcon,
    EyeIcon,
    PauseIcon,
    PlayIcon,
    ShoppingCartIcon,
    ShoppingCartSimpleIcon,
    StackIcon,
    UsersThreeIcon,
    WarningIcon,
    PackageIcon,
    type IconProps,
} from "@phosphor-icons/react";
import { useI18n } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages";
import { ContentHeader } from "@/components/shell/ContentHeader";
import { Button } from "@/components/ui/Button";
import { KpiCard } from "@/components/ui/KpiCard";
import { QueryError } from "@/components/ui/QueryError";
import { StatusChip } from "@/components/ui/StatusChip";
import { formatCount, takaText } from "@/lib/money";
import { AnalyticsTabs } from "../AnalyticsTabs";
import { PlainWords } from "../PlainWords";
import { useLiveAnalytics, type LiveAnalytics, type LiveFeedItem } from "./api";

type T = (key: MessageKey, vars?: Record<string, string | number>) => string;

/** "2:31 pm" in Dhaka time, Western digits in both locales (rules 11, 36). Display only. */
export function dhakaClock(iso: string, locale: string): string {
    const text = new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-US", {
        timeZone: "Asia/Dhaka",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
        numberingSystem: "latn",
    }).format(new Date(iso));
    return locale === "bn"
        ? text
        : text.replace(/\s?(AM|PM)$/, (_, p: string) => ` ${p.toLowerCase()}`);
}

/** "6 am" for a Dhaka hour bucket (0–23) the server sent. */
export function hourLabel(hour: number, locale: string): string {
    const text = new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-US", {
        timeZone: "UTC",
        hour: "numeric",
        hour12: true,
        numberingSystem: "latn",
    }).format(new Date(Date.UTC(2000, 0, 1, hour)));
    return locale === "bn"
        ? text
        : text.replace(/\s?(AM|PM)$/, (_, p: string) => ` ${p.toLowerCase()}`);
}

/** "4 min ago" from the server's whole minutes. */
function ago(minutes: number, t: T): string {
    if (minutes < 1) return t("analyticsLive.ago.now");
    if (minutes < 60)
        return t("analyticsLive.ago.minutes", {
            minutes: formatCount(minutes),
        });
    if (minutes < 1440)
        return t("analyticsLive.ago.hours", {
            hours: formatCount(Math.floor(minutes / 60)),
        });
    return t("analyticsLive.ago.days", {
        days: formatCount(Math.floor(minutes / 1440)),
    });
}

const pick = (en: string | null, bn: string | null, locale: string) =>
    locale === "bn" && bn ? bn : en;

/**
 * Analytics › Live (Figma B · 24 premium pass 522:81629, mobile 522:82129). What is happening in
 * the shop right now, refreshed every minute until paused. Every figure and every window is the
 * server's (rule 1); times are Dhaka (rule 11). Shopper figures cover only shoppers who allowed
 * tracking and the page says so; orders cover every order. No shopper name, phone or address is
 * ever sent here (rule 20).
 */
export function LiveAnalyticsPage() {
    const { t, locale } = useI18n();
    const [paused, setPaused] = useState(false);
    const live = useLiveAnalytics(paused);
    const data = live.data;
    const time = data ? dhakaClock(data.generated_at, locale) : "";

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t("courierReport.crumb") }]}
                title={t("analyticsLive.title")}
                subtitle={t("analyticsLive.subtitle")}
                actions={
                    <>
                        {data && (
                            <span role="status" aria-live="polite">
                                <StatusChip
                                    label={t(
                                        paused
                                            ? "analyticsLive.status.paused"
                                            : "analyticsLive.status.live",
                                        { time }
                                    )}
                                    tone={paused ? "neutral" : "success"}
                                />
                            </span>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            aria-pressed={paused}
                            onClick={() => setPaused((p) => !p)}
                        >
                            {paused ? (
                                <PlayIcon size={16} aria-hidden />
                            ) : (
                                <PauseIcon size={16} aria-hidden />
                            )}
                            {t(
                                paused
                                    ? "analyticsLive.resume"
                                    : "analyticsLive.pause"
                            )}
                        </Button>
                    </>
                }
            />
            <AnalyticsTabs />

            {live.isError ? (
                <QueryError
                    error={live.error}
                    onRetry={() => void live.refetch()}
                />
            ) : !data ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t("analyticsLive.loading")}
                </p>
            ) : (
                <>
                    <PlainWords
                        label={t("analyticsLive.plain.label")}
                        lines={liveSentences(data, t, locale)}
                    />
                    <Kpis data={data} />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <Feed data={data} className="lg:col-span-2" />
                        <div className="flex min-w-0 flex-col gap-4">
                            <Viewing data={data} />
                            <Hourly data={data} />
                        </div>
                    </div>
                    <p className="t-admin-caption text-fg-secondary">
                        {!data.tracking.collecting
                            ? t("analyticsLive.note.off")
                            : t("analyticsLive.note.consent")}
                        {data.tracking.collecting &&
                            !data.tracking.ga4_enabled && (
                                <> {t("analyticsLive.note.noGa4")}</>
                            )}
                    </p>
                </>
            )}
        </section>
    );
}

function Kpis({ data }: { data: LiveAnalytics }) {
    const { t } = useI18n();
    const last = data.last_order;
    const figure = (text: string) => (
        <span className="t-admin-num-lg">{text}</span>
    );
    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <KpiCard
                compact
                label={t("analyticsLive.kpi.now")}
                value={figure(formatCount(data.now.active_sessions))}
                hint={t("analyticsLive.kpi.nowHint", {
                    minutes: formatCount(data.window_minutes),
                })}
                icon={UsersThreeIcon}
                tone="brand"
            />
            <KpiCard
                compact
                label={t("analyticsLive.kpi.checkout")}
                value={figure(formatCount(data.now.checkout_sessions))}
                hint={t("analyticsLive.kpi.checkoutHint")}
                icon={WarningIcon}
                tone="warning"
            />
            <KpiCard
                compact
                label={t("analyticsLive.kpi.orders")}
                value={figure(formatCount(data.orders_today.count))}
                hint={t("analyticsLive.kpi.ordersHint", {
                    value: takaText(data.orders_today.value),
                })}
                icon={PackageIcon}
                tone="info"
            />
            <KpiCard
                compact
                label={t("analyticsLive.kpi.last")}
                value={figure(
                    last
                        ? t("analyticsLive.kpi.lastValue", {
                              minutes: formatCount(last.minutes_ago),
                          })
                        : "—"
                )}
                hint={last ? last.number : t("analyticsLive.kpi.lastNone")}
                icon={StackIcon}
                tone="neutral"
            />
        </div>
    );
}

/** A card panel (241:30674): radius/md, hairline, e1, 20 padding; title + caption. */
function Card({
    title,
    subtitle,
    children,
    className = "",
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
    className?: string;
}) {
    const id = useId();
    return (
        <section
            aria-labelledby={id}
            className={`flex min-w-0 flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}
        >
            <header className="flex min-w-0 flex-col gap-0.5">
                <h2 id={id} className="t-admin-card-title text-fg-primary">
                    {title}
                </h2>
                {subtitle && (
                    <p className="t-admin-caption text-fg-tertiary">
                        {subtitle}
                    </p>
                )}
            </header>
            {children}
        </section>
    );
}

const feedIcons: Record<
    LiveFeedItem["kind"],
    { icon: ComponentType<IconProps>; tile: string }
> = {
    order: {
        icon: ShoppingCartSimpleIcon,
        tile: "bg-success-subtle text-feedback-success",
    },
    add_to_cart: {
        icon: ShoppingCartIcon,
        tile: "bg-brand-subtle text-fg-brand",
    },
    begin_checkout: {
        icon: CreditCardIcon,
        tile: "bg-warning-subtle text-feedback-warning",
    },
    view_item: { icon: EyeIcon, tile: "bg-subtle text-fg-secondary" },
};

/** One feed line in words: the kind, then the server's figures. */
export function feedText(item: LiveFeedItem, t: T, locale: string): string {
    const parts = [t(`analyticsLive.feed.${item.kind}` as MessageKey)];
    const product = pick(item.product_name, item.product_name_bn, locale);
    if (item.kind === "order") {
        if (item.order_number) parts.push(item.order_number);
        if (item.amount !== null) parts.push(takaText(item.amount));
        const district = pick(item.district, item.district_bn, locale);
        if (district) parts.push(district);
    } else if (item.kind === "begin_checkout") {
        if (item.items !== null)
            parts.push(
                item.items === 1
                    ? t("analyticsLive.feed.item")
                    : t("analyticsLive.feed.items", {
                          count: formatCount(item.items),
                      })
            );
        if (item.amount !== null) parts.push(takaText(item.amount));
    } else if (product) {
        parts.push(product);
    }
    return parts.join(" · ");
}

function Feed({
    data,
    className,
}: {
    data: LiveAnalytics;
    className?: string;
}) {
    const { t, locale } = useI18n();
    return (
        <Card
            title={t("analyticsLive.feed.title")}
            subtitle={t(
                data.tracking.collecting
                    ? "analyticsLive.feed.subtitle"
                    : "analyticsLive.feed.subtitleOrders"
            )}
            className={className}
        >
            {data.feed.length === 0 ? (
                <p className="border-t border-line-default pt-4 t-admin-body text-fg-tertiary">
                    {t("analyticsLive.feed.empty")}
                </p>
            ) : (
                <ul
                    className="flex flex-col border-t border-line-default"
                    aria-label={t("analyticsLive.feed.title")}
                >
                    {data.feed.map((item) => {
                        const { icon: Icon, tile } = feedIcons[item.kind];
                        return (
                            <li
                                key={item.id}
                                className="flex items-center gap-3 border-b border-line-default py-3.5 last:border-b-0"
                            >
                                <span
                                    aria-hidden
                                    className={`grid size-8 shrink-0 place-items-center rounded-full ${tile}`}
                                >
                                    <Icon
                                        size={16}
                                        weight={
                                            item.kind === "order"
                                                ? "fill"
                                                : "regular"
                                        }
                                    />
                                </span>
                                <span className="min-w-0 flex-1 t-admin-body text-fg-primary tabular">
                                    {feedText(item, t, locale)}
                                </span>
                                <time
                                    dateTime={item.at}
                                    className="shrink-0 t-admin-caption text-fg-secondary tabular"
                                >
                                    {ago(item.minutes_ago, t)}
                                </time>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}

function Viewing({ data }: { data: LiveAnalytics }) {
    const { t, locale } = useI18n();
    const products = data.viewing.products;
    return (
        <Card
            title={t("analyticsLive.viewing.title")}
            subtitle={t("analyticsLive.viewing.subtitle", {
                minutes: formatCount(data.window_minutes),
            })}
        >
            {products.length === 0 ? (
                <p className="border-t border-line-default pt-4 t-admin-body text-fg-tertiary">
                    {t("analyticsLive.viewing.empty")}
                </p>
            ) : (
                <ul className="flex flex-col border-t border-line-default">
                    {products.map((p) => (
                        <li
                            key={p.key}
                            className="flex items-center gap-3 border-b border-line-default py-3.5 last:border-b-0"
                        >
                            <span className="min-w-0 flex-1 truncate t-admin-body text-fg-primary">
                                {pick(p.name, p.name_bn, locale)}
                            </span>
                            <span className="shrink-0 t-admin-caption-strong text-fg-primary tabular">
                                {p.sessions === 1
                                    ? t("analyticsLive.viewing.shopper")
                                    : t("analyticsLive.viewing.shoppers", {
                                          count: formatCount(p.sessions),
                                      })}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {!data.viewing.pages_available && (
                <p className="t-admin-caption text-fg-tertiary">
                    {t("analyticsLive.viewing.noPages")}
                </p>
            )}
        </Card>
    );
}

/** 24 Dhaka-hour bars; heights are display-only proportions of the server's counts. */
function Hourly({ data }: { data: LiveAnalytics }) {
    const { t, locale } = useI18n();
    const max = Math.max(1, ...data.hourly.map((h) => h.orders));
    const ticks = [0, 12, 23];
    return (
        <Card
            title={t("analyticsLive.hourly.title")}
            subtitle={t("analyticsLive.hourly.subtitle")}
        >
            <ol
                className="flex h-24 items-end gap-0.5"
                aria-label={t("analyticsLive.hourly.title")}
            >
                {data.hourly.map((h) => (
                    <li
                        key={h.hour}
                        className="flex h-full min-w-0 flex-1 items-end"
                        aria-label={t("analyticsLive.hourly.bar", {
                            hour: hourLabel(h.hour, locale),
                            orders: formatCount(h.orders),
                        })}
                    >
                        {h.orders > 0 ? (
                            <span
                                className="block w-full rounded-t-3xs bg-brand"
                                style={{ height: `${(h.orders / max) * 100}%` }}
                            />
                        ) : (
                            <span className="block h-0.5 w-full bg-line-default" />
                        )}
                    </li>
                ))}
            </ol>
            <div
                className="flex justify-between t-admin-caption text-fg-secondary tabular"
                aria-hidden
            >
                {ticks.map((hour) => (
                    <span key={hour}>{hourLabel(hour, locale)}</span>
                ))}
            </div>
        </Card>
    );
}

/** The three "In plain words" lines, built only from the server's figures. */
export function liveSentences(
    data: LiveAnalytics,
    t: T,
    locale: string
): string[] {
    const lines: string[] = [];
    const { active_sessions: active, checkout_sessions: checkout } = data.now;
    if (!data.tracking.collecting)
        lines.push(t("analyticsLive.plain.trackingOff"));
    else if (active === 0) lines.push(t("analyticsLive.plain.noShoppers"));
    else if (active === 1)
        lines.push(
            t("analyticsLive.plain.shoppersOne", {
                checkout: formatCount(checkout),
            })
        );
    else
        lines.push(
            t("analyticsLive.plain.shoppers", {
                active: formatCount(active),
                checkout: formatCount(checkout),
            })
        );

    const o = data.orders_today;
    if (o.count === 0) {
        lines.push(
            t("analyticsLive.plain.noOrders", {
                yesterday: formatCount(o.same_time_yesterday),
            })
        );
    } else {
        const vars = {
            orders: formatCount(o.count),
            value: takaText(o.value),
            difference: formatCount(Math.abs(o.difference)),
        };
        lines.push(
            t(
                o.difference > 0
                    ? "analyticsLive.plain.ordersMore"
                    : o.difference < 0
                    ? "analyticsLive.plain.ordersFewer"
                    : "analyticsLive.plain.ordersSame",
                vars
            )
        );
    }

    const last = data.last_order;
    if (last) {
        const when =
            last.minutes_ago === 1
                ? t("analyticsLive.ago.plainMinute")
                : last.minutes_ago < 1
                ? t("analyticsLive.ago.now")
                : last.minutes_ago < 60
                ? t("analyticsLive.ago.plainMinutes", {
                      minutes: formatCount(last.minutes_ago),
                  })
                : ago(last.minutes_ago, t);
        const district = pick(last.district, last.district_bn, locale);
        const product = pick(last.product_name, last.product_name_bn, locale);
        const key: MessageKey =
            district && product
                ? "analyticsLive.plain.lastOrderFromProduct"
                : district
                ? "analyticsLive.plain.lastOrderFrom"
                : product
                ? "analyticsLive.plain.lastOrderProduct"
                : "analyticsLive.plain.lastOrder";
        lines.push(
            t(key, {
                ago: when,
                district: district ?? "",
                product: product ?? "",
            })
        );
    }
    return lines;
}
