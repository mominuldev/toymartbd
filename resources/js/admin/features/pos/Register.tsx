import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { BarcodeIcon } from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { SearchField } from '@/components/ui/SearchField';
import { useToast } from '@/components/ui/Toast';
import { lookupBarcode, useCatalog, useHold, useHolds, type PosContext, type PosSale, type PosSession } from './api';
import { addLine, cleanPhone, emptyCart, type CartState } from './cart';
import { CartPanel } from './CartPanel';
import { CloseDrawerDialog } from './CloseDrawerDialog';
import { CollectDueDialog } from './CollectDueDialog';
import { CustomerDialog } from './CustomerDialog';
import { HeldSalesDialog } from './HeldSalesDialog';
import { PosBar } from './PosBar';
import { ProductGrid } from './ProductGrid';
import { SaleComplete } from './SaleComplete';

/**
 * The register on an open drawer (Figma B · 33 POS v2, 344:63829): the dark POS bar, then the
 * catalog (scan / search, category pills, tiles) beside Current sale. Charge opens Collect
 * payment; a charged sale swaps the body for Sale complete until Start new sale.
 *
 * Keys: F1 search · F8 held sales · F9 customer · F12 charge. Below `lg` (tablet portrait, phone)
 * a Products / Cart switch shows one column at a time, so every control keeps a 44px target.
 */
