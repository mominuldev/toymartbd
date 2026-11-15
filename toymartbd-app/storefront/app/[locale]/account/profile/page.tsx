import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell, SignOutButton } from '@/components/account/AccountShell';
import { KidsCard } from '@/components/account/KidsCard';
import { Field, inputClass } from '@/components/cart/fields';
import { buttonClass } from '@/components/layout/button-styles';
import { displayPhone } from '@/lib/account/flash';
import { getStoreInfo } from '@/lib/store-info';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getChildren, getConsents, getNotificationPreferences, readAccountFlash, requireAccount } from '@/lib/account/server';
import { format, getMessages, isLocale } from '@/lib/i18n';
import { orderDate } from '@/lib/order/format';
import { privateMetadata } from '@/lib/seo/metadata';

// /account/profile — Profile & settings, Figma 239:13856 (1440) / 238:10951 (390). A row of two cards —
// "Your details" (name, verified mobile — read-only, it is the sign-in identity — optional email, Save)
// and "My kids" (P8 batch 3: first name/nickname + birthday per child) — then "Notifications & language":
// the "Birthday reminders and offers" switch (the purpose consent, never pre-ticked, never on checkout)
// and the message language, with its own Save. Then sign out. Three independent no-JS forms posting to
// Route Handlers (rule 28: the API is reached only from the server). The frame's "Order updates on
// WhatsApp" and "SMS updates" switches sit above the birthday row in the same form: the customer's own
// order_updates channels (GET/PUT /storefront/account/notification-preferences; on by default; sign-in
// codes are never switchable). QueueOrderNotification honours them: WhatsApp off → SMS; both off → none.

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

const cardClass = 'flex flex-col rounded-md border border-line-default bg-surface p-5';

