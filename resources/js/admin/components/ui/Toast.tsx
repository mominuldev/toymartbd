import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CheckCircleIcon, WarningCircleIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';

type Toast = { id: number; tone: 'success' | 'danger'; message: string };
type ToastApi = { notify: (message: string, tone?: Toast['tone']) => void };

const ToastContext = createContext<ToastApi | null>(null);
let nextId = 1;

/** Figma AdminToast 203:2315. One polite live region; toasts dismiss after 5s. */
export function ToastProvider({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    const [toasts, setToasts] = useState<Toast[]>([]);

    const dismiss = useCallback((id: number) => setToasts((all) => all.filter((x) => x.id !== id)), []);
    const notify = useCallback<ToastApi['notify']>(
        (message, tone = 'success') => {
            const id = nextId++;
            setToasts((all) => [...all.slice(-2), { id, tone, message }]);
            window.setTimeout(() => dismiss(id), 5000);
        },
        [dismiss],
    );

    return (
        <ToastContext.Provider value={{ notify }}>
            {children}
            <div aria-live="polite" className="pointer-events-none fixed bottom-4 right-4 z-50 flex w-[min(100%-2rem,24rem)] flex-col gap-2">
                {toasts.map((toast) => (
                    <div key={toast.id} role="status" className="pointer-events-auto flex items-start gap-3 rounded-md border border-line-default bg-surface-raised p-3 shadow-e3">
                        {toast.tone === 'success' ? (
                            <CheckCircleIcon size={20} weight="fill" aria-hidden className="shrink-0 text-feedback-success" />
                        ) : (
                            <WarningCircleIcon size={20} weight="fill" aria-hidden className="shrink-0 text-feedback-danger" />
                        )}
                        <p className="flex-1 t-admin-body text-fg-primary">{toast.message}</p>
                        <button type="button" onClick={() => dismiss(toast.id)} aria-label={t('common.close')} className="text-fg-tertiary hover:text-fg-primary">
                            <XIcon size={16} aria-hidden />
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastApi {
    const value = useContext(ToastContext);
    if (!value) throw new Error('useToast must be used inside <ToastProvider>');
    return value;
}
