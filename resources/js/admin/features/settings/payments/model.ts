import type { StatusTone } from '@/components/ui/StatusChip';
import type { MessageKey } from '@/i18n/messages';
import { gatewayModes, type GatewayKeySet, type GatewayMode, type PaymentGateway, type PaymentGatewayInput } from './api';

/**
 * Integrations › Payments form model. Everything here is display or diffing — the server
 * decides whether a gateway is offered (`status`), whether a key set is complete and whether a
 * save is allowed (422). A secret is write-only (rule 20): the form never holds a saved one.
 */

/** A saved secret is kept, replaced by a typed value, or removed. An unsaved one is just typed. */
export type SecretAction = 'keep' | 'replace' | 'remove';
export type KeyDraft = { values: Record<string, string>; secrets: Record<string, SecretAction> };
export type GatewayDraft = { is_active: boolean; mode: GatewayMode; sandbox: KeyDraft; live: KeyDraft };
export type FormValues = { gateways: Record<string, GatewayDraft> };

export function keyDraft(set: GatewayKeySet): KeyDraft {
    return {
        values: Object.fromEntries(set.fields.map((f) => [f.key, f.secret ? '' : (f.value ?? '')])),
        secrets: Object.fromEntries(set.fields.filter((f) => f.secret).map((f) => [f.key, 'keep' as SecretAction])),
    };
}

export function toDraft(g: PaymentGateway): GatewayDraft {
    return { is_active: g.is_active, mode: g.mode, sandbox: keyDraft(g.credentials.sandbox), live: keyDraft(g.credentials.live) };
}

/** What changed, as labels the footer lists: a switch, the mode, or one key in one mode. */
export type Change = { kind: 'is_active' } | { kind: 'mode' } | { kind: 'key'; mode: GatewayMode; key: string };

/**
 * Only what changed (the PUT body): a typed value is sent, an emptied non-secret or a removed
 * secret is cleared, a secret left blank is never sent (blank would keep it anyway).
 */
export function diff(g: PaymentGateway, d: GatewayDraft): { input: PaymentGatewayInput; changes: Change[] } {
    const input: PaymentGatewayInput = {};
    const changes: Change[] = [];
    if (d.is_active !== g.is_active) {
        input.is_active = d.is_active;
        changes.push({ kind: 'is_active' });
    }
    if (d.mode !== g.mode) {
        input.mode = d.mode;
        changes.push({ kind: 'mode' });
    }
    for (const mode of gatewayModes) {
        const set: Record<string, string> = {};
        const clear: string[] = [];
        for (const field of g.credentials[mode].fields) {
            const typed = (d[mode].values[field.key] ?? '').trim();
            if (field.secret) {
                if (field.set && d[mode].secrets[field.key] === 'remove') clear.push(field.key);
                else if (typed !== '' && (!field.set || d[mode].secrets[field.key] === 'replace')) set[field.key] = typed;
            } else if (typed === '' && field.set) {
                clear.push(field.key);
            } else if (typed !== '' && typed !== (field.value ?? '')) {
                set[field.key] = typed;
            }
        }
        for (const key of [...Object.keys(set), ...clear]) changes.push({ kind: 'key', mode, key });
        if (Object.keys(set).length > 0) input.credentials = { ...input.credentials, [mode]: set };
        if (clear.length > 0) input.clear = { ...input.clear, [mode]: clear };
    }
    return { input, changes };
}

export function savedCount(set: GatewayKeySet): { saved: number; total: number } {
    return { saved: set.fields.filter((f) => f.set).length, total: set.fields.length };
}

type Reason = NonNullable<PaymentGateway['status']['reason']>;

/** The env chip: Sandbox (warning) or Live (success); the live shop on sandbox wins as danger. */
export function envChip(g: PaymentGateway): { tone: StatusTone; label: MessageKey } {
    if (g.status.reason === 'sandbox_in_production') return { tone: 'danger', label: 'paymentGateways.status.sandboxInProduction' };
    return g.mode === 'live' ? { tone: 'success', label: 'paymentGateways.env.live' } : { tone: 'warning', label: 'paymentGateways.env.sandbox' };
}

/** The tile's dot + caption: offered or hidden, and why — the server's `status`, in words. */
export function tileStatus(g: PaymentGateway): { tone: StatusTone; label: MessageKey } {
    if (g.status.enabled) {
        return { tone: 'success', label: g.mode === 'sandbox' ? 'paymentGateways.tile.offeredSandbox' : 'paymentGateways.tile.offered' };
    }
    const hidden: Record<Reason, { tone: StatusTone; label: MessageKey }> = {
        unverified: { tone: 'warning', label: 'paymentGateways.tile.unverified' },
        sandbox_in_production: { tone: 'danger', label: 'paymentGateways.tile.sandboxInProduction' },
        inactive: { tone: 'neutral', label: 'paymentGateways.tile.inactive' },
        not_configured: { tone: 'warning', label: 'paymentGateways.tile.notConfigured' },
        not_registered: { tone: 'neutral', label: 'paymentGateways.tile.notRegistered' },
    };
    return g.status.reason ? hidden[g.status.reason] : hidden.inactive;
}

export type Fact = { tone: StatusTone; chip: MessageKey; text: MessageKey; danger?: boolean };

/** Status › "At checkout": the chip and the reason line, per the server's `status.reason`. */
export function checkoutFact(g: PaymentGateway): Fact {
    if (g.status.enabled) {
        return g.mode === 'sandbox'
            ? { tone: 'success', chip: 'paymentGateways.fact.offered', text: 'paymentGateways.fact.offeredSandbox' }
            : { tone: 'success', chip: 'paymentGateways.fact.offered', text: 'paymentGateways.fact.offeredLive' };
    }
    switch (g.status.reason) {
        case 'unverified':
            return { tone: 'warning', chip: 'paymentGateways.fact.hidden', text: 'paymentGateways.fact.unverified' };
        case 'sandbox_in_production':
            return { tone: 'danger', chip: 'paymentGateways.status.sandboxInProduction', text: 'paymentGateways.fact.sandboxInProduction', danger: true };
        case 'not_configured':
            return { tone: 'warning', chip: 'paymentGateways.fact.hidden', text: 'paymentGateways.fact.notConfigured' };
        case 'not_registered':
            return { tone: 'neutral', chip: 'paymentGateways.fact.notAvailable', text: 'paymentGateways.fact.notRegistered' };
        default:
            return { tone: 'neutral', chip: 'paymentGateways.fact.hidden', text: 'paymentGateways.fact.inactive' };
    }
}

/** Status › "Live verification": an owner CLI step after a first real payment; nothing to do here. */
export function verificationFact(g: PaymentGateway): Fact {
    if (g.verified) return { tone: 'success', chip: 'paymentGateways.fact.verified', text: 'paymentGateways.fact.verifiedText' };
    if (g.mode === 'sandbox') return { tone: 'neutral', chip: 'paymentGateways.fact.notNeeded', text: 'paymentGateways.fact.notNeededText' };
    return { tone: 'warning', chip: 'paymentGateways.fact.waiting', text: 'paymentGateways.fact.waitingText' };
}