export default async function ProfilePage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const hotline = (await getStoreInfo()).contact.hotline.display; // {phone} in notices
    const p = m.profile;
    const n = p.notifications;
    const query = await searchParams;
    const { account, token } = await requireAccount(locale, PATH);
    const [consents, children, channels] = await Promise.all([getConsents(token, locale), getChildren(token, locale), getNotificationPreferences(token, locale)]);
    const birthday = consents.birthday;
    const flash = await readAccountFlash();
    const draft = flash?.kind === 'error' ? flash.draft : {};
    const errors = flash?.kind === 'error' ? flash.errors : {};
    const err = (key: string) => noticeText(errors[key], m, hotline);
    const welcome = query.welcome === '1' && !account.name;
    const notice = noticeText(flash?.message, m, hotline) ?? (welcome ? p.welcome : null);
    // Switched on only when the customer opted in (or just switched it on before an error) — never by default.
    const optedIn = draft.birthday_reminders !== undefined ? draft.birthday_reminders === '1' : birthday.consented;
    const state = birthday.consented ? n.on : birthday.withdrawn_at ? format(n.withdrawn, { date: orderDate(birthday.withdrawn_at, getMessages(locale)) }) : null;
    const childDraft = draft.child_id !== undefined ? draft : {};
    // Order updates are on unless the customer turned a channel off (or just did, before an error).
    const orderOn = (channel: 'whatsapp' | 'sms'): boolean => {
        const drafted = draft[`order_updates_${channel}`];
        return drafted !== undefined ? drafted === '1' : (channels.preferences.find((row) => row.channel === channel)?.enabled ?? true);
    };

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="profile" title={p.title} heading={{}}>
            {notice ? <AccountNotice kind={flash?.kind ?? 'success'} text={notice} /> : null}

            <div className="grid items-start gap-4 lg:grid-cols-2 lg:gap-6">
                {/* Figma Card 239:14037 — "Your details". */}
                <form method="post" action="/api/account/profile" aria-labelledby="details-title" className={`${cardClass} gap-3.5`}>
                    <input type="hidden" name="locale" value={locale} />
                    <input type="hidden" name="intent" value="details" />
                    <h2 id="details-title" className="t-h4 text-fg-primary max-lg:sr-only">
                        {p.details}
                    </h2>
                    <Field id="name" label={p.name} error={err('name')}>
                        <input id="name" name="name" type="text" autoComplete="name" required minLength={2} maxLength={160} autoFocus={welcome} defaultValue={draft.name ?? account.name} placeholder={p.namePlaceholder} aria-invalid={err('name') ? true : undefined} className={inputClass} />
                    </Field>
                    <Field id="phone" label={p.phone} help={p.phoneHelp}>
                        <input id="phone" type="tel" readOnly value={displayPhone(account.phone)} aria-describedby="phone-help" className={`${inputClass} bg-subtle tabular-nums`} />
                    </Field>
                    <Field id="email" label={p.email} error={err('email')}>
                        <input id="email" name="email" type="email" autoComplete="email" maxLength={191} defaultValue={draft.email ?? account.email ?? ''} placeholder={p.emailPlaceholder} aria-invalid={err('email') ? true : undefined} className={inputClass} />
                    </Field>
                    <button type="submit" className={`${buttonClass('primary', 'md')} w-full sm:w-fit`}>
                        {p.save}
                    </button>
                </form>

                {/* Figma Card 239:14101 — "My kids". */}
                <KidsCard locale={locale} m={m} items={children} draft={childDraft} errors={childDraft.child_id ? errors : {}} />
            </div>

            {/* Figma Card 239:14134 — "Notifications & language": the "Offers and birthday reminders" row
                (239:14142) is the birthday-reminder consent; the language choice sits under it. */}
            <form id="notifications" method="post" action="/api/account/profile" aria-labelledby="notifications-title" className={`${cardClass} gap-3.5`}>
                <input type="hidden" name="locale" value={locale} />
                <input type="hidden" name="intent" value="preferences" />
                <h2 id="notifications-title" className="t-h4 text-fg-primary">
                    {n.title}
                </h2>
                <input type="hidden" name="order_updates_shown" value="1" />
                <Switch name="order_updates_whatsapp" title={n.orderWhatsapp} sub={n.orderWhatsappSub} checked={orderOn('whatsapp')} describedBy="order-how" />
                <Switch name="order_updates_sms" title={n.orderSms} sub={n.orderSmsSub} checked={orderOn('sms')} describedBy="order-how" />
                <p id="order-how" className="t-body-sm text-fg-secondary">
                    {n.orderHow}
                </p>
                {channels.whatsapp_available ? null : <p className="t-caption text-fg-secondary">{n.whatsappUnavailable}</p>}
                {!orderOn('whatsapp') && !orderOn('sms') ? <p className="t-caption text-fg-primary">{n.orderNone}</p> : null}
                <div className="border-t border-line-default pt-3.5">
                    <Switch name="birthday_reminders" title={n.reminders} sub={n.remindersSub} checked={optedIn} describedBy="reminders-what reminders-stop" />
                </div>
                <p id="reminders-what" className="t-body-sm text-fg-secondary">
                    {n.what}
                </p>
                {state ? <p className="t-caption text-fg-secondary">{state}</p> : null}
                {optedIn && children.length === 0 ? <p className="t-caption text-fg-primary">{n.needsKids}</p> : null}
                <p id="reminders-stop" className="t-caption text-fg-secondary">
                    {n.stop}
                </p>
                <div className="flex items-center justify-between gap-3 border-t border-line-default pt-3.5">
                    <span id="language-label" className="t-body-strong text-fg-primary">
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
                <button type="submit" className={`${buttonClass('outline', 'md')} w-full sm:w-fit`}>
                    {n.save}
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

/** Figma Toggle 239:14146 — 48 × 28 track, 22 knob; a real checkbox underneath, so it works without JavaScript. */
function Switch({ name, title, sub, checked, describedBy }: { name: string; title: string; sub: string; checked: boolean; describedBy: string }) {
    return (
        <label className="flex min-h-11 cursor-pointer items-center gap-3">
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="t-body-strong text-fg-primary">{title}</span>
                <span className="t-caption text-fg-tertiary">{sub}</span>
            </span>
            <input type="checkbox" role="switch" name={name} value="1" defaultChecked={checked} aria-describedby={describedBy} className="peer sr-only" />
            <span aria-hidden="true" className="relative h-7 w-12 shrink-0 rounded-full bg-line-strong transition-colors duration-(--tm-duration-fast) peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-line-focus after:absolute after:start-0.75 after:top-0.75 after:size-5.5 after:rounded-full after:bg-surface after:shadow-e1 after:transition-transform after:duration-(--tm-duration-fast) peer-checked:after:translate-x-5 rtl:peer-checked:after:-translate-x-5" />
        </label>
    );
}
