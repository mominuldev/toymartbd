// The "4 · ball · 4" illustration from the 404 hero (Figma 163:9448, 560 × 300; the 390 frame
// draws it at 300 × 160). Geometry is the Figma export; every fill is a colour token so it
// follows the theme: the 4s in text/brand, the four-hue ball in bg/brand, bg/accent, bg/teal and
// bg/grape (the §1 diamond motif), a border/brand-subtle floor shadow, surface hub and shine.
const FOUR =
    'M111.8 238.08C104 238.08 98.4533 236.953 95.16 234.7C91.8667 232.273 89.8733 229.24 89.18 225.6C88.4867 221.787 88.14 217.973 88.14 214.16V187.12H24.96C17.16 187.12 11.6133 185.993 8.32 183.74C5.02667 181.313 3.03333 178.28 2.34 174.64C1.64667 170.827 1.3 167.013 1.3 163.2C1.3 161.293 1.3 159.733 1.3 158.52C1.47333 157.307 1.73333 156.093 2.08 154.88L22.1 74.02C24.3533 64.4867 27.6467 58.7667 31.98 56.86C36.3133 54.9533 42.8133 55.04 51.48 57.12C57.8933 58.8533 62.8333 61.3667 66.3 64.66C69.94 67.78 71.24 71.5933 70.2 76.1L54.08 142.4H88.14V78.7C88.14 74.8867 88.4867 71.3333 89.18 68.04C89.8733 64.5733 91.8667 61.7133 95.16 59.46C98.6267 57.2067 104.347 56.08 112.32 56.08C120.12 56.08 125.667 57.2933 128.96 59.72C132.253 61.9733 134.247 64.92 134.94 68.56C135.633 72.2 135.98 76.0133 135.98 80V214.42C135.98 218.407 135.547 222.22 134.68 225.86C133.987 229.5 131.993 232.447 128.7 234.7C125.407 236.953 119.773 238.08 111.8 238.08Z';

export function NotFoundIllustration({ className = '' }: { className?: string }) {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 560 300" className={`h-auto shrink-0 ${className}`}>
            <path d={FOUR} className="fill-fg-brand" />
            <ellipse cx="280" cy="277" rx="80" ry="11" className="fill-line-brand-subtle" />
            <path
                d="M350.711 79.2893C359.997 88.5752 367.362 99.5991 372.388 111.732C377.413 123.864 380 136.868 380 150C380 163.132 377.413 176.136 372.388 188.268C367.362 200.401 359.997 211.425 350.711 220.711L280 150L350.711 79.2893Z"
                className="fill-brand"
            />
            <path d="M350.711 220.711C331.957 239.464 306.522 250 280 250C253.478 250 228.043 239.464 209.289 220.711L280 150L350.711 220.711Z" className="fill-accent" />
            <path d="M209.289 220.711C190.536 201.957 180 176.522 180 150C180 123.478 190.536 98.043 209.289 79.2893L280 150L209.289 220.711Z" className="fill-teal" />
            <path d="M209.289 79.2893C228.043 60.5357 253.478 50 280 50C306.522 50 331.957 60.5357 350.711 79.2893L280 150L209.289 79.2893Z" className="fill-grape" />
            <circle cx="280" cy="150" r="22" className="fill-surface" />
            <ellipse cx="240.297" cy="99.4567" rx="23" ry="13" transform="rotate(-35 240.297 99.4567)" className="fill-surface" />
            <path d={FOUR} transform="translate(400 0)" className="fill-fg-brand" />
            <circle cx="157" cy="13" r="7" className="fill-accent" />
            <circle cx="391" cy="5" r="5" className="fill-teal" />
            <circle cx="528" cy="258" r="8" className="fill-grape" />
            <circle cx="135" cy="273" r="5" className="fill-brand" />
            <circle cx="552" cy="44" r="4" className="fill-accent" />
        </svg>
    );
}
