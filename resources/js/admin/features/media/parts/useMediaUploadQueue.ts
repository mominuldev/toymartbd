import { useQueryClient } from '@tanstack/react-query';
import { useI18n } from '@/i18n/I18nProvider';
import { MAX_UPLOAD_BYTES, fetchMedia, mediaKeys, uploadMedia, type Media } from '../api';
import { formatBytes } from './format';
import { useUploadQueue } from './useUploadQueue';

type Options = {
    /** The folder new files land in; null = not filed. */
    folderId: number | null;
    /** Each file the server accepted, in completion order. Failed files never arrive here. */
    onUploaded?: (media: Media, file: File) => void;
    concurrency?: number;
};

/**
 * `useUploadQueue` wired to POST /media: the 10 MB pre-check (the server re-checks by
 * content), polling GET /media/{id} until renditions are ready, and a refresh of the list,
 * the rail counts and the storage meter after each accepted file.
 */
export function useMediaUploadQueue({ folderId, onUploaded, concurrency = 2 }: Options) {
    const { t } = useI18n();
    const client = useQueryClient();

    return useUploadQueue<Media>({
        concurrency,
        upload: (file, onProgress, signal) => uploadMedia(file, { folderId }, onProgress, signal),
        precheck: (file) => (file.size > MAX_UPLOAD_BYTES ? t('media.queue.tooLarge', { size: formatBytes(file.size) }) : null),
        isProcessing: (media) => media.processing_status === 'processing',
        refresh: (media) =>
            fetchMedia(media.id).then((fresh) => {
                if (fresh.processing_status !== 'processing') void client.invalidateQueries({ queryKey: mediaKeys.lists });
                return fresh;
            }),
        networkError: t('media.queue.network'),
        onUploaded: (media, file) => {
            void client.invalidateQueries({ queryKey: mediaKeys.all });
            onUploaded?.(media, file);
        },
    });
}
