import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Schema } from '@toymart/api-types';
import type { PublishCmsLayoutRequestInput, ReorderCmsBlocksRequestInput, RevertCmsLayoutRequestInput, StoreCmsBlockRequestInput } from '@toymart/api-types/zod';
import { api } from '@/lib/api';

/*
 * Homepage builder client — docs/api/cms-blocks.md. Shapes come from the OpenAPI document
 * (rule 27). A block's `content` is per-type JSON whose schema lives server-side
 * (App\Domain\Content\Blocks\Schemas); `content.ts` narrows it for the settings forms, and the
 * server's 422 `errors[]` (`content.slides.0.headline` …) are shown against the fields.
 */

export type CmsBlock = Schema<'CmsBlock'>;
export type BlockType = CmsBlock['type'];
export type CmsLayoutVersion = Schema<'CmsLayoutVersion'>;
export type CmsPreviewToken = Schema<'CmsPreviewToken'>;
export type CmsHomeDraft = Omit<Schema<'CmsHomeDraft'>, 'blocks' | 'live'> & { blocks: CmsBlock[]; live: CmsLayoutVersion | null };
export type BlockPatch = Partial<Pick<CmsBlock, 'label' | 'is_enabled' | 'show_en' | 'show_bn' | 'show_mobile' | 'show_desktop' | 'starts_at' | 'ends_at'>> & { content?: Record<string, unknown> };

const keys = {
    draft: ['content-home', 'draft'] as const,
    versions: ['content-home', 'versions'] as const,
};

export function useHomeDraft() {
    return useQuery({ queryKey: keys.draft, queryFn: () => api<CmsHomeDraft>('/content/home') });
}

export function useHomeVersions(enabled: boolean) {
    return useQuery({ queryKey: keys.versions, queryFn: () => api<{ data: CmsLayoutVersion[] }>('/content/home/versions').then((b) => b.data), enabled });
}

/** Writes return the block; the draft query is patched in place so the list never flickers. */
function usePatchDraft() {
    const client = useQueryClient();
    return {
        upsert(block: CmsBlock) {
            client.setQueryData<CmsHomeDraft>(keys.draft, (draft) =>
                draft ? { ...draft, has_unpublished_changes: true, blocks: draft.blocks.map((b) => (b.id === block.id ? block : b)) } : draft,
            );
        },
        refetch() {
            return client.invalidateQueries({ queryKey: keys.draft });
        },
        refetchVersions() {
            return client.invalidateQueries({ queryKey: keys.versions });
        },
    };
}

export function useCreateBlock() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: (input: StoreCmsBlockRequestInput) => api<CmsBlock>('/content/home/blocks', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useUpdateBlock() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: ({ id, patch }: { id: number; patch: BlockPatch }) => api<CmsBlock>(`/content/home/blocks/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
        onSuccess: (block) => draft.upsert(block),
    });
}

export function useDeleteBlock() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: (id: number) => api<void>(`/content/home/blocks/${id}`, { method: 'DELETE' }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useDuplicateBlock() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: (id: number) => api<CmsBlock>(`/content/home/blocks/${id}/duplicate`, { method: 'POST' }),
        onSuccess: () => void draft.refetch(),
    });
}

export function useReorderBlocks() {
    const client = useQueryClient();
    return useMutation({
        mutationFn: (input: ReorderCmsBlocksRequestInput) => api<CmsHomeDraft>('/content/home/order', { method: 'PUT', body: JSON.stringify(input) }),
        onMutate: (input) => {
            // Optimistic: the list moves at once; a refusal refetches the server order.
            client.setQueryData<CmsHomeDraft>(keys.draft, (draft) => {
                if (!draft) return draft;
                const byId = new Map(draft.blocks.map((b) => [b.id, b]));
                const blocks = input.ids.map((id, position) => byId.get(id)).filter((b): b is CmsBlock => b !== undefined).map((b, position) => ({ ...b, position }));
                return { ...draft, blocks, has_unpublished_changes: true };
            });
        },
        onSuccess: (draft) => client.setQueryData(keys.draft, draft),
        onError: () => void client.invalidateQueries({ queryKey: keys.draft }),
    });
}

export function usePublish() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: (input: PublishCmsLayoutRequestInput) => api<CmsLayoutVersion>('/content/home/publish', { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => {
            void draft.refetch();
            void draft.refetchVersions();
        },
    });
}

export function useRevert() {
    const draft = usePatchDraft();
    return useMutation({
        mutationFn: ({ versionId, ...input }: RevertCmsLayoutRequestInput & { versionId: number }) =>
            api<CmsLayoutVersion | CmsHomeDraft>(`/content/home/versions/${versionId}/revert`, { method: 'POST', body: JSON.stringify(input) }),
        onSuccess: () => {
            void draft.refetch();
            void draft.refetchVersions();
        },
    });
}

/** A fresh preview token + URL. Tokens live 10 minutes; the builder re-mints one every 8. */
export function usePreviewToken(locale: 'en' | 'bn', enabled: boolean) {
    return useQuery({
        queryKey: ['content-home', 'preview-token', locale],
        queryFn: () => api<CmsPreviewToken>('/content/home/preview-token', { method: 'POST', body: JSON.stringify({ locale }) }),
        enabled,
        staleTime: 8 * 60 * 1000,
        refetchInterval: 8 * 60 * 1000,
        refetchOnWindowFocus: false,
    });
}
