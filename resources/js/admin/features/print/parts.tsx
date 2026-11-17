import { useId, type ReactNode } from 'react';
import { useLocation } from 'react-router';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { TabLink, TabList } from '@/components/ui/Tabs';
import { Switch } from '@/features/settings/messaging/Switch';
import { useI18n } from '@/i18n/I18nProvider';

/**
 * Shared pieces of Settings › Invoices, receipts & labels (Figma 528:81351 A4, 529:81638
 * thermal, 529:82666 labels): the document tabs, the options card with its sections, a toggle
 * row, a choice row, and the preview panel. Each tab composes these; none computes a figure.
 */

/** The three documents, each a route under /settings/invoice. */
export function PrintTabs() {
    const { t } = useI18n();
    useLocation();
    return (
        <TabList label={t('print.tabs.label')}>
            <TabLink to="/settings/invoice" end label={t('print.tab.invoice')} />
            <TabLink to="/settings/invoice/receipt" label={t('print.tab.receipt')} />
            <TabLink to="/settings/invoice/labels" label={t('print.tab.labels')} />
        </TabList>
    );
}

/** Options on the left, preview on the right; stacked below xl (the settings nav takes room). */
export function PrintLayout({ options, preview }: { options: ReactNode; preview: ReactNode }) {
    return (
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <div className="flex min-w-0 flex-col rounded-md border border-line-default bg-surface px-6 pb-2 shadow-e1">{options}</div>
            {preview}
        </div>
    );
}

/** One titled group of options (a hairline between groups, none above the first). */
export function OptionSection({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex flex-col gap-3 border-t border-line-default py-5 first:border-t-0">
            <div className="flex flex-col gap-0.5">
                <h2 id={id} className="t-admin-body-strong text-fg-primary">
                    {title}
                </h2>
                {hint && <p className="t-admin-caption text-fg-secondary">{hint}</p>}
            </div>
            {children}
        </section>
    );
}

/** A labelled on/off switch with an optional hint under the label. */
export function ToggleRow({ label, hint, checked, onChange, disabled = false }: { label: string; hint?: string; checked: boolean; onChange: (value: boolean) => void; disabled?: boolean }) {
    return (
        <div className="flex items-center gap-3">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="t-admin-body text-fg-primary">{label}</span>
                {hint && <span className="t-admin-caption text-fg-tertiary">{hint}</span>}
            </div>
            <fieldset disabled={disabled} className="contents">
                <Switch checked={checked} label={label} onChange={onChange} />
            </fieldset>
        </div>
    );
}

/** A label on the left and a segmented choice on the right. */
export function ChoiceRow<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: readonly { value: T; label: string; lang?: string }[]; onChange: (value: T) => void }) {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <span className="min-w-0 flex-1 t-admin-body text-fg-secondary">{label}</span>
            <SegmentedControl label={label} options={options} value={value} onChange={onChange} />
        </div>
    );
}

/** The grey preview panel: an overline title, an optional action, and the document(s). */
export function PreviewPanel({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
    const id = useId();
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col gap-3 rounded-md bg-subtle p-4 sm:p-6">
            <div className="flex items-center gap-2">
                <h2 id={id} className="min-w-0 flex-1 t-admin-overline text-fg-secondary">
                    {title}
                </h2>
                {action}
            </div>
            <div className="min-w-0 overflow-x-auto">{children}</div>
        </section>
    );
}
