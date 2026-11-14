import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { renderApp } from '@/test/renderApp';
import { boardApi, deliveredRow, dispatcher, emptyStats, noIssues, owner, submittedRow, tracking, transitRow } from './board.fixtures';
import { formatMoney } from './boardParts';

afterEach(() => {
    cleanup();
    localStorage.clear();
});

const urls = (calls: ReturnType<typeof boardApi>) => calls.mock.calls.map(([u]) => String(u));

describe('shipments board', () => {
    it('shows the server’s stat tiles and tab counts, and filters on the server', async () => {
        const calls = boardApi(dispatcher);
        renderApp('/shipments');

        expect(await screen.findByText('83.3%')).toBeTruthy();
        expect(screen.getByText('5 of 6 parcels reached the customer')).toBeTruthy();
        expect(screen.getByText('Dhaka 1.1 · outside 2.6')).toBeTruthy();
        expect(screen.getByText('Oldest 5h 25m · chase after 6h')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Submitted\s*1$/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^All\s*3$/ })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /^In transit\s*1$/ }));
        await waitFor(() => expect(urls(calls).some((u) => u.includes('filter%5Btab%5D=in_transit'))).toBe(true));

        fireEvent.change(screen.getByRole('combobox', { name: 'Courier' }), {
            target: { value: 'steadfast' },
        });
        await waitFor(() => expect(urls(calls).some((u) => u.includes('/shipments/board?') && u.includes('filter%5Bprovider%5D=steadfast'))).toBe(true));
        expect(urls(calls).some((u) => u.includes('/shipments/board/stats') && u.includes('filter%5Bprovider%5D=steadfast'))).toBe(true);
    });

    it('renders rows: awaiting-ID status, not-issued consignment, masked phone, cost basis labels', async () => {
        boardApi(dispatcher);
        renderApp('/shipments');

        await screen.findByText('SHP-26-00097');
        const table = screen.getAllByRole('table')[0]!;
        const submitted = within(table).getByText('SHP-26-00097').closest('tr')!;
        expect(within(submitted).getByText('Submitted — awaiting ID')).toBeTruthy();
        expect(within(submitted).getByText('Not issued yet')).toBeTruthy();
        expect(within(submitted).getByText('est.')).toBeTruthy();
        expect(within(submitted).getByText(/017\*\*\*\*5678/)).toBeTruthy();
        expect(within(submitted).getByText('Submitted 08 Nov 9:05 am')).toBeTruthy();

        const transit = within(table).getByText('SHP-26-00096').closest('tr')!;
        expect(within(transit).getByText('“Reached Sylhet hub”')).toBeTruthy();
        expect(within(transit).getByText('poll')).toBeTruthy();
        expect(within(transit).getByText('—')).toBeTruthy();

        const delivered = within(table).getByText('SHP-26-00093').closest('tr')!;
        expect(within(delivered).queryByText('est.')).toBeNull();
        expect(within(delivered).getByText('webhook')).toBeTruthy();
        expect(formatMoney(deliveredRow.cod_amount)).toBe('4,550');
    });

    it('shows the selected shipment’s card: charges, timeline with sources, partial outcome, actions', async () => {
        const calls = boardApi(owner);
        renderApp(`/shipments?shipment=${transitRow.id}`);

        expect(
            await screen.findByRole('heading', {
                name: 'Shipment SHP-26-00096',
            })
        ).toBeTruthy();
        const timeline = screen.getByRole('list', { name: 'Courier timeline' });
        expect(within(timeline).getByText('Partly delivered')).toBeTruthy();
        expect(within(timeline).getByText('“Reached Savar hub”')).toBeTruthy();
        expect(within(timeline).getByText('সাভার হাব')).toBeTruthy();
        expect(within(timeline).getAllByText(/webhook|poll/)).toHaveLength(2);
        expect(screen.getByText('Delivered (1)', { exact: false })).toBeTruthy();
        expect(screen.getByText('Came back (1)', { exact: false })).toBeTruthy();

        const track = screen.getByRole('link', { name: 'Track on Steadfast' });
        expect(track.getAttribute('href')).toBe(tracking.tracking_url);
        expect(screen.getByRole('link', { name: 'Print label' }).getAttribute('href')).toBe('/api/v1/admin/shipments/96/label?locale=en');

        fireEvent.click(screen.getByRole('button', { name: 'Raw events' }));
        expect(screen.getByText('status, cod_amount')).toBeTruthy();
        expect(urls(calls).some((u) => u.endsWith('/shipments/96/tracking'))).toBe(true);
    });

    it('hides Print label without customers.view_pii and says when a partial delivery is not recorded', async () => {
        boardApi(dispatcher, {
            tracking: {
                ...tracking,
                can_print_label: false,
                outcome: {
                    recorded: false,
                    cod_collected: '1330.00',
                    delivered: [],
                    came_back: [],
                },
            },
        });
        renderApp(`/shipments?shipment=${transitRow.id}`);

        await screen.findByRole('heading', { name: 'Shipment SHP-26-00096' });
        expect(screen.queryByRole('link', { name: 'Print label' })).toBeNull();
        expect(screen.getByText(/Which items came back is not recorded yet/)).toBeTruthy();
    });

    it('lists delivery issues with the mapping link and one bar row per courier area', async () => {
        boardApi(dispatcher);
        renderApp('/shipments');

        expect(await screen.findByText('3 came back to us')).toBeTruthy();
        expect(screen.getByText('Latest courier status: Delivery_Failed')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Map it in Courier areas' }).getAttribute('href')).toBe('/shipments/locations');
        const chart = screen.getByRole('table', {
            name: 'Delivery success by courier',
        });
        expect(within(chart).getAllByRole('row')).toHaveLength(3);
        expect(within(chart).getByText('94.1%')).toBeTruthy();
    });

    it('shows empty states and a dash with a reason, never a zero rate', async () => {
        boardApi(dispatcher, { rows: [], stats: emptyStats, issues: noIssues });
        renderApp('/shipments');

        expect(await screen.findByText('No shipments here')).toBeTruthy();
        expect(await screen.findByText('No parcel was delivered or returned in this range')).toBeTruthy();
        expect(screen.getByText('No parcel was delivered in this range')).toBeTruthy();
        expect(screen.getByText('Every booking has a consignment ID')).toBeTruthy();
        expect(screen.queryByText('0%')).toBeNull();
        expect(await screen.findByText('No delivery issues in the last 30 days')).toBeTruthy();
        expect(screen.getByText('No parcel was delivered or returned in the last 30 days.')).toBeTruthy();
        expect(screen.getByText('Pick a shipment to see its tracking')).toBeTruthy();
    });

    it('renders in Bangla with Western digits', async () => {
        boardApi(dispatcher, { rows: [submittedRow] });
        renderApp('/shipments', { locale: 'bn' });

        expect(await screen.findByText('83.3%')).toBeTruthy();
        expect(screen.getByText('জমা দেওয়া — আইডির অপেক্ষায়')).toBeTruthy();
    });
});
