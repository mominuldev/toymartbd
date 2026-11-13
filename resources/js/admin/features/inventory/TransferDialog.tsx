import { useEffect, useId, useMemo, useRef, useState } from "react";
import { ArrowsLeftRightIcon } from "@phosphor-icons/react";
import { storeStockTransferRequestSchema } from "@toymart/api-types/zod";
import { ApiError } from "@/lib/api";
import { useI18n } from "@/i18n/I18nProvider";
import { Button } from "@/components/ui/Button";
import { Field, inputClass } from "@/components/ui/Field";
import { Input } from "@/components/ui/Input";
import { SearchField } from "@/components/ui/SearchField";
import { Select } from "@/components/ui/Select";
import { useToast } from "@/components/ui/Toast";
import {
    useStock,
    useTransferStock,
    type InventoryLocation,
    type StockRow,
    type TransferInput,
} from "./api";
import { VariantCell, formatUnits } from "./parts";

type FieldName = "variant" | "from" | "to" | "quantity" | "note";
type Errors = Partial<Record<FieldName, string>>;

function formField(apiField: string): FieldName | null {
    if (apiField === "from_location_id") return "from";
    if (apiField === "to_location_id") return "to";
    if (apiField === "note") return "note";
    if (apiField === "lines" || /^lines\.0\.variant_id$/.test(apiField))
        return "variant";
    if (/^lines\.0\.quantity$/.test(apiField)) return "quantity";
    return null;
}

type TransferDialogProps = {
    open: boolean;
    onClose: () => void;
    locations: InventoryLocation[];
    /** Opening source and destination (a short row's "Transfer N": from the branch that has it, to this one). */
    fromId: number;
    toId: number | null;
    /** Preselected variant id (row action); otherwise the dialog searches. */
    variantId?: number | null;
    /** Opening quantity (row action: what the source has available). */
    quantity?: number | null;
};

/**
 * Stock transfer between locations (06-admin-feature-checklist §2.7 [C]; "stock transfer form"
 * is listed as not drawn yet — built from the same frame as the adjustment dialog: ConfirmDialog
 * 203:1387's 520 frame, AdminField 185:151, AdminSelect 184:369, AdminInput 184:338,
 * AdminButton 184:242). The generated schema validates (rule 27); the server checks what is
 * available at the source under the row locks and its 422 lands on the same fields. One
 * Idempotency-Key per opening.
 */
