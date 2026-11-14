import { useMemo, useRef, useState, useSyncExternalStore, type KeyboardEvent } from 'react';
import { BarcodeIcon, CashRegisterIcon, CloudCheckIcon, CloudSlashIcon, PauseCircleIcon, StackIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { SearchField } from '@/components/ui/SearchField';
import { useToast } from '@/components/ui/Toast';
import { lookupBarcode, useCatalog, useHold, useHolds, type PosContext, type PosSession } from './api';
import { addLine, emptyCart, type CartState } from './cart';
import { CartPanel } from './CartPanel';
import { CloseDrawerDialog } from './CloseDrawerDialog';
import { dhakaTime, takaText } from './format';
import { HeldSalesDialog } from './HeldSalesDialog';
import { ProductGrid } from './ProductGrid';

function subscribeOnline(callback: () => void): () => void {
    window.addEventListener('online', callback);
    window.addEventListener('offline', callback);
    return () => {
        window.removeEventListener('online', callback);
        window.removeEventListener('offline', callback);
    };
}

/** The register on an open drawer: header, product grid (left) and cart / payment (right). */
export function Register({ context, session }: { context: PosContext; session: PosSession }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const online = useSyncExternalStore(subscribeOnline, () => navigator.onLine, () => true);
    const [cart, setCart] = useState<CartState>(emptyCart);
    const [category, setCategory] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [closing, setClosing] = useState(false);
    const [showHolds, setShowHolds] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    // Names of tiles the cashier added, so a line reads right before its quote arrives.
    const [names, setNames] = useState<Record<number, string>>({});

    const filters = useMemo(() => ({ categoryId: category, q: term }), [category, term]);
    const catalog = useCatalog(session.id, filters);
    const holds = useHolds(session.id);
    const hold = useHold(session.id);
    const branchName = locale === 'bn' && session.location.name_bn ? session.location.name_bn : session.location.name;

    const add = (variantId: number, name: string) => {
        setNames((n) => ({ ...n, [variantId]: name }));
        setCart((c) => addLine(c, variantId));
    };

    /** A keyboard-wedge scanner types the code and Enter: exact barcode first, else a search. */
    const onSearchKey = async (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const value = search.trim();
        if (value === '') return setTerm('');
        try {
            const found = await lookupBarcode(session.id, value);
            const item = found.data.length === 1 ? found.data[0] : undefined;
            if (item) {
                add(item.variant_id, locale === 'bn' && item.name_bn ? item.name_bn : item.name);
                setSearch('');
                return;
            }
        } catch {
            // fall through to a name search
        }
        setTerm(value);
    };

    const onHold = () => {
        if (cart.lines.length === 0) return;
        hold.mutate(
            {
                lines: cart.lines.map((l) => ({ variant_id: l.variantId, quantity: l.quantity })),
                coupon_code: cart.coupon.trim() || null,
                customer_phone: cart.phone.trim() || null,
            },
            {
                onSuccess: () => {
                    setCart(emptyCart());
                    notify(t('pos.hold.done'), 'success');
                },
                onError: () => notify(t('pos.hold.failed'), 'danger'),
            },
        );
    };

    const heldCount = holds.data?.data.length ?? 0;

    return (
        <>
            <header className="flex shrink-0 flex-wrap items-center gap-3 border-b border-line-default bg-surface px-5 py-3 print:hidden">
                <div className="flex min-w-0 flex-1 flex-col">
                    <h1 className="t-admin-body-strong text-fg-primary">{t('pos.title', { branch: branchName })}</h1>
                    <p className="t-admin-caption text-fg-secondary">
                        {t('pos.subtitle', {
                            register: session.register,
                            cashier: session.cashier.name,
                            time: dhakaTime(session.opened_at, locale),
                            float: takaText(session.opening_float),
                        })}
                    </p>
                </div>
                <span className="inline-flex h-7 items-center gap-1.5 rounded-sm border border-line-default bg-subtle px-2.5 t-admin-caption-strong text-fg-primary" role="status">
                    {online ? <CloudCheckIcon size={14} aria-hidden className="text-feedback-success" /> : <CloudSlashIcon size={14} aria-hidden className="text-feedback-danger" />}
                    {online ? t('pos.online') : t('pos.offline')}
                </span>
                {heldCount > 0 && (
                    <Button variant="outline" size="lg" onClick={() => setShowHolds(true)}>
                        <StackIcon size={18} aria-hidden />
                        {t('pos.held', { count: heldCount })}
                    </Button>
                )}
                <Button variant="outline" size="lg" onClick={onHold} disabled={cart.lines.length === 0 || hold.isPending}>
                    <PauseCircleIcon size={18} aria-hidden />
                    {t('pos.hold')}
                </Button>
                <Button variant="outline" size="lg" onClick={() => setClosing(true)}>
                    <CashRegisterIcon size={18} aria-hidden />
                    {t('pos.closeDrawer')}
                </Button>
            </header>

            <div className="flex min-h-0 flex-1 print:block">
                <section aria-label={t('pos.products')} className="flex min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-5 print:hidden">
                    <div className="flex items-center gap-2">
                        <SearchField
                            ref={searchRef}
                            label={t('pos.search.label')}
                            placeholder={t('pos.search.label')}
                            className="h-11 max-w-xl flex-1"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                if (e.target.value.trim() === '') setTerm('');
                            }}
                            onKeyDown={(e) => void onSearchKey(e)}
                        />
                        <Button variant="outline" size="lg" onClick={() => searchRef.current?.focus()}>
                            <BarcodeIcon size={18} aria-hidden />
                            {t('pos.scan')}
                        </Button>
                    </div>
                    <div role="group" aria-label={t('pos.tabs.label')} className="flex flex-wrap gap-2">
                        {[{ id: null, name: t('pos.tabs.all'), name_bn: null }, ...context.categories].map((c) => {
                            const active = category === c.id;
                            return (
                                <button
                                    key={c.id ?? 'all'}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => setCategory(c.id)}
                                    className={`h-9 rounded-sm border px-3 t-admin-body transition-colors ${active ? 'border-line-focus bg-brand-subtle text-fg-brand' : 'border-line-default bg-surface text-fg-primary hover:bg-subtle'}`}
                                >
                                    {locale === 'bn' && c.name_bn ? c.name_bn : c.name}
                                </button>
                            );
                        })}
                    </div>
                    <ProductGrid query={catalog} onAdd={add} />
                </section>

                <CartPanel context={context} session={session} cart={cart} setCart={setCart} names={names} />
            </div>

            <CloseDrawerDialog open={closing} session={session} onClose={() => setClosing(false)} />
            <HeldSalesDialog
                open={showHolds}
                sessionId={session.id}
                holds={holds.data?.data ?? []}
                onClose={() => setShowHolds(false)}
                onResumed={(held) => {
                    setCart({
                        ...emptyCart(),
                        lines: held.lines.map((l) => ({ variantId: l.variant_id, quantity: l.quantity })),
                        coupon: held.coupon_code ?? '',
                        phone: held.customer_phone ?? '',
                    });
                    setShowHolds(false);
                }}
            />
        </>
    );
}
