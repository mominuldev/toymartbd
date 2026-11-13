import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import {
    ImageIcon,
    LinkIcon,
    ListBulletsIcon,
    ListNumbersIcon,
    MagicWandIcon,
    PackageIcon,
    QrCodeIcon,
    QuotesIcon,
    TextBIcon,
    TextItalicIcon,
    TranslateIcon,
    WarningCircleIcon,
    type Icon,
} from '@phosphor-icons/react';
import { useI18n } from '@/i18n/I18nProvider';
import { Button } from '@/components/ui/Button';
import { controlHeight, inputClass } from '@/components/ui/Field';
import { Select } from '@/components/ui/Select';
import { MediaPickerModal, type InsertFailure, type MediaPickerContext } from '@/features/media/picker';
import type { Media } from '@/features/media/api';

export type DescriptionLanguage = 'en' | 'bn';

type DescriptionEditorProps = {
    /** Stored HTML (sanitised by the API) per language; '' when empty. */
    value: Record<DescriptionLanguage, string>;
    onChange: (language: DescriptionLanguage, html: string) => void;
    /** The 422 message for either language, shown under the editor. */
    error?: string;
    invalid?: Partial<Record<DescriptionLanguage, boolean>>;
    disabled?: boolean;
    /** Opens on this language (tests, deep links); the switch changes it. */
    initialLanguage?: DescriptionLanguage;
    /** Accessible name; defaults to "Description". `hideLabel` keeps it for screen readers only (blog editor, Figma 241:46027). */
    label?: string;
    hideLabel?: boolean;
};

