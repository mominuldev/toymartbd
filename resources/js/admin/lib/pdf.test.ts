import { afterEach, describe, expect, it, vi } from 'vitest';
import { printPdfBlob } from './pdf';

describe('printPdfBlob', () => {
    afterEach(() => {
        document.querySelectorAll('iframe[data-print-frame]').forEach((f) => f.remove());
        vi.restoreAllMocks();
    });

    it('prints from a hidden frame instead of opening a tab', () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        URL.createObjectURL = vi.fn(() => 'blob:pdf-1');
        URL.revokeObjectURL = vi.fn();

        printPdfBlob(new Blob(['%PDF'], { type: 'application/pdf' }));

        const frame = document.querySelector<HTMLIFrameElement>('iframe[data-print-frame]');
        expect(frame).toBeTruthy();
        expect(frame!.getAttribute('src')).toBe('blob:pdf-1');
        expect(frame!.style.visibility).toBe('hidden');
        const print = vi.fn();
        Object.defineProperty(frame!, 'contentWindow', { value: { focus: vi.fn(), print } });
        frame!.onload?.(new Event('load'));
        expect(print).toHaveBeenCalledOnce();
        expect(open).not.toHaveBeenCalled();
    });

    it('keeps one frame at a time', () => {
        URL.createObjectURL = vi.fn(() => 'blob:pdf');
        printPdfBlob(new Blob(['a']));
        printPdfBlob(new Blob(['b']));
        expect(document.querySelectorAll('iframe[data-print-frame]')).toHaveLength(1);
    });
});