export function TransferDialog({
    open,
    onClose,
    locations,
    fromId,
    toId,
    variantId = null,
    quantity = null,
}: TransferDialogProps) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const save = useTransferStock();

    const firstOther = (id: number) =>
        locations.find((l) => l.id !== id)?.id ?? id;
    const [from, setFrom] = useState(String(fromId));
    const [to, setTo] = useState(String(toId ?? firstOther(fromId)));
    const [picked, setPicked] = useState<StockRow | null>(null);
    const [term, setTerm] = useState("");
    const [amount, setAmount] = useState("");
    const [note, setNote] = useState("");
    const [errors, setErrors] = useState<Errors>({});
    const [idempotencyKey, setIdempotencyKey] = useState(() =>
        crypto.randomUUID(),
    );

    const search = term.trim();
    const preset = useStock(
        {
            location: from,
            state: "",
            search: "",
            variant: variantId ? String(variantId) : undefined,
        },
        open && variantId !== null && !picked,
    );
    const results = useStock(
        { location: from, state: "", search },
        open && !picked && variantId === null && search.length >= 2,
    );
    const options = useMemo(
        () => results.data?.pages.flatMap((p) => p.data).slice(0, 8) ?? [],
        [results.data],
    );

    useEffect(() => {
        const row = preset.data?.pages[0]?.data[0];
        if (row && !picked) setPicked(row);
    }, [preset.data, picked]);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setFrom(String(fromId));
            setTo(String(toId ?? firstOther(fromId)));
            setPicked(null);
            setTerm("");
            setAmount(quantity ? String(quantity) : "");
            setNote("");
            setErrors({});
            setIdempotencyKey(crypto.randomUUID());
            save.reset();
            if (typeof dialog.showModal === "function") dialog.showModal();
            else dialog.setAttribute("open", "");
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === "function") dialog.close();
            else dialog.removeAttribute("open");
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const name = (l: InventoryLocation) =>
        locale === "bn" ? (l.name_bn ?? l.name) : l.name;

    function submit() {
        if (!picked || amount.trim() === "") {
            setErrors({
                ...(picked ? {} : { variant: t("validation.required") }),
                ...(amount.trim() === ""
                    ? { quantity: t("validation.required") }
                    : {}),
            });
            return;
        }
        const input: TransferInput = {
            from_location_id: Number(from),
            to_location_id: Number(to),
            note: note.trim() === "" ? null : note.trim(),
            lines: [
                {
                    variant_id: picked.variant_id,
                    quantity: /^\d+$/.test(amount.trim())
                        ? Number(amount.trim())
                        : Number.NaN,
                },
            ],
        };
        const parsed = storeStockTransferRequestSchema.safeParse(input);
        const next: Errors = {};
        if (!parsed.success) {
            for (const issue of parsed.error.issues) {
                const field = formField(issue.path.join("."));
                if (field && !next[field]) next[field] = issue.message;
            }
        }
        if (Object.keys(next).length > 0) {
            setErrors(next);
            return;
        }
        setErrors({});
        save.mutate(
            { input, idempotencyKey },
            {
                onSuccess: (transfer) => {
                    notify(t("transfer.saved", { number: transfer.number }));
                    onClose();
                },
                onError: (error) => {
                    if (error instanceof ApiError && error.status === 422) {
                        const fields: Errors = {};
                        for (const { field, message } of error.problem.errors ??
                            []) {
                            const key = formField(field);
                            if (key && !fields[key]) fields[key] = message;
                        }
                        setErrors(fields);
                        setIdempotencyKey(crypto.randomUUID());
                    } else {
                        notify(
                            error instanceof ApiError
                                ? error.problem.title
                                : t("error.title"),
                            "danger",
                        );
                    }
                },
            },
        );
    }

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            onCancel={onClose}
            className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span
                        aria-hidden
                        className="grid size-11 place-items-center rounded-sm bg-brand-subtle text-fg-brand"
                    >
                        <ArrowsLeftRightIcon size={22} />
                    </span>
                    <h2
                        id={titleId}
                        className="t-admin-section text-fg-primary"
                    >
                        {t("transfer.title")}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">
                        {t("transfer.body")}
                    </p>
                </div>

                <fieldset
                    disabled={save.isPending}
                    className="flex min-w-0 flex-col gap-3.5 px-6 pb-5"
                >
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label={t("transfer.from")} error={errors.from}>
                            {({ id, describedBy, invalid }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    value={from}
                                    onChange={(e) => {
                                        setFrom(e.target.value);
                                        if (to === e.target.value)
                                            setTo(
                                                String(
                                                    firstOther(
                                                        Number(e.target.value),
                                                    ),
                                                ),
                                            );
                                        if (variantId === null) setPicked(null);
                                    }}
                                >
                                    {locations.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {name(l)}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                        <Field label={t("transfer.to")} error={errors.to}>
                            {({ id, describedBy, invalid }) => (
                                <Select
                                    id={id}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                >
                                    {locations
                                        .filter((l) => String(l.id) !== from)
                                        .map((l) => (
                                            <option key={l.id} value={l.id}>
                                                {name(l)}
                                            </option>
                                        ))}
                                </Select>
                            )}
                        </Field>
                    </div>

                    <Field label={t("adjust.variant")} error={errors.variant}>
                        {({ id, describedBy, invalid }) =>
                            picked ? (
                                <div
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="flex flex-col gap-1.5 rounded-sm border border-line-default bg-subtle p-2.5"
                                >
                                    <VariantCell row={picked} />
                                    <p className="t-admin-caption tabular text-fg-secondary">
                                        {t("adjust.current", {
                                            onHand: formatUnits(picked.on_hand),
                                            reserved: formatUnits(
                                                picked.reserved,
                                            ),
                                            available: formatUnits(
                                                picked.available,
                                            ),
                                        })}
                                    </p>
                                    {variantId === null && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="self-start"
                                            onClick={() => setPicked(null)}
                                        >
                                            {t("adjust.variantPick")}
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col gap-1.5">
                                    <SearchField
                                        id={id}
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        label={t("adjust.variantSearch")}
                                        placeholder={t("adjust.variantSearch")}
                                        value={term}
                                        onChange={(e) =>
                                            setTerm(e.target.value)
                                        }
                                    />
                                    {search.length >= 2 && (
                                        <ul
                                            aria-label={t("adjust.variantPick")}
                                            className="flex max-h-60 flex-col overflow-y-auto rounded-sm border border-line-default"
                                        >
                                            {options.length === 0 &&
                                            !results.isFetching ? (
                                                <li className="px-3 py-2 t-admin-caption text-fg-tertiary">
                                                    {t("adjust.variantNone")}
                                                </li>
                                            ) : (
                                                options.map((option) => (
                                                    <li
                                                        key={option.variant_id}
                                                        className="border-t border-line-default first:border-t-0"
                                                    >
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setPicked(
                                                                    option,
                                                                )
                                                            }
                                                            className="w-full px-3 py-2 text-start hover:bg-subtle"
                                                        >
                                                            <VariantCell
                                                                row={option}
                                                            />
                                                        </button>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    )}
                                </div>
                            )
                        }
                    </Field>

                    <Field
                        label={t("transfer.quantity")}
                        hint={t("transfer.quantityHint")}
                        error={errors.quantity}
                        required
                    >
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                inputMode="numeric"
                                autoComplete="off"
                                className="tabular"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label={t("adjust.note")}
                        error={errors.note}
                        optional
                    >
                        {({ id, describedBy, invalid }) => (
                            <textarea
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                rows={2}
                                maxLength={500}
                                className={`py-2 ${inputClass}`}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                        )}
                    </Field>
                </fieldset>

                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button variant="ghost" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button type="submit" disabled={save.isPending}>
                        {t("transfer.submit")}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
