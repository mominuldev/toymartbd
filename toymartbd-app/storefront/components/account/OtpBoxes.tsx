'use client';

import { useRef, type ClipboardEvent, type KeyboardEvent, type FormEvent } from 'react';

interface Props {
    /** Accessible name per box ("Digit 1 of 6"), `{n}` replaced. */
    digitLabel: string;
    /** Group label ("The 6-digit code from the SMS"). */
    label: string;
    invalid: boolean;
    describedBy?: string;
}

// The six code boxes (Figma Verify code 237:9412: 52 × 56, radius sm, focus ring teal). They are six
// plain inputs named d1…d6, so the form posts without JavaScript (the shopper tabs between them).
// Hydrated, typing advances, Backspace goes back, and a pasted or SMS-autofilled code fills them all.
// The first box carries `autocomplete="one-time-code"` so the phone can offer the SMS code.
export function OtpBoxes({ digitLabel, label, invalid, describedBy }: Props) {
    const refs = useRef<Array<HTMLInputElement | null>>([]);

    function fill(from: number, digits: string) {
        const clean = digits.replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d))).replace(/\D/g, '');
        if (!clean) return;
        let i = from;
        for (const d of clean) {
            const box = refs.current[i];
            if (!box) break;
            box.value = d;
            i++;
        }
        refs.current[Math.min(i, 5)]?.focus();
    }

    function onInput(index: number, event: FormEvent<HTMLInputElement>) {
        const value = event.currentTarget.value;
        if (value.length > 1) {
            event.currentTarget.value = '';
            fill(index, value);
            return;
        }
        if (/\d|[০-৯]/.test(value)) refs.current[index + 1]?.focus();
    }

    function onKeyDown(index: number, event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Backspace' && event.currentTarget.value === '' && index > 0) refs.current[index - 1]?.focus();
    }

    function onPaste(index: number, event: ClipboardEvent<HTMLInputElement>) {
        event.preventDefault();
        fill(index, event.clipboardData.getData('text'));
    }

    return (
        <fieldset className="flex justify-between gap-2" aria-describedby={describedBy}>
            <legend className="sr-only">{label}</legend>
            {[0, 1, 2, 3, 4, 5].map((i) => (
                <input
                    key={i}
                    ref={(el) => {
                        refs.current[i] = el;
                    }}
                    name={`d${i + 1}`}
                    type="text"
                    inputMode="numeric"
                    autoComplete={i === 0 ? 'one-time-code' : 'off'}
                    maxLength={i === 0 ? 6 : 1}
                    pattern="[0-9০-৯]*"
                    required
                    autoFocus={i === 0}
                    aria-label={digitLabel.replace('{n}', String(i + 1))}
                    aria-invalid={invalid || undefined}
                    onInput={(e) => onInput(i, e)}
                    onKeyDown={(e) => onKeyDown(i, e)}
                    onPaste={(e) => onPaste(i, e)}
                    className="t-h3 h-14 w-full max-w-13 min-w-0 rounded-sm border border-line-strong bg-surface text-center text-fg-primary tabular-nums focus:border-line-focus aria-[invalid=true]:border-line-danger"
                />
            ))}
        </fieldset>
    );
}
