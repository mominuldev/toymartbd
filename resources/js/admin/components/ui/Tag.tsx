export type TagTone = 'pink' | 'butter' | 'mint' | 'sky' | 'lavender' | 'peach';

const tones: Record<TagTone, string> = {
    pink: 'bg-tile-pink',
    butter: 'bg-tile-butter',
    mint: 'bg-tile-mint',
    sky: 'bg-tile-sky',
    lavender: 'bg-tile-lavender',
    peach: 'bg-tile-peach',
};

/** Figma Tag 185:92 — customer/product tags; the one place storefront pastels appear. Meaning is in the word. */
export function Tag({ label, tone = 'pink' }: { label: string; tone?: TagTone }) {
    return <span className={`inline-flex h-5.5 items-center rounded-xs px-2 t-admin-caption-strong whitespace-nowrap text-fg-primary ${tones[tone]}`}>{label}</span>;
}
