'use client';

import { useEffect, useRef, useState } from 'react';

import { popupDismissKey } from '@/lib/content/types';

interface Props {
    id: number;
    title: string | null;
    body: string | null;
    lang?: 'en';
    bangla: boolean;
    link: { href: string; target?: '_blank'; rel?: string } | null;
    linkLabel: string | null;
    image: { src: string; srcSet: string | null; width: number | null; height: number | null; alt: string } | null;
    labels: { notice: string; close: string };
}

function dismissed(id: number): boolean {
    try {
        return window.localStorage.getItem(popupDismissKey(id)) === '1';
    } catch {
        return false;
    }
}

function remember(id: number): void {
    try {
        window.localStorage.setItem(popupDismissKey(id), '1');
    } catch {
        // Private mode or blocked storage: the popup simply shows again next visit.
    }
}

// The popup banner: a native <dialog>, opened once the page has hydrated unless this banner was
// dismissed before (localStorage, per banner id). Never rendered on cart or checkout — PageShell
// only mounts it on other pages and the API answers no popup in a purchase context (rule 25b).
// Without JavaScript nothing shows: a popup is never essential content.
export function BannerPopup({ id, title, body, lang, bangla, link, linkLabel, image, labels }: Props) {
    const ref = useRef<HTMLDialogElement>(null);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        if (dismissed(id)) return;
        setReady(true);
    }, [id]);

    useEffect(() => {
        const dialog = ref.current;
        if (ready && dialog && !dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
    }, [ready]);

    if (!ready) return null;

    const close = () => {
        remember(id);
        ref.current?.close();
        setReady(false);
    };

    return (
        <dialog
            ref={ref}
            aria-label={title ?? labels.notice}
            onCancel={close}
            className="m-auto w-[min(100%-2rem,28rem)] overflow-hidden rounded-xl bg-surface p-0 text-fg-primary shadow-e4 backdrop:bg-fg-primary/40"
        >
            {image ? (
                // eslint-disable-next-line @next/next/no-img-element -- media renditions come with their own srcset
                <img src={image.src} srcSet={image.srcSet ?? undefined} sizes="28rem" width={image.width ?? undefined} height={image.height ?? undefined} alt={image.alt} className="h-auto w-full" />
            ) : null}
            <div lang={lang} className={`flex flex-col gap-3 p-6 ${bangla && !lang ? 'lang-bn' : ''}`}>
                {title ? <h2 className="t-h3">{title}</h2> : null}
                {body ? <p className="t-body text-fg-secondary">{body}</p> : null}
                <div className="flex flex-wrap items-center gap-3">
                    {link && linkLabel ? (
                        <a {...link} onClick={() => remember(id)} className="t-label inline-flex min-h-touch-min items-center rounded-full bg-brand px-5 text-fg-on-brand">
                            {linkLabel}
                        </a>
                    ) : null}
                    <button type="button" onClick={close} className="t-label inline-flex min-h-touch-min items-center rounded-full px-4 text-fg-primary hover:bg-subtle">
                        {labels.close}
                    </button>
                </div>
            </div>
        </dialog>
    );
}
