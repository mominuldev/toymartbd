import { useId, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { closePosSessionRequestSchema } from '@toymart/api-types/zod';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { ApiError } from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { Field } from '@/components/ui/Field';
import { Input } from '@/components/ui/Input';
import { closeDialog, useDialog } from './dialog';
import { posKeys, useCloseSession, type PosSession } from './api';
import { isZeroAmount, TAKA, takaText } from './format';

/**
 * Close drawer: what the drawer should hold (the server's expected cash = float + cash kept),
 * the takings by tender, then the counted cash. The answer — expected, counted, over / short —
 * is the server's, shown before the register returns to "Open a drawer". No Figma frame;
 * built from ConfirmDialog 203:1387's frame like the other admin dialogs.
 */
export function CloseDrawerDialog({ open, session, onClose }: { open: boolean; session: PosSession; onClose: () => void }) {
    const { t } = useI18n();
    const client = useQueryClient();
    const titleId = useId();
    const close = useCloseSession(session.id);
    const [counted, setCounted] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [result, setResult] = useState<PosSession | null>(null);
    const ref = useDialog(open, () => {
        setCounted('');
        setNote('');
        setError(undefined);
        setResult(null);
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const parsed = closePosSessionRequestSchema.safeParse({ counted_cash: counted.trim(), note: note.trim() || null });
        if (!parsed.success) return setError(t('pos.close.invalid'));
        close.mutate(parsed.data, {
            onSuccess: (closed) => setResult(closed),
            onError: (e) => setError(e instanceof ApiError ? (e.problem.errors?.[0]?.message ?? e.problem.detail ?? e.problem.title) : t('pos.error.generic')),
        });
    };

    const finish = () => {
        onClose();
        if (result) void client.invalidateQueries({ queryKey: posKeys.all });
    };

    const diff = result?.over_short ?? '0.00';
    const diffText = isZeroAmount(diff) ? t('pos.close.exact') : diff.startsWith('-') ? t('pos.close.short', { amount: takaText(diff.slice(1)) }) : t('pos.close.over', { amount: takaText(diff) });

    return (
        <dialog ref={ref} aria-labelledby={titleId} onClose={finish} className="m-auto w-[min(100%-2rem,28rem)] rounded-lg bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-overlay">
            <form onSubmit={submit} className="flex flex-col gap-4 p-6">
                <h2 id={titleId} className="t-admin-section">
                    {t('pos.close.title')}
                </h2>
                <dl className="flex flex-col gap-2 rounded-md bg-subtle p-4 t-admin-body">
                    <div className="flex justify-between">
                        <dt className="text-fg-secondary">{t('pos.close.sales', { count: session.totals.sales_count })}</dt>
                        <dd>{takaText(session.totals.sales_total)}</dd>
                    </div>
                    {session.totals.by_method.map((m) => (
                        <div key={m.method} className="flex justify-between">
                            <dt className="text-fg-secondary">{t(`pos.tender.${m.method}` as MessageKey)}</dt>
                            <dd>{takaText(m.amount)}</dd>
                        </div>
                    ))}
                    <div className="flex justify-between border-t border-line-default pt-2 t-admin-body-strong">
                        <dt>{t('pos.close.expected')}</dt>
                        <dd>{takaText(result?.expected_cash ?? session.totals.expected_cash)}</dd>
                    </div>
                </dl>
                {result ? (
                    <p role="status" className="t-admin-body-strong">
                        {t('pos.close.result', { counted: takaText(result.counted_cash ?? '0'), diff: diffText })}
                    </p>
                ) : (
                    <>
                        <Field label={t('pos.close.counted')} hint={t('pos.close.countedHint')} error={error}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} aria-describedby={describedBy} aria-invalid={invalid} prefix={TAKA} inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} />
                            )}
                        </Field>
                        <Field label={t('pos.close.note')} optional>
                            {({ id }) => <Input id={id} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
                        </Field>
                    </>
                )}
                <div className="flex justify-end gap-2">
                    {result ? (
                        <Button onClick={() => closeDialog(ref.current, finish)}>{t('pos.close.done')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={() => closeDialog(ref.current, finish)}>
                                {t('pos.close.cancel')}
                            </Button>
                            <Button type="submit" disabled={close.isPending}>
                                {t('pos.close.submit')}
                            </Button>
                        </>
                    )}
                </div>
            </form>
        </dialog>
    );
}
