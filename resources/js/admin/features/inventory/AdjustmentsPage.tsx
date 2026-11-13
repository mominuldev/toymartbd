import { useMemo, useState } from "react";
import { useSearchParams } from "react-router";
import {
    approveStockAdjustmentRequestSchema,
    rejectStockAdjustmentRequestSchema,
} from "@toymart/api-types/zod";
import { ApiError } from "@/lib/api";
import { useI18n } from "@/i18n/I18nProvider";
import type { MessageKey } from "@/i18n/messages";
import { can, useMe } from "@/features/auth/api";
import { Button } from "@/components/ui/Button";
import { ContentHeader } from "@/components/shell/ContentHeader";
import { DataTableFooter, dataTable } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/ui/EmptyState";
import { QueryError } from "@/components/ui/QueryError";
import { StatusChip, type StatusTone } from "@/components/ui/StatusChip";
import { TabButton } from "@/components/ui/Tabs";
import { useToast } from "@/components/ui/Toast";
import {
    useAdjustments,
    useDecideAdjustment,
    type Adjustment,
    type AdjustmentStatus,
} from "./api";
import { NoteDialog } from "./NoteDialog";
import { Taka, VariantCell, formatUnits, signed } from "./parts";

const statusTone: Record<AdjustmentStatus, StatusTone> = {
    applied: "success",
    approved: "success",
    pending: "warning",
    rejected: "neutral",
};

type Decision = {
    adjustment: Adjustment;
    decision: "approve" | "reject";
} | null;

/**
 * Stock adjustments and the approval queue (P5-INV; not drawn — built from the Inventory screen's
 * parts: ContentHeader 187:530, AdminTab 185:125, DataTable 192:532, StatusChip 185:79,
 * AdminButton 184:242 and the ConfirmDialog 203:1387 frame for the decision). The queue is the
 * default tab. Approve / Reject need inventory.approve_adjustments; the server re-checks every
 * line against the stock at that moment, so a 422 here means the stock moved since the request.
 */
