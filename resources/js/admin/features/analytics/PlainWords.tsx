import { useId } from 'react';
import { SparkleIcon } from '@phosphor-icons/react';

/**
 * "In plain words" (Figma Analytics premium pass 518:78370): the three sentences an owner reads
 * first. Every figure in them is the server's — the page only puts them into words (rule 1).
 */
export function PlainWords({ label, lines }: { label: string; lines: string[] }) {
    const id = useId();
    if (lines.length === 0) return null;
    return (
        <section aria-labelledby={id} className="flex items-start gap-4 rounded-md bg-brand-subtle p-5 sm:p-6">
            <SparkleIcon size={22} aria-hidden className="mt-0.5 shrink-0 text-fg-brand" />
            <div className="flex min-w-0 flex-col gap-2">
                <h2 id={id} className="t-admin-overline text-fg-brand">
                    {label}
                </h2>
                <ul className="flex list-disc flex-col gap-1.5 ps-5 t-admin-body text-fg-primary">
                    {lines.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

/**
 * A server change string ("38.20", "-12.5") as words-ready parts: the absolute value with Western
 * digits and whether it went up. Display only — the number is the server's.
 */
export function change(value: string | null | undefined): { amount: string; up: boolean } | null {
    if (value === null || value === undefined) return null;
    const down = value.startsWith('-');
    const amount = value.replace(/^-/, '').replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
    if (Number(amount) === 0) return null;
    return { amount, up: !down };
}
