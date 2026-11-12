import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { acknowledgeInventoryAlertRequestSchema } from "@toymart/api-types/zod";
import { ApiError } from "@/lib/api";
import { useI18n } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages";
import { can, useMe } from "@/features/auth/api";
import { Button, buttonClass } from "@/components/ui/Button";
import { ContentHeader } from "@/components/shell/ContentHeader";
import { DataTableFooter, dataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { QueryError } from "@/components/ui/QueryError";
import { StatusChip, type StatusTone } from "@/components/ui/StatusChip";
import { TabButton } from "@/components/ui/Tabs";
import { useToast } from "@/components/ui/Toast";
import {
    alertKinds,
    useAcknowledgeAlert,
    useAlerts,
    type AlertKind,
    type InventoryAlert,
} from "./api";
import { NoteDialog } from "./NoteDialog";
import { VariantCell, formatUnits, signed } from "./parts";

const kindTone: Record<AlertKind, StatusTone> = {
    ledger_drift: "danger",
    reserved_drift: "danger",
    out_of_stock: "warning",
    low_stock: "anomaly",
};

/**
 * Inventory alerts (P5-INV; not drawn — built from the Inventory screen's parts: ContentHeader
 * 187:530, AdminTab 185:125, DataTable 192:532, StatusChip 185:79 and the ConfirmDialog 203:1387
 * frame). Drift found by the nightly reconciliation stays open until a person acknowledges it
 * (inventory.approve_adjustments); nothing here changes stock — a real correction is a stock
 * adjustment, linked from each drift row's Ledger. Every figure is the server's.
 */
export function AlertsPage() {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const kindParam = params.get("kind");
    const kind: AlertKind | "" = alertKinds.includes(kindParam as AlertKind)
        ? (kindParam as AlertKind)
        : "";
    const list = useAlerts({ kind });
    const rows = useMemo(
        () => list.data?.pages.flatMap((page) => page.data) ?? [],
        [list.data],
    );
    const counts = list.data?.pages[0]?.meta.counts;
    const canAck = can(me, "inventory.approve_adjustments");
    const ack = useAcknowledgeAlert();
    const [open, setOpen] = useState<InventoryAlert | null>(null);
    const [error, setError] = useState<string | undefined>();

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const day = (iso: string) =>
        new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
            timeZone: "UTC",
            day: "2-digit",
            month: "short",
            numberingSystem: "latn",
        }).format(new Date(`${iso}T00:00:00Z`));

    function submit(note: string) {
        if (!open) return;
        const input = { note: note.trim() === "" ? null : note.trim() };
        const parsed = acknowledgeInventoryAlertRequestSchema.safeParse(input);
        if (!parsed.success) {
            setError(parsed.error.issues[0]?.message);
            return;
        }
        ack.mutate(
            { id: open.id, input },
            {
                onSuccess: () => {
                    notify(t("alerts.acknowledged"));
                    setOpen(null);
                },
                onError: (e) => {
                    if (e instanceof ApiError && e.status === 422)
                        setError(
                            e.problem.errors?.[0]?.message ?? e.problem.title,
                        );
                    else
                        notify(
                            e instanceof ApiError
                                ? e.problem.title
                                : t("error.title"),
                            "danger",
                        );
                },
            },
        );
    }

    const total = counts
        ? alertKinds.reduce((sum, k) => sum + counts[k], 0)
        : undefined;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[
                    { label: t("catalog.crumb") },
                    { label: t("inventory.crumb"), to: "/inventory" },
                    { label: t("alerts.crumb") },
                ]}
                title={t("alerts.title")}
                subtitle={t("alerts.subtitle")}
            />

            <div
                role="group"
                aria-label={t("alerts.title")}
                className="scrollbar-none flex overflow-x-auto border-b border-line-default"
            >
                <TabButton
                    active={kind === ""}
                    label={t("alerts.tab.all")}
                    count={total}
                    onClick={() => setParams({}, { replace: true })}
                />
                {alertKinds.map((k) => (
                    <TabButton
                        key={k}
                        active={kind === k}
                        label={t(`alerts.kind.${k}` as MessageKey)}
                        count={counts?.[k]}
                        onClick={() =>
                            setParams({ kind: k }, { replace: true })
                        }
                    />
                ))}
            </div>

            {list.isError ? (
                <QueryError
                    error={list.error}
                    onRetry={() => void list.refetch()}
                />
            ) : list.isPending ? (
                <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                    {t("common.loading")}
                </p>
            ) : rows.length === 0 ? (
                <EmptyState
                    reason="allClear"
                    title={t("alerts.empty")}
                    body={t("alerts.emptyBody")}
                />
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead
                            className={`hidden lg:table-header-group ${dataTable.head}`}
                        >
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>
                                    {t("alerts.col.kind")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t("inventory.col.product")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t("alerts.col.location")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t("alerts.col.figures")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t("alerts.col.raised")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    <span className="sr-only">
                                        {t("inventory.col.actions")}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((a) => {
                                const drift =
                                    a.kind === "ledger_drift" ||
                                    a.kind === "reserved_drift";
                                return (
                                    <tr
                                        key={a.id}
                                        className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 lg:table-row lg:p-0 ${dataTable.row}`}
                                    >
                                        <td className={cell}>
                                            <StatusChip
                                                label={t(
                                                    `alerts.kind.${a.kind}` as MessageKey,
                                                )}
                                                tone={kindTone[a.kind]}
                                            />
                                        </td>
                                        <td
                                            className={`min-w-0 basis-full lg:basis-auto ${cell}`}
                                        >
                                            <VariantCell
                                                row={{
                                                    ...a,
                                                    variant_name_bn: null,
                                                }}
                                            />
                                        </td>
                                        <td
                                            className={`${cell} t-admin-body-strong text-fg-secondary`}
                                        >
                                            {a.location_code.toUpperCase()}
                                        </td>
                                        <td
                                            className={`${cell} t-admin-body tabular text-fg-primary`}
                                        >
                                            {drift
                                                ? t("alerts.driftFigures", {
                                                      expected: formatUnits(
                                                          a.expected ?? 0,
                                                      ),
                                                      actual: formatUnits(
                                                          a.actual ?? 0,
                                                      ),
                                                      difference: signed(
                                                          a.difference ?? 0,
                                                      ),
                                                  })
                                                : t("alerts.stockFigures", {
                                                      actual: formatUnits(
                                                          a.actual ?? 0,
                                                      ),
                                                      expected: formatUnits(
                                                          a.expected ?? 0,
                                                      ),
                                                  })}
                                        </td>
                                        <td
                                            className={`${cell} t-admin-caption tabular text-fg-secondary`}
                                        >
                                            {day(a.alert_date)}
                                        </td>
                                        <td
                                            className={`${cell} max-lg:basis-full`}
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <Link
                                                    to={`/inventory/movements?variant=${a.variant_id}&location=${a.location_id}&range=all`}
                                                    className={buttonClass(
                                                        "ghost",
                                                        "sm",
                                                    )}
                                                >
                                                    {t("inventory.ledger")}
                                                </Link>
                                                {drift && canAck && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            setError(undefined);
                                                            setOpen(a);
                                                        }}
                                                    >
                                                        {t(
                                                            "alerts.acknowledge",
                                                        )}
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    {list.hasNextPage && (
                        <DataTableFooter
                            summary={t("inventory.count", {
                                count: rows.length,
                            })}
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => void list.fetchNextPage()}
                                disabled={list.isFetchingNextPage}
                            >
                                {list.isFetchingNextPage
                                    ? t("common.loading")
                                    : t("common.loadMore")}
                            </Button>
                        </DataTableFooter>
                    )}
                </div>
            )}

            <NoteDialog
                open={open !== null}
                title={t("alerts.ackTitle")}
                body={t("alerts.ackBody")}
                noteLabel={t("alerts.ackNote")}
                confirmLabel={t("alerts.acknowledge")}
                pending={ack.isPending}
                error={error}
                onConfirm={submit}
                onClose={() => setOpen(null)}
            />
        </section>
    );
}
