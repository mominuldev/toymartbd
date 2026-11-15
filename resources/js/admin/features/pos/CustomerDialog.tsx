import { useId, useRef, useState } from 'react';
import { MagnifyingGlassIcon, PhoneIcon, UserPlusIcon, XIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { IconButton } from '@/components/ui/IconButton';
import { Input } from '@/components/ui/Input';
import { useCustomerSearch, type PosCustomerMatch } from './api';
import { cleanPhone } from './cart';
import { closeDialog, useDialog } from './dialog';
import { isZeroAmount, phoneDisplay, takaText } from './format';
import { KEY } from './keys';

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * Customer (F9) — Figma B · 33 POS v2 351:64874. Search by name or phone (the server matches),
 * pick Walk-in, a match (open due and limit shown, as the server reports them), or add a new
 * customer by name + phone. Nothing is created here: the sale creates or finds the customer by
 * phone when it is charged (PosCustomers).
 */
export function CustomerDialog({ open, onClose, onPick }: { open: boolean; onClose: () => void; onPick: (customer: { phone: string; name: string } | null) => void }) {
    const { t } = useI18n();
    const titleId = useId();
    const searchRef = useRef<HTMLInputElement>(null);
    const [q, setQ] = useState('');
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [error, setError] = useState<string | null>(null);
    const ref = useDialog(open, () => {
        setQ('');
        setName('');
        setPhone('');
        setError(null);
        requestAnimationFrame(() => searchRef.current?.focus());
    });
    const search = useCustomerSearch(q);
    const matches = q.trim().length >= 2 ? (search.data?.data ?? []) : [];

    const pick = (customer: { phone: string; name: string } | null) => {
        onPick(customer);
        closeDialog(ref.current, onClose);
    };

    const addNew = () => {
        const valid = cleanPhone(phone);
        if (name.trim() === '') return setError(t('pos.customerDialog.nameRequired'));
        if (!valid) return setError(t('pos.customer.invalid'));
        pick({ phone: valid, name: name.trim() });
    };

    const row = 'flex min-h-14 w-full items-center gap-3 border-b border-line-default px-4 py-3 text-start last:border-0 hover:bg-subtle';

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={onClose} className="m-auto w-[min(100%-2rem,33.5rem)] rounded-xl bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <header className="flex items-center gap-3 border-b border-line-default px-6 py-5">
                <h2 id={titleId} className="flex-1 t-admin-card-title">
                    {t('pos.customerDialog.title')}
                </h2>
                <span aria-hidden className="t-admin-mono-sm text-fg-tertiary">
                    {KEY.f9}
                </span>
                <IconButton variant="outline" aria-label={t('pos.pay.close')} onClick={() => closeDialog(ref.current, onClose)}>
                    <XIcon size={18} aria-hidden />
                </IconButton>
            </header>
            <div className="flex max-h-[min(42rem,80dvh)] flex-col gap-4 overflow-y-auto px-6 py-5">
                <Input
                    ref={searchRef}
                    size="md"
                    type="search"
                    aria-label={t('pos.customerDialog.search')}
                    placeholder={t('pos.customerDialog.search')}
                    leading={<MagnifyingGlassIcon size={16} />}
                    className="h-11"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                <ul aria-label={t('pos.customerDialog.title')} className="overflow-hidden rounded-md border border-line-default">
                    <li>
                        <button type="button" className={row} onClick={() => pick(null)}>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="t-admin-body-strong">{t('pos.customerDialog.walkIn')}</span>
                                <span className="t-admin-caption text-fg-secondary">{t('pos.customerDialog.walkInHint')}</span>
                            </span>
                        </button>
                    </li>
                    {matches.map((m: PosCustomerMatch) => (
                        <li key={m.id}>
                            <button type="button" className={row} onClick={() => pick({ phone: m.phone, name: m.name })}>
                                <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-subtle t-admin-caption-strong text-fg-brand">
                                    {initials(m.name)}
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate t-admin-body-strong">{m.name}</span>
                                    <span className="truncate t-admin-caption text-fg-secondary">
                                        {t('pos.customerDialog.meta', { phone: phoneDisplay(m.phone), count: m.orders_count, limit: takaText(m.due_limit) })}
                                    </span>
                                </span>
                                {!isZeroAmount(m.open_due) && <span className="t-admin-num text-feedback-warning">{t('pos.customerDialog.due', { amount: takaText(m.open_due) })}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
                {q.trim().length > 0 && q.trim().length < 2 && <p className="t-admin-caption text-fg-tertiary">{t('pos.customerDialog.short')}</p>}
                {q.trim().length >= 2 && search.data && matches.length === 0 && <p className="t-admin-caption text-fg-secondary">{t('pos.customerDialog.none')}</p>}

                <form
                    className="flex flex-col gap-2.5 rounded-md border border-line-default bg-subtle p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        addNew();
                    }}
                >
                    <h3 className="t-admin-body-strong">{t('pos.customerDialog.new')}</h3>
                    <label className="flex flex-col gap-1.5">
                        <span className="t-admin-caption-strong text-fg-secondary">{t('pos.customerDialog.name')}</span>
                        <Input value={name} maxLength={160} className="h-11" onChange={(e) => setName(e.target.value)} />
                    </label>
                    <label className="flex flex-col gap-1.5">
                        <span className="t-admin-caption-strong text-fg-secondary">{t('pos.customerDialog.phone')}</span>
                        <Input
                            leading={<PhoneIcon size={16} />}
                            inputMode="tel"
                            placeholder={t('pos.customer.placeholder')}
                            value={phone}
                            className="h-11"
                            aria-invalid={phone.trim() !== '' && !cleanPhone(phone)}
                            onChange={(e) => setPhone(e.target.value)}
                        />
                    </label>
                    <p className={`t-admin-caption ${error ? 'text-feedback-danger' : 'text-fg-tertiary'}`} role={error ? 'alert' : undefined}>
                        {error ?? t('pos.customerDialog.newHint')}
                    </p>
                    <Button type="submit" size="lg">
                        <UserPlusIcon size={18} aria-hidden />
                        {t('pos.customerDialog.add')}
                    </Button>
                </form>
            </div>
        </dialog>
    );
}
