import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell, SignOutButton } from '@/components/account/AccountShell';
import { card } from '@/components/account/styles';
import { Field, inputClass, SelectBox } from '@/components/cart/fields';
import { buttonClass } from '@/components/layout/button-styles';
import { displayPhone } from '@/lib/account/flash';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { readAccountFlash, requireAccount } from '@/lib/account/server';
import { isLocale } from '@/lib/i18n';

// /account/profile — Profile & settings, Figma 239:13856 (1440) / 238:10951 (390): "Your details" (name,
// verified mobile — read-only, it is the sign-in identity — and optional email) plus the language for
// messages, then sign out. Not built yet (no backend): "My kids" (child names + birthdays), WhatsApp/SMS
// notification toggles and account deletion.

const PATH = '/account/profile';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: accountMessages(locale).meta.profile, robots: { index: false, follow: false } };
}

export default async function ProfilePage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const p = m.profile;
    const query = await searchParams;
    const { account } = await requireAccount(locale, PATH);
    const flash = await readAccountFlash();
    const draft = flash?.kind === 'error' ? flash.draft : {};
    const errors = flash?.kind === 'error' ? flash.errors : {};
    const err = (key: string) => noticeText(errors[key], m);
    const welcome = query.welcome === '1' && !account.name;
    const notice = noticeText(flash?.message, m) ?? (welcome ? p.welcome : null);

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="profile" title={p.title}>
            <h1 className="t-h1 text-fg-primary">{p.title}</h1>

            {notice ? <AccountNotice kind={flash?.kind ?? 'success'} text={notice} /> : null}

            <form method="post" action="/api/account/profile" className={`${card} flex flex-col gap-3.5 lg:max-w-120`}>
                <input type="hidden" name="locale" value={locale} />
                <h2 className="t-h4 text-fg-primary">{p.details}</h2>
                <Field id="name" label={p.name} error={err('name')}>
                    <input id="name" name="name" type="text" autoComplete="name" required minLength={2} maxLength={160} autoFocus={welcome} defaultValue={draft.name ?? account.name} placeholder={p.namePlaceholder} aria-invalid={err('name') ? true : undefined} className={inputClass} />
                </Field>
                <Field id="phone" label={p.phone} help={p.phoneHelp}>
                    <input id="phone" type="tel" readOnly value={displayPhone(account.phone)} aria-describedby="phone-help" className={`${inputClass} bg-subtle tabular-nums`} />
                </Field>
                <Field id="email" label={p.email} error={err('email')}>
                    <input id="email" name="email" type="email" autoComplete="email" maxLength={191} defaultValue={draft.email ?? account.email ?? ''} placeholder={p.emailPlaceholder} aria-invalid={err('email') ? true : undefined} className={inputClass} />
                </Field>
                <Field id="preferred_language" label={p.language} error={err('preferred_language')}>
                    <SelectBox id="preferred_language" name="preferred_language" defaultValue={draft.preferred_language ?? account.preferred_language}>
                        <option value="bn" lang="bn">
                            {p.languages.bn}
                        </option>
                        <option value="en" lang="en">
                            {p.languages.en}
                        </option>
                    </SelectBox>
                </Field>
                <button type="submit" className={`${buttonClass('primary', 'md')} w-fit`}>
                    {p.save}
                </button>
            </form>

            <section className={`${card} flex flex-col gap-2 lg:max-w-120`}>
                <h2 className="t-h4 text-fg-primary">{p.signOutTitle}</h2>
                <p className="t-body-sm text-fg-secondary">{p.signOutBody}</p>
                <SignOutButton locale={locale} label={p.signOut} className={`${buttonClass('secondary', 'md')} w-fit`} />
            </section>
        </AccountShell>
    );
}
