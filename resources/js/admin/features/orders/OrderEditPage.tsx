import { Link, useParams } from 'react-router';
import { useI18n } from '@/i18n/I18nProvider';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { useOrder } from './api';
import { OrderComposer } from './OrderComposer';
import { statusLabel } from './parts';

/**
 * `/admin/orders/:orderId/edit` — edit after placement (P4-ORD-07). Only while the server
 * says `editable` (pending, on hold, confirmed, processing and orders.update); otherwise
 * the page explains why instead of showing a form the server would refuse.
 */
export function OrderEditPage() {
    const { t } = useI18n();
    const { orderId } = useParams();
    const order = useOrder(Number(orderId));

    if (order.isError) return <QueryError error={order.error} onRetry={() => void order.refetch()} />;
    if (order.isPending)
        return (
            <p className="t-admin-body text-fg-tertiary" aria-busy="true">
                {t('common.loading')}
            </p>
        );
    if (!order.data.editable)
        return (
            <EmptyState
                reason="noPermission"
                title={t('orders.edit.notEditable', { status: t(statusLabel(order.data.status)) })}
                actions={
                    <Link to={`/orders/${order.data.id}`} className="t-admin-body-strong text-fg-link hover:underline">
                        {order.data.number}
                    </Link>
                }
            />
        );

    // Keyed by order id, so navigating between orders resets the form.
    return <OrderComposer key={order.data.id} mode={{ kind: 'edit', order: order.data }} />;
}
