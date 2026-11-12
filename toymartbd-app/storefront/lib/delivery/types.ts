import type { Schema } from '@toymart/api-types';

// Client-safe types for the delivery estimator (P4-STO-06). Generated from the API's OpenAPI
// document — never hand-written twice (rule 27).

export type DeliveryQuote = Schema<'DeliveryQuote'>;
export type DeliveryRates = Schema<'DeliveryRates'>;
export type District = Schema<'StorefrontDistrict'>;
export type Upazila = Schema<'StorefrontUpazila'>;

/** What the customer picked: a district and, where the charge depends on it, an upazila (null = the district's city / no sub-area). */
export interface DeliverySelection {
    districtId: number;
    upazilaId: number | null;
}

/** Everything the estimator needs before a district is chosen; null fields mean the API could not supply them. */
export interface DeliveryReference {
    rates: DeliveryRates | null;
    districts: District[];
    /** Upazilas of the districts whose charge depends on the upazila (`upazila_required`), by district id. */
    upazilas: Record<number, Upazila[]>;
}
