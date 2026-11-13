import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router';
import { ArrowClockwiseIcon, ArrowsLeftRightIcon, CheckCircleIcon, FileTextIcon } from '@phosphor-icons/react';
import { ApiError } from '@/lib/api';
import { formatDhakaDate } from '@/lib/format';
import { useI18n } from '@/i18n/I18nProvider';
import { can, useMe } from '@/features/auth/api';
import { ContentHeader } from '@/components/shell/ContentHeader';
import { Button, buttonClass } from '@/components/ui/Button';
import { dataTable } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { monoInputClass } from '@/components/ui/Field';
import { QueryError } from '@/components/ui/QueryError';
import { useToast } from '@/components/ui/Toast';
import { fieldErrors, useRegenerateSitemap, useRobots, useSaveRobots, useSitemapStatus, type SitemapStatus } from './api';
import { formatCount } from './parts';

/** The sitemap file a partition is served as — a file name, not translatable copy. */
const sitemapFileName = (partition: string): string => `sitemap-${partition}.xml`;

/** File names, not copy. */
const ROBOTS_FILE = 'robots.txt';
const INDEX_FILE = 'sitemap.xml';

/**
 * SEO overview (Figma "B · 29 SEO" 241:46427). Only the "Sitemaps & crawling" card has data
 * today; site health and Search Console need the Search Console connection (Phase 10), so
 * they render the explicit "not connected" state — never a zero.
 */
export function SeoOverviewPage() {
    const { t, locale } = useI18n();
    const sitemap = useSitemapStatus();
    const robots = useRobots();

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.grow') }, { label: t('seo.crumb') }]}
                title={t('seo.title')}
                subtitle={t('seo.subtitle')}
                actions={
                    <>
                        <Link to="/seo/redirects" className={buttonClass('outline')}>
                            <ArrowsLeftRightIcon size={16} aria-hidden />
                            {t('seo.redirects.title')}
                        </Link>
                        <Link to="/seo/sitemap" className={buttonClass('outline')}>
                            <FileTextIcon size={16} aria-hidden />
                            {t('seo.sitemapRobots')}
                        </Link>
                    </>
                }
            />

            <div className="rounded-md border border-line-default bg-surface shadow-e1">
                <EmptyState reason="notConnected" title={t('seo.console.title')} body={t('seo.console.body')} />
            </div>

            <div className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1">
                <h2 className="t-admin-section text-fg-primary">{t('seo.crawling.title')}</h2>
                {sitemap.isError ? (
                    <QueryError error={sitemap.error} onRetry={() => void sitemap.refetch()} />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {partitions(sitemap.data).map((p) => (
                            <li key={p.partition} className="flex min-w-0 flex-col gap-1 rounded-sm bg-subtle p-3">
                                <p className="truncate t-admin-mono text-fg-primary">{sitemapFileName(p.partition)}</p>
                                <p className="t-admin-caption tabular text-fg-secondary">{t('seo.crawling.urls', { count: formatCount(p.urls), locales: p.locales })}</p>
                                {sitemap.data?.generated_at && (
                                    <p className="flex items-center gap-1 t-admin-caption text-fg-secondary">
                                        <CheckCircleIcon size={14} aria-hidden className="text-feedback-success" />
                                        {t('seo.crawling.built', { date: formatDhakaDate(sitemap.data.generated_at, locale) })}
                                    </p>
                                )}
                            </li>
                        ))}
                        <li className="flex min-w-0 flex-col gap-1 rounded-sm bg-subtle p-3">
                            <p className="t-admin-mono text-fg-primary">{ROBOTS_FILE}</p>
                            <p className="t-admin-caption text-fg-secondary">{robots.data ? (robots.data.is_default ? t('seo.crawling.robotsDefault') : t('seo.crawling.robotsEdited')) : t('common.loading')}</p>
                            <Link to="/seo/sitemap" className="t-admin-caption text-feedback-info hover:underline">
                                {t('seo.crawling.robotsEdit')}
                            </Link>
                        </li>
                    </ul>
                )}
                {sitemap.data && !sitemap.data.generated_at && <p className="t-admin-caption text-fg-secondary">{t('seo.sitemap.never')}</p>}
            </div>
        </section>
    );
}