export function Register({ context, session }: { context: PosContext; session: PosSession }) {
    const { t, locale } = useI18n();
    const { notify } = useToast();
    const [cart, setCart] = useState<CartState>(emptyCart);
    const [category, setCategory] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const [closing, setClosing] = useState(false);
    const [showHolds, setShowHolds] = useState(false);
    const [paying, setPaying] = useState(false);
    const [completed, setCompleted] = useState<PosSale | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [choosingCustomer, setChoosingCustomer] = useState(false);
    const [collecting, setCollecting] = useState(false);
    const [view, setView] = useState<'products' | 'cart'>('products');
    // Names of tiles the cashier added, so a line reads right before its quote arrives.
    const [names, setNames] = useState<Record<number, string>>({});
    // F12 asks the cart, which knows whether the quote allows a charge (CartPanel sets it).
    const chargeKey = useRef<() => void>(() => undefined);

    const filters = useMemo(() => ({ categoryId: category, q: term }), [category, term]);
    const catalog = useCatalog(session.id, filters);
    const holds = useHolds(session.id);
    const hold = useHold(session.id);
    const inCart = useMemo(() => Object.fromEntries(cart.lines.map((l) => [l.variantId, l.quantity])), [cart.lines]);

    const add = (variantId: number, name: string) => {
        setNames((n) => ({ ...n, [variantId]: name }));
        setCart((c) => addLine(c, variantId));
    };

    const startNext = () => {
        setCompleted(null);
        setCart(emptyCart());
        requestAnimationFrame(() => searchRef.current?.focus());
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

    const onHold = (label: string | null = null) => {
        if (cart.lines.length === 0) return;
        // No label typed: the sale is held under the customer's name, so it is found by name in Held.
        const customerName = cart.name.trim();
        hold.mutate(
            {
                label: label ?? (customerName !== '' ? customerName.slice(0, 80) : null),
                lines: cart.lines.map((l) => ({
                    variant_id: l.variantId,
                    quantity: l.quantity,
                })),
                coupon_code: cart.coupon.trim() || null,
                customer_phone: cart.phone.trim() || null,
                customer_name: cart.phone.trim() !== '' && customerName !== '' ? customerName.slice(0, 160) : null,
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

    // Register-wide function keys. Inside an open dialog its own keys apply instead.
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            if (completed) {
                if (event.key === 'Enter' && document.activeElement === document.body) {
                    event.preventDefault();
                    startNext();
                }
                return;
            }
            // Ask the DOM, not the dialog flags: a dialog closed by Escape is gone at once, while its
            // flag clears a render later — a quick F8 after Escape would otherwise be swallowed.
            if (document.querySelector('dialog[open]')) return;
            if (event.key === 'F1') {
                event.preventDefault();
                searchRef.current?.focus();
            } else if (event.key === 'F8') {
                event.preventDefault();
                setShowHolds(true);
            } else if (event.key === 'F9') {
                event.preventDefault();
                setChoosingCustomer(true);
            } else if (event.key === 'F12') {
                event.preventDefault();
                chargeKey.current();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [completed]);

    const heldCount = holds.data?.data.length ?? 0;
    // A count of pieces, not money.
    const pieces = cart.lines.reduce((sum, l) => sum + l.quantity, 0);

    return (
        <>
            <PosBar session={session} heldCount={heldCount} onHeld={() => setShowHolds(true)} onCloseDrawer={() => setClosing(true)} />

            {completed ? (
                <SaleComplete context={context} sale={completed} onNext={startNext} />
            ) : (
                <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
                    <div role="group" aria-label={t('pos.view.label')} className="flex shrink-0 gap-1 border-b border-line-default bg-surface p-2 lg:hidden">
                        {(['products', 'cart'] as const).map((v) => (
                            <button
                                key={v}
                                type="button"
                                aria-pressed={view === v}
                                onClick={() => setView(v)}
                                className="h-11 flex-1 rounded-sm t-admin-button text-fg-secondary aria-pressed:bg-brand-subtle aria-pressed:text-fg-brand"
                            >
                                {v === 'products' ? t('pos.products') : t('pos.view.cart', { count: pieces })}
                            </button>
                        ))}
                    </div>
                    <section
                        aria-label={t('pos.products')}
                        className={`${view === 'products' ? 'flex' : 'hidden'} min-h-0 min-w-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5 lg:flex print:hidden`}
                    >
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <SearchField
                                ref={searchRef}
                                label={t('pos.search.label')}
                                placeholder={t('pos.search.label')}
                                shortcut="F1"
                                aria-keyshortcuts="F1"
                                className="h-11 flex-1"
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
                        <div role="group" aria-label={t('pos.tabs.label')} className="flex shrink-0 gap-2 overflow-x-auto pb-1">
                            {[
                                {
                                    id: null,
                                    name: t('pos.tabs.all'),
                                    name_bn: null,
                                },
                                ...context.categories,
                            ].map((c) => {
                                const active = category === c.id;
                                return (
                                    <button
                                        key={c.id ?? 'all'}
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => setCategory(c.id)}
                                        className={`h-11 shrink-0 rounded-full border px-4 lg:h-9 lg:px-3.5 transition-colors ${
                                            active
                                                ? 'border-line-brand bg-brand t-admin-body-strong text-fg-on-brand'
                                                : 'border-line-default bg-surface t-admin-body text-fg-primary hover:bg-subtle'
                                        }`}
                                    >
                                        {locale === 'bn' && c.name_bn ? c.name_bn : c.name}
                                    </button>
                                );
                            })}
                        </div>
                        <ProductGrid query={catalog} inCart={inCart} onAdd={add} />
                    </section>

                    <CartPanel
                        visible={view === 'cart'}
                        context={context}
                        session={session}
                        cart={cart}
                        setCart={setCart}
                        names={names}
                        onOpenCustomer={() => setChoosingCustomer(true)}
                        onCollectDue={() => setCollecting(true)}
                        onHold={() => setShowHolds(true)}
                        holdPending={hold.isPending}
                        paying={paying}
                        setPaying={setPaying}
                        onCharged={setCompleted}
                        chargeKey={chargeKey}
                    />
                </div>
            )}

            <CloseDrawerDialog open={closing} session={session} onClose={() => setClosing(false)} />
            <CustomerDialog
                open={choosingCustomer}
                onClose={() => setChoosingCustomer(false)}
                onPick={(picked) => setCart((c) => ({ ...c, phone: picked?.phone ?? '', name: picked?.name ?? '' }))}
            />
            {cleanPhone(cart.phone) && (
                <CollectDueDialog
                    open={collecting}
                    context={context}
                    session={session}
                    phone={cleanPhone(cart.phone) ?? ''}
                    customerName={cart.name.trim() || cart.phone}
                    onClose={() => setCollecting(false)}
                />
            )}
            <HeldSalesDialog
                open={showHolds}
                sessionId={session.id}
                holds={holds.data?.data ?? []}
                cartHasLines={cart.lines.length > 0}
                holdPending={hold.isPending}
                defaultLabel={cart.name.trim()}
                onHold={(label) => onHold(label)}
                onClose={() => setShowHolds(false)}
                onResumed={(held) => {
                    setCompleted(null);
                    setCart({
                        ...emptyCart(),
                        lines: held.lines.map((l) => ({
                            variantId: l.variant_id,
                            quantity: l.quantity,
                        })),
                        coupon: held.coupon_code ?? '',
                        phone: held.customer_phone ?? '',
                        name: held.customer_name ?? '',
                    });
                    setShowHolds(false);
                }}
            />
        </>
    );
}
