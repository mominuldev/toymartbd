import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell, SignOutButton } from '@/components/account/AccountShell';
import { card } from '@/components/account/styles';
import { Field, inputClass } from '@/components/cart/fields';
import { buttonClass } from '@/components/layout/button-styles';
import { displayPhone } from '@/lib/account/flash';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getConsents, readAccountFlash, requireAccount } from '@/lib/account/server';
import { format, getMessages, isLocale } from '@/lib/i18n';
import { orderDate } from '@/lib/order/format';
import { privateMetadata } from '@/lib/seo/metadata';

// /account/profile — Profile & settings, Figma 239:13856 (1440) / 238:10951 (390): "Your details" (name,
// verified mobile — read-only, it is the sign-in identity — and optional email) plus the language for
// messages, a "Birthday message" card (P8: own date of birth + an opt-in that is never pre-ticked; no
// Figma frame — it follows the Preferences card), then sign out. Not built yet (no backend): "My kids"
// (child names + birthdays), WhatsApp/SMS notification toggles and account deletion.

const PATH = '/account/profile';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, '/account/profile', accountMessages(locale).meta.profile);
}

export default async function ProfilePage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const p = m.profile;
    const query = await searchParams;
    const { account, token } = await requireAccount(locale, PATH);
    const consents = await getConsents(token, locale);
    const birthday = consents.birthday;
    const b = p.birthday;
    const flash = await readAccountFlash();
    const draft = flash?.kind === 'error' ? flash.draft : {};
    const errors = flash?.kind === 'error' ? flash.errors : {};
    const err = (key: string) => noticeText(errors[key], m);
    const welcome = query.welcome === '1' && !account.name;
    const notice = noticeText(flash?.message, m) ?? (welcome ? p.welcome : null);
    // Ticked only when the customer opted in (or just ticked it before an error) — never by default.
    const optedIn = draft.birthday_messages !== undefined ? draft.birthday_messages === '1' : birthday.consented;
    const birthdayState = birthday.consented ? b.on : birthday.withdrawn_at ? format(b.withdrawn, { date: orderDate(birthday.withdrawn_at, getMessages(locale)) }) : null;

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="profile" title={p.title} heading={{}}>

            {notice ? <AccountNotice kind={flash?.kind ?? 'success'} text={notice} /> : null}

            {/* Figma 238:10951 / 239:13856: a details card, a Preferences card (language as a two-option
                segmented control), then Sign out as a full-width outline button. One form, one Save, so it
                works without JavaScript. "My kids", WhatsApp/SMS preferences and "Delete my account" are in
                the design but have no backend yet. */}
            <form method="post" action="/api/account/profile" className="flex flex-col gap-4 lg:max-w-120">
                <input type="hidden" name="locale" value={locale} />
                <div className={`${card} flex flex-col gap-3.5`}>
                    <h2 className="sr-only">{p.details}</h2>
                    <Field id="name" label={p.name} error={err('name')}>
                        <input id="name" name="name" type="text" autoComplete="name" required minLength={2} maxLength={160} autoFocus={welcome} defaultValue={draft.name ?? account.name} placeholder={p.namePlaceholder} aria-invalid={err('name') ? true : undefined} className={inputClass} />
                    </Field>
                    <Field id="phone" label={p.phone} help={p.phoneHelp}>
                        <input id="phone" type="tel" readOnly value={displayPhone(account.phone)} aria-describedby="phone-help" className={`${inputClass} bg-subtle tabular-nums`} />
                    </Field>
                    <Field id="email" label={p.email} error={err('email')}>
                        <input id="email" name="email" type="email" autoComplete="email" maxLength={191} defaultValue={draft.email ?? account.email ?? ''} placeholder={p.emailPlaceholder} aria-invalid={err('email') ? true : undefined} className={inputClass} />
                    </Field>
                </div>
                <fieldset className={`${card} flex flex-col gap-3`}>
                    <legend className="contents">
                        <span className="t-h4 text-fg-primary">{p.preferences}</span>
                    </legend>
                    <div className="flex items-center justify-between gap-3">
                        <span id="language-label" className="t-body-sm text-fg-primary">
                            {p.language}
                        </span>
                        <div role="radiogroup" aria-labelledby="language-label" className="flex shrink-0 rounded-full border border-line-default bg-subtle p-0.5">
                            {(['en', 'bn'] as const).map((code) => (
                                <label key={code} lang={code} className="t-body-sm-strong flex h-9 cursor-pointer items-center rounded-full px-4 text-fg-secondary has-checked:bg-surface has-checked:text-fg-primary has-checked:shadow-e1 has-focus-visible:outline-2 has-focus-visible:outline-line-focus">
                                    <input type="radio" name="preferred_language" value={code} defaultChecked={(draft.preferred_language ?? account.preferred_language) === code} className="sr-only" />
                                    {p.languages[code]}
                                </label>
                            ))}
                        </div>
                    </div>
                    {err('preferred_language') ? (
                        <p role="alert" className="t-caption text-feedback-danger">
                            {err('preferred_language')}
                        </p>
                    ) : null}
                </fieldset>
                <fieldset className={`${card} flex flex-col gap-3`} aria-describedby="birthday-what birthday-stop">
                    <legend className="contents">
                        <span className="t-h4 text-fg-primary">{b.title}</span>
                    </legend>
                    <p id="birthday-what" className="t-body-sm text-fg-secondary">
                        {b.what}
                    </p>
                    <Field id="date_of_birth" label={b.dob} help={b.dobHelp} error={err('date_of_birth')}>
                        <input
                            id="date_of_birth"
                            name="date_of_birth"
                            type="date"
                            autoComplete="bday"
                            min="1900-01-01"
                            defaultValue={draft.date_of_birth ?? account.date_of_birth ?? ''}
                            aria-describedby="date_of_birth-help"
                            aria-invalid={err('date_of_birth') ? true : undefined}
                            className={`${inputClass} tabular-nums`}
                        />
                    </Field>
                    <label className="t-body-sm flex min-h-11 items-center gap-3 text-fg-primary">
                        <input type="checkbox" name="birthday_messages" value="1" defaultChecked={optedIn} className="size-5 shrink-0 accent-brand" />
                        {b.optIn}
                    </label>
                    {birthdayState ? <p className="t-caption text-fg-secondary">{birthdayState}</p> : null}
                    <p id="birthday-stop" className="t-caption text-fg-secondary">
                        {b.stop}
                    </p>
                </fieldset>
                <button type="submit" className={`${buttonClass('primary', 'md')} w-full lg:w-fit`}>
                    {p.save}
                </button>
            </form>

            <SignOutButton
                locale={locale}
                label={p.signOut}
                className="t-button flex h-11 w-full items-center justify-center gap-2 rounded-full border-2 border-line-brand-subtle bg-surface text-fg-brand hover:bg-subtle lg:max-w-120 [&>svg]:text-fg-brand"
            />
        </AccountShell>
    );
}
