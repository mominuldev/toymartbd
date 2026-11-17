import { useMutation, useQuery } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { ProductCopyRequestInput } from '@toymart/api-types/zod';
import { ApiError, api } from '@/lib/api';

/** `POST /products/{product}/ai-copy` (Figma 538:82911) — a draft only; nothing is saved. */
export type ProductCopyDraft = Schema<'ProductCopyDraft'>;
export type ProductCopy = NonNullable<ProductCopyDraft['en']>;
export type CopyFact = ProductCopyDraft['facts'][number];
/** The request body — the generated type, never a hand-written copy (rule 27). */
export type ProductCopyRequest = ProductCopyRequestInput;
export type CopyField = ProductCopyRequest['fields'][number];
export type CopyLanguage = ProductCopyRequest['language'];
export type CopyTone = ProductCopyRequest['tone'];

export const copyFields: readonly CopyField[] = ['title', 'description', 'seo'];
export const copyLanguages: readonly CopyLanguage[] = ['en', 'bn', 'both'];
export const copyTones: readonly CopyTone[] = ['warm', 'playful', 'premium'];

/** Server character limits (App\Domain\Ai\ProductCopy\ProductCopyWriter::LIMITS) — for the counters only. */
export const copyLimits = { title: 70, seo_title: 60, meta_description: 160 } as const;

/** `GET /products/{product}/ai-copy/facts` — the facts listed before the first draft (no AI call). */
export function useProductCopyFacts(productId: number) {
    return useQuery({
        queryKey: ['products', productId, 'ai-copy-facts'],
        queryFn: () => api<Schema<'ProductCopyFacts'>>(`/products/${productId}/ai-copy/facts`),
    });
}

export function useWriteProductCopy(productId: number) {
    return useMutation({
        mutationFn: (body: ProductCopyRequest) => api<ProductCopyDraft>(`/products/${productId}/ai-copy`, { method: 'POST', body: JSON.stringify(body) }),
    });
}

/** 409 `ai-not-shared` from this endpoint: product details are switched off in AI sharing. */
export function isNotShared(error: unknown): boolean {
    return error instanceof ApiError && error.is('ai-not-shared');
}

/** The texts "Use these texts" hands to the product form: per language, only what was written. */
export type CopyTexts = Partial<Record<'en' | 'bn', Partial<Record<'title' | 'description_html' | 'seo_title' | 'meta_description', string>>>>;

/** Product form paths each text fills: EN into the English fields, BN into the Bangla ones, SEO into the SEO section. Never the slug (rule 37). */
export type ProductCopyPath = 'name' | 'name_bn' | 'description' | 'description_bn' | 'seo.title' | 'seo.title_bn' | 'seo.description' | 'seo.description_bn';

export function productFieldsFromCopy(texts: CopyTexts): [ProductCopyPath, string][] {
    const out: [ProductCopyPath, string][] = [];
    for (const lang of ['en', 'bn'] as const) {
        const copy = texts[lang];
        if (!copy) continue;
        const bn = lang === 'bn';
        if (copy.title !== undefined) out.push([bn ? 'name_bn' : 'name', copy.title]);
        if (copy.description_html !== undefined) out.push([bn ? 'description_bn' : 'description', copy.description_html]);
        if (copy.seo_title !== undefined) out.push([bn ? 'seo.title_bn' : 'seo.title', copy.seo_title]);
        if (copy.meta_description !== undefined) out.push([bn ? 'seo.description_bn' : 'seo.description', copy.meta_description]);
    }
    return out;
}
