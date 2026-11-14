import { describe, expect, it } from 'vitest';

import { maintenanceOn } from '../lib/maintenance';

describe('storefront maintenance switch', () => {
    it('is on only for the exact value "1"', () => {
        expect(maintenanceOn({ STOREFRONT_MAINTENANCE: '1' })).toBe(true);
        expect(maintenanceOn({ STOREFRONT_MAINTENANCE: ' 1 ' })).toBe(true);
        for (const value of [undefined, '', '0', 'false', 'true', 'yes']) {
            expect(maintenanceOn({ STOREFRONT_MAINTENANCE: value })).toBe(false);
        }
    });
});
