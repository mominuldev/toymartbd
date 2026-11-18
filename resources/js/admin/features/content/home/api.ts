import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { PublishCmsLayoutRequestInput, ReorderCmsBlocksRequestInput, RevertCmsLayoutRequestInput, StoreCmsBlockRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/*
 * Section builder client — docs/api/cms-blocks.md. One client for every layout: the homepage
 * (`/content/home`) and each static page (`/content/pages/{id}/layout`, owner decision
 * 2026-11-16) share the same endpoints and shapes under their own base path. Shapes come from the
 * OpenAPI document (rule 27). A block's `content` is per-type JSON whose schema lives server-side
 * (App\Domain\Content\Blocks\Schemas); `content.ts` narrows it for the settings forms, and the
 * server's 422 `errors[]` (`content.slides.0.headline` …) are shown against the fields.
 */

export type CmsBlock = Schema<'CmsBlock'>;
export type BlockType = CmsBlock['type'];
export type CmsLayoutVersion = Schema<'CmsLayoutVersion'>;
export type CmsPreviewToken = Schema<'CmsPreviewToken'>;
export type CmsHomeDraft = Omit<Schema<'CmsHomeDraft'>, 'blocks' | 'live'> & { blocks: CmsBlock[]; live: CmsLayoutVersion | null };
export type BlockPatch = Partial<Pick<CmsBlock, 'label' | 'is_enabled' | 'show_en' | 'show_bn' | 'show_mobile' | 'show_desktop' | 'starts_at' | 'ends_at'>> & { content?: Record<string, unknown> };

/** Where a layout's endpoints live: the homepage, or one static page. */
export type LayoutBase = '/content/home' | `/content/pages/${number}/layout`;

export const homeLayout: LayoutBase = '/content/home';

export function pageLayout(pageId: number): LayoutBase {
    return `/content/pages/${pageId}/layout`;
}

/** Each layout has its own cache, so the homepage and a page never share a draft. */
const keys = {
    draft: (base: LayoutBase) => ['content-layout', base, 'draft'] as const,
    versions: (base: LayoutBase) => ['content-layout', base, 'versions'] as const,
    previewToken: (base: LayoutBase, locale: 'en' | 'bn') => ['content-layout', base, 'preview-token', locale] as const,
};

export function useLayoutDraft(base: LayoutBase) {
    return useQuery({ queryKey: keys.draft(base), queryFn: () => api<CmsHomeDraft>(base) });
}

export function useLayoutVersions(base: LayoutBase, enabled: boolean) {
    return useQuery({ queryKey: keys.versions(base), queryFn: () => api<{ data: CmsLayoutVersion[] }>(`${base}/versions`).then((b) => b.data), enabled });
}

/** Writes return the block; the draft query is patched in place so the list never flickers. */
function usePatchDraft(base: LayoutBase) {
    const client = useQueryClient();
    return {
        upsert(block: CmsBlock) {
            client.setQueryData<CmsHomeDraft>(keys.draft(base), (draft) =>
                draft ? { ...draft, has_unpublished_changes: true, blocks: draft.blocks.map((b) => (b.id === block.id ? block : b)) } : draft,
            );
        },
        refetch() {
            return client.invalidateQueries({ queryKey: keys.draft(base) });
        },
        refetchVersions() {
            return client.invalidateQueries({ queryKey: keys.versions(base) });
        },
    };
}

export function useCreateBlock(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: (input: StoreCmsBlockRequestInput) => api<CmsBlock>(`${base}/blocks`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useUpdateBlock(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: ({ id, patch }: { id: number; patch: BlockPatch }) => api<CmsBlock>(`${base}/blocks/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
        onSuccess: (block) => draft.upsert(block),
    });
}

export function useDeleteBlock(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: (id: number) => api<void>(`${base}/blocks/${id}`, { method: 'DELETE' }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useDuplicateBlock(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: (id: number) => api<CmsBlock>(`${base}/blocks/${id}/duplicate`, { method: 'POST' }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useReorderBlocks(base: LayoutBase) {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: ReorderCmsBlocksRequestInput) => api<CmsHomeDraft>(`${base}/order`, { method: 'PUT', body: JSON.stringify(input) }),
        onMutate: (input) => {
            // Optimistic: the list moves at once; a refusal refetches the server order.
            client.setQueryData<CmsHomeDraft>(keys.draft(base), (draft) => {
                if (!draft) return draft;
                const byId = new Map(draft.blocks.map((b) => [b.id, b]));
                const blocks = input.ids.map((id) => byId.get(id)).filter((b): b is CmsBlock => b !== undefined).map((b, position) => ({ ...b, position }));
                return { ...draft, blocks, has_unpublished_changes: true };
            });
        },
        onSuccess: (draft) => client.setQueryData(keys.draft(base), draft),
        onError: () => void client.invalidateQueries({ queryKey: keys.draft(base) }),
    });
}

export function usePublish(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: (input: PublishCmsLayoutRequestInput) => api<CmsLayoutVersion>(`${base}/publish`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => {
            void draft.refetch();
            void draft.refetchVersions();
        },
    });
}

export function useRevert(base: LayoutBase) {
    const draft = usePatchDraft(base);
    return useMutation({
        mutationFn: ({ versionId, ...input }: RevertCmsLayoutRequestInput & { versionId: number }) =>
            api<CmsLayoutVersion | CmsHomeDraft>(`${base}/versions/${versionId}/revert`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => {
            void draft.refetch();
            void draft.refetchVersions();
        },
    });
}

/** Mints a one-off preview token + URL, for a preview opened outside the builder (the Pages row menu). */
export function useIssuePreview() {
    return useMutation({
        mutationFn: ({ base, locale }: { base: LayoutBase; locale: 'en' | 'bn' }) =>
            api<CmsPreviewToken>(`${base}/preview-token`, { method: 'POST', body: JSON.stringify({ locale }) }),
    });
}

/** A fresh preview token + URL. Tokens live 10 minutes; the builder re-mints one every 8. */
export function usePreviewToken(base: LayoutBase, locale: 'en' | 'bn', enabled: boolean) {
    return useQuery({
        queryKey: keys.previewToken(base, locale),
        queryFn: () => api<CmsPreviewToken>(`${base}/preview-token`, { method: 'POST', body: JSON.stringify({ locale }) }),
        enabled,
        staleTime: 8 * 60 * 1000,
        refetchInterval: 8 * 60 * 1000,
        refetchOnWindowFocus: false,
    });
}