/** Figma's tile order; anything else follows, the static pages last. */
const ORDER = ['products', 'categories', 'brands', 'pages', 'blog', 'guides', 'static'];

/** Files grouped as Google sees them: one sitemap per type, in two languages. */
function partitions(status?: SitemapStatus): { partition: string; urls: number; locales: number }[] {
    const map = new Map<string, { urls: number; locales: Set<string> }>();
    for (const file of status?.files ?? []) {
        const entry = map.get(file.partition) ?? { urls: 0, locales: new Set<string>() };
        if (file.locale === 'en') entry.urls += file.urls;
        entry.locales.add(file.locale);
        map.set(file.partition, entry);
    }
    const rank = (p: string) => {
        const i = ORDER.indexOf(p);
        return i === -1 ? ORDER.length : i;
    };
    return [...map].map(([partition, v]) => ({ partition, urls: v.urls, locales: v.locales.size })).sort((a, b) => rank(a.partition) - rank(b.partition));
}

/**
 * SEO → Sitemap & robots.txt (S-04; no own frame — the "Sitemaps & crawling" card of
 * 241:46427 opened up). Sitemap build status and on-demand rebuild; the robots.txt editor,
 * validated line by line on the server, with exactly what crawlers receive.
 */
export function SitemapRobotsPage() {
    const { t, locale } = useI18n();
    const { data: me } = useMe();
    const { notify } = useToast();
    const editable = can(me, 'seo.manage');
    const sitemap = useSitemapStatus();
    const regenerate = useRegenerateSitemap();

    return (
        <section className="flex flex-col gap-6">
            <ContentHeader
                breadcrumb={[{ label: t('nav.group.grow') }, { label: t('seo.crumb'), to: '/seo' }, { label: t('seo.sitemapRobots') }]}
                title={t('seo.sitemapRobots')}
                subtitle={t('seo.sitemap.subtitle')}
                actions={
                    editable && (
                        <Button
                            onClick={() =>
                                regenerate.mutate(undefined, {
                                    onSuccess: () => notify(t('seo.sitemap.queued')),
                                    onError: (e) => notify(e instanceof ApiError ? e.problem.title : t('error.title'), 'danger'),
                                })
                            }
                            disabled={regenerate.isPending}
                        >
                            <ArrowClockwiseIcon size={16} aria-hidden />
                            {t('seo.sitemap.regenerate')}
                        </Button>
                    )
                }
            />

            <div className={dataTable.card}>
                <div className="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-5 pb-4">
                    <h2 className="t-admin-section text-fg-primary">{t('seo.sitemap.title')}</h2>
                    {sitemap.data && (
                        <p className="t-admin-caption tabular text-fg-secondary">
                            {sitemap.data.generated_at
                                ? t('seo.sitemap.summary', { date: formatDhakaDate(sitemap.data.generated_at, locale), total: formatCount(sitemap.data.total_urls), files: sitemap.data.files.length })
                                : t('seo.sitemap.never')}
                        </p>
                    )}
                </div>
                {sitemap.isError ? (
                    <QueryError error={sitemap.error} onRetry={() => void sitemap.refetch()} />
                ) : sitemap.data && sitemap.data.files.length === 0 ? (
                    <EmptyState className="border-t border-line-default" title={t('seo.sitemap.emptyTitle')} body={t('seo.sitemap.emptyBody')} />
                ) : (
                    <div className="overflow-x-auto">
                        <table className={dataTable.table}>
                            <thead className={dataTable.head}>
                                <tr>
                                    <th scope="col" className={dataTable.th}>{t('seo.sitemap.col.file')}</th>
                                    <th scope="col" className={dataTable.th}>{t('seo.sitemap.col.language')}</th>
                                    <th scope="col" className={`${dataTable.th} text-end`}>{t('seo.sitemap.col.urls')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className={dataTable.row}>
                                    <td className={`${dataTable.td} t-admin-mono-sm`}>
                                        <a href={sitemap.data?.sitemap_url} target="_blank" rel="noreferrer" className="hover:underline">
                                            {INDEX_FILE}
                                        </a>
                                    </td>
                                    <td className={`${dataTable.td} t-admin-caption text-fg-secondary`}>{t('seo.sitemap.index')}</td>
                                    <td className={`${dataTable.td} text-end t-admin-body tabular`}>{formatCount(sitemap.data?.files.length ?? 0)}</td>
                                </tr>
                                {(sitemap.data?.files ?? []).map((file) => (
                                    <tr key={file.file} className={dataTable.row}>
                                        <td className={`${dataTable.td} t-admin-mono-sm`}>
                                            <a href={file.url} target="_blank" rel="noreferrer" className="hover:underline">
                                                {file.file}
                                            </a>
                                        </td>
                                        <td className={`${dataTable.td} t-admin-caption text-fg-secondary`}>{file.locale === 'bn' ? t('seo.sitemap.bn') : t('seo.sitemap.en')}</td>
                                        <td className={`${dataTable.td} text-end t-admin-body tabular`}>{formatCount(file.urls)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <RobotsEditor editable={editable} />
        </section>
    );
}

/** The robots.txt editor: server validation per line, then exactly what is served. */
export function RobotsEditor({ editable }: { editable: boolean }) {
    const { t } = useI18n();
    const { notify } = useToast();
    const id = useId();
    const robots = useRobots();
    const save = useSaveRobots();
    const [content, setContent] = useState('');
    const [errors, setErrors] = useState<string[]>([]);

    useEffect(() => {
        if (robots.data) setContent(robots.data.content);
    }, [robots.data]);

    if (robots.isError) return <QueryError error={robots.error} onRetry={() => void robots.refetch()} />;
    if (!robots.data) return <p aria-busy="true" className="sr-only" />;

    const submit = () => {
        setErrors([]);
        save.mutate(content, {
            onSuccess: () => notify(t('seo.robots.saved')),
            onError: (error) => {
                const messages = fieldErrors(error, 'content');
                if (messages.length > 0) setErrors(messages);
                else notify(error instanceof ApiError ? error.problem.title : t('error.title'), 'danger');
            },
        });
    };

    return (
        <div className="flex flex-col gap-4 rounded-md border border-line-default bg-surface p-5 shadow-e1">
            <div className="flex flex-col gap-1">
                <h2 className="t-admin-section text-fg-primary">{t('seo.robots.title')}</h2>
                <p className="t-admin-caption text-fg-secondary">{t('seo.robots.hint')}</p>
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <div className="flex min-w-0 flex-col gap-1.5">
                    <label htmlFor={`${id}-content`} className="t-admin-body-strong text-fg-primary">
                        {t('seo.robots.editor')}
                    </label>
                    <textarea
                        id={`${id}-content`}
                        rows={14}
                        spellCheck={false}
                        readOnly={!editable}
                        aria-invalid={errors.length > 0 || undefined}
                        aria-describedby={errors.length > 0 ? `${id}-errors` : undefined}
                        value={content}
                        onChange={(e) => setContent(e.target.value)}
                        className={`${monoInputClass} py-2`}
                    />
                    {errors.length > 0 && (
                        <ul id={`${id}-errors`} role="alert" className="flex flex-col gap-0.5">
                            {errors.map((message) => (
                                <li key={message} className="t-admin-caption text-feedback-danger">
                                    {message}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="flex min-w-0 flex-col gap-1.5">
                    <p className="t-admin-body-strong text-fg-primary">{t('seo.robots.served')}</p>
                    <pre data-testid="robots-served" className="max-h-90 min-h-full overflow-auto rounded-sm bg-subtle p-3 t-admin-mono-sm whitespace-pre-wrap text-fg-secondary">
                        {robots.data.served}
                    </pre>
                </div>
            </div>
            {editable && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line-default pt-4">
                    <Button variant="ghost" onClick={() => setContent(robots.data?.default_content ?? '')}>
                        {t('seo.robots.reset')}
                    </Button>
                    <Button onClick={submit} disabled={save.isPending || content === robots.data.content}>
                        {save.isPending ? t('common.saving') : t('seo.robots.save')}
                    </Button>
                </div>
            )}
        </div>
    );
}
