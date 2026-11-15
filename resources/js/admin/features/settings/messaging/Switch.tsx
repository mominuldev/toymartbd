/** Figma toggle (Store settings B · 38 rows): 36×20 track, bg/brand on, border/strong off. */
export function Switch({ checked, label, onChange }: { checked: boolean; label: string; onChange: (value: boolean) => void }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors duration-150 ease-standard disabled:cursor-not-allowed disabled:opacity-60 ${
                checked ? 'bg-brand' : 'bg-line-strong'
            }`}
        >
            <span aria-hidden className={`size-4 rounded-full bg-surface shadow-e1 transition-transform duration-150 ease-standard ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}
