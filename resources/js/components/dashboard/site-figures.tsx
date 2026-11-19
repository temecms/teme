import { Link } from '@inertiajs/react';
import { __ } from '@/lib/i18n';
import type { SiteFigure } from '@/types';

/** The dot's token by the figure's status colour, spelled out for Tailwind. */
const DOTS: Record<string, string> = {
    ok: 'bg-ok',
    'neutral-dot': 'bg-neutral-dot',
    info: 'bg-info',
    warn: 'bg-warn',
    accent: 'bg-accent',
};

/** One figure: the value large, its label beneath with its status dot. */
function FigureTile({ figure }: { figure: SiteFigure }) {
    const body = (
        <>
            <span className="text-title font-semibold tabular-nums">
                {figure.value.toLocaleString()}
            </span>
            <span className="text-small text-ink-2 flex items-center gap-2">
                <span
                    aria-hidden="true"
                    data-dot={figure.dot}
                    className={`size-2 shrink-0 rounded-full ${DOTS[figure.dot] ?? 'bg-neutral-dot'}`}
                />
                {figure.label}
            </span>
        </>
    );

    const className =
        'flex flex-col gap-1 px-5 py-4 shadow-raised rounded-lg bg-surface';

    return figure.url === null ? (
        <span className={className}>{body}</span>
    ) : (
        <Link
            href={figure.url}
            className={`${className} hover:bg-surface-2 focus-visible:outline-ring no-underline focus-visible:outline-2 focus-visible:outline-offset-2`}
        >
            {body}
        </Link>
    );
}

/**
 * The site's five figures as a row: 5 across from 1024, 3 from 768, 2 under
 * 768. Each links to its list when the person may open it.
 */
export function SiteFigures({ figures }: { figures: SiteFigure[] }) {
    return (
        <nav
            aria-label={__('Site figures')}
            data-test="site-figures"
            className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5"
        >
            {figures.map((figure) => (
                <FigureTile key={figure.key} figure={figure} />
            ))}
        </nav>
    );
}
