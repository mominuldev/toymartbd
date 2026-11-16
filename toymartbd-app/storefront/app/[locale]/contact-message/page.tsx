import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { ContactFormBlock, type ContactFormFlash } from '@/components/content/blocks/ContactFormBlock';
import { Band } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { PageShell } from '@/components/PageShell';
import { readAccountFlash } from '@/lib/account/server';
import type { ContactFormContent } from '@/lib/content/home-blocks';
import { findPageLayout } from '@/lib/content/page-layout';
import { getMessages, isLocale, localizedPath, type Locale } from '@/lib/i18n';
import { privateMetadata } from '@/lib/seo/metadata';
import { cmsPaths } from '@/lib/site-config';

// /contact-message — where the Contact form lands after a post (app/api/contact/route.ts). The
// Contact page itself stays cached (ISR), so the per-visitor outcome lives here, read from the
// one-shot flash cookie: the thank-you, or the same form again with its errors and what was typed.
// The form's copy and side cards are the Contact page's own `contact_form` section when it has
// one. Never indexed.

const PATH = '/contact-message';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, PATH, getMessages(locale).content.contactMessageTitle);
}

async function formContent(locale: Locale): Promise<ContactFormContent> {
    const layout = await findPageLayout(cmsPaths.contact.replace(/^\//, ''), locale);
    const block = layout?.blocks.find((b) => b.type === 'contact_form');
    if (block?.type === 'contact_form') return block.content;
    return { title: getMessages(locale).content.contactMessageTitle, intro: null, privacy: null, visit: null, links: null };
}

export default async function ContactMessagePage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const t = getMessages(locale).content;
    const flash = await readAccountFlash();

    if (flash?.kind === 'success') {
        return (
            <PageShell locale={locale} path={PATH} top="tight" bleed>
                <Band>
                    <section className="mx-auto flex max-w-160 flex-col items-center gap-4 rounded-lg border border-line-default bg-surface p-8 text-center lg:p-12" role="status">
                        <span aria-hidden="true" className="flex size-14 items-center justify-center rounded-full bg-feedback-success text-fg-on-brand">
                            <Icon name="checkCircle" className="size-7" />
                        </span>
                        <h1 className="t-h1 text-fg-primary">{t.contactSentTitle}</h1>
                        <p className="t-body-lg text-fg-secondary">{t.contactSentBody.replace('{phone}', flash.draft.phone ?? '')}</p>
                        <a href={localizedPath(locale, cmsPaths.contact)} className={buttonClass('secondary')}>
                            {t.contactSentBack}
                        </a>
                    </section>
                </Band>
            </PageShell>
        );
    }

    const errors: ContactFormFlash['errors'] = {};
    for (const key of ['name', 'phone', 'topic', 'order_number', 'message'] as const) {
        if (flash?.errors[key]) errors[key] = t.contactFormErrors[key];
    }
    const formFlash: ContactFormFlash = {
        errors,
        draft: flash?.draft ?? {},
        message: flash?.message === 'limited' ? t.contactFormLimited : flash?.message === 'failed' ? t.contactFormFailed : null,
    };

    return (
        <PageShell locale={locale} path={PATH} top="tight" bleed>
            <Band>
                <ContactFormBlock id="message" content={await formContent(locale)} locale={locale} flash={formFlash} heading="h1" />
            </Band>
        </PageShell>
    );
}
