import { useRef, useState, type FormEvent } from 'react';
import { ArrowRightIcon, PlusIcon, UploadSimpleIcon } from '@phosphor-icons/react';
import { saveRedirectRequestSchema } from '@toymart/api-types/zod';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTableFooter, dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Field, controlHeight, inputClass, monoInputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { Select } from '@/components/ui/Select';
import { TabButton, TabList } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import {
    useDeleteRedirect,
    useImportRedirects,
    useRedirects,
    useSaveRedirect,
    useSlugRedirects,
    type ImportResult,
    type Redirect,
    type RedirectInput,
    type SlugRedirect,
} from './api';
import { ModalDialog, RowMenu, RowMenuItem, formatCount } from './parts';

type View = 'all' | 'automatic' | 'manual';
type FormErrors = Partial<Record<'from_path' | 'to_path' | 'status_code' | 'note', string>>;

/** Client check with the API's own schema (rule 27); the server's conflict/loop rules come back as 422s. */
function check(input: RedirectInput): FormErrors {
    const result = saveRedirectRequestSchema.safeParse(input);
    if (result.success) return {};
    const errors: FormErrors = {};
    for (const issue of result.error.issues) {
        const key = issue.path[0] as keyof FormErrors;
        errors[key] ??= issue.message;
    }
    return errors;
}

function serverErrors(error: unknown): FormErrors | null {
    if (!(error instanceof ApiError) || error.status !== 422) return null;
    return {
        from_path: error.fieldError('from_path'),
        to_path: error.fieldError('to_path'),
        status_code: error.fieldError('status_code'),
        note: error.fieldError('note'),
    };
}

/**
 * SEO → Redirects (Figma "B · 30 Redirects" 241:46849, S-04). Manual 301/302s with hit
 * counts, the automatic 301s every published slug change adds, quick add, an edit dialog
 * and CSV import. Loops and chains are refused or collapsed by the server
 * (docs/api/seo.md §2), so the client only shows what it says.
 */
