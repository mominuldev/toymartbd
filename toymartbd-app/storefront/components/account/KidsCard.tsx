import { Icon } from '@/components/icons/Icon';
import { Field, SelectBox, inputClass } from '@/components/cart/fields';
import { smallButton } from '@/components/account/styles';
import { noticeText } from '@/lib/account/format';
import type { AccountMessages } from '@/lib/account/messages';
import type { AccountChild } from '@/lib/account/types';
import { format, type Locale } from '@/lib/i18n';

// "My kids" — Figma 239:13856 (Card 239:14101): title, a caption, one pastel tile per child (smiley,
// "Arian · 6 years" / "Birthday 12 Mar"), then an outline "+ Add a child" button. Every action is a
// plain POST to /api/account/children, and the forms open with <details>, so it all works without
// JavaScript. The tile is the edit control (pencil at its end, as in the 390 frame 238:10951); Remove
// (not drawn) is a trash button beside it. Children's data: only a first name or nickname and the birthday (year optional).

/** The most a customer may add — the API's cap (CustomerChild::MAX_PER_CUSTOMER). */
export const MAX_CHILDREN = 6;

const TILES = ['bg-tile-sky', 'bg-tile-pink', 'bg-tile-mint', 'bg-tile-butter', 'bg-tile-lavender', 'bg-tile-peach'] as const;

interface Props {
    locale: Locale;
    m: AccountMessages;
    items: AccountChild[];
    /** After a failed submit: which form ('new' or a child id) and what was typed. */
    draft: Record<string, string>;
    errors: Record<string, string>;
}

/** "12 Mar" — Western digits in both locales (rule 36). */
export function birthdayLabel(child: Pick<AccountChild, 'birth_month' | 'birth_day'>, k: AccountMessages['profile']['kids']): string {
    return `${String(child.birth_day).padStart(2, '0')} ${k.monthsShort[child.birth_month - 1] ?? ''}`;
}

export function childTitle(child: Pick<AccountChild, 'name' | 'age'>, k: AccountMessages['profile']['kids']): string {
    const name = child.name ?? k.unnamed;
    return child.age === null ? name : format(k.nameAge, { name, age: String(child.age) });
}

export function KidsCard({ locale, m, items, draft, errors }: Props) {
    const k = m.profile.kids;
    const failedFor = draft.child_id ?? null;
    const err = (key: string) => noticeText(errors[key], m);
    const formError = failedFor !== null ? (err('children') ?? err('birth_day') ?? err('birth_year') ?? err('birth_month') ?? err('name')) : null;

    return (
        <section id="kids" aria-labelledby="kids-title" className="flex flex-col gap-3 rounded-md border border-line-default bg-surface p-5">
            <h2 id="kids-title" className="t-h4 text-fg-primary">
                {k.title}
            </h2>
            <p className="t-caption text-fg-secondary">{k.intro}</p>

            {items.length === 0 ? <p className="t-body-sm text-fg-secondary">{k.empty}</p> : null}
            <ul className="flex flex-col gap-3">
                {items.map((child, i) => {
                    const label = child.name ?? k.unnamed;
                    const open = failedFor === String(child.id);
                    return (
                        <li key={child.id} className={`relative rounded-sm ${TILES[i % TILES.length]}`}>
                            <details open={open}>
                                {/* The tile row is the summary: tapping it (or the pencil) opens the edit form. */}
                                <summary aria-label={format(k.editLabel, { name: label })} className="flex cursor-pointer list-none items-center gap-3 p-3 pe-14 [&::-webkit-details-marker]:hidden">
                                    <Icon name="smiley" className="size-6 shrink-0 text-fg-brand" />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="t-body-strong text-fg-primary">{childTitle(child, k)}</span>
                                        <span className="t-caption text-fg-secondary tabular-nums">{format(k.birthday, { date: birthdayLabel(child, k) })}</span>
                                    </span>
                                    <Icon name="pencilSimple" className="size-5 shrink-0 text-fg-primary" />
                                </summary>
                                <div className="px-3 pb-3">
                                    <ChildForm locale={locale} m={m} child={child} draft={open ? draft : null} error={open ? formError : null} />
                                </div>
                            </details>
                            <form method="post" action="/api/account/children" className="absolute end-1 top-2.5">
                                <input type="hidden" name="locale" value={locale} />
                                <input type="hidden" name="intent" value="delete" />
                                <input type="hidden" name="id" value={child.id} />
                                <button type="submit" aria-label={format(k.removeLabel, { name: label })} className="flex size-11 items-center justify-center rounded-full text-fg-primary hover:bg-surface/60">
                                    <Icon name="trash" className="size-4" />
                                </button>
                            </form>
                        </li>
                    );
                })}
            </ul>

            {items.length < MAX_CHILDREN ? (
                <details open={failedFor === 'new'} className="flex flex-col gap-3 [&[open]>summary]:mb-3">
                    <summary className={`${smallButton.outline} w-fit cursor-pointer list-none [&::-webkit-details-marker]:hidden`}>
                        <Icon name="plus" className="size-4" />
                        {k.add}
                    </summary>
                    <ChildForm locale={locale} m={m} child={null} draft={failedFor === 'new' ? draft : null} error={failedFor === 'new' ? formError : null} />
                </details>
            ) : (
                <p className="t-caption text-fg-secondary">{format(k.full, { max: String(MAX_CHILDREN) })}</p>
            )}
        </section>
    );
}

