'use client';

import { useEffect, useRef } from 'react';

// The address form's district → upazila cascade (the checkout pattern, P4-STO-09). Without JavaScript
// the "Show upazilas" button re-renders the form with the chosen district's upazilas. Hydrated, this
// island hides that button and presses it as soon as the district changes.
export function RefreshOnDistrict({ buttonId }: { buttonId: string }) {
    const marker = useRef<HTMLSpanElement>(null);

    useEffect(() => {
        const form = marker.current?.closest('form');
        const button = document.getElementById(buttonId);
        if (!form || !(button instanceof HTMLButtonElement)) return;
        button.hidden = true;
        const onChange = (event: Event) => {
            const target = event.target;
            if (target instanceof HTMLSelectElement && target.name === 'district_id') {
                const upazila = form.querySelector<HTMLSelectElement>('select[name="upazila_id"]');
                if (upazila) upazila.value = '';
                form.requestSubmit(button);
            }
        };
        form.addEventListener('change', onChange);
        return () => form.removeEventListener('change', onChange);
    }, [buttonId]);

    return <span ref={marker} hidden />;
}