export function RedirectsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const editable = can(me, 'seo.manage');
    const [view, setView] = useState<View>('all');
    const [manualPage, setManualPage] = useState(1);
    const [autoPage, setAutoPage] = useState(1);
    const manual = useRedirects(manualPage, '');
    const automatic = useSlugRedirects(autoPage);
    const [editing, setEditing] = useState<Redirect | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Redirect | null>(null);
    const [importing, setImporting] = useState(false);
    const remove = useDeleteRedirect();

    const manualTotal = manual.data?.meta.total;
    const autoTotal = automatic.data?.meta.total;
    const error = manual.error ?? automatic.error;

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.grow') }, { label: t('seo.crumb'), to: '/seo' }, { label: t('seo.redirects.title') }]}
                title={t('seo.redirects.title')}
                subtitle={t('seo.redirects.subtitle')}
                actions={
                    editable && (
                        <>
                            <Button variant="outline" onClick={() => setImporting(true)}>
                                <UploadSimpleIcon size={16} aria-hidden />
                                {t('seo.redirects.import')}
                            </Button>
                            <Button onClick={() => setEditing('new')}>
                                <PlusIcon size={16} aria-hidden />
                                {t('seo.redirects.add')}
                            </Button>
                        </>
                    )
                }
            />

            <TabList label={t('seo.redirects.tabs')}>
                <TabButton active={view === 'all'} label={t('seo.redirects.tab.all')} count={manualTotal !== undefined && autoTotal !== undefined ? manualTotal + autoTotal : undefined} onClick={() => setView('all')} />
                <TabButton active={view === 'automatic'} label={t('seo.redirects.tab.automatic')} count={autoTotal} onClick={() => setView('automatic')} />
                <TabButton active={view === 'manual'} label={t('seo.redirects.tab.manual')} count={manualTotal} onClick={() => setView('manual')} />
            </TabList>

            {editable && view !== 'automatic' && <QuickAdd />}

            {error ? (
                <QueryError error={error} onRetry={() => void (manual.error ? manual.refetch() : automatic.refetch())} />
            ) : (
                <div className={dataTable.card}>
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th scope="col" className={dataTable.th}>{t('seo.redirects.col.from')}</th>
                                    <th scope="col" className={dataTable.th}>{t('seo.redirects.col.to')}</th>
                                    <th scope="col" className={dataTable.th}>{t('seo.redirects.col.type')}</th>
                                    <th scope="col" className={dataTable.th}>{t('seo.redirects.col.source')}</th>
                                    <th scope="col" className={`${dataTable.th} text-end`}>{t('seo.redirects.col.hits')}</th>
                                    <th scope="col" className={dataTable.th}>
                                        <span className="sr-only">{t('common.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {view !== 'automatic' &&
                                    (manual.data?.data ?? []).map((row) => (
                                        <tr key={`m-${row.id}`} className={dataTable.row}>
                                            <td className={`${dataTable.td} t-admin-mono-sm break-all`}>{row.from_path}</td>
                                            <td className={`${dataTable.td} t-admin-mono-sm break-all`}>{row.to_path}</td>
                                            <td className={dataTable.td}>
                                                <CodeChip code={row.status_code} />
                                            </td>
                                            <td className={`${dataTable.td} t-admin-caption text-fg-secondary`}>
                                                {[row.source === 'import' ? t('seo.redirects.source.import') : t('seo.redirects.source.manual'), row.note, row.is_active ? null : t('seo.redirects.inactive')].filter(Boolean).join(' · ')}
                                            </td>
                                            <td className={`${dataTable.td} text-end`}>
                                                <span className="t-admin-body-strong tabular" title={row.last_hit_at ? t('seo.redirects.lastHit', { date: formatDhakaDate(row.last_hit_at, locale) }) : undefined}>
                                                    {formatCount(row.hit_count)}
                                                </span>
                                            </td>
                                            <td className={`${dataTable.td} w-12`}>
                                                {editable && (
                                                    <RowMenu label={t('seo.redirects.rowActions', { path: row.from_path })}>
                                                        <RowMenuItem onClick={() => setEditing(row)}>{t('common.edit')}</RowMenuItem>
                                                        <RowMenuItem onClick={() => setDeleting(row)} danger>
                                                            {t('common.delete')}
                                                        </RowMenuItem>
                                                    </RowMenu>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                {view !== 'manual' && (automatic.data?.data ?? []).map((row) => <AutoRow key={`a-${row.id}`} row={row} />)}
                            </tbody>
                        </table>
                    </div>
                    {isEmpty(view, manual.data?.data.length, automatic.data?.data.length) && (
                        <EmptyState className="border-t border-line-default" title={t('seo.redirects.emptyTitle')} body={t('seo.redirects.emptyBody')} />
                    )}
                    {view === 'manual' && manual.data && <Pager page={manual.data.meta.current_page} last={manual.data.meta.last_page} total={manual.data.meta.total} onPage={setManualPage} />}
                    {view === 'automatic' && automatic.data && <Pager page={automatic.data.meta.current_page} last={automatic.data.meta.last_page} total={automatic.data.meta.total} onPage={setAutoPage} />}
                </div>
            )}

            {editing !== null && <RedirectDialog redirect={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {importing && <ImportDialog onClose={() => setImporting(false)} />}

            <ConfirmDialog
                open={deleting !== null}
                title={t('seo.redirects.deleteTitle', { path: deleting?.from_path ?? '' })}
                body={t('seo.redirects.deleteBody')}
                confirmLabel={t('common.delete')}
                pending={remove.isPending}
                onClose={() => setDeleting(null)}
                onConfirm={() =>
                    deleting &&
                    remove.mutate(deleting.id, {
                        onSuccess: () => {
                            setDeleting(null);
                            notify(t('seo.redirects.deleted'));
                        },
                        onError: (e) => notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger'),
                    })
                }
            />
        </section>
    );
}

function isEmpty(view: View, manual = 0, automatic = 0): boolean {
    if (view === 'manual') return manual === 0;
    if (view === 'automatic') return automatic === 0;
    return manual + automatic === 0;
}

function CodeChip({ code }: { code: number }) {
    return <span className="inline-flex h-6 items-center rounded-xs border border-line-default bg-subtle px-2 t-admin-caption-strong tabular text-fg-primary">{code}</span>;
}

function AutoRow({ row }: { row: SlugRedirect }) {
    const { t } = useI18n();
    return (
        <tr className={dataTable.row}>
            <td className={`${dataTable.td} t-admin-mono-sm break-all`}>{row.from_path ?? row.old_slug}</td>
            <td className={`${dataTable.td} t-admin-mono-sm break-all`}>{row.to_path ?? row.new_slug}</td>
            <td className={dataTable.td}>
                <CodeChip code={row.status_code} />
            </td>
            <td className={`${dataTable.td} t-admin-caption text-fg-secondary`}>{[t('seo.redirects.source.auto'), row.entity_name].filter(Boolean).join(' · ')}</td>
            <td className={`${dataTable.td} text-end t-admin-caption text-fg-tertiary`}>{t('seo.redirects.hitsNotCounted')}</td>
            <td className={`${dataTable.td} w-12`} />
        </tr>
    );
}

function Pager({ page, last, total, onPage }: { page: number; last: number; total: number; onPage: (page: number) => void }) {
    const { t } = useI18n();
    return (
        <DataTableFooter summary={t('seo.redirects.pageSummary', { page, last, total: formatCount(total) })}>
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                {t('seo.redirects.prev')}
            </Button>
            <Button variant="outline" size="sm" disabled={page >= last} onClick={() => onPage(page + 1)}>
                {t('seo.redirects.next')}
            </Button>
        </DataTableFooter>
    );
}

/** The inline "from → to · type · Add" strip above the table (241:46849). */
function QuickAdd() {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveRedirect();
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [code, setCode] = useState<'301' | '302'>('301');
    const [errors, setErrors] = useState<FormErrors>({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const input: RedirectInput = { from_path: from.trim(), to_path: to.trim(), status_code: code };
        const local = check(input);
        setErrors(local);
        if (Object.keys(local).length > 0) return;
        save.mutate(
            { id: null, input },
            {
                onSuccess: () => {
                    setFrom('');
                    setTo('');
                    notify(t('seo.redirects.created'));
                },
                onError: (error) => {
                    const fields = serverErrors(error);
                    if (fields) setErrors(fields);
                    else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                },
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate aria-label={t('seo.redirects.quickAdd')} className="flex flex-col gap-2 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <div className="flex flex-col gap-3 md:flex-row md:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <input
                        aria-label={t('seo.redirects.field.from')}
                        aria-invalid={errors.from_path ? true : undefined}
                        placeholder={t('seo.redirects.field.fromPlaceholder')}
                        spellCheck={false}
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className={`${controlHeight.md} ${inputClass}`}
                    />
                    {errors.from_path && <p className="t-admin-caption text-feedback-danger">{errors.from_path}</p>}
                </div>
                <ArrowRightIcon size={16} aria-hidden className="hidden shrink-0 self-center text-fg-tertiary md:mt-3 md:block md:self-start" />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <input
                        aria-label={t('seo.redirects.field.to')}
                        aria-invalid={errors.to_path ? true : undefined}
                        placeholder={t('seo.redirects.field.toPlaceholder')}
                        spellCheck={false}
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className={`${controlHeight.md} ${inputClass}`}
                    />
                    {errors.to_path && <p className="t-admin-caption text-feedback-danger">{errors.to_path}</p>}
                </div>
                <Select aria-label={t('seo.redirects.field.type')} value={code} onChange={(e) => setCode(e.target.value === '302' ? '302' : '301')} className="md:w-37">
                    <option value="301">{t('seo.redirects.type.301')}</option>
                    <option value="302">{t('seo.redirects.type.302')}</option>
                </Select>
                <Button type="submit" disabled={save.isPending}>
                    {t('common.add')}
                </Button>
            </div>
        </form>
    );
}

function RedirectDialog({ redirect, onClose }: { redirect: Redirect | null; onClose: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const save = useSaveRedirect();
    const [from, setFrom] = useState(redirect?.from_path ?? '');
    const [to, setTo] = useState(redirect?.to_path ?? '');
    const [code, setCode] = useState<'301' | '302'>(redirect?.status_code === 302 ? '302' : '301');
    const [active, setActive] = useState(redirect?.is_active ?? true);
    const [note, setNote] = useState(redirect?.note ?? '');
    const [errors, setErrors] = useState<FormErrors>({});
    const first = useRef<HTMLInputElement>(null);

    const submit = () => {
        const input: RedirectInput = { from_path: from.trim(), to_path: to.trim(), status_code: code, is_active: active, note: note.trim() === '' ? null : note.trim() };
        const local = check(input);
        setErrors(local);
        if (Object.keys(local).length > 0) return;
        save.mutate(
            { id: redirect?.id ?? null, input },
            {
                onSuccess: () => {
                    notify(t(redirect ? 'seo.redirects.saved' : 'seo.redirects.created'));
                    onClose();
                },
                onError: (error) => {
                    const fields = serverErrors(error);
                    if (fields) setErrors(fields);
                    else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
                },
            },
        );
    };

    return (
        <ModalDialog
            open
            onClose={onClose}
            initialFocus={first}
            width="lg"
            title={redirect ? t('seo.redirects.editTitle') : t('seo.redirects.add')}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={save.isPending}>
                        {save.isPending ? t('common.saving') : t('common.save')}
                    </Button>
                </>
            }
        >
            <div className="flex w-full flex-col gap-3.5">
                <p className="t-admin-caption text-fg-secondary">{t('seo.redirects.dialogHint')}</p>
                <Field label={t('seo.redirects.field.from')} error={errors.from_path}>
                    {({ id, describedBy, invalid }) => (
                        <input ref={first} id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} spellCheck={false} value={from} onChange={(e) => setFrom(e.target.value)} className={`${controlHeight.md} ${monoInputClass}`} />
                    )}
                </Field>
                <Field label={t('seo.redirects.field.to')} error={errors.to_path}>
                    {({ id, describedBy, invalid }) => (
                        <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} spellCheck={false} value={to} onChange={(e) => setTo(e.target.value)} className={`${controlHeight.md} ${monoInputClass}`} />
                    )}
                </Field>
                <Field label={t('seo.redirects.field.type')} error={errors.status_code}>
                    {({ id, describedBy }) => (
                        <Select id={id} aria-describedby={describedBy} value={code} onChange={(e) => setCode(e.target.value === '302' ? '302' : '301')}>
                            <option value="301">{t('seo.redirects.type.301')}</option>
                            <option value="302">{t('seo.redirects.type.302')}</option>
                        </Select>
                    )}
                </Field>
                <Field label={t('seo.redirects.field.note')} error={errors.note} optional>
                    {({ id, describedBy, invalid }) => (
                        <input id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} value={note} onChange={(e) => setNote(e.target.value)} className={`${controlHeight.md} ${inputClass}`} />
                    )}
                </Field>
                <label className="flex min-h-10 items-center gap-3 t-admin-body text-fg-primary">
                    <input type="checkbox" className="size-4 accent-brand" checked={active} onChange={(e) => setActive(e.target.checked)} />
                    {t('seo.redirects.field.active')}
                </label>
            </div>
        </ModalDialog>
    );
}

function ImportDialog({ onClose }: { onClose: () => void }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const run = useImportRedirects();
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    const submit = () => {
        if (!file) return;
        setProblem(null);
        run.mutate(file, {
            onSuccess: (outcome) => {
                setResult(outcome);
                if (outcome.errors.length === 0) {
                    notify(t('seo.redirects.imported', { created: outcome.created, updated: outcome.updated }));
                    onClose();
                }
            },
            onError: (error) => {
                setResult(null);
                setProblem(error instanceof ApiError ? (error.fieldError('file') ?? error.problem.title) : t('error.title'));
            },
        });
    };

    return (
        <ModalDialog
            open
            onClose={onClose}
            width="lg"
            title={t('seo.redirects.importTitle')}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={!file || run.isPending}>
                        {run.isPending ? t('seo.redirects.importing') : t('seo.redirects.importRun')}
                    </Button>
                </>
            }
        >
            <div className="flex w-full flex-col gap-3">
                <p className="t-admin-caption text-fg-secondary">{t('seo.redirects.importHint')}</p>
                <pre className="rounded-sm bg-subtle p-3 t-admin-mono-sm text-fg-secondary">{'from_path,to_path,status_code,note\n/old-page,/toys,301,'}</pre>
                <Field label={t('seo.redirects.importFile')} error={problem ?? undefined}>
                    {({ id, describedBy, invalid }) => (
                        <input id={id} type="file" accept=".csv,text/csv" aria-describedby={describedBy} aria-invalid={invalid || undefined} onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="t-admin-body text-fg-primary" />
                    )}
                </Field>
                {result && result.errors.length > 0 && (
                    <div role="alert" className="flex flex-col gap-1.5 rounded-sm border border-line-default bg-subtle p-3">
                        <p className="t-admin-body-strong text-feedback-danger">{t('seo.redirects.importFailed', { count: result.errors.length })}</p>
                        <ul className="flex max-h-48 flex-col gap-1 overflow-auto">
                            {result.errors.map((e, i) => (
                                <li key={`${e.line}-${e.field}-${i}`} className="t-admin-caption text-fg-primary">
                                    {t('seo.redirects.importLine', { line: e.line, message: e.message })}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </ModalDialog>
    );
}
