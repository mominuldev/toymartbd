import { useEffect, useId, useRef, useState } from 'react';
import { WarningIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from './Button';
import { Field } from './Field';
import { Input } from './Input';

type ConfirmDialogProps = {
    open: boolean;
    title: string;
    body: string;
    confirmLabel: string;
    /** Destructive actions require typing this exact text (docs/UI-UX-DESIGN-SPEC.md §4, ConfirmDialog).
     *  Omit it (and typePrompt) for a reversible choice such as discarding unsaved edits. */
    confirmText?: string;
    typePrompt?: string;
    /** Label of the safe button; defaults to Cancel. */
    cancelLabel?: string;
    pending?: boolean;
    onConfirm: () => void;
    onClose: () => void;
};

/**
 * Figma ConfirmDialog / Destructive typed 203:1387 — 520 wide, radius/lg, e4; a 44px
 * danger-subtle warning tile, section title, body; the typed confirmation; a
 * border-topped footer with Cancel (ghost, autofocused so Enter is always safe) and the
 * danger action. Built on <dialog>.showModal(): real focus trap, Esc closes, inert
 * background, and no close on outside click.
 */
export function ConfirmDialog({ open, title, body, confirmLabel, confirmText, typePrompt, cancelLabel, pending, onConfirm, onClose }: ConfirmDialogProps) {
    const { t } = useI18n();
    const ref = useRef<HTMLDialogElement>(null);
    const cancel = useRef<HTMLButtonElement>(null);
    const titleId = useId();
    const [typed, setTyped] = useState('');

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            setTyped('');
            // jsdom has no showModal; the attribute keeps tests meaningful.
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            // React renders no autofocus attribute, so showModal() would pick the input.
            cancel.current?.focus();
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
            className="m-auto w-[min(100%-2rem,32.5rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay"
        >
            <form
                method="dialog"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (confirmText === undefined || typed === confirmText) onConfirm();
                }}
            >
                <div className="flex flex-col items-start gap-2.5 px-6 pt-6 pb-4">
                    <span aria-hidden className="grid size-11 place-items-center rounded-sm bg-danger-subtle text-feedback-danger">
                        <WarningIcon size={22} />
                    </span>
                    <h2 id={titleId} className="t-admin-section text-fg-primary">
                        {title}
                    </h2>
                    <p className="t-admin-body text-fg-secondary">{body}</p>
                </div>
                {confirmText !== undefined && (
                    <div className="px-6 pb-4">
                        <Field label={typePrompt ?? ''}>
                            {({ id }) => <Input id={id} autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} />}
                        </Field>
                    </div>
                )}
                <div className="flex justify-end gap-3 border-t border-line-default px-6 py-4">
                    <Button ref={cancel} variant="ghost" onClick={onClose}>
                        {cancelLabel ?? t('common.cancel')}
                    </Button>
                    <Button type="submit" variant="danger" disabled={(confirmText !== undefined && typed !== confirmText) || pending}>
                        {confirmLabel}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
