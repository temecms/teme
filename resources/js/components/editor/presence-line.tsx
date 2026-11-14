import type { EditorPerson } from '@/types';

/** Names as a sentence: "Ade", "Ade and Bo", "Ade, Bo and Cy". */
function names(people: EditorPerson[]): string {
    const list = people.map((person) => person.name);

    return list.length <= 1
        ? (list[0] ?? '')
        : `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
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
        <span
            role="status"
            className="bg-warning-muted text-warning rounded-sm px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap"
        >
            {names(people)} {people.length === 1 ? 'is' : 'are'} also editing.
        </span>
    );
}
