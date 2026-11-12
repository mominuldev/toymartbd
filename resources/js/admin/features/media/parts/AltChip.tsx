import { StatusChip } from '@/components/ui/StatusChip';
import { useI18n } from '@/i18n/I18nProvider';
import type { MessageKey } from '@/i18n/messages';

export type AltState = 'complete' | 'missingBn' | 'missingEn' | 'missingBoth' | 'notNeeded';

/** Which alt texts an asset still needs. A PDF needs none (A25 — "No alt needed"). */
export function altState(kind: string, altEn: string | null | undefined, altBn: string | null | undefined): AltState {
    if (kind !== 'image') return 'notNeeded';
    const en = Boolean(altEn?.trim());
    const bn = Boolean(altBn?.trim());
    if (en && bn) return 'complete';
    if (!en && !bn) return 'missingBoth';
    return en ? 'missingBn' : 'missingEn';
}

const looks: Record<AltState, { tone: 'success' | 'warning' | 'danger' | 'neutral'; tile: MessageKey; row: MessageKey }> = {
    complete: { tone: 'success', tile: 'media.alt.complete', row: 'media.alt.completeShort' },
    missingBn: { tone: 'warning', tile: 'media.alt.missingBn', row: 'media.alt.missingBn' },
    missingEn: { tone: 'warning', tile: 'media.alt.missingEn', row: 'media.alt.missingEn' },
    missingBoth: { tone: 'danger', tile: 'media.alt.missingBoth', row: 'media.alt.missingBothShort' },
    notNeeded: { tone: 'neutral', tile: 'media.alt.notNeeded', row: 'media.alt.notNeededShort' },
};

/**
 * The alt-text StatusChip on a MediaTile (Figma 289:2121) and in the list view's Alt text
 * column (303:53979, `form="row"`): Success "Alt EN · বাং", Warning "বাং alt missing",
 * Danger "Alt missing", Neutral "No alt needed". Icon shape + tint, never colour alone.
 */
export function AltChip({ state, form = 'tile' }: { state: AltState; form?: 'tile' | 'row' }) {
    const { t } = useI18n();
    const look = looks[state];
    return <StatusChip tone={look.tone} label={t(form === 'tile' ? look.tile : look.row)} />;
}
