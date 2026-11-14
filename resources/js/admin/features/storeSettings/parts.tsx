import { useEffect, useRef, type ReactNode } from 'react';
import { InfoIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';

/** Who may see / change Store settings: read with settings.view, write with settings.update. */
export function useSettingsAccess() {
    const { data: me } = useMe();

    return { me, canView: can(me, 'settings.view'), canEdit: can(me, 'settings.update') };
}

/** Opens and closes a native <dialog> with the `open` prop; `onOpen` resets the form. */
export function useDialog(open: boolean, onOpen: () => void) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            onOpen();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    return ref;
}

/** Field errors from a 422 problem, first message per field; null for any other failure. */
export function problemErrors(error: unknown): Record<string, string> | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    const next: Record<string, string> = {};
    for (const { field, message } of error.problem.errors ?? []) next[field] ??= message;
    return next;
}

/** First message per field from a failed generated-schema parse (rule 27). */
export function issuesOf(issues: readonly { path: readonly PropertyKey[]; message: string }[]): Record<string, string> {
    const next: Record<string, string> = {};
    for (const issue of issues) next[String(issue.path[0])] ??= issue.message;
    return next;
}

/** A subtle info strip (placeholders still unset, read-only notice). */
export function Note({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-sm bg-subtle px-3.5 py-3 t-admin-caption text-fg-secondary" role="status">
            <InfoIcon size={16} aria-hidden className="shrink-0 text-fg-brand" />
            <span>{children}</span>
        </p>
    );
}

/** The bottom-right save row each section card carries (sections are separate routes, so there is no page-wide save). */
export function SaveRow({ children }: { children: ReactNode }) {
    return <div className="flex justify-end border-t border-line-default pt-4">{children}</div>;
}

/** `+8801711000222` → `+880 1711-000222` for display; anything else unchanged. */
export function formatMobile(e164: string | null): string {
    if (!e164) return '';
    const m = /^\+880(\d{4})(\d{6})$/.exec(e164);
    return m ? `+880 ${m[1]}-${m[2]}` : e164;
}
