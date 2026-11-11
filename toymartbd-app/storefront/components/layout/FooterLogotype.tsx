import { getMessages } from '@/lib/i18n';

// The giant multicolour "toymart" in the footer (Figma Footer 124:3310, node 153:2135): Fredoka
// Bold, each letter a different pastel, 300px on the 1440 frame and 96px on the 390 frame.
// Decorative logotype, hidden from screen readers.
//
// Why an SVG: the text style (Latin/Logotype/Desktop, t-logotype: 300px, −3% tracking) is really
// "fill the content width". Drawn as SVG text, the font size and tracking are in viewBox units
// (300 / −9 = −3%), and the whole word scales with the element's width. The viewBox is the
// measured ink box of the word in Fredoka Bold.
// Letter inks: color/deco/* (Figma 153:2135 text ranges).
const inks = ['fill-deco-coral', 'fill-deco-sun', 'fill-deco-sky', 'fill-deco-pink', 'fill-deco-mint', 'fill-deco-grape', 'fill-deco-coral'] as const;

export function FooterLogotype({ className = '' }: { className?: string }) {
    const w = getMessages('en').site.wordmark;
    const letters = [...`${w.first}${w.second}`.toLowerCase()];
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 1060 336" className={`h-auto ${className}`}>
            <text x="530" y="262" textAnchor="middle" fontSize="300" letterSpacing="-9" style={{ fontFamily: 'var(--tm-font-display-latin)', fontWeight: 700 }}>
                {letters.map((letter, i) => (
                    <tspan key={i} className={inks[i % inks.length]}>
                        {letter}
                    </tspan>
                ))}
            </text>
        </svg>
    );
}
