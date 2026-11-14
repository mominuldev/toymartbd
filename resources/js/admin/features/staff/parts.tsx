import { useEffect, useRef, type ReactNode } from 'react';
import { dataTable } from '@/components/ui/DataTable';

/** Column header cell for the staff tables. */
export function Th({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <th scope="col" className={`${dataTable.th} ${className}`}>
            {children}
        </th>
    );
}

/** Column name in front of a value on the stacked (below lg) row; hidden where the header shows. */
export function CellLabel({ text }: { text: string }) {
    return <span className="me-1.5 t-admin-caption text-fg-tertiary lg:hidden">{text}</span>;
}

/** Opens and closes a native <dialog> with `open`, falling back to the attribute where showModal is missing (jsdom). */
export function useDialog(open: boolean, onOpen: () => void) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            onOpen();
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (!open && dialog.open) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    return ref;
}

/**
 * Matrix rows, grouped the way the business thinks about them rather than one group per
 * API resource. Every permission the API returns must appear in exactly one group — the
 * test asserts it, so a new permission cannot silently vanish from the screen.
 */
export const permissionGroups = [
    { key: 'general', prefixes: ['dashboard'] },
    { key: 'orders', prefixes: ['orders', 'returns', 'refunds'] },
    { key: 'delivery', prefixes: ['shipments'] },
    { key: 'risk', prefixes: ['risk'] },
    { key: 'customers', prefixes: ['customers', 'inbox'] },
    { key: 'catalog', prefixes: ['catalog', 'reviews'] },
    { key: 'stock', prefixes: ['inventory', 'purchasing'] },
    { key: 'money', prefixes: ['finance', 'reports'] },
    { key: 'store', prefixes: ['marketing', 'content', 'media', 'seo'] },
    { key: 'admin', prefixes: ['settings', 'staff', 'roles', 'audit'] },
] as const;

/** Groups the permission codes, keeping the server's order inside each group. */
export function groupPermissions(codes: readonly string[]): { key: (typeof permissionGroups)[number]['key']; codes: string[] }[] {
    return permissionGroups
        .map((group) => ({ key: group.key, codes: codes.filter((code) => (group.prefixes as readonly string[]).includes(code.split('.')[0] ?? '')) }))
        .filter((group) => group.codes.length > 0);
}
