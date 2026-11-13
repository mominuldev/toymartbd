import type { ComponentType, ReactNode } from 'react';
import { ShieldCheckIcon, ShieldWarningIcon, type IconProps } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';
import { Tag, type TagTone } from '@/components/ui/Tag';
import type { RiskLevel, RiskStatus, Segment } from './api';
import { initials } from './format';

/** Segment → Tag pastel (Figma B · 17 tags: VIP butter, Repeat mint, New sky, Watch list pink). Meaning is in the word. */
const segmentTone: Record<Segment, TagTone | null> = {
    new: 'sky',
    active: 'mint',
    vip: 'butter',
    at_risk: 'peach',
    lapsed: 'lavender',
    no_orders: null,
};

export const segmentLabel = (segment: Segment): MessageKey => `customers.segment.${segment}` as MessageKey;

export function SegmentTag({ segment }: { segment: Segment }) {
    const { t } = useI18n();
    const tone = segmentTone[segment];
    return tone === null ? <span className="t-admin-caption text-fg-tertiary">{t(segmentLabel(segment))}</span> : <Tag label={t(segmentLabel(segment))} tone={tone} />;
}

/** A risk list status as a tag; `normal` shows nothing. */
export function RiskStatusTag({ status }: { status: RiskStatus }) {
    const { t } = useI18n();
    if (status === 'normal') return null;
    return <Tag label={t(`customers.riskStatus.${status}` as MessageKey)} tone={status === 'whitelist' ? 'mint' : 'pink'} />;
}

const riskLook: Record<RiskLevel, { icon: ComponentType<IconProps>; edge: string; tint: string }> = {
    low: { icon: ShieldCheckIcon, edge: 'border-feedback-success', tint: 'text-feedback-success' },
    medium: { icon: ShieldWarningIcon, edge: 'border-status-low-stock', tint: 'text-status-low-stock' },
    high: { icon: ShieldWarningIcon, edge: 'border-feedback-danger', tint: 'text-feedback-danger' },
};

/** Figma RiskPill 190:405 — level word + OUR score, a 2px level edge; never colour alone (rule 24). */
export function RiskPill({ level, score }: { level: RiskLevel; score: number }) {
    const { t } = useI18n();
    const { icon: Icon, edge, tint } = riskLook[level];
    return (
        <span className={`inline-flex h-5.5 items-center gap-1 rounded-xs border-l-2 bg-subtle px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary ${edge}`}>
            <Icon size={14} aria-hidden className={`shrink-0 ${tint}`} />
            {t(`customers.risk.pill.${level}` as MessageKey, { score })}
        </span>
    );
}

export const riskTint = (level: RiskLevel): string => riskLook[level].tint;

/** Figma Avatar 52:483 Small · Initials on bg/surface — decorative; the name is always next to it. */
export function Initials({ name }: { name: string }) {
    return (
        <span aria-hidden className="grid size-8 shrink-0 place-items-center rounded-full border border-line-default bg-surface font-display-latin text-xs/none font-bold text-fg-primary">
            {initials(name)}
        </span>
    );
}

/** Figma StatCard 189:360 (profile and analytics): overline label, large figure, caption. */
export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5 rounded-md border border-line-default bg-surface p-4 shadow-e1">
            <p className="t-admin-overline text-fg-secondary">{label}</p>
            <p className="t-admin-num-lg text-fg-primary">{value}</p>
            <p className="t-admin-caption text-fg-secondary tabular">{hint}</p>
        </div>
    );
}

/** A surface card with the section title (Admin/section) — the profile rail and the analytics panels. */
export function Panel({ title, subtitle, children, className = '', titleId }: { title: ReactNode; subtitle?: ReactNode; children: ReactNode; className?: string; titleId?: string }) {
    return (
        <section aria-labelledby={titleId} className={`flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5 shadow-e1 ${className}`}>
            <div className="flex flex-col gap-0.5">
                <h2 id={titleId} className="t-admin-section text-fg-primary">
                    {title}
                </h2>
                {subtitle && <p className="t-admin-caption text-fg-secondary">{subtitle}</p>}
            </div>
            {children}
        </section>
    );
}
