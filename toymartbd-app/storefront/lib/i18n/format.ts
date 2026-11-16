// Placeholder substitution, kept apart from the message catalogs: a client component that imports
// it (or localizedPath from ./config) must not pull both full dictionaries into every page's JS —
// importing it from the index did, and each new string grew every page's bundle (budget gate, UI-UX §8).

/** Replace `{name}` placeholders. Values are inserted as-is (React escapes them on render). */
export function format(template: string, values: Record<string, string>): string {
    return template.replace(/\{(\w+)\}/g, (match, key: string) => values[key] ?? match);
}