export function AdjustmentsPage() {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const { data: me } = useMe();
    const [params, setParams] = useSearchParams();
    const tab = params.get("tab") === "all" ? "all" : "pending";
    const list = useAdjustments({ status: tab === "pending" ? "pending" : "" });
    const rows = useMemo(
        () => list.data?.pages.flatMap((page) => page.data) ?? [],
        [list.data],
    );
    const pendingCount = list.data?.pages[0]?.meta.counts.pending;
    const canApprove = can(me, "inventory.approve_adjustments");
    const viewCost = can(me, "catalog.view_cost");
    const decide = useDecideAdjustment();
    const [open, setOpen] = useState<Decision>(null);
    const [error, setError] = useState<string | undefined>();
    const [key, setKey] = useState(() => crypto.randomUUID());

    const cell = `${dataTable.td} max-lg:h-auto max-lg:p-0`;
    const fmtDate = (iso: string) =>
        new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
            timeZone: "Asia/Dhaka",
            day: "2-digit",
            month: "short",
            hour: "numeric",
            minute: "2-digit",
            numberingSystem: "latn",
        }).format(new Date(iso));

    function submit(note: string) {
        if (!open) return;
        const { adjustment, decision } = open;
        const input = { note: note.trim() === "" ? null : note.trim() };
        const schema =
            decision === "approve"
                ? approveStockAdjustmentRequestSchema
                : rejectStockAdjustmentRequestSchema;
        const parsed = schema.safeParse(input);
        if (!parsed.success || (decision === "reject" && !input.note)) {
            setError(
                parsed.success
                    ? t("validation.required")
                    : parsed.error.issues[0]?.message,
            );
            return;
        }
        setError(undefined);
        const done = (a: Adjustment) => {
            notify(
                t(
                    decision === "approve"
                        ? "approvals.approved"
                        : "approvals.rejected",
                    { number: a.number },
                ),
            );
            setOpen(null);
        };
        const failed = (e: Error) => {
            setKey(crypto.randomUUID());
            if (e instanceof ApiError && e.status === 422)
                setError(e.problem.errors?.[0]?.message ?? e.problem.title);
            else
                notify(
                    e instanceof ApiError ? e.problem.title : t("error.title"),
                    "danger",
                );
        };
        if (decision === "approve")
            decide.mutate(
                { id: adjustment.id, decision, input, idempotencyKey: key },
                { onSuccess: done, onError: failed },
            );
        else
            decide.mutate(
                {
                    id: adjustment.id,
                    decision,
                    input: { note: input.note ?? "" },
                    idempotencyKey: key,
                },
                { onSuccess: done, onError: failed },
            );
    }

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[
                    { label: t("catalog.crumb") },
                    { label: t("inventory.crumb"), to: "/inventory" },
                    { label: t("approvals.crumb") },
                ]}
                title={t("approvals.title")}
                subtitle={t("approvals.subtitle")}
            />

            <div
                role="group"
                aria-label={t("approvals.title")}
                className="scrollbar-none flex overflow-x-auto border-b border-line-default"
            >
                <TabButton
                    active={tab === "pending"}
                    label={t("approvals.tab.pending")}
                    count={pendingCount}
                    onClick={() => setParams({}, { replace: true })}
                />
                <TabButton
                    active={tab === "all"}
                    label={t("approvals.tab.all")}
                    onClick={() => setParams({ tab: "all" }, { replace: true })}
                />
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
                tab === "pending" ? (
                    <EmptyState
                        reason="allClear"
                        title={t("approvals.empty")}
                        body={t("approvals.emptyBody")}
                    />
                ) : (
                    <EmptyState
                        title={t("approvals.emptyAll")}
                        body={t("approvals.emptyAllBody")}
                    />
                )
            ) : (
                <div className={dataTable.card}>
                    <table className={dataTable.table}>
                        <thead
                            className={`hidden lg:table-header-group ${dataTable.head}`}
                        >
                            <tr className="border-b border-line-strong">
                                <th scope="col" className={dataTable.th}>
                                    {t("approvals.col.number")}
                                </th>
                                <th scope="col" className={dataTable.th}>
                                    {t("approvals.col.lines")}
                                </th>
                                <th
                                    scope="col"
                                    className={dataTable.th.replace(
                                        "text-start",
                                        "text-end",
                                    )}
                                >
                                    {t("approvals.col.units")}
                                </th>
                                {viewCost && (
                                    <th
                                        scope="col"
                                        className={dataTable.th.replace(
                                            "text-start",
                                            "text-end",
                                        )}
                                    >
                                        {t("approvals.col.value")}
                                    </th>
                                )}
                                <th scope="col" className={dataTable.th}>
                                    {t("approvals.col.status")}
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
                                const first = a.items[0];
                                const own =
                                    a.requested_by !== null &&
                                    a.requested_by === me?.name;
                                return (
                                    <tr
                                        key={a.id}
                                        className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 lg:table-row lg:p-0 ${dataTable.row}`}
                                    >
                                        <td
                                            className={`basis-full lg:basis-auto ${cell}`}
                                        >
                                            <p className="t-admin-mono-sm text-fg-primary">
                                                {a.number}
                                            </p>
                                            <p className="t-admin-caption text-fg-secondary">
                                                {t(
                                                    `adjust.reason.${a.reason}` as MessageKey,
                                                )}{" "}
                                                ·{" "}
                                                {a.location_code.toUpperCase()}{" "}
                                                · {fmtDate(a.created_at)}
                                                {a.requested_by
                                                    ? ` · ${a.requested_by}`
                                                    : ""}
                                            </p>
                                            {a.note && (
                                                <p className="t-admin-caption text-fg-tertiary">
                                                    {a.note}
                                                </p>
                                            )}
                                        </td>
                                        <td className={`min-w-0 ${cell}`}>
                                            {first && (
                                                <VariantCell
                                                    row={{
                                                        ...first,
                                                        variant_name_bn: null,
                                                    }}
                                                />
                                            )}
                                            <p className="t-admin-caption tabular text-fg-secondary">
                                                {first &&
                                                    (first.counted !== null
                                                        ? `${formatUnits(first.quantity_before)} → ${formatUnits(first.counted)}`
                                                        : signed(first.change))}
                                                {a.items.length > 1 &&
                                                    ` · ${t("approvals.more", { count: a.items.length - 1 })}`}
                                            </p>
                                        </td>
                                        <td
                                            className={`${cell} t-admin-num tabular lg:text-end`}
                                        >
                                            {formatUnits(a.requested_units)}
                                        </td>
                                        {viewCost && (
                                            <td
                                                className={`${cell} t-admin-num tabular whitespace-nowrap lg:text-end`}
                                            >
                                                {a.requested_value !==
                                                    undefined && (
                                                    <Taka
                                                        amount={
                                                            a.requested_value
                                                        }
                                                    />
                                                )}
                                            </td>
                                        )}
                                        <td className={cell}>
                                            <StatusChip
                                                label={t(
                                                    `approvals.status.${a.status}` as MessageKey,
                                                )}
                                                tone={statusTone[a.status]}
                                            />
                                            {a.decision_note && (
                                                <p className="mt-1 t-admin-caption text-fg-tertiary">
                                                    {a.decision_note}
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${cell} max-lg:basis-full`}
                                        >
                                            {a.status === "pending" &&
                                                canApprove && (
                                                    <div className="flex items-center gap-2.5">
                                                        <Button
                                                            size="sm"
                                                            disabled={own}
                                                            title={
                                                                own
                                                                    ? t(
                                                                          "approvals.self",
                                                                      )
                                                                    : undefined
                                                            }
                                                            onClick={() => {
                                                                setError(
                                                                    undefined,
                                                                );
                                                                setKey(
                                                                    crypto.randomUUID(),
                                                                );
                                                                setOpen({
                                                                    adjustment:
                                                                        a,
                                                                    decision:
                                                                        "approve",
                                                                });
                                                            }}
                                                        >
                                                            {t(
                                                                "approvals.approve",
                                                            )}
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => {
                                                                setError(
                                                                    undefined,
                                                                );
                                                                setOpen({
                                                                    adjustment:
                                                                        a,
                                                                    decision:
                                                                        "reject",
                                                                });
                                                            }}
                                                        >
                                                            {t(
                                                                "approvals.reject",
                                                            )}
                                                        </Button>
                                                    </div>
                                                )}
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
                title={
                    open
                        ? t(
                              open.decision === "approve"
                                  ? "approvals.approveTitle"
                                  : "approvals.rejectTitle",
                              { number: open.adjustment.number },
                          )
                        : ""
                }
                body={
                    open
                        ? t(
                              open.decision === "approve"
                                  ? "approvals.approveBody"
                                  : "approvals.rejectBody",
                          )
                        : ""
                }
                noteLabel={
                    open?.decision === "reject"
                        ? t("approvals.reason")
                        : t("approvals.note")
                }
                required={open?.decision === "reject"}
                confirmLabel={
                    open?.decision === "reject"
                        ? t("approvals.reject")
                        : t("approvals.approve")
                }
                danger={open?.decision === "reject"}
                pending={decide.isPending}
                error={error}
                onConfirm={submit}
                onClose={() => setOpen(null)}
            />
        </section>
    );
}
