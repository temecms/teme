import { Link } from '@inertiajs/react';
import { __ } from '@/lib/i18n';
import type { SiteFigure } from '@/types';

/** One figure tile: the value large, its label with its group beneath. */
function FigureTile({ figure }: { figure: SiteFigure }) {
    const label = figure.group
        ? `${figure.label} · ${figure.group}`
        : figure.label;

    const body = (
        <>
            <span className="text-title font-semibold tabular-nums">
                {figure.value.toLocaleString()}
            </span>
            <span className="text-small text-ink-2">{label}</span>
        </>
    );

    const className =
        'flex flex-col gap-1 px-5 py-4 shadow-raised rounded-lg bg-surface';

    return figure.url === null ? (
        <span className={className}>{body}</span>
    ) : (
        <Link
            href={figure.url}
            className={`${className} underline-offset-2 hover:underline`}
        >
            {body}
        </Link>
    );
}

/**
 * The site's figures as a row of tiles: 5 across from 1024, 3 from 640, 2 on
 * a phone. Each tile links to its list when the person may open it.
 */
export function SiteFigures({ figures }: { figures: SiteFigure[] }) {
    return (
        <nav
            aria-label={__('Site figures')}
            data-test="site-figures"
            className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5"
        >
            {figures.map((figure) => (
                <FigureTile key={figure.key} figure={figure} />
            ))}
        </nav>
    );
}
