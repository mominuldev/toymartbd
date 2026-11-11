import { Icon } from '@/components/icons/Icon';

// Figma AnnouncementBar 123:1729: a thin promise strip above the header (36 tall) on the candy
// bloom gradient. One true message at a time, never auto-rotating, not sticky. Home only in the
// design (Home 126:10 / 133:8); the 404, 500 and utility pages do not carry it.
export function AnnouncementBar({ message }: { message: string }) {
    return (
        <p className="t-body-sm flex min-h-9 items-center justify-center gap-2 bg-(image:--tm-gradient-bloom-candy) px-gutter-mobile text-center font-semibold text-fg-primary">
            <Icon name="sparkle" className="size-3.5 text-fg-brand" />
            <span>{message}</span>
            <Icon name="sparkle" className="size-3.5 text-fg-brand" />
        </p>
    );
}
