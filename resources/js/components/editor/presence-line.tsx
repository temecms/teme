import { StatusPill } from '@/components/ui/status-pill';
import { __, __n } from '@/lib/i18n';
import type { EditorPerson } from '@/types';

/** Names as a sentence: "Ade", "Ade and Bo", "Ade, Bo and Cy". */
function names(people: EditorPerson[]): string {
    const list = people.map((person) => person.name);

    return list.length <= 1
        ? (list[0] ?? '')
        : __(':names and :last', {
              names: list.slice(0, -1).join(', '),
              last: list[list.length - 1],
          });
}

/**
 * Who else has unsaved work in the article: the warning that usually
 * prevents a stale save. Nothing when nobody else is here.
 */
export function PresenceLine({ people }: { people: EditorPerson[] }) {
    if (people.length === 0) {
        return null;
    }

    return (
        <StatusPill role="status" tone="warn" className="whitespace-nowrap">
            {__n(
                ':names is also editing.|:names are also editing.',
                people.length,
                { names: names(people) },
            )}
        </StatusPill>
    );
}
