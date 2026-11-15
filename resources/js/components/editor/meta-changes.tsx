import { __ } from '@/lib/i18n';
import type { FieldChange, TermChange } from '@/types';

/** One field's change in words: before → after, or whichever side there is. */
function fieldLine(change: FieldChange): string {
    if (change.before === null) {
        return change.after ?? 'empty';
    }

    return `${change.before} → ${change.after ?? 'empty'}`;
}

/** One taxonomy's change: what was added, then what was removed. */
function termLine(change: TermChange): string {
    return [
        change.added.length > 0 ? `added: ${change.added.join(', ')}` : '',
        change.removed.length > 0
            ? `removed: ${change.removed.join(', ')}`
            : '',
    ]
        .filter((part) => part !== '')
        .join('; ');
}

/**
 * The fields and categories that changed between two revisions, above the
 * body's diff, so a change to the SEO title or a move to another category is
 * never an empty comparison. Plain text: unlike the blocks, these values are
 * not escaped by the server, so none is drawn as HTML.
 */
export function MetaChanges({
    fields,
    terms,
}: {
    fields: FieldChange[];
    terms: TermChange[];
}) {
    if (fields.length === 0 && terms.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="meta-changes-title"
            className="border-line text-small grid gap-2 rounded-md border px-4 py-3"
        >
            <h2 id="meta-changes-title" className="font-semibold">
                {__('Fields and categories')}
            </h2>
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1">
                {fields.map((change) => (
                    <div key={change.key} className="contents">
                        <dt className="text-ink-2">{change.label}</dt>
                        <dd className="break-words">{fieldLine(change)}</dd>
                    </div>
                ))}
                {terms.map((change) => (
                    <div key={change.taxonomy} className="contents">
                        <dt className="text-ink-2">{change.label}</dt>
                        <dd className="break-words">{termLine(change)}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
