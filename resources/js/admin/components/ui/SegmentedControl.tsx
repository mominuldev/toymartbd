type Option<T extends string> = { value: T; label: string; lang?: string };

type SegmentedControlProps<T extends string> = {
    label: string;
    options: readonly Option<T>[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
};

/** Segment classes, shared with LanguageSwitch (which sits in the same Figma family). */
export const segmentGroupClass = 'flex items-center gap-0.5 rounded-sm bg-subtle p-0.5';

/**
 * Figma SegmentedControl 185:110 — 32 high on bg/subtle; items 28 high, radius/xs,
 * button-sm; the selected item is bg/surface + e1 with text/primary.
 */
export function SegmentedControl<T extends string>({ label, options, value, onChange, className = '' }: SegmentedControlProps<T>) {
    return (
        <div role="group" aria-label={label} className={`${segmentGroupClass} h-8 ${className}`}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    lang={option.lang}
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className="h-7 rounded-xs px-3 t-admin-button-sm whitespace-nowrap text-fg-secondary hover:text-fg-primary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1"
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
