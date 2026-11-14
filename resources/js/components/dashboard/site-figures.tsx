import { Link } from '@inertiajs/react';
import type { SiteFigure } from '@/types';

type Group = { group: string | null; figures: SiteFigure[] };

/** Figures that share a heading, in the order they came. */
function grouped(figures: SiteFigure[]): Group[] {
    const groups: Group[] = [];

    for (const figure of figures) {
        const last = groups.at(-1);

        if (last !== undefined && last.group === figure.group) {
            last.figures.push(figure);
        } else {
            groups.push({ group: figure.group, figures: [figure] });
        }
    }

    return groups;
}

/** One figure: the number large, its label small beneath. */
function Figure({ figure }: { figure: SiteFigure }) {
    const body = (
        <>
            <span className="text-[22px] leading-none font-semibold tabular-nums">
                {figure.value.toLocaleString()}
            </span>
            <span className="text-ink-2 mt-1 text-xs">{figure.label}</span>
        </>
    );

    return figure.url === null ? (
        <span className="flex flex-col">{body}</span>
    ) : (
        <Link
            href={figure.url}
            className="flex flex-col underline-offset-2 hover:underline"
        >
            {body}
        </Link>
    );
}

/**
 * The site's figures in one compact row: each type's three under its name,
 * then Media — a link where the person may open its screen. It wraps; it
 * never scrolls sideways.
 */
export function SiteFigures({ figures }: { figures: SiteFigure[] }) {
    return (
        <section
            aria-labelledby="site-figures"
            className="border-line border-t pt-4"
        >
            <h2 id="site-figures" className="sr-only">
                The site in figures
            </h2>
            <div className="flex flex-wrap gap-x-10 gap-y-4">
                {grouped(figures).map(({ group, figures: members }) => (
                    <div key={group ?? members[0].key} className="grid gap-1.5">
                        <p className="text-ink-2 text-[11px] font-medium">
                            {group ?? ' '}
                        </p>
                        <ul
                            aria-label={group ?? undefined}
                            className="flex flex-wrap gap-x-6 gap-y-2"
                        >
                            {members.map((figure) => (
                                <li key={figure.key}>
                                    <Figure figure={figure} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </section>
    );
}
