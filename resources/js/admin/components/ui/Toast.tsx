import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CheckCircleIcon, InfoIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { IconButton } from './IconButton';

type Tone = 'success' | 'danger' | 'info';
type Toast = { id: number; tone: Tone; message: string };
type ToastApi = { notify: (message: string, tone?: Tone) => void };

const ToastContext = createContext<ToastApi | null>(null);
let nextId = 1;

const looks = {
    success: { icon: CheckCircleIcon, edge: 'border-feedback-success', tint: 'text-feedback-success' },
    danger: { icon: WarningCircleIcon, edge: 'border-feedback-danger', tint: 'text-feedback-danger' },
    info: { icon: InfoIcon, edge: 'border-feedback-info', tint: 'text-feedback-info' },
} as const;

/**
 * Figma AdminToast 203:2315 — bottom-right, 420 wide, surface-raised, radius/sm, e3, a 3px
 * feedback-coloured left edge, 18px icon, Admin/body text, ghost close. One polite live
 * region; success and info dismiss after 5s, errors persist until closed.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    const [toasts, setToasts] = useState<Toast[]>([]);

    const dismiss = useCallback((id: number) => setToasts((all) => all.filter((x) => x.id !== id)), []);
    const notify = useCallback<ToastApi['notify']>(
        (message, tone = 'success') => {
            const id = nextId++;
            setToasts((all) => [...all.slice(-2), { id, tone, message }]);
            if (tone !== 'danger') window.setTimeout(() => dismiss(id), 5000);
        },
        [dismiss],
    );

    return (
        <ToastContext.Provider value={{ notify }}>
            {children}
            <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(100%-2rem,26.25rem)] flex-col gap-2">
                {toasts.map((toast) => {
                    const { icon: Icon, edge, tint } = looks[toast.tone];
                    return (
                        <div
                            key={toast.id}
                            role="status"
                            className={`pointer-events-auto flex items-center gap-2.5 rounded-sm border-l-3 bg-surface-raised py-3 ps-3.5 pe-2 shadow-e3 ${edge}`}
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
