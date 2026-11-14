// Storefront maintenance switch (docs/ops/DEPLOYMENT.md §5). Read by proxy.ts on every page
// request. Only the exact value "1" turns it on, so a stray "false"/"0" never takes the shop down.

export const MAINTENANCE_RETRY_AFTER_SECONDS = 600;

export function maintenanceOn(env: Record<string, string | undefined>): boolean {
    return env.STOREFRONT_MAINTENANCE?.trim() === '1';
}