function ChildForm({ locale, m, child, draft, error }: { locale: Locale; m: AccountMessages; child: AccountChild | null; draft: Record<string, string> | null; error: string | null }) {
    const k = m.profile.kids;
    const p = child ? `child-${child.id}` : 'child-new';
    const thisYear = new Date().getFullYear();
    const value = (key: string, fallback: string) => draft?.[key] ?? fallback;

    return (
        <form method="post" action="/api/account/children" aria-label={child ? format(k.editTitle, { name: child.name ?? k.unnamed }) : k.addTitle} className="flex flex-col gap-3 rounded-sm bg-surface p-3">
            <input type="hidden" name="locale" value={locale} />
            <input type="hidden" name="intent" value="save" />
            {child ? <input type="hidden" name="id" value={child.id} /> : null}
            <Field id={`${p}-name`} label={k.name}>
                <input id={`${p}-name`} name="child_name" type="text" maxLength={40} autoComplete="off" defaultValue={value('child_name', child?.name ?? '')} className={inputClass} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field id={`${p}-day`} label={k.day} required>
                    <SelectBox id={`${p}-day`} name="birth_day" required defaultValue={value('birth_day', child ? String(child.birth_day) : '')} aria-invalid={error ? true : undefined}>
                        <option value="">{k.choose}</option>
                        {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                            <option key={d} value={d}>
                                {d}
                            </option>
                        ))}
                    </SelectBox>
                </Field>
                <Field id={`${p}-month`} label={k.month} required>
                    <SelectBox id={`${p}-month`} name="birth_month" required defaultValue={value('birth_month', child ? String(child.birth_month) : '')} aria-invalid={error ? true : undefined}>
                        <option value="">{k.choose}</option>
                        {k.months.map((name, i) => (
                            <option key={name} value={i + 1}>
                                {name}
                            </option>
                        ))}
                    </SelectBox>
                </Field>
            </div>
            <Field id={`${p}-year`} label={k.year} help={k.yearHelp}>
                <SelectBox id={`${p}-year`} name="birth_year" defaultValue={value('birth_year', child?.birth_year ? String(child.birth_year) : '')} aria-describedby={`${p}-year-help`}>
                    <option value="">{k.yearNone}</option>
                    {Array.from({ length: 26 }, (_, i) => thisYear - i).map((y) => (
                        <option key={y} value={y}>
                            {y}
                        </option>
                    ))}
                </SelectBox>
            </Field>
            {error ? (
                <p role="alert" className="t-caption text-feedback-danger">
                    {error}
                </p>
            ) : null}
            <button type="submit" className={`${smallButton.primary} w-fit`}>
                {k.save}
            </button>
        </form>
    );
}
