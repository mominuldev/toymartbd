import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { AccountNotice } from '@/components/account/AccountNotice';
import { AccountShell } from '@/components/account/AccountShell';
import { AddressCard } from '@/components/account/AddressCard';
import { RefreshOnDistrict } from '@/components/account/RefreshOnDistrict';
import { card } from '@/components/account/styles';
import { Field, inputClass, SelectBox, textareaClass } from '@/components/cart/fields';
import { buttonClass } from '@/components/layout/button-styles';
import { nationalPhone } from '@/lib/account/flash';
import { noticeText } from '@/lib/account/format';
import { accountMessages } from '@/lib/account/messages';
import { getAddresses, readAccountFlash, requireAccount } from '@/lib/account/server';
import { getUpazilas } from '@/lib/checkout/geo';
import { getDeliveryReference } from '@/lib/delivery/fetch';
import { placeName } from '@/lib/delivery/format';
import { format, isLocale, localizedPath } from '@/lib/i18n';

// /account/addresses — Addresses, Figma 238:10058 (390): saved AddressCards, then "Add a new address"
// with the checkout's cascading selects — district → upazila → area + house/road + landmark (rule 31;
// the division follows from the district). Without JS, "Show upazilas" re-renders the form with the
// chosen district's upazilas (what was typed survives in the one-shot flash cookie); with JS the
// RefreshOnDistrict island presses it on change. `?edit=ID` fills the form with a saved address.

const PATH = '/account/addresses';

export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ locale: string }>;
    searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { locale } = await params;
    if (!isLocale(locale)) return {};
    return { title: accountMessages(locale).meta.addresses, robots: { index: false, follow: false } };
}

const ID = /^[1-9]\d{0,8}$/;

