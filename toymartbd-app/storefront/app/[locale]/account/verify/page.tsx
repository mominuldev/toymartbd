import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound, redirect } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { OtpBoxes } from '@/components/account/OtpBoxes';
import { ResendTimer } from '@/components/account/ResendTimer';
import { card } from '@/components/account/styles';
import { Icon } from '@/components/icons/Icon';
import { buttonClass } from '@/components/layout/button-styles';
import { PageShell } from '@/components/PageShell';
import { OTP_COOKIE } from '@/lib/account/cookies';
import { decodeOtp, displayPhone } from '@/lib/account/flash';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { readAccountFlash } from '@/lib/account/server';
import { format, isLocale, localizedPath } from '@/lib/i18n';
import { contact } from '@/lib/site-config';
import { privateMetadata } from '@/lib/seo/metadata';

// /account/verify — Enter the code, Figma 237:9412 (390). Six boxes (plain inputs: the form posts
// without JS), a resend timer, "Wrong number? Change it", and a help card. The code goes by SMS only
// — never WhatsApp (rule 32b). The phone comes from the httpOnly cookie set by /api/account/otp.
// The design's voice-call fallback after 3 tries is not built (no voice provider); the help card
// offers the hotline instead.

const PATH = '/account/verify';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return privateMetadata(locale, '/account/verify', accountMessages(locale).meta.verify);
}

export default async function VerifyPage({ params }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const otp = decodeOtp((await cookies()).get(OTP_COOKIE)?.value);
    if (!otp) redirect(localizedPath(locale, '/account/login'));

    const flash = await readAccountFlash();
    const codeError = noticeText(flash?.errors.code, m);
    const notice = noticeText(flash?.message, m);
    const phone = displayPhone(otp.phone);

    return (
        <PageShell locale={locale} path={PATH} top="tight">
            <div className="mx-auto flex w-full max-w-120 flex-col gap-4 pb-8 lg:pb-20">
                <header className="flex items-center gap-2">
                    <a href={localizedPath(locale, '/account/login')} aria-label={m.verify.back} className="flex size-10 shrink-0 items-center justify-center rounded-full border border-line-default bg-surface text-fg-primary">
                        <Icon name="arrowLeft" className="size-4.5" />
                    </a>
                    <div className="flex min-w-0 flex-col">
                        <h1 className="t-h2 text-fg-primary">{m.verify.title}</h1>
                        <p className="t-caption text-fg-tertiary tabular-nums">{format(m.verify.sentTo, { phone })}</p>
                    </div>
                </header>

                {notice ? <AccountNotice kind={flash?.kind ?? 'error'} text={notice} /> : null}

                {/* Boxes → resend timer → Verify (Figma order). Two forms cannot nest, so the Verify button
                    sits after the resend form and joins the code form through its `form` attribute. */}
                <div className={`${card} flex flex-col items-center gap-4.5 px-4 py-6`}>
                    <form id="verify-form" method="post" action="/api/account/verify" className="flex w-full flex-col gap-2">
                        <input type="hidden" name="locale" value={locale} />
                        <OtpBoxes digitLabel={m.verify.digit} label={m.verify.codeLabel} invalid={Boolean(codeError)} describedBy={codeError ? 'code-error' : undefined} />
                        {codeError ? (
                            <p id="code-error" role="alert" className="t-caption text-feedback-danger">
                                {codeError}
                            </p>
                        ) : null}
                    </form>
                    <form method="post" action="/api/account/otp" className="t-body-sm flex items-center gap-1.5 text-fg-secondary">
                        <input type="hidden" name="locale" value={locale} />
                        <input type="hidden" name="intent" value="resend" />
                        <Icon name="clock" className="size-icon-sm text-fg-secondary" />
                        <ResendTimer until={otp.sentAt + otp.resendIn * 1000} waitLabel={m.verify.resendIn}>
                            <button type="submit" className="t-body-sm-strong text-fg-link underline">
                                {m.verify.resend}
                            </button>
                        </ResendTimer>
                    </form>
                    <button type="submit" form="verify-form" className={`${buttonClass('primary')} w-full`}>
                        {m.verify.submit}
                    </button>
                </div>

                <a href={localizedPath(locale, '/account/login')} className="t-button self-center py-2 text-fg-primary">
                    {m.verify.change}
                </a>

                <section className="flex flex-col gap-1 rounded-sm bg-tile-sky p-4">
                    <h2 className="t-body-sm-strong text-fg-primary">{m.verify.helpTitle}</h2>
                    <p className="t-caption text-fg-secondary">{format(m.verify.helpBody, { phone: contact.hotline.display })}</p>
                </section>
            </div>
        </PageShell>
    );
}
