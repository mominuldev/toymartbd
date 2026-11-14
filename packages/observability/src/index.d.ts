/** Sentry PII scrubbing shared by the admin and the storefront (rule 20). See index.js. */
export declare const REDACTED: '[redacted]';
export declare function maskPhones(value: string): string;
export declare function maskEmails(value: string): string;
export declare function scrubText(value: string): string;
export declare function isSensitiveKey(key: string | number): boolean;
export declare function scrubValue(value: unknown, depth?: number): unknown;
/** Generic over `object` so it fits every Sentry SDK's Event type. */
export declare function scrubEvent<E extends object>(event: E): E;
export declare function scrubBreadcrumb<B extends object>(breadcrumb: B): B;
export declare function sampleRate(raw: string | undefined, fallback: number): number;
export interface BaseSentryOptions {
    dsn: string | undefined;
    enabled: boolean;
    release: string | undefined;
    environment: string;
    sendDefaultPii: false;
    sampleRate: number;
    tracesSampleRate: number;
    beforeSend: typeof scrubEvent;
    beforeSendTransaction: typeof scrubEvent;
    beforeBreadcrumb: typeof scrubBreadcrumb;
}
export declare function baseSentryOptions(env: {
    dsn?: string;
    release?: string;
    environment?: string;
    sampleRate?: string;
    tracesSampleRate?: string;
}): BaseSentryOptions;
