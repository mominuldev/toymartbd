import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useBlocker, useOutletContext } from 'react-router';
import { CheckCircleIcon, InfoIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { can, useMe } from '@/features/auth/api';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';

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

/** `+8801711000222` → `+880 1711-000222` for display; anything else unchanged. */
export function formatMobile(e164: string | null): string {
    if (!e164) return '';
    const m = /^\+880(\d{4})(\d{6})$/.exec(e164);
    return m ? `+880 ${m[1]}-${m[2]}` : e164;
}

/** What SettingsLayout hands each section through <Outlet context>: where the sticky save bar goes. */
export type SettingsOutletContext = { saveBarSlot: HTMLElement | null };

/** How long "Saved" holds in the bar before it slides out (Figma FormActions 198:1085: 2.4 s). */
export const SAVED_FLASH_MS = 2400;

/** `[saved, flash]`: `flash()` after a save turns `saved` on for SAVED_FLASH_MS. */
export function useSavedFlash(): [boolean, () => void] {
    const [saved, setSaved] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    useEffect(() => () => window.clearTimeout(timer.current), []);
    const flash = useCallback(() => {
        window.clearTimeout(timer.current);
        setSaved(true);
        timer.current = window.setTimeout(() => setSaved(false), SAVED_FLASH_MS);
    }, []);
    return [saved, flash];
}

/** "Logo and tagline changed" from the changed parts' names ("logo", "tagline"), in the UI language. */
export function useChangeSummary(parts: readonly string[]): string {
    const { t, locale } = useI18n();
    if (parts.length === 0) return '';
    const list = new Intl.ListFormat(locale === 'bn' ? 'bn' : 'en', { type: 'conjunction' }).format(parts);
    const sentence = t('storeSettings.saveBar.changed', { list });
    return sentence.charAt(0).toUpperCase() + sentence.slice(1);
}

/**
 * Leaving a section with unsaved edits asks first — another section, a sidebar link, the back
 * button (useBlocker) — and closing or reloading the tab warns (beforeunload). One prompt for
 * every Store settings section, like Categories' panel.
 */
export function UnsavedChangesGuard({ when }: { when: boolean }) {
    const { t } = useI18n();
    const blocker = useBlocker(({ currentLocation, nextLocation }) => when && currentLocation.pathname !== nextLocation.pathname);
    useEffect(() => {
        if (!when) return;
        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [when]);

    return (
        <ConfirmDialog
            open={blocker.state === 'blocked'}
            title={t('storeSettings.leave.title')}
            body={t('storeSettings.leave.body')}
            confirmLabel={t('storeSettings.leave.discard')}
            cancelLabel={t('storeSettings.leave.stay')}
            onClose={() => blocker.reset?.()}
            onConfirm={() => blocker.proceed?.()}
        />
    );
}

type StickySaveBarProps = {
    /** "Site & branding" — read into "Unsaved changes in Site & branding". */
    section: string;
    /** Unsaved edits exist: the bar shows. */
    dirty: boolean;
    /** Just saved (useSavedFlash): the bar says "Saved" for a moment. */
    saved?: boolean;
    /** What "Saved" says when the section has more to tell ("…New invoices will use them."). */
    savedLabel?: string;
    /** "Logo and tagline changed" (useChangeSummary). */
    summary?: string;
    saving: boolean;
    onDiscard: () => void;
    /** The id of the section's <form>; Save is a submit button pointing at it. */
    form: string;
};

/**
 * The Store settings save bar — Figma FormActions 198:1085 as drawn in B · 38 Premium
 * (395:69479; mobile 396:70057): 64 high, bg/surface, border/default top, e4, a 10 brand dot,
 * "Unsaved changes in {section}" (Admin/body) and what changed (Admin/caption, text/tertiary),
 * then Discard (ghost) and Save changes (primary), 12 apart. Every section uses it; it is
 * portalled into the layout's slot so it spans the content width past the section nav, and is
 * not rendered when clean. A labelled region; it never takes focus. Below lg the buttons are
 * 44 high (touch targets) and Save reads "Save".
 */
export function StickySaveBar({ section, dirty, saved = false, savedLabel, summary, saving, onDiscard, form }: StickySaveBarProps) {
    const { t } = useI18n();
    const context = useOutletContext<SettingsOutletContext | undefined>();
    if (!dirty && !saved) return null;

    const touch = 'max-lg:h-11 max-lg:px-4';
    const bar = (
        <section
            aria-label={t('storeSettings.saveBar.label', { section })}
            className="sticky bottom-0 z-10 -mx-4 -mb-4 flex min-h-16 items-center gap-3 border-t border-line-default bg-surface px-4 py-2.5 shadow-e4 md:-mx-5 md:-mb-10 md:px-5 xl:-mx-6 xl:px-6"
        >
            {saved && !dirty ? (
                <p role="status" className="flex flex-1 items-center gap-2 t-admin-body text-fg-primary">
                    <CheckCircleIcon size={16} aria-hidden className="shrink-0 text-feedback-success" />
                    {savedLabel ?? t('storeSettings.saveBar.saved')}
                </p>
            ) : (
                <>
                    <p role="status" className="flex min-w-0 flex-1 items-center gap-2">
                        <span aria-hidden className="hidden size-2.5 shrink-0 rounded-full bg-brand md:block" />
                        <span className="hidden t-admin-body whitespace-nowrap text-fg-primary md:inline">{t('storeSettings.saveBar.unsaved', { section })}</span>
                        <span className="t-admin-body-strong text-fg-primary md:hidden">{t('storeSettings.saveBar.unsavedShort')}</span>
                        {summary && <span className="hidden min-w-0 truncate t-admin-caption text-fg-tertiary md:inline">{summary}</span>}
                    </p>
                    <Button variant="ghost" disabled={saving} onClick={onDiscard} className={touch}>
                        {t('storeSettings.saveBar.discard')}
                    </Button>
                    {/* Mobile says "Save" (396:70071); the accessible name stays "Save changes". */}
                    <Button type="submit" form={form} disabled={saving} aria-label={saving ? t('storeSettings.saving') : t('storeSettings.save')} className={touch}>
                        <span aria-hidden className="hidden md:inline">
                            {saving ? t('storeSettings.saving') : t('storeSettings.save')}
                        </span>
                        <span aria-hidden className="md:hidden">
                            {saving ? t('storeSettings.saving') : t('storeSettings.saveBar.saveShort')}
                        </span>
                    </Button>
                </>
            )}
        </section>
    );

    return context?.saveBarSlot ? createPortal(bar, context.saveBarSlot) : bar;
}
