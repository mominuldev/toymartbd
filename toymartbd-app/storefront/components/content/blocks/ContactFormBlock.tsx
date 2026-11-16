import { MessageField } from '@/components/contact/MessageField';
import { blockLink, bnClass, tileIcons } from '@/components/content/blocks/shared';
import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import type { ContactFormContent } from '@/lib/content/home-blocks';
import { getMessages, type Locale } from '@/lib/i18n';

// "Send us a message" — Contact us 165:9974 (1440) / 167:7500 (390). The form card (808, radius lg,
// hairline border, 28 inset, 20 apart) beside a 440 rail, 32 apart: the "Visit our shop" card (a
// sky map tile with a brand map-pin disc, the text, hours rows) and the "Quicker than a message"
// links card (rows with a hairline above, a 36 brand-subtle disc, the label and a caret). At 390
// everything stacks and the button is full width.
//
// The form is a plain POST to /api/contact (app/api/contact/route.ts), so it works without
// JavaScript: the handler calls POST /storefront/contact-messages (→ the admin Inbox) and answers
// 303 to /contact-message, which shows the thank-you or this form again with the errors and what
// was typed (`flash`). Field rules mirror the API's FormRequest; the server is the validator.

export const CONTACT_TOPICS = ['choosing_toy', 'order', 'delivery', 'returns', 'gifts_bulk', 'other'] as const;
export type ContactTopic = (typeof CONTACT_TOPICS)[number];
export const CONTACT_MESSAGE_MAX = 500;

export interface ContactFormFlash {
    errors: Partial<Record<'name' | 'phone' | 'topic' | 'order_number' | 'message', string>>;
    draft: Partial<Record<'name' | 'phone' | 'topic' | 'order_number' | 'message', string>>;
    message: string | null;
}

const field =
    't-body w-full rounded-md border border-line-strong bg-surface px-4 text-fg-primary placeholder:text-fg-tertiary aria-[invalid=true]:border-line-danger focus-visible:border-line-focus';

interface Props {
    id: string;
    content: ContactFormContent;
    locale: Locale;
    flash?: ContactFormFlash | null;
    /** The form's own heading level: h1 on the /contact-message page. */
    heading?: 'h1' | 'h2';
}

export function ContactFormBlock({ id, content, locale, flash = null, heading: Heading = 'h2' }: Props) {
    const t = getMessages(locale).content;
    const headingId = `cms-${id}-title`;
    const errors = flash?.errors ?? {};
    const draft = flash?.draft ?? {};
    const fid = (name: string) => `contact-${id}-${name}`;
    const label = (name: string, text: string, required: boolean) => (
        <label htmlFor={fid(name)} className="t-field-label text-fg-primary">
            {text}
            {required ? (
                <>
                    <span aria-hidden="true" className="text-feedback-danger">
                        {' '}*
                    </span>
                    <span className="sr-only"> ({t.contactFormRequired})</span>
                </>
            ) : null}
        </label>
    );
    const help = (name: keyof ContactFormFlash['errors'], text: string) => (
        <p id={`${fid(name)}-help`} role={errors[name] ? 'alert' : undefined} className={`t-caption ${errors[name] ? 'text-feedback-danger' : 'text-fg-secondary'}`}>
            {errors[name] ?? text}
        </p>
    );
    const input = (name: keyof ContactFormFlash['errors'], props: React.InputHTMLAttributes<HTMLInputElement>) => (
        <input
            id={fid(name)}
            name={name}
            defaultValue={draft[name] ?? ''}
            aria-describedby={`${fid(name)}-help`}
            aria-invalid={errors[name] ? true : undefined}
            className={`${field} h-13`}
            {...props}
        />
    );

    return (
        <div className={`flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8 ${bnClass(locale)}`}>
            <section id="contact-form" aria-labelledby={headingId} className="flex min-w-0 flex-1 flex-col gap-5 rounded-lg border border-line-default bg-surface p-5 lg:p-7">
                <Heading id={headingId} className="t-h2 text-fg-primary">
                    {content.title}
                </Heading>
                {content.intro ? <p className="t-body text-fg-secondary">{content.intro}</p> : null}
                {flash?.message ? (
                    <p role="alert" className="t-body-sm rounded-md bg-warning-subtle px-4 py-3 text-fg-primary">
                        {flash.message}
                    </p>
                ) : null}
                <form method="post" action="/api/contact" className="flex flex-col gap-5">
                    <input type="hidden" name="locale" value={locale} />
                    <div className="grid gap-5 lg:grid-cols-2 lg:gap-4">
                        <div className="flex flex-col gap-2">
                            {label('name', t.contactFormName, true)}
                            {input('name', { type: 'text', required: true, maxLength: 80, autoComplete: 'name', placeholder: t.contactFormNamePlaceholder })}
                            {help('name', t.contactFormNameHelp)}
                        </div>
                        <div className="flex flex-col gap-2">
                            {label('phone', t.contactFormPhone, true)}
                            {input('phone', { type: 'tel', required: true, maxLength: 16, inputMode: 'tel', autoComplete: 'tel', placeholder: '01XXXXXXXXX' })}
                            {help('phone', t.contactFormPhoneHelp)}
                        </div>
                        <div className="flex flex-col gap-2">
                            {label('topic', t.contactFormTopic, false)}
                            <span className="relative block">
                                <select
                                    id={fid('topic')}
                                    name="topic"
                                    defaultValue={draft.topic ?? 'choosing_toy'}
                                    aria-describedby={`${fid('topic')}-help`}
                                    aria-invalid={errors.topic ? true : undefined}
                                    className={`${field} h-13 appearance-none border-line-default pe-11`}
                                >
                                    {CONTACT_TOPICS.map((topic) => (
                                        <option key={topic} value={topic}>
                                            {t.contactFormTopics[topic]}
                                        </option>
                                    ))}
                                </select>
                                <Icon name="caretDown" className="pointer-events-none absolute end-4 top-1/2 size-5 -translate-y-1/2 text-fg-primary" />
                            </span>
                            {help('topic', t.contactFormTopicHelp)}
                        </div>
                        <div className="flex flex-col gap-2">
                            {label('order_number', t.contactFormOrder, false)}
                            {input('order_number', { type: 'text', maxLength: 32, autoComplete: 'off', placeholder: 'TMB-' })}
                            {help('order_number', t.contactFormOrderHelp)}
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        {label('message', t.contactFormMessage, true)}
                        <MessageField
                            id={fid('message')}
                            name="message"
                            max={CONTACT_MESSAGE_MAX}
                            defaultValue={draft.message ?? ''}
                            placeholder={t.contactFormMessagePlaceholder}
                            describedBy={`${fid('message')}-help`}
                            invalid={Boolean(errors.message)}
                            countLabel={t.contactFormCount}
                            help={help('message', t.contactFormMessageHelp)}
                            className={`${field} h-30 resize-y py-4`}
                        />
                    </div>
                    <div className="flex flex-col items-stretch gap-4 lg:flex-row lg:items-center">
                        <button type="submit" className={`${buttonClass('primary')} max-lg:w-full`}>
                            {t.contactFormSend}
                        </button>
                        {content.privacy ? <p className="t-body-sm text-fg-secondary max-lg:text-center">{content.privacy}</p> : null}
                    </div>
                </form>
            </section>

            {content.visit || content.links ? (
                <div className="flex flex-col gap-6 lg:w-110 lg:shrink-0">
                    {content.visit ? <VisitCard id={id} visit={content.visit} locale={locale} /> : null}
                    {content.links ? <LinksCard id={id} links={content.links} locale={locale} /> : null}
                </div>
            ) : null}
        </div>
    );
}

