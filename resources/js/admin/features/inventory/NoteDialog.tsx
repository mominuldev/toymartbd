import { useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { CheckCircleIcon, WarningIcon } from "@phosphor-icons/react";
import { useI18n } from "@/i18n/I18nProvider";
import { Button } from "@/components/ui/Button";
import { Field, inputClass } from "@/components/ui/Field";

type NoteDialogProps = {
    open: boolean;
    title: string;
    body: string;
    noteLabel: string;
    confirmLabel: string;
    /** A rejection must say why; an approval or acknowledgement may. */
    required?: boolean;
    /** Danger tile + danger action (reject), otherwise the brand tile + primary action. */
    danger?: boolean;
    pending?: boolean;
    /** The server's (or the generated schema's) message for the note. */
    error?: string;
    onConfirm: (note: string) => void;
    onClose: () => void;
};

/**
 * A decision with a note — approve / reject an adjustment, acknowledge an alert. Figma
 * ConfirmDialog 203:1387's frame (520 wide, radius/lg, e4, 44px tile, border-topped footer,
 * Cancel autofocused so Enter is always safe) with an AdminField 185:151 textarea in place of
 * the typed confirmation. Portalled into body, like ConfirmDialog.
 */
export function NoteDialog({
    open,
    title,
    body,
    noteLabel,
    confirmLabel,
    required,
    danger,
    pending,
    error,
    onConfirm,
    onClose,
}: NoteDialogProps) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const cancel = useRef<HTMLButtonElement>(null);
    const titleId = useId();
    const [note, setNote] = useState("");

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setNote("");
            if (typeof dialog.showModal === "function") dialog.showModal();
            else dialog.setAttribute("open", "");
            cancel.current?.focus();
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === "function") dialog.close();
            else dialog.removeAttribute("open");
        }
    }, [open]);

    const Icon = danger ? WarningIcon : CheckCircleIcon;

    return createPortal(
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
                    event.stopPropagation();
                    onConfirm(note);
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span
                        aria-hidden
                        className={`grid size-11 place-items-center rounded-sm ${danger ? "bg-danger-subtle text-feedback-danger" : "bg-brand-subtle text-fg-brand"}`}
                    >
                        <Icon size={22} />
                    </span>
                    <h2
                        id={titleId}
                        className="t-admin-section text-fg-primary"
                    >
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                <div className="px-6 pb-5">
                    <Field
                        label={noteLabel}
                        error={error}
                        required={required}
                        optional={!required}
                    >
                        {({ id, describedBy, invalid }) => (
                            <textarea
                                id={id}
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                rows={3}
                                maxLength={500}
                                className={`py-2 ${inputClass}`}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                            />
                        )}
                    </Field>
                </div>
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button ref={cancel} variant="ghost" onClick={onClose}>
                        {t("common.cancel")}
                    </Button>
                    <Button
                        type="submit"
                        variant={danger ? "danger" : "primary"}
                        disabled={pending}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </form>
        </dialog>,
        document.body,
    );
}