export default async function AddressesPage({ params, searchParams }: Props) {
    const { locale } = await params;
    if (!isLocale(locale)) notFound();
    const m = accountMessages(locale);
    const a = m.addresses;
    const bn = locale === 'bn';
    const query = await searchParams;
    const { token, account } = await requireAccount(locale, PATH);
    const [addresses, reference, flash] = await Promise.all([getAddresses(token, locale), getDeliveryReference(locale), readAccountFlash()]);

    const editId = typeof query.edit === 'string' && ID.test(query.edit) ? Number(query.edit) : null;
    const editing = editId !== null ? (addresses.find((x) => x.id === editId) ?? null) : null;
    const draft = flash?.draft ?? {};
    const hasDraft = Object.keys(draft).length > 0;
    const value = (key: string, saved: string | null | undefined): string => (hasDraft ? (draft[key] ?? '') : (saved ?? ''));

    const districtValue = value('district_id', editing ? String(editing.district.id) : '');
    const districtId = ID.test(districtValue) ? Number(districtValue) : null;
    const district = districtId !== null ? (reference.districts.find((d) => d.id === districtId) ?? null) : null;
    const upazilas = district ? (reference.upazilas[district.id] ?? (await getUpazilas(district.id, locale))) : [];
    const upazilaValue = value('upazila_id', editing?.upazila ? String(editing.upazila.id) : '');
    const type = value('type', editing?.type ?? 'home') || 'home';

    const errors = flash?.kind === 'error' ? flash.errors : {};
    const err = (key: string) => noticeText(errors[key], m);
    const invalid = (key: string) => (errors[key] ? { 'aria-invalid': true as const } : {});
    const notice = noticeText(flash?.message, m) ?? (Object.keys(errors).length > 0 ? a.errorSummary : null);

    return (
        <AccountShell locale={locale} path={PATH} account={account} section="addresses" title={a.title} heading={{ sub: a.sub }}>

            {notice ? <AccountNotice kind={flash?.kind ?? 'error'} text={notice} /> : null}

            {addresses.length === 0 ? (
                <p className="t-body-sm rounded-sm border border-line-default bg-surface p-4 text-fg-secondary">{a.empty}</p>
            ) : (
                <ul className="grid gap-3 lg:grid-cols-2 lg:gap-4">
                    {addresses.map((address) => (
                        <li key={address.id}>
                            <AddressCard address={address} locale={locale} m={m} />
                        </li>
                    ))}
                </ul>
            )}

            <form id="address-form" method="post" action="/api/account/addresses" className={`${card} flex scroll-mt-32 flex-col gap-3.5 lg:max-w-160`}>
                <RefreshOnDistrict buttonId="address-refresh" />
                {/* Enter in a field submits the form's FIRST submit button: make that "save", not "show upazilas". */}
                <button type="submit" name="intent" value="save" tabIndex={-1} aria-hidden="true" className="sr-only" />
                <input type="hidden" name="locale" value={locale} />
                {editing ? <input type="hidden" name="id" value={editing.id} /> : null}
                <h2 className="t-h3 text-fg-primary">{editing ? a.editTitle : a.addTitle}</h2>

                <Field id="recipient_name" label={a.name} required requiredLabel={a.required} error={err('recipient_name')}>
                    <input id="recipient_name" name="recipient_name" type="text" autoComplete="name" required minLength={2} maxLength={160} defaultValue={value('recipient_name', editing?.recipient_name ?? account.name)} className={inputClass} {...invalid('recipient_name')} />
                </Field>
                <Field id="phone" label={a.phone} required requiredLabel={a.required} help={a.phoneHelp} error={err('phone')}>
                    <input id="phone" name="phone" type="tel" inputMode="tel" autoComplete="tel-national" required maxLength={20} defaultValue={value('phone', nationalPhone(editing?.phone ?? account.phone) ?? editing?.phone ?? account.phone)} aria-describedby="phone-help" className={`${inputClass} tabular-nums`} {...invalid('phone')} />
                </Field>
                <div className="grid gap-3.5 sm:grid-cols-2">
                    <Field id="district_id" label={a.district} required requiredLabel={a.required} help={a.districtHelp} error={err('district_id')}>
                        <SelectBox id="district_id" name="district_id" required autoComplete="address-level1" defaultValue={district ? String(district.id) : ''} aria-describedby="district_id-help" {...invalid('district_id')}>
                            <option value="">{a.districtPlaceholder}</option>
                            {reference.districts.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {placeName(d, bn)}
                                </option>
                            ))}
                        </SelectBox>
                    </Field>
                    <Field id="upazila_id" label={a.upazila} required={!district?.city_option} requiredLabel={a.required} help={district ? a.districtHelp : a.upazilaFirst} error={err('upazila_id')}>
                        <SelectBox
                            id="upazila_id"
                            name="upazila_id"
                            required={Boolean(district) && !district?.city_option}
                            disabled={!district}
                            autoComplete="address-level2"
                            defaultValue={upazilaValue}
                            key={district?.id ?? 0}
                            aria-describedby="upazila_id-help"
                            {...invalid('upazila_id')}
                        >
                            <option value="">{district?.city_option ? format(a.cityOption, { district: placeName(district, bn) }) : district ? a.upazilaPlaceholder : a.upazilaFirst}</option>
                            {upazilas.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {placeName(u, bn)}
                                </option>
                            ))}
                        </SelectBox>
                    </Field>
                </div>
                <button id="address-refresh" type="submit" name="intent" value="refresh" formNoValidate className={`${buttonClass('secondary', 'md')} w-full`}>
                    {a.showUpazilas}
                </button>
                <Field id="area" label={a.area} required requiredLabel={a.required} error={err('area')}>
                    <input id="area" name="area" type="text" autoComplete="address-level3" required minLength={2} maxLength={160} defaultValue={value('area', editing?.area)} placeholder={a.areaPlaceholder} className={inputClass} {...invalid('area')} />
                </Field>
                <Field id="address_line" label={a.line} required requiredLabel={a.required} error={err('address_line')}>
                    <textarea id="address_line" name="address_line" autoComplete="street-address" required minLength={5} maxLength={500} rows={2} defaultValue={value('address_line', editing?.address_line)} placeholder={a.linePlaceholder} className={textareaClass} {...invalid('address_line')} />
                </Field>
                <Field id="landmark" label={a.landmark} help={a.landmarkHelp} error={err('landmark')}>
                    <input id="landmark" name="landmark" type="text" maxLength={255} defaultValue={value('landmark', editing?.landmark)} placeholder={a.landmarkPlaceholder} aria-describedby="landmark-help" className={inputClass} {...invalid('landmark')} />
                </Field>

                <fieldset className="flex flex-col gap-2">
                    <legend className="t-field-label mb-2 text-fg-primary">{a.type}</legend>
                    <div className="flex flex-wrap gap-2">
                        {(['home', 'office', 'other'] as const).map((key) => (
                            <label key={key} className="t-body-sm-strong flex h-9 cursor-pointer items-center rounded-full border-2 border-line-default bg-surface px-4 text-fg-primary has-checked:border-line-brand has-checked:bg-brand-subtle has-focus-visible:shadow-focus">
                                <input type="radio" name="type" value={key} defaultChecked={type === key} className="sr-only" />
                                {a.types[key]}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <Field id="label" label={a.label} error={err('label')}>
                    <input id="label" name="label" type="text" maxLength={64} defaultValue={value('label', editing?.label)} placeholder={a.labelPlaceholder} className={inputClass} />
                </Field>
                <label className="t-body-sm flex min-h-11 items-center gap-3 text-fg-primary">
                    <input type="checkbox" name="is_default" value="1" defaultChecked={value('is_default', editing?.is_default ? '1' : '') === '1'} className="size-5 accent-brand" />
                    {a.isDefault}
                </label>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" name="intent" value="save" className={`${buttonClass('primary')} w-full sm:w-auto`}>
                        {a.save}
                    </button>
                    {editing ? (
                        <a href={localizedPath(locale, PATH)} className={`${buttonClass('secondary')} w-full sm:w-auto`}>
                            {a.cancel}
                        </a>
                    ) : null}
                </div>
            </form>
        </AccountShell>
    );
}
