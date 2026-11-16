'use client';

import { useState, type ReactNode } from 'react';

// The message textarea with its live "n / 500" count (Figma Textarea footer). Without JavaScript
// the count shows the starting length and `maxLength` still caps the text.

interface Props {
    id: string;
    name: string;
    max: number;
    defaultValue: string;
    placeholder: string;
    className: string;
    describedBy: string;
    invalid: boolean;
    countLabel: string;
    /** The helper or error line, left of the count. */
    help: ReactNode;
}

export function MessageField({ id, name, max, defaultValue, placeholder, className, describedBy, invalid, countLabel, help }: Props) {
    const [count, setCount] = useState(defaultValue.length);
    return (
        <>
            <textarea
                id={id}
                name={name}
                required
                minLength={10}
                maxLength={max}
                rows={4}
                defaultValue={defaultValue}
                placeholder={placeholder}
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                onChange={(e) => setCount(e.currentTarget.value.length)}
                className={className}
            />
            <div className="flex items-start justify-between gap-2">
                {help}
                <span className="t-caption shrink-0 text-fg-secondary" data-count>
                    {countLabel.replace('{count}', String(count)).replace('{max}', String(max))}
                </span>
            </div>
        </>
    );
}
