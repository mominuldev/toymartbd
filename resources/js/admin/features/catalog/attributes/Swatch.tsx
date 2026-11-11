/**
 * A colour swatch. The colour is catalog data (`color_hex` from the API), not a
 * design value, so it is the one place a colour arrives through an inline style.
 */
export function Swatch({ hex, label }: { hex: string | null; label: string }) {
    return (
        <span
            role="img"
            aria-label={label}
            title={label}
            className="inline-block size-5 shrink-0 rounded-full border border-line-strong bg-subtle"
            style={hex ? { backgroundColor: hex } : undefined}
        />
    );
}