function VisitCard({ id, visit, locale }: { id: string; visit: NonNullable<ContactFormContent['visit']>; locale: Locale }) {
    const t = getMessages(locale).content;
    const map = visit.map_href ? blockLink(locale, visit.map_href) : null;
    const tile = (
        <span aria-hidden="true" className="flex size-14 items-center justify-center rounded-full bg-brand text-fg-on-brand">
            <Icon name="mapPin" className="size-7" />
        </span>
    );
    return (
        <section aria-labelledby={`cms-${id}-visit`} className="flex flex-col gap-4 rounded-lg border border-line-default bg-surface p-5 lg:p-7">
            {map ? (
                <a
                    href={map.href}
                    {...(map.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                    className="flex h-45 items-center justify-center rounded-md-minus bg-tile-sky"
                    aria-label={t.contactMap}
                >
                    {tile}
                </a>
            ) : (
                <div className="flex h-45 items-center justify-center rounded-md-minus bg-tile-sky">{tile}</div>
            )}
            <h2 id={`cms-${id}-visit`} className="t-channel-title text-fg-primary">
                {visit.title}
            </h2>
            {visit.body ? <p className="t-body text-fg-secondary">{visit.body}</p> : null}
            {visit.hours.length > 0 ? (
                <dl className="flex flex-col gap-2">
                    {visit.hours.map((row, i) => (
                        <div key={i} className="flex items-start justify-between gap-4">
                            <dt className="t-body text-fg-secondary">{row.label}</dt>
                            <dd className="t-body-strong text-end text-fg-primary">{row.value}</dd>
                        </div>
                    ))}
                </dl>
            ) : null}
        </section>
    );
}

function LinksCard({ id, links, locale }: { id: string; links: NonNullable<ContactFormContent['links']>; locale: Locale }) {
    return (
        <nav aria-labelledby={`cms-${id}-links`} className="flex flex-col gap-1 rounded-lg border border-line-default bg-surface p-5 lg:p-7">
            <h2 id={`cms-${id}-links`} className="t-channel-title mb-2 text-fg-primary">
                {links.title}
            </h2>
            <ul className="flex flex-col">
                {links.items.map((item, i) => {
                    const link = blockLink(locale, item.href);
                    return (
                        <li key={i} className="border-t border-line-default">
                            <a
                                href={link.href}
                                {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                                className="flex min-h-touch-min items-center gap-3 py-3 text-fg-primary hover:text-fg-brand"
                            >
                                <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-fg-brand">
                                    <Icon name={tileIcons[item.icon]} className="size-4.5" />
                                </span>
                                <span className="t-body-strong min-w-0 flex-1">{item.label}</span>
                                <Icon name="caretRight" className="size-4.5 shrink-0" />
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
