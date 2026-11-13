import type { Metadata } from 'next';
import { notFound, redirect } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { card } from '@/components/account/styles';
import { Field, inputClass } from '@/components/cart/fields';
import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { PageShell } from '@/components/PageShell';
import { safeNext } from '@/lib/account/flash';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { accountToken, readAccountFlash } from '@/lib/account/server';
import { isLocale, localizedPath } from '@/lib/i18n';
import { privateMetadata } from '@/lib/seo/metadata';

// /account/login — Sign in, Figma 237:9273 (390). Phone → SMS code; guest checkout stays the default
// way to buy. The phone is POSTed (never in a URL) to /api/account/otp, which works without JS.
// Google / email sign-in in the design are not built: the owner's decision is phone + OTP only.

const PATH = '/account/login';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, '/account/login', accountMessages(locale).meta.login);
}

export default async function LoginPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const query = await searchParams;
    const flash = await readAccountFlash();
    const next = safeNext(flash?.draft.next ?? (typeof query.next === 'string' ? query.next : null));
    if (await accountToken()) redirect(localizedPath(locale, next));

    const phoneError = noticeText(flash?.errors.phone, m);
    const notice = noticeText(flash?.message, m);

    return (
        <PageShell locale={locale} path={PATH} top="tight">
            <div className="mx-auto flex w-full max-w-120 flex-col gap-4 pb-8 lg:pb-20">
                <section className="flex flex-col items-center gap-2.5 rounded-lg bg-(image:--tm-gradient-bloom-candy) px-5 py-6 text-center">
                    <span className="flex size-18 items-center justify-center rounded-full bg-surface text-fg-brand">
                        <Icon name="smiley" className="size-10" />
                    </span>
                    <h1 className="t-h1 text-fg-primary">{m.login.title}</h1>
                    <p className="t-body-sm text-fg-secondary">{m.login.sub}</p>
                </section>

                {notice ? <AccountNotice kind={flash?.kind ?? 'error'} text={notice} /> : null}

                <form method="post" action="/api/account/otp" className={`${card} flex flex-col gap-3.5`}>
                    <input type="hidden" name="locale" value={locale} />
                    <input type="hidden" name="next" value={next} />
                    <Field id="phone" label={m.login.phone} help={m.login.phoneHelp} error={phoneError}>
                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel-national"
                            required
                            maxLength={20}
                            defaultValue={flash?.draft.phone ?? ''}
                            placeholder={m.login.phonePlaceholder}
                            aria-describedby="phone-help"
                            aria-invalid={phoneError ? true : undefined}
                            className={`${inputClass} tabular-nums`}
                        />
                    </Field>
                    <button type="submit" className={`${buttonClass('primary')} w-full`}>
                        {m.login.submit}
                    </button>
                    <p className="t-caption text-center text-fg-tertiary">{m.login.smsNote}</p>
                </form>

                <section className="flex flex-col gap-1.5 rounded-sm bg-tile-mint p-4">
                    <h2 className="t-body-sm-strong text-fg-primary">{m.login.guestTitle}</h2>
                    <p className="t-caption text-fg-secondary">
                        {m.login.guestBody}{' '}
                        <a href={localizedPath(locale, '/cart')} className="font-semibold text-fg-link underline">
                            {m.login.guestLink}
                        </a>
                    </p>
                </section>
            </div>
        </PageShell>
    );
}