/** Links the API keeps (App\Domain\Catalog\Services\ProductDescription): http, https and mailto only. */
export function isAllowedLink(url: string): boolean {
    return /^(https?:\/\/[^\s/?#]+\.[^\s]*|mailto:[^\s@]+@[^\s@]+)$/i.test(url.trim());
}

/** Our own media only — the stable /m/{id}/… URL; the API drops any other image host anyway. */
export function isOwnMediaUrl(src: string): boolean {
    try {
        const url = new URL(src, window.location.origin);
        return /^\/m\/\d+\//.test(url.pathname) && (url.protocol === 'https:' || url.protocol === 'http:');
    } catch {
        return false;
    }
}

/** Images limited to our media on paste and load, so what the editor shows is what the API keeps. */
const MediaImage = Image.extend({
    parseHTML() {
        return [{ tag: 'img[src]', getAttrs: (node) => (node instanceof HTMLElement && isOwnMediaUrl(node.getAttribute('src') ?? '') ? null : false) }];
    },
}).configure({ inline: false, allowBase64: false });

function extensions() {
    return [
        StarterKit.configure({
            heading: { levels: [2, 3] },
            code: false,
            codeBlock: false,
            horizontalRule: false,
            strike: false,
            underline: false,
            // No empty <p></p> appended after a list or quote: it would be stored as noise.
            trailingNode: false,
            link: {
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                protocols: ['mailto'],
                // rel is the API's to decide (external links get noopener nofollow); never target=_blank.
                HTMLAttributes: { rel: null, target: null },
                isAllowedUri: (url) => isAllowedLink(url),
            },
        }),
        MediaImage,
    ];
}

/** The editable area: Admin/body, 14 padding, 8 between blocks (Figma "editor" 241:37924). */
const contentClass = [
    'min-h-28 flex flex-col gap-2 p-3.5 t-admin-body text-fg-primary outline-none',
    '[&_h2]:t-admin-section [&_h3]:t-admin-body-strong',
    '[&_ul]:list-disc [&_ul]:ps-5 [&_ol]:list-decimal [&_ol]:ps-5 [&_li]:ps-0.5',
    '[&_blockquote]:border-s-2 [&_blockquote]:border-line-strong [&_blockquote]:ps-3 [&_blockquote]:text-fg-secondary',
    '[&_a]:text-fg-link [&_a]:underline',
    '[&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-sm [&_img.ProseMirror-selectednode]:shadow-focus',
].join(' ');

/**
 * The Description field (Figma "desc" 241:37921): the RichTextToolbar 201:1570 over the
 * editor area. One editor per language — the EN | বাং switch shows one and keeps the other's
 * text and undo history. The HTML goes to the API as it is; the API sanitises it on save
 * against its allowlist, and the toolbar offers only what that allowlist keeps.
 */
export function DescriptionEditor({ value, onChange, error, invalid = {}, disabled = false, initialLanguage = 'en', label, hideLabel = false }: DescriptionEditorProps) {
    const { t } = useI18n();
    const labelId = useId();
    const errorId = useId();
    const [language, setLanguage] = useState<DescriptionLanguage>(initialLanguage);
    const [picking, setPicking] = useState(false);

    const en = useLanguageEditor('en', value.en, onChange, { labelId, errorId: error ? errorId : undefined, invalid: Boolean(invalid.en), disabled });
    const bn = useLanguageEditor('bn', value.bn, onChange, { labelId, errorId: error ? errorId : undefined, invalid: Boolean(invalid.bn), disabled });
    const editor = language === 'en' ? en : bn;

    const pickerContext: MediaPickerContext = {
        title: t('products.editor.pickerTitle'),
        hint: t('products.editor.pickerHint'),
        uploadHint: t('products.editor.pickerUploadHint', { folder: t('media.folder.system.products') }),
        multiple: false,
        max: 1,
        kinds: ['image'],
        defaultFolder: 'products',
    };

    const insertImage = async (picked: Media[]): Promise<InsertFailure[]> => {
        const media = picked[0];
        if (!media || !editor) return [];
        const src = media.public_url;
        if (!src) return [{ media, reason: t('products.editor.imageNotReady') }];
        const alt = (language === 'bn' ? (media.alt_text_bn ?? media.alt_text) : (media.alt_text ?? media.alt_text_bn)) ?? '';
        editor
            .chain()
            .focus()
            .setImage({ src, alt, ...(media.width ? { width: media.width } : {}), ...(media.height ? { height: media.height } : {}) })
            .run();
        return [];
    };

    return (
        <div className="flex flex-col gap-1.5">
            <span id={labelId} className={hideLabel ? 'sr-only' : 't-admin-body-strong text-fg-primary'}>
                {label ?? t('products.field.description')}
            </span>
            <RichTextToolbar editor={editor} language={language} onLanguage={setLanguage} onImage={() => setPicking(true)} disabled={disabled} />
            <div className="overflow-hidden rounded-sm border border-line-default bg-surface focus-within:border-line-focus focus-within:ring-1 focus-within:ring-inset focus-within:ring-line-focus has-aria-invalid:border-line-danger">
                <EditorContent editor={en} hidden={language !== 'en'} />
                <EditorContent editor={bn} hidden={language !== 'bn'} />
            </div>
            {error && (
                <p id={errorId} className="flex items-start gap-1 t-admin-caption text-fg-primary">
                    <WarningCircleIcon size={14} aria-hidden className="mt-px shrink-0 text-feedback-danger" />
                    {error}
                </p>
            )}
            {picking && <MediaPickerModal context={pickerContext} onInsert={insertImage} onClose={() => setPicking(false)} />}
        </div>
    );
}

function useLanguageEditor(
    language: DescriptionLanguage,
    html: string,
    onChange: DescriptionEditorProps['onChange'],
    { labelId, errorId, invalid, disabled }: { labelId: string; errorId?: string; invalid: boolean; disabled: boolean },
): Editor | null {
    // The last HTML this editor emitted, so a parent re-render with the same text does not reset the caret.
    const emitted = useRef(html);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const editor = useEditor({
        extensions: extensions(),
        content: html,
        editable: !disabled,
        immediatelyRender: true,
        shouldRerenderOnTransaction: false,
        editorProps: {
            attributes: {
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-labelledby': labelId,
                lang: language,
                class: `${contentClass} ${language === 'bn' ? 'lang-bn' : ''}`,
            },
        },
        onUpdate: ({ editor: current }) => {
            const next = current.isEmpty ? '' : current.getHTML();
            emitted.current = next;
            onChangeRef.current(language, next);
        },
    });

    // Discard / "Save and add another" reset the form: load the new text without emitting a change.
    useEffect(() => {
        if (!editor || html === emitted.current) return;
        emitted.current = html;
        editor.commands.setContent(html, { emitUpdate: false });
    }, [editor, html]);

    useEffect(() => {
        editor?.setEditable(!disabled, false);
    }, [editor, disabled]);

    // aria-invalid / aria-describedby follow the field's error state.
    useEffect(() => {
        const dom = editor?.view.dom;
        if (!dom) return;
        if (invalid) dom.setAttribute('aria-invalid', 'true');
        else dom.removeAttribute('aria-invalid');
        if (errorId) dom.setAttribute('aria-describedby', errorId);
        else dom.removeAttribute('aria-describedby');
    }, [editor, invalid, errorId]);

    return editor;
}

type Block = 'paragraph' | 'h2' | 'h3';
type Chain = ReturnType<Editor['chain']>;

type ToolbarProps = {
    editor: Editor | null;
    language: DescriptionLanguage;
    onLanguage: (language: DescriptionLanguage) => void;
    onImage: () => void;
    disabled: boolean;
};

/**
 * RichTextToolbar 201:1570 on bg/subtle, radius/sm, 4 padding, 2 between controls:
 * block select · B I Aa · lists and quote · link, image, product, QR · AI write · EN | বাং.
 * Product embed, QR and AI write have no backend yet: they render per Figma, disabled,
 * with a "coming soon" tooltip — never a fake action.
 */
export function RichTextToolbar({ editor, language, onLanguage, onImage, disabled }: ToolbarProps) {
    const { t } = useI18n();
    const blockId = useId();
    const [linking, setLinking] = useState(false);
    const [href, setHref] = useState('');
    const [linkError, setLinkError] = useState(false);

    const state = useEditorState({
        editor,
        selector: ({ editor: e }) =>
            e
                ? {
                      bold: e.isActive('bold'),
                      italic: e.isActive('italic'),
                      bulletList: e.isActive('bulletList'),
                      orderedList: e.isActive('orderedList'),
                      blockquote: e.isActive('blockquote'),
                      link: e.isActive('link'),
                      block: (e.isActive('heading', { level: 2 }) ? 'h2' : e.isActive('heading', { level: 3 }) ? 'h3' : 'paragraph') as Block,
                  }
                : null,
    });

    const off = disabled || !editor;
    const run = (command: (chain: Chain) => Chain) => {
        if (editor) command(editor.chain().focus()).run();
    };

    const setBlock = (block: Block) =>
        run((c) => (block === 'paragraph' ? c.setParagraph() : c.setHeading({ level: block === 'h2' ? 2 : 3 })));

    const openLink = () => {
        setHref(editor?.getAttributes('link').href ?? '');
        setLinkError(false);
        setLinking(true);
    };
    const applyLink = () => {
        const url = href.trim();
        if (url === '') {
            run((c) => c.extendMarkRange('link').unsetLink());
            setLinking(false);
            return;
        }
        if (!isAllowedLink(url)) {
            setLinkError(true);
            return;
        }
        run((c) => c.extendMarkRange('link').setLink({ href: url }));
        setLinking(false);
    };

    const other: DescriptionLanguage = language === 'en' ? 'bn' : 'en';

    return (
        <div className="flex flex-col gap-1.5">
            <div role="toolbar" aria-label={t('products.editor.toolbar')} className="flex flex-wrap items-center gap-0.5 self-start rounded-sm border border-line-default bg-subtle p-1">
                <label htmlFor={blockId} className="sr-only">
                    {t('products.editor.block')}
                </label>
                <Select id={blockId} size="sm" className="w-32" value={state?.block ?? 'paragraph'} disabled={off} onChange={(e) => setBlock(e.target.value as Block)}>
                    <option value="paragraph">{t('products.editor.paragraph')}</option>
                    <option value="h2">{t('products.editor.heading2')}</option>
                    <option value="h3">{t('products.editor.heading3')}</option>
                </Select>
                <Divider />
                <ToolButton icon={TextBIcon} label={t('products.editor.bold')} pressed={state?.bold} disabled={off} onClick={() => run((c) => c.toggleBold())} />
                <ToolButton icon={TextItalicIcon} label={t('products.editor.italic')} pressed={state?.italic} disabled={off} onClick={() => run((c) => c.toggleItalic())} />
                <Divider />
                <ToolButton icon={ListBulletsIcon} label={t('products.editor.bulletList')} pressed={state?.bulletList} disabled={off} onClick={() => run((c) => c.toggleBulletList())} />
                <ToolButton icon={ListNumbersIcon} label={t('products.editor.orderedList')} pressed={state?.orderedList} disabled={off} onClick={() => run((c) => c.toggleOrderedList())} />
                <ToolButton icon={QuotesIcon} label={t('products.editor.quote')} pressed={state?.blockquote} disabled={off} onClick={() => run((c) => c.toggleBlockquote())} />
                <Divider />
                <ToolButton icon={LinkIcon} label={t('products.editor.link')} pressed={state?.link || linking} expanded={linking} disabled={off} onClick={() => (linking ? setLinking(false) : openLink())} />
                <ToolButton icon={ImageIcon} label={t('products.editor.image')} haspopup="dialog" disabled={off} onClick={onImage} />
                <ToolButton icon={PackageIcon} label={t('products.editor.productEmbed')} soon />
                <ToolButton icon={QrCodeIcon} label={t('products.editor.qrEmbed')} soon />
                <Divider />
                <Soon label={t('products.editor.comingSoon', { feature: t('products.editor.aiWrite') })}>
                    {(describedBy) => (
                        <button type="button" aria-disabled="true" aria-describedby={describedBy} onClick={(e) => e.preventDefault()} className="flex h-8 cursor-not-allowed items-center gap-1.5 rounded-sm px-3 t-admin-button-sm text-fg-disabled">
                            <MagicWandIcon size={16} aria-hidden />
                            {t('products.editor.aiWrite')}
                        </button>
                    )}
                </Soon>
                <button
                    type="button"
                    onClick={() => onLanguage(other)}
                    className="flex h-8 items-center gap-1.5 rounded-sm px-3 t-admin-button-sm text-fg-primary hover:bg-surface"
                >
                    {/* Visually hidden, not aria-label: the visible "EN | বাং" stays part of the name (WCAG 2.5.3). */}
                    <span className="sr-only">{t('products.editor.language', { language: t(language === 'en' ? 'products.editor.english' : 'products.editor.bangla') })}</span>
                    <TranslateIcon size={16} aria-hidden />
                    <span className={language === 'en' ? 'text-fg-primary' : 'text-fg-tertiary'}>EN</span>
                    <span aria-hidden className="text-fg-tertiary">
                        |
                    </span>
                    <span lang="bn" className={language === 'bn' ? 'text-fg-primary' : 'text-fg-tertiary'}>
                        বাং
                    </span>
                </button>
            </div>

            {linking && (
                <div className="flex flex-wrap items-start gap-2 rounded-sm border border-line-default bg-subtle p-2">
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <input
                            type="url"
                            aria-label={t('products.editor.linkUrl')}
                            aria-invalid={linkError || undefined}
                            placeholder="https://"
                            value={href}
                            autoFocus
                            onChange={(e) => {
                                setHref(e.target.value);
                                setLinkError(false);
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    applyLink();
                                } else if (e.key === 'Escape') {
                                    e.preventDefault();
                                    setLinking(false);
                                }
                            }}
                            className={`${controlHeight.sm} ${inputClass}`}
                        />
                        <p className={`t-admin-caption ${linkError ? 'text-fg-primary' : 'text-fg-tertiary'}`}>{linkError ? t('products.editor.linkInvalid') : t('products.editor.linkHint')}</p>
                    </div>
                    <Button size="sm" onClick={applyLink}>
                        {t('products.editor.linkApply')}
                    </Button>
                    {state?.link && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                run((c) => c.extendMarkRange('link').unsetLink());
                                setLinking(false);
                            }}
                        >
                            {t('products.editor.linkRemove')}
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" onClick={() => setLinking(false)}>
                        {t('products.editor.linkCancel')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function Divider() {
    return <span aria-hidden className="mx-px h-5 w-px bg-line-default" />;
}

type ToolButtonProps = {
    icon: Icon;
    label: string;
    onClick?: () => void;
    pressed?: boolean;
    expanded?: boolean;
    haspopup?: 'dialog';
    disabled?: boolean;
    /** No backend yet: shown per Figma, disabled, with a "coming soon" tooltip. */
    soon?: boolean;
};

/** AdminIconButton 184:267, ghost, 32 with a 16 icon; aria-pressed for marks and blocks. */
function ToolButton({ icon: IconComponent, label, onClick, pressed, expanded, haspopup, disabled, soon }: ToolButtonProps) {
    const { t } = useI18n();
    const base = 'inline-grid size-8 shrink-0 place-items-center rounded-sm transition-colors duration-150 ease-standard';

    if (soon) {
        return (
            <Soon label={t('products.editor.comingSoon', { feature: label })}>
                {(describedBy) => (
                    <button type="button" aria-label={label} aria-disabled="true" aria-describedby={describedBy} onClick={(e) => e.preventDefault()} className={`${base} cursor-not-allowed text-fg-disabled`}>
                        <IconComponent size={16} aria-hidden />
                    </button>
                )}
            </Soon>
        );
    }

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            aria-pressed={pressed === undefined ? undefined : pressed}
            aria-expanded={expanded}
            aria-haspopup={haspopup}
            disabled={disabled}
            // Keep the editor's selection: the toolbar acts on it.
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={`${base} text-fg-secondary hover:bg-surface hover:text-fg-primary aria-pressed:bg-surface aria-pressed:text-fg-primary aria-pressed:shadow-e1 disabled:cursor-not-allowed disabled:text-fg-disabled disabled:hover:bg-transparent`}
        >
            <IconComponent size={16} aria-hidden />
        </button>
    );
}

/** A tooltip on hover and keyboard focus (aria-disabled keeps the button focusable so it can be read). */
function Soon({ label, children }: { label: string; children: (describedBy: string) => ReactNode }) {
    const id = useId();
    return (
        <span className="group/soon relative inline-flex">
            {children(id)}
            <span
                id={id}
                role="tooltip"
                className="pointer-events-none invisible absolute top-full left-1/2 z-20 mt-1 -translate-x-1/2 rounded-sm border border-line-default bg-surface-raised px-2 py-1 t-admin-caption whitespace-nowrap text-fg-primary opacity-0 shadow-e2 transition-opacity duration-150 ease-standard group-focus-within/soon:visible group-focus-within/soon:opacity-100 group-hover/soon:visible group-hover/soon:opacity-100"
            >
                {label}
            </span>
        </span>
    );
}
