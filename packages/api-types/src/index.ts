// Types generated from docs/api/openapi.json by `npm run api-types:generate`.
// Never hand-edit src/schema.ts; regenerate after `composer openapi:export`.
export type { components, operations, paths } from './schema';

import type { components } from './schema';

/** Shorthand for a response/request schema: `Schema<'AuthUserResource'>`. */
export type Schema<Name extends keyof components['schemas']> = components['schemas'][Name];
