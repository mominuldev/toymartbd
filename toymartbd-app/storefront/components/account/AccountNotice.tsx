import { Icon } from '@/components/icons/Icon';

// The one-shot notice after a form post: success in mint, error in the danger tint. Announced politely.
export function AccountNotice({ kind, text }: { kind: 'error' | 'success'; text: string | null }) {
    if (!text) return null;
    const error = kind === 'error';
    return (
        <p
            role={error ? 'alert' : 'status'}
            className={`t-body-sm flex items-start gap-2 rounded-sm px-3 py-2.5 ${error ? 'bg-danger-subtle text-fg-primary' : 'bg-success-subtle text-fg-primary'}`}
        >
            <Icon name={error ? 'warningCircle' : 'checkCircle'} className={`size-icon-sm mt-0.5 ${error ? 'text-feedback-danger' : 'text-status-in-stock'}`} />
            <span>{text}</span>
        </p>
    );
}
