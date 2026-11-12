import { OrderComposer } from './OrderComposer';

/** `/admin/orders/new` — a phone / Facebook / WhatsApp order (P4-ORD-06, Figma 207:2243). */
export function OrderCreatePage() {
    return <OrderComposer mode={{ kind: 'create' }} />;
}
