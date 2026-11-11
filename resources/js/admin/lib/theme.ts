import { useCallback, useState } from 'react';

export type AdminTheme = 'admin-light' | 'admin-dark';

// Same key the pre-paint script in resources/views/admin.blade.php reads.
const STORAGE_KEY = 'tm-admin-theme';

function currentTheme(): AdminTheme {
    return document.documentElement.getAttribute('data-theme') === 'admin-dark' ? 'admin-dark' : 'admin-light';
}

export function useTheme(): { theme: AdminTheme; toggle: () => void } {
    const [theme, setTheme] = useState<AdminTheme>(currentTheme);

    const toggle = useCallback(() => {
        const next: AdminTheme = currentTheme() === 'admin-dark' ? 'admin-light' : 'admin-dark';
        document.documentElement.setAttribute('data-theme', next);
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Private mode: the choice lasts until reload.
        }
        setTheme(next);
    }, []);

    return { theme, toggle };
}
