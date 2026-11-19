import { __ } from '@/lib/i18n';
import type { FieldChange, TermChange } from '@/types';

/**
 * One field's change: the old value struck and the new one marked, or,
 * when there was none before, the new value or what happened to it
 * ("image added") as it stands.
 */
function FieldLine({ change }: { change: FieldChange }) {
    if (change.before === null) {
        return <>{change.after ?? __('empty')}</>;
    }

    return (
        <>
            <del>{change.before}</del>{' '}
            {change.after === null ? __('empty') : <ins>{change.after}</ins>}
        </>
    );
}

/** One taxonomy's change: the terms removed struck, the terms added marked. */
function TermLine({ change }: { change: TermChange }) {
    return (
        <>
            {change.removed.length > 0 && (
                <del>{change.removed.join(', ')}</del>
            )}
            {change.removed.length > 0 && change.added.length > 0 && ' '}
            {change.added.length > 0 && <ins>{change.added.join(', ')}</ins>}
        </>
    );
}

/**
 * The title, excerpt, other fields and terms that changed between two
 * revisions, above the body's diff, so a change to the SEO title or a move
 * to another category is never an empty comparison. Plain text: unlike the
 * blocks, these values are not escaped by the server, so none is drawn as
 * HTML. The marks take their colors from the panel around them (DiffView).
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
            className="text-body grid gap-2"
        >
            <h2 id="meta-changes-title" className="text-lead font-semibold">
                {__('Title, excerpt and terms')}
            </h2>
            <dl className="grid gap-1">
                {fields.map((change) => (
                    <div
                        key={change.key}
                        className="flex flex-wrap gap-x-3 max-sm:flex-col"
                    >
                        <dt className="text-ink-2 w-40 shrink-0">
                            {change.label}
                        </dt>
                        <dd className="min-w-0 flex-1 break-words">
                            <FieldLine change={change} />
                        </dd>
                    </div>
                ))}
                {terms.map((change) => (
                    <div
                        key={change.taxonomy}
                        className="flex flex-wrap gap-x-3 max-sm:flex-col"
                    >
                        <dt className="text-ink-2 w-40 shrink-0">
                            {change.label}
                        </dt>
                        <dd className="min-w-0 flex-1 break-words">
                            <TermLine change={change} />
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
