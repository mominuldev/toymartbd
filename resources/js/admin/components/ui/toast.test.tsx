import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/i18n/I18nProvider';
import { dismissDirection, ToastProvider, useToast } from './Toast';

let api: ReturnType<typeof useToast>;
function Grab() {
    api = useToast();
    return null;
}

function mount() {
    render(
        <I18nProvider initialLocale="en">
            <ToastProvider>
                <Grab />
            </ToastProvider>
        </I18nProvider>,
    );
}

const messages = () => screen.queryAllByRole('status').map((el) => el.textContent);

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
    // Reduced motion: dismissals fade on a timer rather than flying on rAF, so fake timers drive them.
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce'), addEventListener() {}, removeEventListener() {} }));
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('toast stack', () => {
    it('stacks newest first inside one labelled live region', () => {
        mount();
        act(() => {
            api.notify('Saved brand');
            api.notify('Could not book', 'danger');
        });
        expect(messages()).toEqual(['Could not book', 'Saved brand']);
        const region = screen.getByRole('region', { name: 'Notifications' });
        expect(region.querySelector('[aria-live="polite"]')).toBeTruthy();
    });

    it('auto-dismisses success after 5s but keeps an error until closed', () => {
        mount();
        act(() => {
            api.notify('Saved brand');
            api.notify('Could not book', 'danger');
        });
        act(() => vi.advanceTimersByTime(5000 + 250));
        expect(messages()).toEqual(['Could not book']);

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        act(() => vi.advanceTimersByTime(250));
        expect(messages()).toEqual([]);
    });

    it('pauses auto-dismiss while hovered and resumes with the time left', () => {
        mount();
        act(() => api.notify('Saved brand'));
        act(() => vi.advanceTimersByTime(3000));
        const region = screen.getByRole('region', { name: 'Notifications' });
        fireEvent.pointerEnter(region, { pointerType: 'mouse' });
        act(() => vi.advanceTimersByTime(10_000));
        expect(messages()).toEqual(['Saved brand']);

        fireEvent.pointerLeave(region, { pointerType: 'mouse' });
        act(() => vi.advanceTimersByTime(1900));
        expect(messages()).toEqual(['Saved brand']);
        act(() => vi.advanceTimersByTime(400));
        expect(messages()).toEqual([]);
    });

    it('dismisses the focused card with Delete and keeps focus in the stack', () => {
        mount();
        act(() => {
            api.notify('First', 'danger');
            api.notify('Second', 'danger');
        });
        const [front] = screen.getAllByRole('status');
        act(() => front!.focus());
        fireEvent.keyDown(front!, { key: 'Delete' });
        act(() => vi.advanceTimersByTime(250));
        expect(messages()).toEqual(['First']);
        expect(document.activeElement?.textContent).toContain('First');
    });

    it('decides a swipe by distance or by a flick away from rest', () => {
        expect(dismissDirection(40, 0)).toBe(0);
        expect(dismissDirection(120, 0)).toBe(1);
        expect(dismissDirection(-120, 0)).toBe(-1);
        expect(dismissDirection(30, 0.5)).toBe(1);
        // A flick back toward the middle returns the card.
        expect(dismissDirection(30, -0.5)).toBe(0);
    });
});
