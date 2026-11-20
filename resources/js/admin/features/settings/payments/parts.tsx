import type { ReactNode } from 'react';
import type { StatusTone } from '@/components/ui/StatusChip';

const dotTone: Record<StatusTone, string> = {
    neutral: 'bg-line-strong',
    info: 'bg-feedback-info',
    success: 'bg-feedback-success',
    warning: 'bg-feedback-warning',
    danger: 'bg-feedback-danger',
    brand: 'bg-brand',
    anomaly: 'bg-feedback-warning',
};

/** The 8px status dot beside a caption; the caption carries the meaning, never the colour alone. */
export function Dot({ tone }: { tone: StatusTone }) {
    return <span aria-hidden className={`size-2 shrink-0 rounded-full ${dotTone[tone]}`} />;
}

/** A panel section (B · 37a): the 296px "About" column at lg+, the controls beside it; stacked below. */
export function Section({ id, title, about, children }: { id: string; title: string; about: ReactNode; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col gap-4 border-t border-line-default p-4 sm:p-6 lg:flex-row lg:gap-8">
            <div className="flex min-w-0 flex-col gap-1 lg:w-74 lg:shrink-0">
                <h3 id={id} className="t-admin-section text-fg-primary">
                    {title}
                </h3>
                <p className="t-admin-body text-fg-secondary">{about}</p>
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-4">{children}</div>
        </section>
    );
}

/** "20 Nov, 4:52 pm" in Dhaka time, Western digits in both locales (rules 11, 36). */
export function dhakaStamp(iso: string, locale: string): string {
    const date = new Date(iso);
    const tag = locale === 'bn' ? 'bn-BD' : 'en-GB';
    const base = { timeZone: 'Asia/Dhaka', numberingSystem: 'latn' } as const;
    const day = new Intl.DateTimeFormat(tag, { ...base, day: 'numeric', month: 'short' }).format(date);
    const time = new Intl.DateTimeFormat(tag, { ...base, hour: 'numeric', minute: '2-digit', hour12: true }).format(date).toLowerCase();
    return `${day}, ${time}`;
}
