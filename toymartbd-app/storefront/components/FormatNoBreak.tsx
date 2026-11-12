import { Fragment, type ReactNode } from 'react';

// `format()` for strings that carry a value which must never break across lines — a phone number
// such as 09610-123456 otherwise wraps at its hyphen ("09610-" / "123456") in the narrower Bangla
// trust tiles at 390 (P4-I18N-02). Each `{name}` listed in `keep` is wrapped in a nowrap,
// tabular-figure span; the rest is plain text, exactly as `format()` would produce.
export function formatNoBreak(template: string, values: Record<string, string>, keep: readonly string[]): ReactNode {
    return template.split(/(\{\w+\})/g).map((part, i) => {
        const name = /^\{(\w+)\}$/.exec(part)?.[1];
        if (name === undefined || values[name] === undefined) return <Fragment key={i}>{part}</Fragment>;
        return keep.includes(name) ? (
            <span key={i} className="whitespace-nowrap tabular-nums">
                {values[name]}
            </span>
        ) : (
            <Fragment key={i}>{values[name]}</Fragment>
        );
    });
}
