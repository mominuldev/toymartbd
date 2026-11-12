import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '@/lib/api';

export type UploadStatus = 'queued' | 'uploading' | 'processing' | 'done' | 'failed';

export type UploadItem<T> = {
    /** Client id; stable for the life of the queue row. */
    key: string;
    file: File;
    status: UploadStatus;
    loaded: number;
    total: number;
    /** The server's reason, verbatim (422 `errors[file]`, else the problem detail/title). */
    error: string | null;
    /** The server's record once the upload is accepted. Never set on a failed item. */
    result: T | null;
};

export type UploadFn<T> = (file: File, onProgress: (loaded: number, total: number) => void, signal: AbortSignal) => Promise<T>;

export type UploadQueueOptions<T> = {
    upload: UploadFn<T>;
    /** Parallel uploads. 2 keeps a 4G uplink busy without starving the progress of each file. */
    concurrency?: number;
    /** A client-side pre-check (UX only; the server is authoritative). Return a reason to fail the file without sending it. */
    precheck?: (file: File) => string | null;
    /** True while the server is still making renditions for this record. */
    isProcessing?: (result: T) => boolean;
    /** Re-reads a processing record (polled every `pollMs`) until `isProcessing` turns false. */
    refresh?: (result: T) => Promise<T>;
    pollMs?: number;
    /** Called once per file the server accepted. Failed files never reach it. */
    onUploaded?: (result: T, file: File) => void;
    /** Fallback text when a failure carries no reason (a network drop). */
    networkError?: string;
};

let seq = 0;

/** The text to show for a failed upload: the server's own words, never a generic "failed". */
export function uploadErrorMessage(error: unknown, fallback = 'Upload failed'): string {
    if (error instanceof ApiError) {
        return error.fieldError('file') ?? error.problem.errors?.[0]?.message ?? error.problem.detail ?? error.problem.title ?? fallback;
    }
    return fallback;
}

/**
 * The upload queue behind UploadQueue (Figma 292:2170) and the picker's Upload tab.
 * Files go up with limited concurrency, each with its own progress; a refusal keeps the
 * server's reason verbatim and the file stays in the queue with Retry and Remove. A file
 * that failed is never reported through `onUploaded`, so it can never be inserted.
 */
export function useUploadQueue<T>({
    upload,
    concurrency = 2,
    precheck,
    isProcessing,
    refresh,
    pollMs = 2000,
    onUploaded,
    networkError,
}: UploadQueueOptions<T>) {
    const [items, setItems] = useState<UploadItem<T>[]>([]);
    const controllers = useRef(new Map<string, AbortController>());
    const running = useRef(new Set<string>());
    // Latest callbacks without re-creating `add` on every render.
    const opts = useRef({ upload, precheck, isProcessing, refresh, onUploaded, networkError, concurrency });
    opts.current = { upload, precheck, isProcessing, refresh, onUploaded, networkError, concurrency };
    const itemsRef = useRef(items);
    itemsRef.current = items;

    const patch = useCallback((key: string, next: Partial<UploadItem<T>>) => {
        setItems((all) => all.map((item) => (item.key === key ? { ...item, ...next } : item)));
    }, []);

    const start = useCallback(
        (item: UploadItem<T>) => {
            const controller = new AbortController();
            controllers.current.set(item.key, controller);
            running.current.add(item.key);
            patch(item.key, { status: 'uploading', loaded: 0, total: item.file.size, error: null });
            opts.current
                .upload(item.file, (loaded, total) => patch(item.key, { loaded, total }), controller.signal)
                .then((result) => {
                    const processing = opts.current.isProcessing?.(result) ?? false;
                    patch(item.key, { status: processing ? 'processing' : 'done', result, loaded: item.file.size });
                    opts.current.onUploaded?.(result, item.file);
                })
                .catch((error: unknown) => {
                    if (controller.signal.aborted) return; // cancelled: the row is already gone
                    patch(item.key, { status: 'failed', error: uploadErrorMessage(error, opts.current.networkError) });
                })
                .finally(() => {
                    running.current.delete(item.key);
                    controllers.current.delete(item.key);
                    setItems((all) => [...all]); // wake the scheduler
                });
        },
        [patch],
    );

    // Scheduler: fill free slots with queued files, in the order they were added.
    useEffect(() => {
        const free = opts.current.concurrency - running.current.size;
        if (free <= 0) return;
        items
            .filter((item) => item.status === 'queued' && !running.current.has(item.key))
            .slice(0, free)
            .forEach(start);
    }, [items, start]);

    // Poll processing rows until their renditions are ready.
    const processingKeys = items.filter((i) => i.status === 'processing').map((i) => i.key).join(',');
    useEffect(() => {
        if (!processingKeys || !opts.current.refresh) return;
        const id = window.setInterval(() => {
            itemsRef.current
                .filter((item) => item.status === 'processing' && item.result !== null)
                .forEach((item) => {
                    opts.current.refresh!(item.result as T)
                        .then((fresh) => {
                            const still = opts.current.isProcessing?.(fresh) ?? false;
                            patch(item.key, { result: fresh, status: still ? 'processing' : 'done' });
                        })
                        .catch(() => undefined); // keep polling; a blip is not a failure
                });
        }, pollMs);
        return () => window.clearInterval(id);
    }, [processingKeys, pollMs, patch]);

    useEffect(
        () => () => {
            controllers.current.forEach((c) => c.abort());
        },
        [],
    );

    const add = useCallback((files: Iterable<File>) => {
        const next: UploadItem<T>[] = Array.from(files).map((file) => {
            const reason = opts.current.precheck?.(file) ?? null;
            return {
                key: `u${++seq}`,
                file,
                status: reason ? 'failed' : 'queued',
                loaded: 0,
                total: file.size,
                error: reason,
                result: null,
            };
        });
        setItems((all) => [...all, ...next]);
        return next.map((i) => i.key);
    }, []);

    const retry = useCallback((key: string) => {
        setItems((all) =>
            all.map((item) => {
                if (item.key !== key || item.status !== 'failed') return item;
                // A file the pre-check refused is refused again; only server refusals are retried.
                const reason = opts.current.precheck?.(item.file) ?? null;
                return reason ? { ...item, error: reason } : { ...item, status: 'queued', error: null, loaded: 0 };
            }),
        );
    }, []);

    const remove = useCallback((key: string) => {
        controllers.current.get(key)?.abort();
        setItems((all) => all.filter((item) => item.key !== key));
    }, []);

    const clearFinished = useCallback(() => {
        setItems((all) => all.filter((item) => item.status !== 'done' && item.status !== 'failed'));
    }, []);

    const reset = useCallback(() => {
        controllers.current.forEach((c) => c.abort());
        setItems([]);
    }, []);

    const counts = {
        total: items.length,
        done: items.filter((i) => i.status === 'done').length,
        processing: items.filter((i) => i.status === 'processing').length,
        uploading: items.filter((i) => i.status === 'uploading' || i.status === 'queued').length,
        failed: items.filter((i) => i.status === 'failed').length,
    };

    return { items, counts, add, retry, remove, clearFinished, reset, busy: counts.uploading + counts.processing > 0 };
}

export type UploadQueueApi<T> = ReturnType<typeof useUploadQueue<T>>;
