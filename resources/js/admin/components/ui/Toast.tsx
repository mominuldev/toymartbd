import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { CheckCircleIcon, InfoIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { IconButton } from './IconButton';

type Tone = 'success' | 'danger' | 'info';
type Toast = { id: number; tone: Tone; message: string };
type ToastApi = { notify: (message: string, tone?: Tone) => void };

const ToastContext = createContext<ToastApi | null>(null);
let nextId = 1;

const looks = {
    success: { icon: CheckCircleIcon, tint: 'text-feedback-success' },
    danger: { icon: WarningCircleIcon, tint: 'text-feedback-danger' },
    info: { icon: InfoIcon, tint: 'text-feedback-info' },
} as const;

/**
 * Figma AdminToast 203:2315 — bottom-right, 420 wide, surface-raised, radius/sm, e3, a border/default
 * hairline all round (no coloured left edge, owner 2026-11-16), 18px feedback-tinted icon, Admin/body text, ghost close. One polite live
 * region; success and info dismiss after 5s, errors persist until closed.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    const [toasts, setToasts] = useState<Toast[]>([]);
    // Pending auto-dismiss timers, cleared on unmount: a timer that outlives the provider would
    // set state on an unmounted tree (and, in tests, after the DOM is torn down).
    const timers = useRef(new Set<number>());
    useEffect(() => {
        const pending = timers.current;
        return () => {
            pending.forEach((timer) => window.clearTimeout(timer));
            pending.clear();
        };
    }, []);

    const dismiss = useCallback((id: number) => setToasts((all) => all.filter((x) => x.id !== id)), []);
    const notify = useCallback<ToastApi['notify']>(
        (message, tone = 'success') => {
            const id = nextId++;
            setToasts((all) => [...all.slice(-2), { id, tone, message }]);
            if (tone !== 'danger') {
                const timer = window.setTimeout(() => {
                    timers.current.delete(timer);
                    dismiss(id);
                }, 5000);
                timers.current.add(timer);
            }
        },
        [dismiss],
    );

    return (
        <ToastContext.Provider value={{ notify }}>
            {children}
            <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(100%-2rem,26.25rem)] flex-col gap-2">
                {toasts.map((toast) => {
                    const { icon: Icon, tint } = looks[toast.tone];
                    return (
                        <div
                            key={toast.id}
                            role="status"
                            className={`pointer-events-auto flex items-center gap-2.5 rounded-sm border border-line-default bg-surface-raised py-3 ps-3.5 pe-2 shadow-e3`}
                        >
                            <Icon size={18} aria-hidden className={`shrink-0 ${tint}`} />
                            <p className="flex-1 t-admin-body text-fg-primary">{toast.message}</p>
                            <IconButton size="sm" aria-label={t('common.close')} onClick={() => dismiss(toast.id)}>
                                <XIcon size={16} aria-hidden />
                            </IconButton>
                        </div>
                    );
                })}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const value = useContext(ToastContext);
    if (!value) throw new Error('useToast must be used inside <ToastProvider>');
    return value;
}
