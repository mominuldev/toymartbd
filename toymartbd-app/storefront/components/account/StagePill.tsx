import { Icon } from '@/components/icons/Icon';
import type { IconName } from '@/components/icons/paths';
import type { OrderStage } from '@/lib/cart/types';

// AccountOrderCard's status pill (Figma 236:2302): a pastel pill with an icon AND words — never colour
// alone. Moving → sky with a truck; being prepared → butter with a clock; delivered → mint; stopped → subtle.

const look: Record<OrderStage, { tone: string; icon: IconName }> = {
    placed: { tone: 'bg-tile-butter', icon: 'clock' },
    confirmed: { tone: 'bg-tile-butter', icon: 'clock' },
    processing: { tone: 'bg-tile-butter', icon: 'clock' },
    shipped: { tone: 'bg-tile-sky', icon: 'truck' },
    delivered: { tone: 'bg-tile-mint', icon: 'checkCircle' },
    cancelled: { tone: 'bg-subtle', icon: 'x' },
    returned: { tone: 'bg-subtle', icon: 'arrowCounterClockwise' },
};

export function StagePill({ stage, label }: { stage: OrderStage; label: string }) {
    const { tone, icon } = look[stage];
    return (
        <span className={`t-caption-strong inline-flex w-fit items-center gap-1.5 rounded-full py-1.5 ps-2.5 pe-3 text-fg-primary ${tone}`}>
            <Icon name={icon} className="size-4" />
            {label}
        </span>
    );
}
