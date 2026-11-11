'use client';

import { useEffect } from 'react';

interface Props {
    formId: string;
}

/** Desktop sidebar debounce before applying (storefront spec §A7.5: 400ms). */
export const AUTO_APPLY_DELAY_MS = 400;

// Progressive enhancement for the desktop FilterSidebar (§A7.3 "Apply: none"): once hydrated, a
// change submits the GET form after a short debounce, and `data-enhanced` hides the submit button.
// Without JavaScript the form keeps its button and works the same — each submit is a normal
// navigation, so the back button steps through filter states.
export function FilterAutoApply({ formId }: Props) {
    useEffect(() => {
        const form = document.getElementById(formId);
        if (!(form instanceof HTMLFormElement)) return;
        form.dataset.enhanced = '';
        let timer: ReturnType<typeof setTimeout> | undefined;
        const onChange = () => {
            clearTimeout(timer);
            timer = setTimeout(() => form.requestSubmit(), AUTO_APPLY_DELAY_MS);
        };
        form.addEventListener('change', onChange);
        return () => {
            clearTimeout(timer);
            form.removeEventListener('change', onChange);
            delete form.dataset.enhanced;
        };
    }, [formId]);

    return null;
}
