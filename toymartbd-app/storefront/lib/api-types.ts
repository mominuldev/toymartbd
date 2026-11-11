// Contract types come from the OpenAPI spec (CLAUDE.md rule 27): `npm run api-types:generate`.
import type { Schema } from '@toymart/api-types';

export type Brand = Schema<'StorefrontBrand'>;

export interface BrandListResponse {
    data: Brand[];
}

/** `GET /brands/{slug}` — accepted both bare and wrapped in `{data}` (Laravel API Resource default). */
export type BrandResponse = Brand | { data: Brand };

/** RFC 9457 problem details (CLAUDE.md rule 18). */
export interface ProblemDetails {
    type: string;
    title: string;
    status: number;
    detail?: string;
    [extension: string]: unknown;
}
