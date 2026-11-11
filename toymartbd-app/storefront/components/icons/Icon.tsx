import { iconPaths, type IconName } from './paths';

interface Props {
    name: IconName;
    /** Sizing utility from the icon tokens, e.g. `size-icon-md`. */
    className?: string;
}

// Decorative by default: every icon sits beside visible text or inside a control with an
// accessible name, so it is hidden from assistive technology. Colour follows `currentColor`.
export function Icon({ name, className = 'size-icon-md' }: Props) {
    return (
        <svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false" className={`shrink-0 ${className}`}>
            {iconPaths[name].map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}
