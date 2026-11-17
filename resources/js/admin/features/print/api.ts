import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { UpdatePrintSettingsRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/** Settings › Invoices, receipts & labels: what each printed document shows (GET /settings/print). */
export type PrintSettings = Schema<'PrintSettings'>;
export type InvoicePrint = PrintSettings['invoice'];
export type ReceiptPrint = PrintSettings['receipt'];
export type LabelsPrint = PrintSettings['labels'];
/** The PUT body — the generated request type (rule 27). */
export type PrintSettingsInput = UpdatePrintSettingsRequestInput;

export const printSettingsKey = ['settings', 'print'] as const;

export function usePrintSettings(enabled = true) {
    return useQuery({ queryKey: printSettingsKey, queryFn: () => api<PrintSettings>('/settings/print'), enabled });
}

/** Saves one or more whole documents; the response is the full settings, written to the cache. */
export function useSavePrintSettings() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: PrintSettingsInput) => api<PrintSettings>('/settings/print', { method: 'PUT', body: JSON.stringify(input) }),
        onSuccess: (data) => client.setQueryData(printSettingsKey, data),
    });
}
